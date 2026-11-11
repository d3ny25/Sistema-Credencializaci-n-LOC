<?php
use App\Enums\BiometricoAmbito;
use App\Services\BiometricIndexingService;
use App\Models\BiometricoIndexRun;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

ini_set('memory_limit', '-1');
set_time_limit(0);

$ambito = BiometricoAmbito::ESTATALES;
$path = 'C:/Users/Daniel/Documents/Residencias/Bases de datos/Biometricos_Estatal/ESTATALES';

// 1. Forzar el estado de la corrida previa a 'interrumpida' para que el servicio la acepte como reanudable
$ultimaCorrida = BiometricoIndexRun::where('uuid', '9802dc5d-f8d1-40a9-9003-28f8fb84bbab')->first();

if (!$ultimaCorrida) {
    echo "[ERROR] No se encontró la corrida.\n";
    exit;
}

$ultimaCorrida->estado = 'interrumpida';
$ultimaCorrida->save();

echo "[OK] Corrida preparada para reanudación (UUID: {$ultimaCorrida->uuid})\n";
echo "     Último Checkpoint registrado: " . ($ultimaCorrida->ultima_ruta_procesada ?? 'Ninguno') . "\n\n";

$service = app(BiometricIndexingService::class);
$startTime = microtime(true);

$resultado = $service->indexar(
    ambito: $ambito,
    rootPath: $path,
    chunkSize: 500,
    dryRun: false,
    resume: true,
    limit: null,
    iniciadoPor: null,
    progressCallback: function($stats) {
        echo "[Progreso] Procesados: {$stats['procesados']} | Checkpoint: {$stats['ultimo_archivo']} | RAM: " . round(memory_get_usage() / 1024 / 1024) . " MB\n";
    },
    targetResumeIdOrUuid: $ultimaCorrida->uuid,
    force: true
);

$duration = round(microtime(true) - $startTime, 2);

echo "\n================================================================\n";
echo "  RESULTADO DE LA REANUDACIÓN MASIVA\n";
echo "================================================================\n";
echo "Estado Final              : " . ($resultado->estado?->value ?? 'completado') . "\n";
echo "Archivos Procesados       : " . ($resultado->total_procesados ?? 0) . "\n";
echo "Duración Total            : " . $duration . " segundos\n";
echo "================================================================\n";