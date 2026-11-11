<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessLocCredentialsImportBatch;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Services\LocCredentialsXlsImporter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class LocCredentialsImportController extends Controller
{
    public function upload(Request $request): RedirectResponse
    {
        if (! $this->importSchemaReady()) {
            throw ValidationException::withMessages([
                'archivo_vigencias' => 'La importación de vigencias requiere la migración de soporte para credenciales. No se creó un lote ni se modificó el historial.',
            ]);
        }

        $maxUploadKilobytes = $this->maxUploadKilobytes();
        $request->validate([
            'archivo_vigencias' => [
                'required',
                'file',
                'mimes:xls',
                'extensions:xls',
                'max:'.$maxUploadKilobytes,
            ],
        ], [
            'archivo_vigencias.mimes' => 'Selecciona un libro Excel binario .xls.',
            'archivo_vigencias.extensions' => 'El archivo debe tener extensión .xls.',
            'archivo_vigencias.max' => 'El archivo supera el límite efectivo configurado para PHP y la aplicación.',
        ]);

        $file = $request->file('archivo_vigencias');
        $path = $file->store('loc-imports', 'local');
        if ($path === false) {
            throw ValidationException::withMessages([
                'archivo_vigencias' => 'No fue posible guardar temporalmente el archivo de vigencias.',
            ]);
        }

        $storedFile = Storage::disk('local')->path($path);
        $fileHash = hash_file('sha256', $storedFile);
        if ($fileHash === false) {
            Storage::disk('local')->delete($path);

            throw ValidationException::withMessages([
                'archivo_vigencias' => 'No fue posible verificar la integridad del archivo recibido. Vuelve a intentarlo.',
            ]);
        }

        try {
            return Cache::lock('loc-credentials-import-upload:'.$request->user()->id.':'.$fileHash, 30)
                ->block(5, function () use ($request, $file, $path, $fileHash): RedirectResponse {
                    $existingBatch = LocImportBatch::query()
                        ->where('usuario_id', $request->user()->id)
                        ->where('archivo_sha256', $fileHash)
                        ->where('tipo_fuente', LocCredentialsXlsImporter::SOURCE_TYPE)
                        ->whereIn('estado', ['pendiente', 'procesando', 'en_revision', 'completado', 'fallido_parcial'])
                        ->latest('id')
                        ->first();

                    if ($existingBatch) {
                        Storage::disk('local')->delete($path);

                        $message = $existingBatch->estado === 'completado'
                            ? 'Este archivo de vigencias ya fue confirmado; se abrió el lote existente para consultar el resultado.'
                            : 'Este archivo ya tiene un análisis de vigencias registrado; se abrió el análisis existente.';

                        return redirect()->route('gestion.vigencias.importaciones.preview', $existingBatch)
                            ->with('success', $message);
                    }

                    $batch = null;
                    try {
                        $batch = DB::transaction(function () use ($request, $file, $fileHash, $path): LocImportBatch {
                            $created = LocImportBatch::create([
                                'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
                                'nombre_archivo' => mb_substr($file->getClientOriginalName(), 0, 255),
                                'fecha_procesamiento' => now(),
                                'usuario_id' => $request->user()->id,
                                'estado' => 'pendiente',
                                'archivo_sha256' => $fileHash,
                                'total_leidas' => 0,
                                'total_aceptadas' => 0,
                                'total_rechazadas' => 0,
                                'total_actualizadas' => 0,
                            ]);

                            ProcessLocCredentialsImportBatch::dispatch($created->id, $path)
                                ->onConnection(config('loc_import.credentials_queue_connection'))
                                ->onQueue(config('loc_import.credentials_queue'))
                                ->afterCommit();

                            return $created;
                        });

                        return redirect()->route('gestion.vigencias.importaciones.preview', $batch)
                            ->with('success', 'Archivo de vigencias recibido. El análisis quedó en cola; aún no se modificó la base de credenciales.');
                    } catch (Throwable $e) {
                        if ($batch) {
                            $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
                            $batch->update([
                                'estado' => 'fallido',
                                'resumen_errores' => json_encode(array_merge($summary, [
                                    'error' => 'No fue posible encolar el trabajo de análisis.',
                                    'diagnostico' => ['exception_type' => $e::class],
                                ]), JSON_UNESCAPED_UNICODE),
                            ]);
                        }
                        Storage::disk('local')->delete($path);
                        Log::error('No se pudo encolar un lote de vigencias LOC.', [
                            'batch_id' => $batch?->id,
                            'exception_type' => $e::class,
                        ]);

                        throw ValidationException::withMessages([
                            'archivo_vigencias' => 'No fue posible iniciar el análisis. Revisa la cola de trabajos e inténtalo de nuevo.',
                        ]);
                    }
                });
        } catch (LockTimeoutException) {
            Storage::disk('local')->delete($path);

            throw ValidationException::withMessages([
                'archivo_vigencias' => 'Otra solicitud del mismo archivo todavía se está registrando. Espera unos segundos y consulta el historial.',
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function progress(LocImportBatch $batch): JsonResponse
    {
        abort_unless($batch->tipo_fuente === LocCredentialsXlsImporter::SOURCE_TYPE, 404);

        $summary = json_decode((string) $batch->resumen_errores, true) ?: [];

        return response()->json([
            'status' => $batch->estado,
            'rows_read' => $batch->total_leidas,
            'accepted' => $batch->total_aceptadas,
            'rejected' => $batch->total_rechazadas,
            'new' => $batch->total_insertadas,
            'updated' => $batch->total_actualizadas,
            'unchanged' => $batch->total_sin_cambios,
            'percentage' => $summary['porcentaje'] ?? null,
            'estimated_rows' => $summary['filas_estimadas'] ?? null,
            'error' => $batch->estado === 'fallido' ? ($summary['error'] ?? 'Falló el análisis del archivo.') : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request, LocImportBatch $batch): View|JsonResponse
    {
        abort_unless($batch->tipo_fuente === LocCredentialsXlsImporter::SOURCE_TYPE, 404);
        abort_unless(in_array($batch->estado, ['pendiente', 'procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'], true), 404);

        $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
        $hasStagingRows = ! in_array($batch->estado, ['pendiente', 'procesando'], true)
            && Schema::hasTable('loc_import_staging_rows');

        $onlyErrors = $request->boolean('solo_errores');
        $actionFilter = $request->input('accion');
        $search = trim((string) $request->input('buscar'));

        $counts = [
            'nuevo' => (int) $batch->total_insertadas,
            'nuevo_conciliado' => (int) ($summary['nuevo_conciliado'] ?? 0),
            'nuevo_sin_enlace' => (int) ($summary['nuevo_sin_enlace'] ?? 0),
            'actualizar' => (int) $batch->total_actualizadas,
            'sin_cambios' => (int) $batch->total_sin_cambios,
            'cancelacion' => (int) ($summary['cancelacion'] ?? 0),
            'conflicto' => 0,
            'rechazado' => 0,
            'omitido' => 0,
        ];

        if ($hasStagingRows) {
            $dbCounts = LocImportStagingRow::where('import_batch_id', $batch->id)
                ->selectRaw('accion, COUNT(*) as total')
                ->groupBy('accion')
                ->pluck('total', 'accion')
                ->all();

            foreach ($dbCounts as $acc => $cnt) {
                $counts[$acc] = (int) $cnt;
            }

            if ($counts['nuevo_sin_enlace'] === 0 && ($counts['nuevo'] ?? 0) > 0) {
                $counts['nuevo_sin_enlace'] = LocImportStagingRow::where('import_batch_id', $batch->id)
                    ->where('accion', 'nuevo')
                    ->where(function ($q) {
                        $q->whereNull('cuip')
                            ->orWhere('datos->conciliacion->persona_resuelta', false)
                            ->orWhereNull('datos->campos->persona_id');
                    })
                    ->count();
                $counts['nuevo_conciliado'] = max(0, $counts['nuevo'] - $counts['nuevo_sin_enlace']);
            }

            if ($counts['cancelacion'] === 0) {
                $counts['cancelacion'] = LocImportStagingRow::where('import_batch_id', $batch->id)
                    ->where('datos->campos->estatus_credencial', 'CANCELACION')
                    ->count();
            }

            $query = LocImportStagingRow::query()
                ->where('import_batch_id', $batch->id);

            if ($onlyErrors) {
                $query->whereIn('accion', ['conflicto', 'rechazado']);
            } elseif ($actionFilter === 'nuevo_conciliado') {
                $query->where('accion', 'nuevo')
                    ->where('datos->conciliacion->persona_resuelta', true);
            } elseif ($actionFilter === 'nuevo_sin_enlace') {
                $query->where('accion', 'nuevo')
                    ->where(function ($q) {
                        $q->whereNull('cuip')
                            ->orWhere('datos->conciliacion->persona_resuelta', false)
                            ->orWhereNull('datos->campos->persona_id');
                    });
            } elseif ($actionFilter === 'cancelacion') {
                $query->where('datos->campos->estatus_credencial', 'CANCELACION');
            } elseif ($actionFilter === 'omitido') {
                $query->where('accion', LocCredentialsXlsImporter::ACTION_OMITIDO);
            } elseif ($actionFilter && in_array($actionFilter, ['nuevo', 'actualizar', 'sin_cambios', 'conflicto', 'rechazado', 'omitido'], true)) {
                $query->where('accion', $actionFilter);
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('cuip', 'like', "%{$search}%")
                        ->orWhere('matricula', 'like', "%{$search}%")
                        ->orWhere('id_armamento', 'like', "%{$search}%");
                });
            }

            $rows = $query->orderBy('numero_fila')->paginate(50)->withQueryString();
        } else {
            $rows = new LengthAwarePaginator([], 0, 50);
        }

        $structuralErrorsCount = ($counts['conflicto'] ?? 0) + ($counts['rechazado'] ?? 0);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'table_html' => view('modules.partials.gestion-vigencias-table', [
                    'batch' => $batch,
                    'rows' => $rows,
                    'only_errors' => $onlyErrors,
                ])->render(),
                'chips_html' => view('modules.partials.gestion-vigencias-chips', [
                    'batch' => $batch,
                    'counts' => $counts,
                    'action_filter' => $actionFilter,
                    'only_errors' => $onlyErrors,
                    'structural_errors_count' => $structuralErrorsCount,
                ])->render(),
                'total_coincidentes' => $rows->total(),
                'structural_errors' => $structuralErrorsCount,
            ]);
        }

        return view('modules.gestion-credenciales-import-preview', [
            'batch' => $batch,
            'rows' => $rows,
            'counts' => $counts,
            'structural_errors_count' => $structuralErrorsCount,
            'summary' => $summary,
            'only_errors' => $onlyErrors,
            'action_filter' => $actionFilter,
            'search' => $search,
        ]);
    }

    public function confirm(Request $request, LocImportBatch $batch, LocCredentialsXlsImporter $importer): RedirectResponse
    {
        abort_unless($batch->tipo_fuente === LocCredentialsXlsImporter::SOURCE_TYPE, 404);
        $user = $request->user();
        abort_unless($user && ($batch->usuario_id === $user->id || $user->role === 'superadmin'), 403);

        try {
            $result = $importer->commit($batch, $user->id);
        } catch (Throwable $exception) {
            Log::error('Falló la confirmación de una importación de vigencias LOC.', [
                'batch_id' => $batch->id,
                'exception_type' => $exception::class,
            ]);

            $message = $exception instanceof \RuntimeException
                ? $exception->getMessage()
                : 'No fue posible completar la importación de vigencias. Revisa los conflictos antes de reanudarla.';

            return back()->withErrors(['importacion_vigencias' => $message]);
        }

        return redirect()->route('gestion.historial', ['tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE])
            ->with('success', "Base de credenciales confirmada: {$result['inserted']} nuevas, {$result['updated']} actualizadas y {$result['unchanged']} sin cambios.");
    }

    public function omitRow(Request $request, LocImportBatch $batch, LocImportStagingRow $row, LocCredentialsXlsImporter $importer): RedirectResponse
    {
        abort_unless($batch->tipo_fuente === LocCredentialsXlsImporter::SOURCE_TYPE, 404);
        abort_unless((int) $row->import_batch_id === (int) $batch->id, 404);
        $user = $request->user();
        abort_unless($user && ($batch->usuario_id === $user->id || $user->role === 'superadmin'), 403);

        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'motivo.required' => 'Debes proporcionar un motivo para omitir la fila.',
            'motivo.min' => 'El motivo debe tener al menos 5 caracteres.',
        ]);

        try {
            $importer->omitStagingRow($row, $validated['motivo'], $user->id);
        } catch (Throwable $exception) {
            return back()->withErrors(['omitir' => $exception->getMessage()]);
        }

        return back()->with('success', "Fila #{$row->numero_fila} omitida formalmente con trazabilidad.");
    }

    private function importSchemaReady(): bool
    {
        return Schema::hasTable('jobs')
            && Schema::hasTable('loc_import_batches')
            && Schema::hasTable('loc_import_staging_rows')
            && Schema::hasTable('loc_vigencias_credenciales')
            && Schema::hasColumns('loc_vigencias_credenciales', [
                'arma_corta_id',
                'matricula_corta',
                'arma_larga_id',
                'matricula_larga',
                'periodo_credencial',
                'fila_hash',
            ]);
    }

    private function maxUploadKilobytes(): int
    {
        $limits = [max(1, (int) config('loc_import.max_file_kilobytes', 81920))];
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $value = trim((string) ini_get($setting));
            if ($value === '' || $value === '0') {
                continue;
            }
            $unit = strtolower(substr($value, -1));
            $bytes = (float) $value;
            $bytes *= match ($unit) {
                'g' => 1024 ** 3,
                'm' => 1024 ** 2,
                'k' => 1024,
                default => 1,
            };
            $kilobytes = (int) floor($bytes / 1024);
            if ($setting === 'post_max_size') {
                $kilobytes = max(1, $kilobytes - 1);
            }
            $limits[] = max(1, $kilobytes);
        }

        return min($limits);
    }
}

