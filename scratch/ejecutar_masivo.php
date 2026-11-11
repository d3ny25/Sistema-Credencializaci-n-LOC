<?php
use Illuminate\Contracts\Console\Kernel;
use App\Enums\BiometricoAmbito;
use App\Services\BiometricIndexingService;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

echo "================================================================\n";
echo "  EJECUTOR MASIVO DIRECTO — SISTEMA ESTATAL LOC 139\n";
echo "================================================================\n";

// 1. Limpieza preventiva de leases y corridas colgadas
DB::statement('SET FOREIGN_KEY_CHECKS=0;');
App\Models\BiometricoIndexLease::truncate();
App\Models\BiometricoIndexRun::query()->update(['estado' => 'interrumpida']);
DB::statement('SET FOREIGN_KEY_CHECKS=1;');
echo "[OK] Limpieza de bloqueos previa completada.\n";

// 2. Configurar entorno para procesamiento masivo sin límites
ini_set('memory_limit', '-1');
set_time_limit(0);

$ambito = BiometricoAmbito::ESTATALES;
$path = 'C:/Users/Daniel/Documents/Residencias/Bases de datos/Biometricos_Estatal/ESTATALES/FOTOS';

echo "Iniciando indexación para ámbito: " . $ambito->value . "\n";
echo "Ruta objetivo: " . $path . "\n\n";

$service = app(BiometricIndexingService::class);
$startTime = microtime(true);

// Orden correcto: ($ambito, $rootPath, $chunkSize, $dryRun, $force)
$resultado = $service->indexar($ambito, $path, 500, false, true);

$duration = round(microtime(true) - $startTime, 2);

echo "\n================================================================\n";
echo "  RESULTADO DE LA INDEXACIÓN MASIVA\n";
echo "================================================================\n";
echo "Estado Final              : " . ($resultado->estado?->value ?? 'completado') . "\n";
echo "Archivos Detectados       : " . ($resultado->total_detectados ?? 0) . "\n";
echo "Archivos Procesados       : " . ($resultado->total_procesados ?? 0) . "\n";
echo "Archivos Nuevos           : " . ($resultado->nuevos ?? 0) . "\n";
echo "Archivos Inválidos        : " . ($resultado->invalidos ?? 0) . "\n";
echo "Duración Total            : " . $duration . " segundos\n";
echo "================================================================\n";