<?php

namespace App\Jobs;

use App\Exceptions\LocImportDiagnosticException;
use App\Models\LocImportBatch;
use App\Services\LocPersonalXlsImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProcessLocImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public int $batchId,
        public string $storedPath,
    ) {}

    public function handle(LocPersonalXlsImporter $importer): void
    {
        Log::info('Inicia el worker de importación LOC.', [
            'batch_id' => $this->batchId,
            'memory_limit' => ini_get('memory_limit'),
            'php_version' => PHP_VERSION,
            'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
        ]);
        $active = true;
        $memoryReserve = str_repeat('x', 8 * 1024 * 1024);
        register_shutdown_function(function () use (&$active, &$memoryReserve): void {
            if (! $active) {
                return;
            }

            $lastError = error_get_last();
            if (($lastError['type'] ?? null) !== E_ERROR
                || ! str_contains(strtolower((string) ($lastError['message'] ?? '')), 'memory')) {
                return;
            }

            $memoryReserve = null;
            try {
                $batch = LocImportBatch::query()->find($this->batchId);
                if (! $batch || ! in_array($batch->estado, ['pendiente', 'procesando'], true)) {
                    return;
                }

                $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
                $diagnostic = array_merge($summary['diagnostico'] ?? [], [
                    'incident_id' => (string) Str::uuid(),
                    'stage' => $summary['diagnostico']['etapa_actual'] ?? 'procesamiento_job',
                    'error_code' => 'PHP_MEMORY_LIMIT_EXCEEDED',
                    'exception_type' => 'FatalError',
                    'technical_message' => 'PHP agotó el límite de memoria del worker.',
                    'location' => $summary['diagnostico']['location'] ?? [],
                    'staging_rows_preserved' => (int) DB::table('loc_import_staging_rows')
                        ->where('import_batch_id', $batch->id)
                        ->count(),
                    'memory_limit' => ini_get('memory_limit'),
                    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                ]);
                $batch->update([
                    'estado' => 'fallido',
                    'resumen_errores' => json_encode(array_merge($summary, [
                        'error' => 'El análisis superó el límite de memoria del worker. El lote incompleto no puede confirmarse; revisa la incidencia antes de volver a cargar el archivo.',
                        'tipo_error' => 'FatalError',
                        'diagnostico' => $diagnostic,
                    ]), JSON_UNESCAPED_UNICODE),
                ]);
                Log::critical('El worker de importación LOC agotó la memoria PHP.', [
                    'batch_id' => $batch->id,
                    ...$diagnostic,
                ]);
            } catch (Throwable $exception) {
                error_log('No fue posible persistir el diagnóstico de memoria LOC: '.$exception::class);
            }
        });

        try {
            $shouldProcess = DB::transaction(function (): bool {
                $batch = LocImportBatch::query()->lockForUpdate()->findOrFail($this->batchId);
                if ($batch->estado !== 'pendiente') {
                    return false;
                }

                $batch->update(['estado' => 'procesando']);

                return true;
            });

            if (! $shouldProcess) {
                Storage::disk('local')->delete($this->storedPath);

                return;
            }

            $path = Storage::disk('local')->path($this->storedPath);
            if (! is_file($path)) {
                throw new \RuntimeException('No se encontró el archivo temporal de importación.');
            }

            try {
                $importer->stage($path, LocImportBatch::query()->findOrFail($this->batchId));
            } finally {
                Storage::disk('local')->delete($this->storedPath);
            }
        } finally {
            $active = false;
            $memoryReserve = null;
        }
    }

    public function failed(Throwable $exception): void
    {
        $batch = LocImportBatch::query()->find($this->batchId);
        if (! $batch) {
            Storage::disk('local')->delete($this->storedPath);

            return;
        }

        $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
        if (($summary['diagnostico']['error_code'] ?? null) === 'PHP_MEMORY_LIMIT_EXCEEDED') {
            Storage::disk('local')->delete($this->storedPath);

            return;
        }
        $stagingRowsPreserved = (int) DB::table('loc_import_staging_rows')
            ->where('import_batch_id', $batch->id)
            ->count();
        $incidentId = $exception instanceof LocImportDiagnosticException
            ? $exception->incidentId
            : (string) Str::uuid();
        $memoryLimitExceeded = $this->isMemoryLimitFailure($exception);
        $diagnostic = $exception instanceof LocImportDiagnosticException
            ? [
                'incident_id' => $incidentId,
                'stage' => $exception->stage,
                'error_code' => $exception->errorCode,
                'exception_type' => $exception->causeExceptionType,
                'technical_message' => $exception->technicalMessage,
                'location' => $exception->location,
                'stage_metrics' => $exception->stageMetrics,
                ...$exception->diagnosticDetails,
            ]
            : [
                'incident_id' => $incidentId,
                'stage' => $summary['diagnostico']['etapa_actual'] ?? 'procesamiento_job',
                'error_code' => $exception instanceof TimeoutExceededException
                    ? 'LOC_IMPORT_TIMEOUT'
                    : ($memoryLimitExceeded ? 'PHP_MEMORY_LIMIT_EXCEEDED' : 'LOC_IMPORT_JOB_FAILED'),
                'exception_type' => $exception::class,
                'technical_message' => $exception instanceof TimeoutExceededException
                    ? 'El worker agotó el límite de tiempo del trabajo LOC.'
                    : ($memoryLimitExceeded
                        ? 'PHP agotó el límite de memoria del worker.'
                        : 'La ejecución del trabajo de importación LOC falló fuera de una etapa instrumentada.'),
                'location' => $summary['diagnostico']['location'] ?? [],
                'stage_metrics' => $summary['diagnostico']['stage_metrics'] ?? [],
                'staging_rows_preserved' => $stagingRowsPreserved,
                'memory_limit' => ini_get('memory_limit'),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ];
        $diagnostic = array_merge($diagnostic, [
            'staging_rows_preserved' => $stagingRowsPreserved,
            'memory_limit' => ini_get('memory_limit'),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ]);
        $safeMessage = $exception instanceof LocImportDiagnosticException
            ? $exception->userMessage
            : ($exception instanceof TimeoutExceededException
                ? 'El análisis excedió el tiempo máximo del trabajador de cola. Revisa la etapa reportada y vuelve a intentar sólo después de corregir la causa.'
                : ($memoryLimitExceeded
                    ? 'El análisis superó el límite de memoria del worker. El lote incompleto no puede confirmarse; revisa la incidencia antes de volver a cargar el archivo.'
                    : 'No fue posible analizar el archivo .xls. Consulta la incidencia de soporte.'));

        $batch->update([
            'estado' => 'fallido',
            'resumen_errores' => json_encode(array_merge($summary, [
                'error' => $safeMessage,
                'tipo_error' => class_basename($exception),
                'diagnostico' => array_merge($summary['diagnostico'] ?? [], $diagnostic),
            ]), JSON_UNESCAPED_UNICODE),
        ]);

        Log::error('Falló el análisis asíncrono de una importación LOC.', array_merge([
            'batch_id' => $batch->id,
        ], $diagnostic));

        Storage::disk('local')->delete($this->storedPath);
    }

    private function isMemoryLimitFailure(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'allowed memory size')
            || str_contains($message, 'memory limit exceeded')
            || str_contains($message, 'out of memory');
    }
}
