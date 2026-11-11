<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LocImportBatch;
use App\Services\LocCatalogoXlsImporter;
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

class ProcessLocCatalogoImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public int $batchId,
        public string $storedPath,
    ) {}

    public function handle(LocCatalogoXlsImporter $importer): void
    {
        Log::info('Inicia procesamiento de catálogo organizacional en cola.', [
            'batch_id' => $this->batchId,
        ]);

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
                throw new \RuntimeException('No se encontró el archivo temporal de catálogo.');
            }

            try {
                $importer->stage($path, LocImportBatch::query()->findOrFail($this->batchId));
            } finally {
                Storage::disk('local')->delete($this->storedPath);
            }
        } catch (Throwable $e) {
            $this->failed($e);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $batch = LocImportBatch::query()->find($this->batchId);
        if ($batch && in_array($batch->estado, ['pendiente', 'procesando'], true)) {
            $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
            $summary['error'] = 'No fue posible completar el análisis del catálogo: ' . $exception->getMessage();
            $batch->update([
                'estado' => 'fallido',
                'resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE),
            ]);

            Log::error('Falló el procesamiento en cola del catálogo organizacional.', [
                'batch_id' => $this->batchId,
                'exception_type' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        Storage::disk('local')->delete($this->storedPath);
    }
}

