<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessLocCatalogoImportBatch;
use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Services\LocCatalogoXlsImporter;
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

class LocCatalogoImportController extends Controller
{
    public function upload(Request $request): RedirectResponse
    {
        $maxUploadKilobytes = $this->maxUploadKilobytes();

        $request->validate([
            'archivo_catalogo' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'extensions:xlsx,xls',
                'max:' . $maxUploadKilobytes,
            ],
        ], [
            'archivo_catalogo.required' => 'Selecciona el libro Excel oficial del catálogo organizacional.',
            'archivo_catalogo.mimes' => 'El archivo debe ser un libro Excel válido (.xlsx o .xls).',
            'archivo_catalogo.extensions' => 'El archivo debe tener extensión .xlsx o .xls.',
            'archivo_catalogo.max' => 'El archivo supera el límite de carga configurado.',
        ]);

        $file = $request->file('archivo_catalogo');
        $path = $file->store('loc-imports', 'local');
        if ($path === false) {
            throw ValidationException::withMessages([
                'archivo_catalogo' => 'No fue posible guardar temporalmente el archivo del catálogo.',
            ]);
        }

        $storedFile = Storage::disk('local')->path($path);
        $fileHash = hash_file('sha256', $storedFile);
        if ($fileHash === false) {
            Storage::disk('local')->delete($path);

            throw ValidationException::withMessages([
                'archivo_catalogo' => 'No fue posible verificar la integridad del archivo recibido.',
            ]);
        }

        $userId = $request->user()->id;

        try {
            return Cache::lock('loc-catalogo-import-upload:' . $userId . ':' . $fileHash, 30)
                ->block(5, function () use ($request, $file, $path, $fileHash, $userId): RedirectResponse {
                    $existingBatch = LocImportBatch::query()
                        ->where('usuario_id', $userId)
                        ->where('archivo_sha256', $fileHash)
                        ->where('tipo_fuente', LocCatalogoXlsImporter::SOURCE_TYPE)
                        ->whereIn('estado', ['pendiente', 'procesando', 'en_revision', 'completado'])
                        ->latest('id')
                        ->first();

                    if ($existingBatch) {
                        Storage::disk('local')->delete($path);

                        $message = $existingBatch->estado === 'completado'
                            ? 'Este archivo de catálogo ya fue confirmado y publicado previamente.'
                            : 'Este archivo ya cuenta con un análisis registrado en revisión.';

                        return redirect()->route('gestion.catalogo.importaciones.preview', $existingBatch)
                            ->with('info', $message);
                    }

                    $batch = null;
                    try {
                        $batch = DB::transaction(function () use ($request, $file, $fileHash, $userId, $path): LocImportBatch {
                            $created = LocImportBatch::create([
                                'tipo_fuente' => LocCatalogoXlsImporter::SOURCE_TYPE,
                                'nombre_archivo' => mb_substr($file->getClientOriginalName(), 0, 255),
                                'fecha_procesamiento' => now(),
                                'usuario_id' => $userId,
                                'estado' => 'pendiente',
                                'archivo_sha256' => $fileHash,
                                'total_leidas' => 0,
                                'total_aceptadas' => 0,
                                'total_rechazadas' => 0,
                                'total_actualizadas' => 0,
                            ]);

                            ProcessLocCatalogoImportBatch::dispatch($created->id, $path)
                                ->onConnection(config('loc_import.credentials_queue_connection', 'sync'))
                                ->onQueue(config('loc_import.credentials_queue', 'default'))
                                ->afterCommit();

                            return $created;
                        });

                        return redirect()->route('gestion.catalogo.importaciones.preview', $batch)
                            ->with('success', 'Archivo de catálogo recibido. El análisis se encuentra en proceso.');
                    } catch (Throwable $e) {
                        if ($batch) {
                            $batch->update([
                                'estado' => 'fallido',
                                'resumen_errores' => json_encode(['error' => 'No fue posible iniciar el análisis: ' . $e->getMessage()]),
                            ]);
                        }
                        Storage::disk('local')->delete($path);
                        Log::error('No se pudo registrar el lote de catálogo organizacional.', [
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);

                        throw ValidationException::withMessages([
                            'archivo_catalogo' => 'No fue posible procesar el archivo. Inténtalo nuevamente.',
                        ]);
                    }
                });
        } catch (LockTimeoutException) {
            Storage::disk('local')->delete($path);

            throw ValidationException::withMessages([
                'archivo_catalogo' => 'Otra solicitud con este mismo archivo se está procesando actualmente.',
            ]);
        }
    }

    public function progress(LocImportBatch $batch): JsonResponse
    {
        abort_unless($batch->tipo_fuente === LocCatalogoXlsImporter::SOURCE_TYPE, 404);

        $summary = json_decode((string) $batch->resumen_errores, true) ?: [];

        return response()->json([
            'status' => $batch->estado,
            'rows_read' => $batch->total_leidas,
            'accepted' => $batch->total_aceptadas,
            'rejected' => $batch->total_rechazadas,
            'new' => $batch->total_insertadas,
            'updated' => $batch->total_actualizadas,
            'unchanged' => $batch->total_sin_cambios,
            'error' => $batch->estado === 'fallido' ? ($summary['error'] ?? 'Falló el análisis del archivo.') : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request, LocImportBatch $batch): View
    {
        abort_unless($batch->tipo_fuente === LocCatalogoXlsImporter::SOURCE_TYPE, 404);
        abort_unless(in_array($batch->estado, ['pendiente', 'procesando', 'en_revision', 'completado', 'fallido'], true), 404);

        $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
        $activeVersion = LocCatalogoOrganizacional::versionVigente();

        $actionFilter = $request->input('accion');
        $search = trim((string) $request->input('buscar'));

        $counts = [
            'nuevo' => (int) $batch->total_insertadas,
            'actualizar' => (int) $batch->total_actualizadas,
            'sin_cambios' => (int) $batch->total_sin_cambios,
            'conflicto' => 0,
            'rechazado' => 0,
        ];

        $hasStaging = Schema::hasTable('loc_import_staging_rows');

        if ($hasStaging) {
            $dbCounts = LocImportStagingRow::query()
                ->where('import_batch_id', $batch->id)
                ->selectRaw('accion, COUNT(*) as total')
                ->groupBy('accion')
                ->pluck('total', 'accion')
                ->all();

            foreach ($dbCounts as $acc => $tot) {
                $counts[$acc] = (int) $tot;
            }

            $query = LocImportStagingRow::query()
                ->where('import_batch_id', $batch->id);

            if ($actionFilter && in_array($actionFilter, ['nuevo', 'actualizar', 'sin_cambios', 'conflicto', 'rechazado'], true)) {
                $query->where('accion', $actionFilter);
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search): void {
                    $q->where('clave_origen', 'like', "%{$search}%")
                        ->orWhere('datos', 'like', "%{$search}%");
                });
            }

            $rows = $query->orderBy('numero_fila')->paginate(50)->withQueryString();
        } else {
            $rows = new LengthAwarePaginator([], 0, 50);
        }

        $blockingErrors = ($counts['conflicto'] ?? 0) + ($counts['rechazado'] ?? 0);

        return view('modules.gestion-catalogo-import-preview', [
            'batch' => $batch,
            'active_version' => $activeVersion,
            'rows' => $rows,
            'counts' => $counts,
            'blocking_errors' => $blockingErrors,
            'summary' => $summary,
            'action_filter' => $actionFilter,
            'search' => $search,
        ]);
    }

    public function confirm(Request $request, LocImportBatch $batch, LocCatalogoXlsImporter $importer): RedirectResponse
    {
        abort_unless($batch->tipo_fuente === LocCatalogoXlsImporter::SOURCE_TYPE, 404);
        $user = $request->user();
        abort_unless($user && ($batch->usuario_id === $user->id || $user->role === 'superadmin'), 403);

        try {
            $result = $importer->commit($batch, $user->id);
        } catch (Throwable $exception) {
            Log::error('Falló la confirmación del catálogo organizacional.', [
                'batch_id' => $batch->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'No fue posible publicar la versión del catálogo. Revisa los errores antes de reintentar.';

            return back()->withErrors(['confirmacion_catalogo' => $message]);
        }

        $msg = $result['already_confirmed'] ?? false
            ? "El catálogo de este lote ya se encontraba publicado (Versión v{$result['version']})."
            : "Catálogo organizacional publicado exitosamente como Versión v{$result['version']}: {$result['inserted']} nuevas claves, {$result['updated']} actualizadas y {$result['unchanged']} sin cambios.";

        return redirect()->route('gestion.historial', ['tipo_fuente' => LocCatalogoXlsImporter::SOURCE_TYPE])
            ->with('success', $msg);
    }

    public function current(Request $request): View
    {
        $activeVersion = LocCatalogoOrganizacional::versionVigente();
        $search = trim((string) $request->input('buscar'));

        $query = LocCatalogoOrganizacional::vigente();

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('clave_origen', 'like', "%{$search}%")
                    ->orWhere('agrupamiento', 'like', "%{$search}%")
                    ->orWhere('municipio', 'like', "%{$search}%")
                    ->orWhere('region', 'like', "%{$search}%");
            });
        }

        $records = $query->orderBy('clave_origen')->paginate(50)->withQueryString();

        return view('modules.gestion-catalogo-vigente', [
            'active_version' => $activeVersion,
            'records' => $records,
            'search' => $search,
        ]);
    }

    public function showVersion(Request $request, LocCatalogoVersion $version): View
    {
        $search = trim((string) $request->input('buscar'));

        $query = LocCatalogoOrganizacional::deVersion($version->import_batch_id);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('clave_origen', 'like', "%{$search}%")
                    ->orWhere('agrupamiento', 'like', "%{$search}%")
                    ->orWhere('municipio', 'like', "%{$search}%")
                    ->orWhere('region', 'like', "%{$search}%");
            });
        }

        $records = $query->orderBy('clave_origen')->paginate(50)->withQueryString();

        return view('modules.gestion-catalogo-version', [
            'version' => $version,
            'records' => $records,
            'search' => $search,
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

