<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LocImportBatch;
use App\Services\LocCredentialsOleReuseReader;
use App\Services\LocCredentialsXlsImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProcessLocCredentialsImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public int $batchId,
        public string $storedPath,
    ) {}

    public function handle(LocCredentialsXlsImporter $importer): void
    {
        Log::info('Inicia el worker de importación de vigencias LOC.', [
            'batch_id' => $this->batchId,
            'memory_limit' => ini_get('memory_limit'),
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
                $diagnostic = [
                    'incident_id' => (string) Str::uuid(),
                    'stage' => 'procesamiento_job',
                    'error_code' => 'PHP_MEMORY_LIMIT_EXCEEDED',
                    'exception_type' => 'FatalError',
                    'technical_message' => 'PHP agotó el límite de memoria del worker en vigencias.',
                    'staging_rows_preserved' => DB::table('loc_import_staging_rows')
                        ->where('import_batch_id', $batch->id)->count(),
                    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                ];
                $summary['diagnostico'] = array_merge($summary['diagnostico'] ?? [], $diagnostic);
                $summary['error'] = 'El análisis superó el límite de memoria del worker. El lote incompleto no puede confirmarse.';
                $batch->update([
                    'estado' => 'fallido',
                    'resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE),
                ]);
                Log::critical('El worker de vigencias LOC agotó la memoria PHP.', [
                    'batch_id' => $batch->id,
                    ...$diagnostic,
                ]);
            } catch (Throwable $exception) {
                error_log('No fue posible persistir el diagnóstico de memoria de vigencias LOC: '.$exception::class);
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
            LocCredentialsOleReuseReader::clearCache();
        }
    }

    public function failed(Throwable $exception): void
    {
        LocCredentialsOleReuseReader::clearCache();
        $batch = LocImportBatch::query()->find($this->batchId);
        if ($batch && in_array($batch->estado, ['pendiente', 'procesando'], true)) {
            $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
            $diagnostic = [
                'incident_id' => (string) Str::uuid(),
                'stage' => 'procesamiento_job',
                'error_code' => 'LOC_CREDENTIALS_IMPORT_JOB_FAILED',
                'exception_type' => $exception::class,
                'technical_message' => 'El análisis del archivo de vigencias no pudo completarse.',
                'staging_rows_preserved' => DB::table('loc_import_staging_rows')
                    ->where('import_batch_id', $batch->id)->count(),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ];
            $summary['diagnostico'] = array_merge($summary['diagnostico'] ?? [], $diagnostic);
            $summary['error'] = 'No fue posible completar el análisis. El lote incompleto no se puede confirmar.';
            $batch->update([
                'estado' => 'fallido',
                'resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE),
            ]);
            Log::error('Falló el trabajo de importación de vigencias LOC.', [
                'batch_id' => $batch->id,
                'exception_type' => $exception::class,
                ...$diagnostic,
            ]);
        }

        Storage::disk('local')->delete($this->storedPath);
    }
}

