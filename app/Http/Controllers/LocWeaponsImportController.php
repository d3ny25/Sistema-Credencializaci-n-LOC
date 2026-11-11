<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessLocWeaponsImportBatch;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Services\LocWeaponsXlsImporter;
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

class LocWeaponsImportController extends Controller
{
    public function upload(Request $request): RedirectResponse
    {
        if (! $this->importSchemaReady()) {
            throw ValidationException::withMessages([
                'archivo_armas' => 'La importación de armamento requiere la migración incremental 2026_10_22_000001. No se creó un lote ni se modificó el inventario.',
            ]);
        }

        $request->validate([
            'reanalyze' => ['sometimes', 'accepted'],
            'reanalysis_request_key' => ['nullable', 'uuid'],
        ]);
        $isReanalysis = $request->boolean('reanalyze');
        $requestKey = $request->input('reanalysis_request_key');
        if ($isReanalysis && ! $requestKey) {
            throw ValidationException::withMessages([
                'reanalyze' => 'No se recibió el identificador de esta solicitud de reanálisis. Vuelve a cargar el formulario.',
            ]);
        }

        $maxUploadKilobytes = $this->maxUploadKilobytes();
        $request->validate([
            'archivo_armas' => [
                'required',
                'file',
                'mimes:xls',
                'extensions:xls',
                'max:'.$maxUploadKilobytes,
            ],
        ], [
            'archivo_armas.mimes' => 'Selecciona un libro Excel binario .xls.',
            'archivo_armas.extensions' => 'El archivo debe tener extensión .xls.',
            'archivo_armas.max' => 'El archivo supera el límite efectivo configurado para PHP y la aplicación.',
        ]);

        $file = $request->file('archivo_armas');
        $path = $file->store('loc-imports', 'local');
        if ($path === false) {
            throw ValidationException::withMessages([
                'archivo_armas' => 'No fue posible guardar temporalmente el archivo de armamento.',
            ]);
        }

        $storedFile = Storage::disk('local')->path($path);
        $fileHash = hash_file('sha256', $storedFile);
        if ($fileHash === false) {
            Storage::disk('local')->delete($path);

            throw ValidationException::withMessages([
                'archivo_armas' => 'No fue posible verificar el archivo recibido. Vuelve a intentarlo.',
            ]);
        }

        try {
            $operationKey = $isReanalysis ? ':reanalyze:'.$requestKey : ':upload';

            return Cache::lock('loc-weapons-import-upload:'.$request->user()->id.':'.$fileHash.$operationKey, 30)
                ->block(5, function () use ($request, $file, $path, $fileHash, $isReanalysis, $requestKey): RedirectResponse {
                    $dispatchFailure = null;
                    $response = DB::transaction(function () use ($request, $file, $path, $fileHash, $isReanalysis, $requestKey, &$dispatchFailure): RedirectResponse {
                        $matchingBatchesQuery = LocImportBatch::query()
                            ->where('usuario_id', $request->user()->id)
                            ->where('archivo_sha256', $fileHash)
                            ->where('tipo_fuente', 'armamento_loc');

                        if ($isReanalysis) {
                            $anchorBatch = (clone $matchingBatchesQuery)
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->first();
                            $matchingBatches = $anchorBatch
                                ? (clone $matchingBatchesQuery)->orderBy('id')->get()
                                : collect();
                        } else {
                            $matchingBatches = (clone $matchingBatchesQuery)
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->get();
                        }

                        if ($isReanalysis) {
                            if ($matchingBatches->isEmpty()) {
                                Storage::disk('local')->delete($path);

                                throw ValidationException::withMessages([
                                    'archivo_armas' => 'El reanálisis requiere que exista un lote anterior del mismo archivo y usuario.',
                                ]);
                            }

                            foreach ($matchingBatches->reverse() as $priorBatch) {
                                $summary = json_decode((string) $priorBatch->resumen_errores, true) ?: [];
                                $reanalysis = $summary['reanalyze'] ?? null;
                                $requestKeys = is_array($reanalysis['request_keys'] ?? null)
                                    ? $reanalysis['request_keys']
                                    : [];

                                if (in_array($requestKey, $requestKeys, true)) {
                                    Storage::disk('local')->delete($path);

                                    return redirect()->route('gestion.armamento.importaciones.preview', $priorBatch)
                                        ->with('success', 'Esta solicitud de reanálisis ya tiene un lote; se abrió su estado actual.');
                                }
                            }

                            $activeBatch = $matchingBatches->reverse()->first(
                                fn (LocImportBatch $candidate): bool => in_array($candidate->estado, ['pendiente', 'procesando'], true),
                            );

                            if ($activeBatch) {
                                $summary = json_decode((string) $activeBatch->resumen_errores, true) ?: [];
                                if (is_array($summary['reanalyze'] ?? null)) {
                                    $summary['reanalyze']['request_keys'] = array_values(array_unique([
                                        ...($summary['reanalyze']['request_keys'] ?? []),
                                        $requestKey,
                                    ]));
                                    $activeBatch->update([
                                        'resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                                    ]);
                                }
                                Storage::disk('local')->delete($path);

                                return redirect()->route('gestion.armamento.importaciones.preview', $activeBatch)
                                    ->with('success', 'Ya hay un análisis de este archivo en curso; se abrió ese lote para evitar un trabajo duplicado.');
                            }

                            $sourceBatch = $matchingBatches->last();
                            $reanalysisMetadata = [
                                'source_batch_id' => $sourceBatch->id,
                                'request_keys' => [$requestKey],
                                'requested_by' => $request->user()->id,
                                'requested_at' => now()->toIso8601String(),
                            ];
                        } else {
                            $existingBatch = $matchingBatches
                                ->reverse()
                                ->first(fn (LocImportBatch $candidate): bool => in_array(
                                    $candidate->estado,
                                    ['pendiente', 'procesando', 'en_revision', 'completado', 'fallido_parcial'],
                                    true,
                                ));

                            if ($existingBatch) {
                                Storage::disk('local')->delete($path);

                                return redirect()->route('gestion.armamento.importaciones.preview', $existingBatch)
                                    ->with('success', 'Este archivo ya tiene un lote; se abrió su estado actual.');
                            }
                            $reanalysisMetadata = null;
                        }

                        $initialSummary = $reanalysisMetadata === null
                            ? null
                            : json_encode(['reanalyze' => $reanalysisMetadata], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        $batch = LocImportBatch::query()->create([
                            'tipo_fuente' => 'armamento_loc',
                            'nombre_archivo' => mb_substr($file->getClientOriginalName(), 0, 255),
                            'fecha_procesamiento' => now(),
                            'usuario_id' => $request->user()->id,
                            'estado' => 'pendiente',
                            'archivo_sha256' => $fileHash,
                            'total_leidas' => 0,
                            'total_aceptadas' => 0,
                            'total_rechazadas' => 0,
                            'total_actualizadas' => 0,
                            'resumen_errores' => $initialSummary,
                        ]);

                        try {
                            ProcessLocWeaponsImportBatch::dispatch($batch->id, $path)
                                ->onConnection(config('loc_import.weapons_queue_connection'))
                                ->onQueue(config('loc_import.weapons_queue'))
                                ->afterCommit();
                        } catch (Throwable $exception) {
                            $batch->update([
                                'estado' => 'fallido',
                                'resumen_errores' => json_encode(array_merge(
                                    json_decode((string) $batch->resumen_errores, true) ?: [],
                                    [
                                        'error' => 'No fue posible iniciar el análisis de armamento.',
                                        'diagnostico' => [
                                            'exception_type' => $exception::class,
                                            'stage' => 'encolado',
                                        ],
                                    ],
                                ), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            ]);
                            Storage::disk('local')->delete($path);
                            Log::error('No se pudo encolar un lote de armamento LOC.', [
                                'batch_id' => $batch->id,
                                'exception_type' => $exception::class,
                            ]);

                            $dispatchFailure = ValidationException::withMessages([
                                'archivo_armas' => 'No fue posible iniciar el análisis. Revisa la cola de trabajos.',
                            ]);

                            return redirect()->route('gestion.armamento.importaciones.preview', $batch);
                        }

                        if ($reanalysisMetadata !== null) {
                            Log::info('Se creó un lote independiente de reanálisis de armamento LOC.', [
                                'batch_id' => $batch->id,
                                'source_batch_id' => $reanalysisMetadata['source_batch_id'],
                                'user_id' => $request->user()->id,
                            ]);
                        }

                        return redirect()->route('gestion.armamento.importaciones.preview', $batch)
                            ->with('success', $isReanalysis
                                ? 'Se creó un lote independiente de reanálisis. Los lotes anteriores y el inventario no se modificaron.'
                                : 'Archivo recibido. El análisis de armamento quedó en cola; aún no se modificó el inventario.');
                    }, 3);

                    if ($dispatchFailure) {
                        throw $dispatchFailure;
                    }

                    return $response;
                });
        } catch (LockTimeoutException) {
            Storage::disk('local')->delete($path);

            throw ValidationException::withMessages([
                'archivo_armas' => 'Otra solicitud del mismo archivo todavía se está registrando. Consulta el historial antes de volver a intentarlo.',
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function preview(Request $request, LocImportBatch $batch): View|JsonResponse
    {
        abort_unless($batch->tipo_fuente === 'armamento_loc', 404);
        abort_unless(in_array($batch->estado, ['pendiente', 'procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'], true), 404);

        $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
        $onlyErrors = $request->boolean('solo_errores');
        $actionFilter = (string) $request->query('accion', '');
        $search = trim((string) $request->query('buscar', ''));

        $query = LocImportStagingRow::query()->where('import_batch_id', $batch->id);
        if ($onlyErrors) {
            $query->whereIn('accion', ['rechazado', 'conflicto']);
        } elseif ($actionFilter !== '') {
            if ($actionFilter === 'requieren_correccion') {
                $query->whereIn('accion', ['rechazado', 'conflicto']);
            } else {
                $query->where('accion', $actionFilter);
            }
        }

        if ($search !== '') {
            $query->where(function ($sub) use ($search) {
                $sub->where('datos->campos->matricula', 'like', "%{$search}%")
                    ->orWhere('datos->campos->marca', 'like', "%{$search}%")
                    ->orWhere('datos->campos->modelo', 'like', "%{$search}%")
                    ->orWhere('datos->campos->id_armamento', 'like', "%{$search}%");
            });
        }

        $rows = in_array($batch->estado, ['pendiente', 'procesando'], true)
            ? new LengthAwarePaginator([], 0, 25)
            : $query->orderBy('numero_fila')->paginate(25)->withQueryString();
        $counts = in_array($batch->estado, ['pendiente', 'procesando'], true)
            ? collect()
            : LocImportStagingRow::query()
                ->where('import_batch_id', $batch->id)
                ->selectRaw('accion, COUNT(*) as total')
                ->groupBy('accion')
                ->pluck('total', 'accion');

        $canConfirm = $request->user()
            && ($batch->usuario_id === $request->user()->id || $request->user()->role === 'superadmin');

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'table_html' => view('modules.partials.gestion-armas-table', [
                    'batch' => $batch,
                    'rows' => $rows,
                    'onlyErrors' => $onlyErrors,
                    'canConfirm' => $canConfirm,
                ])->render(),
                'chips_html' => view('modules.partials.gestion-armas-chips', [
                    'batch' => $batch,
                    'counts' => $counts,
                    'actionFilter' => $actionFilter,
                    'onlyErrors' => $onlyErrors,
                ])->render(),
                'total_coincidentes' => $rows->total(),
            ]);
        }

        return view('modules.gestion-armas-import-preview', [
            'batch' => $batch,
            'rows' => $rows,
            'counts' => $counts,
            'summary' => $summary,
            'onlyErrors' => $onlyErrors,
            'actionFilter' => $actionFilter,
            'search' => $search,
            'canConfirm' => $canConfirm,
        ]);
    }

    public function progress(LocImportBatch $batch): JsonResponse
    {
        abort_unless($batch->tipo_fuente === 'armamento_loc', 404);
        abort_unless(in_array($batch->estado, ['pendiente', 'procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'], true), 404);

        $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
        $progress = $summary['progreso'] ?? [];

        return response()->json([
            'status' => $batch->estado,
            'rows_read' => $batch->total_leidas,
            'rows_estimated' => $progress['filas_estimadas'] ?? null,
            'stage' => $summary['diagnostico']['etapa_actual'] ?? null,
            'queued_seconds' => $batch->estado === 'pendiente'
                ? max(0, $batch->created_at?->diffInSeconds(now()) ?? 0)
                : null,
            'updated_seconds' => max(0, $batch->updated_at?->diffInSeconds(now()) ?? 0),
            'new' => $batch->total_nuevas,
            'updated' => $batch->total_actualizadas,
            'unchanged' => $batch->total_sin_cambios,
            'rejected' => $batch->total_rechazadas,
            'error' => $batch->estado === 'fallido' ? ($summary['error'] ?? 'No se pudo analizar el archivo.') : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request, LocImportBatch $batch, LocWeaponsXlsImporter $importer): RedirectResponse
    {
        abort_unless($batch->tipo_fuente === 'armamento_loc', 404);
        $user = $request->user();
        abort_unless($user && ($batch->usuario_id === $user->id || $user->role === 'superadmin'), 403);

        try {
            $result = $importer->commit($batch, $user->id);
        } catch (Throwable $exception) {
            Log::error('Falló la confirmación de una importación de armamento LOC.', [
                'batch_id' => $batch->id,
                'exception_type' => $exception::class,
            ]);

            $message = $exception instanceof \RuntimeException
                ? $exception->getMessage()
                : 'No fue posible completar la importación. Revisa el lote antes de reanudarlo.';

            return back()->withErrors(['importacion_armas' => $message]);
        }

        $updatedCount = $result['updated'] ?? 0;

        return redirect()->route('gestion.historial')
            ->with('success', "Inventario confirmado: {$result['inserted']} nuevas, {$updatedCount} actualizadas, {$result['unchanged']} sin cambios y {$result['rejected']} rechazadas o en conflicto.");
    }

    public function omitRow(Request $request, LocImportBatch $batch, LocImportStagingRow $row, LocWeaponsXlsImporter $importer): RedirectResponse
    {
        abort_unless($batch->tipo_fuente === 'armamento_loc', 404);
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
            && Schema::hasColumns('loc_import_batches', [
                'archivo_sha256',
                'revision_sha256',
                'hoja_procesada',
                'total_columnas',
                'total_nuevas',
                'total_sin_cambios',
                'total_insertadas',
                'fecha_confirmacion',
            ])
            && Schema::hasTable('loc_import_staging_rows')
            && Schema::hasColumns('loc_import_staging_rows', [
                'import_batch_id',
                'numero_fila',
                'matricula',
                'id_armamento',
                'datos',
                'accion',
                'estado_aplicacion',
            ])
            && Schema::hasColumns('loc_armas', ['matricula', 'id_armamento', 'datos_origen'])
            && collect(Schema::getIndexes('loc_armas'))->contains(
                static fn (array $index): bool => $index['name'] === 'loc_armas_matricula_unique'
                    && $index['unique']
                    && $index['columns'] === ['matricula']
            );
    }

    private function maxUploadKilobytes(): int
    {
        $limits = [max(1, (int) config('loc_import.max_file_kilobytes', 51200))];
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
