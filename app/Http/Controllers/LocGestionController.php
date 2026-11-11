<?php

namespace App\Http\Controllers;

use App\Exceptions\LocImportDiagnosticException;
use App\Jobs\ProcessLocImportBatch;
use App\Models\LocArma;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\LocPersona;
use App\Models\LocVigenciaCredencial;
use App\Models\LocCatalogoOrganizacional;
use App\Services\LocPersonalXlsImporter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class LocGestionController extends Controller
{
    public function index(): View
    {
        $batches = LocImportBatch::query()->latest('id')->paginate(15);

        return view('modules.gestion', [
            'section' => 'fuentes',
            'batches' => $batches,
            'max_upload_kilobytes' => $this->maxUploadKilobytes(),
            'import_schema_ready' => $this->importSchemaReady(),
            'weapons_import_schema_ready' => $this->weaponsImportSchemaReady(),
            'credentials_import_schema_ready' => $this->credentialsImportSchemaReady(),
            'catalogo_import_schema_ready' => $this->catalogoImportSchemaReady(),
            'catalogo_version_activa' => LocCatalogoOrganizacional::versionVigente(),
            'summary' => [
                'people' => LocPersona::query()->count(),
                'weapons' => LocArma::query()->count(),
                'credentials' => LocVigenciaCredencial::query()->count(),
                'catalog_keys' => Schema::hasTable('loc_catalogo_organizacional') ? LocCatalogoOrganizacional::vigente()->count() : 0,
                'last_successful_import' => LocImportBatch::query()
                    ->where('estado', 'completado')
                    ->latest('fecha_procesamiento')
                    ->value('fecha_procesamiento'),
            ],
        ]);
    }

    private function credentialsImportSchemaReady(): bool
    {
        return Schema::hasTable('loc_vigencias_credenciales')
            && Schema::hasColumns('loc_vigencias_credenciales', [
                'arma_corta_id', 'matricula_corta', 'arma_larga_id', 'matricula_larga', 'periodo_credencial', 'fila_hash',
            ]);
    }

    private function catalogoImportSchemaReady(): bool
    {
        return Schema::hasTable('loc_catalogo_organizacional')
            && Schema::hasTable('loc_catalogo_versiones')
            && Schema::hasTable('loc_import_staging_rows');
    }

    private function weaponsImportSchemaReady(): bool
    {
        if (! Schema::hasColumns('loc_import_staging_rows', ['matricula', 'id_armamento'])
            || ! Schema::hasColumns('loc_armas', ['matricula', 'id_armamento', 'datos_origen'])) {
            return false;
        }

        return collect(Schema::getIndexes('loc_armas'))->contains(
            static fn (array $index): bool => $index['name'] === 'loc_armas_matricula_unique'
                && $index['unique']
                && $index['columns'] === ['matricula']
        );
    }

    public function history(Request $request): View
    {
        $filters = $request->validate([
            'estado' => ['nullable', 'in:pendiente,procesando,en_revision,completado,fallido,fallido_parcial'],
            'tipo_fuente' => ['nullable', 'string', 'max:40'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);
        $query = LocImportBatch::query()->with('usuario')->latest('id');
        $query->when($filters['estado'] ?? null, fn ($builder, $value) => $builder->where('estado', $value));
        $query->when($filters['tipo_fuente'] ?? null, fn ($builder, $value) => $builder->where('tipo_fuente', $value));
        $query->when($filters['desde'] ?? null, fn ($builder, $value) => $builder->whereDate('fecha_procesamiento', '>=', $value));
        $query->when($filters['hasta'] ?? null, fn ($builder, $value) => $builder->whereDate('fecha_procesamiento', '<=', $value));
        $batches = $query->paginate(20)->withQueryString();

        return view('modules.gestion', [
            'section' => 'historial',
            'batches' => $batches,
            'filters' => $filters,
            'sources' => LocImportBatch::query()->distinct()->orderBy('tipo_fuente')->pluck('tipo_fuente'),
            'summary' => [
                'total' => LocImportBatch::query()->count(),
                'last_processed_at' => LocImportBatch::query()->max('fecha_procesamiento'),
                'rejected' => LocImportBatch::query()->sum('total_rechazadas'),
            ],
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        if (! $this->importSchemaReady()) {
            throw ValidationException::withMessages([
                'archivo_loc' => 'La importación aún no está habilitada en esta base: faltan las migraciones LOC 2026_10_21_000002 y 2026_10_21_000003. No se creó un lote ni se modificó el padrón.',
            ]);
        }

        $maxUploadKilobytes = $this->maxUploadKilobytes();
        $request->validate([
            'archivo_loc' => [
                'required',
                'file',
                'mimes:xls',
                'extensions:xls',
                'max:'.$maxUploadKilobytes,
            ],
        ], [
            'archivo_loc.mimes' => 'Selecciona un archivo Excel antiguo con extensión .xls.',
            'archivo_loc.extensions' => 'El nombre del archivo debe terminar en .xls.',
            'archivo_loc.max' => 'El archivo supera el límite efectivo configurado para PHP y la aplicación ('.number_format($maxUploadKilobytes / 1024, 1).' MB).',
        ]);

        $file = $request->file('archivo_loc');
        try {
            $path = $file->store('loc-imports', 'local');
        } catch (Throwable $e) {
            Log::error('No se pudo almacenar temporalmente un archivo de importación LOC.', [
                'exception' => class_basename($e),
            ]);

            throw ValidationException::withMessages([
                'archivo_loc' => 'No fue posible guardar el archivo de forma temporal. Revisa el espacio disponible e inténtalo de nuevo.',
            ]);
        }
        if ($path === false) {
            throw ValidationException::withMessages([
                'archivo_loc' => 'No fue posible guardar el archivo de forma temporal. Revisa el espacio disponible e inténtalo de nuevo.',
            ]);
        }

        $storedFile = Storage::disk('local')->path($path);
        $fileHash = hash_file('sha256', $storedFile);
        if ($fileHash === false) {
            Storage::disk('local')->delete($path);

            throw ValidationException::withMessages([
                'archivo_loc' => 'No fue posible verificar el archivo recibido. Vuelve a seleccionarlo e inténtalo de nuevo.',
            ]);
        }

        try {
            return Cache::lock('loc-import-upload:'.$request->user()->id.':'.$fileHash, 30)
                ->block(5, function () use ($request, $file, $path, $fileHash): RedirectResponse {
                    $existingBatch = LocImportBatch::query()
                        ->where('usuario_id', $request->user()->id)
                        ->where('archivo_sha256', $fileHash)
                        ->where('tipo_fuente', 'personal_autorizado_loc')
                        ->whereIn('estado', ['pendiente', 'procesando', 'en_revision', 'completado', 'fallido_parcial'])
                        ->latest('id')
                        ->first();

                    if ($existingBatch) {
                        Storage::disk('local')->delete($path);

                        $message = $existingBatch->estado === 'completado'
                            ? 'Este archivo ya fue confirmado; se abrió el lote existente para consultar el resultado.'
                            : 'Este archivo ya tiene un lote registrado; se abrió el análisis existente.';

                        return redirect()->route('gestion.importaciones.preview', $existingBatch)
                            ->with('success', $message);
                    }

                    $batch = null;
                    try {
                        $batch = LocImportBatch::create([
                            'tipo_fuente' => 'personal_autorizado_loc',
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

                        ProcessLocImportBatch::dispatch($batch->id, $path)
                            ->onConnection(config('loc_import.queue_connection'))
                            ->onQueue(config('loc_import.queue'));

                        return redirect()->route('gestion.importaciones.preview', $batch)
                            ->with('success', 'Archivo recibido. El análisis está en cola; la vista previa aparecerá cuando el servidor termine de procesarlo.');
                    } catch (Throwable $e) {
                        $diagnostic = $e instanceof LocImportDiagnosticException
                            ? [
                                'incident_id' => $e->incidentId,
                                'stage' => $e->stage,
                                'error_code' => $e->errorCode,
                                'exception_type' => $e->causeExceptionType,
                                'technical_message' => $e->technicalMessage,
                                'location' => $e->location,
                                'stage_metrics' => $e->stageMetrics,
                                ...$e->diagnosticDetails,
                            ]
                            : [
                                'incident_id' => (string) Str::uuid(),
                                'stage' => 'recepcion_despacho',
                                'error_code' => 'LOC_IMPORT_DISPATCH_FAILED',
                                'exception_type' => $e::class,
                                'technical_message' => 'No fue posible registrar el trabajo en la cola de importación.',
                                'location' => [],
                                'stage_metrics' => [],
                            ];
                        $safeMessage = $e instanceof LocImportDiagnosticException
                            ? $e->userMessage
                            : $this->safeImportError($e);
                        Log::error('No se pudo iniciar el análisis de una importación LOC.', [
                            'batch_id' => $batch?->id,
                            ...$diagnostic,
                        ]);

                        if ($batch) {
                            $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
                            $batch->update([
                                'estado' => 'fallido',
                                'resumen_errores' => json_encode(array_merge($summary, [
                                    'error' => $safeMessage,
                                    'tipo_error' => class_basename($e),
                                    'diagnostico' => array_merge($summary['diagnostico'] ?? [], $diagnostic),
                                ]), JSON_UNESCAPED_UNICODE),
                            ]);
                        }

                        Storage::disk('local')->delete($path);

                        throw ValidationException::withMessages([
                            'archivo_loc' => $batch
                                ? $safeMessage.' (Incidencia '.$diagnostic['incident_id'].').'
                                : 'No fue posible iniciar el análisis. Revisa el almacenamiento y la cola de trabajos, e inténtalo de nuevo.',
                        ]);
                    }
                });
        } catch (LockTimeoutException) {
            Storage::disk('local')->delete($path);

            throw ValidationException::withMessages([
                'archivo_loc' => 'Otra solicitud del mismo archivo todavía se está registrando. Espera unos segundos y consulta el historial antes de volver a intentarlo.',
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }

    public function preview(Request $request, LocImportBatch $batch): View|JsonResponse
    {
        abort_unless($batch->tipo_fuente === 'personal_autorizado_loc', 404);
        abort_unless(in_array($batch->estado, ['pendiente', 'en_revision', 'completado', 'fallido', 'fallido_parcial', 'procesando'], true), 404);

        $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
        $hasStagingRows = ! in_array($batch->estado, ['pendiente', 'procesando'], true)
            && Schema::hasTable('loc_import_staging_rows');
        $onlyErrors = $request->boolean('solo_errores');
        $actionFilter = $request->input('accion');
        $search = trim((string) $request->input('buscar'));

        if ($hasStagingRows) {
            $counts = LocImportStagingRow::where('import_batch_id', $batch->id)
                ->selectRaw('accion, COUNT(*) as total')
                ->groupBy('accion')->pluck('total', 'accion');
            $rowsQuery = LocImportStagingRow::where('import_batch_id', $batch->id);

            if ($onlyErrors || $actionFilter === 'requieren_correccion' || $actionFilter === 'errores') {
                $rowsQuery->whereIn('accion', ['rechazado', 'conflicto']);
            } elseif ($actionFilter && in_array($actionFilter, ['nuevo', 'actualizar', 'sin_cambios', 'conflicto', 'rechazado', 'omitido'], true)) {
                $rowsQuery->where('accion', $actionFilter);
            }

            if ($search !== '') {
                $rowsQuery->where(function ($q) use ($search): void {
                    $q->where('cuip', 'like', "%{$search}%")
                        ->orWhere('datos', 'like', "%{$search}%");
                });
            }

            $rows = $rowsQuery->orderBy('numero_fila')->paginate(25)->withQueryString();
            $exceptionRows = LocImportStagingRow::where('import_batch_id', $batch->id)
                ->whereIn('accion', ['rechazado', 'conflicto'])
                ->orderBy('numero_fila')
                ->paginate(25, ['*'], 'errores_page')
                ->withQueryString();
            $applicationCounts = LocImportStagingRow::where('import_batch_id', $batch->id)
                ->selectRaw('estado_aplicacion, COUNT(*) as total')
                ->groupBy('estado_aplicacion')->pluck('total', 'estado_aplicacion');
            $missingCuipCount = LocImportStagingRow::where('import_batch_id', $batch->id)->whereNull('cuip')->count();
        } else {
            $counts = collect();
            $rows = new LengthAwarePaginator([], 0, 25);
            $exceptionRows = new LengthAwarePaginator([], 0, 25, 1, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'errores_page',
            ]);
            $applicationCounts = collect();
            $missingCuipCount = 0;
        }

        $blockingErrors = (int) (($counts['conflicto'] ?? 0) + ($counts['rechazado'] ?? 0));

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'table_html' => view('modules.partials.gestion-personal-table', compact(
                    'batch', 'rows', 'counts', 'actionFilter', 'search', 'onlyErrors', 'blockingErrors'
                ))->render(),
                'chips_html' => view('modules.partials.gestion-personal-chips', compact(
                    'batch', 'counts', 'actionFilter', 'onlyErrors', 'blockingErrors'
                ))->render(),
                'total_coincidentes' => $rows->total(),
                'blocking_errors' => $blockingErrors,
            ]);
        }

        return view('modules.gestion-import-preview', compact(
            'batch',
            'counts',
            'rows',
            'exceptionRows',
            'onlyErrors',
            'actionFilter',
            'search',
            'blockingErrors',
            'applicationCounts',
            'missingCuipCount',
            'summary'
        ));
    }

    public function omitRow(Request $request, LocImportBatch $batch, LocImportStagingRow $row, LocPersonalXlsImporter $importer): RedirectResponse
    {
        abort_unless($batch->tipo_fuente === 'personal_autorizado_loc', 404);
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

    public function progress(LocImportBatch $batch): JsonResponse
    {
        abort_unless($batch->tipo_fuente === 'personal_autorizado_loc', 404);
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

    public function confirm(Request $request, LocImportBatch $batch, LocPersonalXlsImporter $importer): RedirectResponse
    {
        abort_unless($batch->tipo_fuente === 'personal_autorizado_loc', 404);
        $user = $request->user();
        abort_unless($user && ($batch->usuario_id === $user->id || $user->role === 'superadmin'), 403);

        try {
            $result = $importer->commit($batch, $user->id);
        } catch (Throwable $e) {
            Log::error('Falló la confirmación de una importación LOC.', [
                'batch_id' => $batch->id,
                'exception' => class_basename($e),
            ]);

            $message = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'No fue posible completar la importación. Revisa el estado del lote antes de reanudarlo.';

            return back()->withErrors(['importacion' => $message]);
        }

        return redirect()->route('gestion.historial')
            ->with('success', "Importación confirmada: {$result['inserted']} insertadas, {$result['updated']} actualizadas, {$result['unchanged']} sin cambios y {$result['rejected']} rechazadas o en conflicto.");
    }

    private function maxUploadKilobytes(): int
    {
        $limits = [max(1, (int) config('loc_import.max_file_kilobytes', 102400))];

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

    private function safeImportError(Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (str_starts_with($message, 'Faltan encabezados obligatorios:')
            || str_starts_with($message, 'El encabezado obligatorio ')) {
            return $message;
        }

        if (str_contains($message, 'no corresponde a un libro .xls válido')) {
            return 'El contenido no es un archivo .xls legible. Verifica el formato y vuelve a cargarlo.';
        }

        return 'No fue posible analizar el archivo .xls. Verifica que sea un libro legible con los encabezados CUIP y NOMBRE.';
    }

    private function importSchemaReady(): bool
    {
        return Schema::hasTable('jobs')
            && Schema::hasTable('loc_import_batches')
            && Schema::hasTable('loc_import_staging_rows')
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
            && Schema::hasColumns('loc_import_staging_rows', [
                'import_batch_id',
                'numero_fila',
                'datos',
                'accion',
                'estado_aplicacion',
            ])
            && Schema::hasColumn('loc_personas', 'datos_loc');
    }
}
