<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Services\LocCredentialsXlsImporter;

echo "=================================================================\n";
echo "FASE 3.4.6 - CONFIRMACION REAL AUTORIZADA DEL LOTE #15\n";
echo "LOTE: VIGENCIAS_CREDENCIALES EN MARIADB (loc_credencializacion)\n";
echo "=================================================================\n\n";

$evidence = [
    'fecha_inicio' => now()->toIso8601String(),
    'conexion' => DB::getDefaultConnection(),
    'base_datos' => config('database.connections.' . DB::getDefaultConnection() . '.database'),
    'host' => config('database.connections.' . DB::getDefaultConnection() . '.host') . ':' . config('database.connections.' . DB::getDefaultConnection() . '.port'),
    'precondiciones' => [],
    'pre_confirmacion' => [],
    'resultado_confirm' => null,
    'post_confirmacion' => [],
    'auditoria_post' => [],
    'fecha_finalizacion' => null,
];

// -------------------------------------------------------------
// 1. VERIFICACION ESTRICTA DE PRECONDICIONES
// -------------------------------------------------------------
echo "[1] Verificando precondiciones obligatorias...\n";

// 1.1 Conexión y base de datos
$expectedDb = 'loc_credencializacion';
$expectedHost = '127.0.0.1';
$expectedPort = '3308';
$actualDb = config('database.connections.' . DB::getDefaultConnection() . '.database');
$actualHost = (string) config('database.connections.' . DB::getDefaultConnection() . '.host');
$actualPort = (string) config('database.connections.' . DB::getDefaultConnection() . '.port');

if ($actualDb !== $expectedDb || $actualHost !== $expectedHost || $actualPort !== $expectedPort) {
    throw new \RuntimeException("Precondición 1.1 fallida: Conexión incorrecta. Esperado: {$expectedDb} en {$expectedHost}:{$expectedPort}. Actual: {$actualDb} en {$actualHost}:{$actualPort}.");
}
$evidence['precondiciones']['conexion_correcta'] = true;
echo "    ✓ Conexión a {$actualDb} en {$actualHost}:{$actualPort} verificada.\n";

// 1.2 Lote #15 existe, tipo vigencias_credenciales, estado en_revision
$batch = LocImportBatch::find(15);
if (!$batch) {
    throw new \RuntimeException("Precondición 1.2 fallida: Lote #15 no encontrado.");
}
if ($batch->tipo_fuente !== 'vigencias_credenciales' || $batch->estado !== 'en_revision') {
    throw new \RuntimeException("Precondición 1.2 fallida: Lote #15 no cumple tipo o estado (tipo: {$batch->tipo_fuente}, estado: {$batch->estado}).");
}
$evidence['precondiciones']['lote_15_en_revision'] = true;
echo "    ✓ Lote #15 existe, tipo {$batch->tipo_fuente}, estado {$batch->estado}.\n";

// 1.3 Huella de revisión esperada
$expectedFingerprint = '6867a19fbe2ff2dc2e2b238b1ec0bfd23014b0e93941093af942fa4cbc9c22dd';
$importer = app(LocCredentialsXlsImporter::class);
$currentFingerprint = $importer->reviewFingerprint($batch);

if ($batch->revision_sha256 !== $expectedFingerprint) {
    throw new \RuntimeException("Precondición 1.3 fallida: revision_sha256 del lote ({$batch->revision_sha256}) no coincide con la esperada ({$expectedFingerprint}).");
}
if ($currentFingerprint !== $expectedFingerprint) {
    throw new \RuntimeException("Precondición 1.3 fallida: reviewFingerprint() calculada ({$currentFingerprint}) no coincide con la esperada ({$expectedFingerprint}).");
}
$evidence['precondiciones']['huella_revision_valida'] = true;
echo "    ✓ Huella de revisión validada: {$expectedFingerprint}.\n";

// 1.4 Conteos de staging
$stagingCounts = DB::table('loc_import_staging_rows')
    ->where('import_batch_id', 15)
    ->select('accion', 'estado_aplicacion', DB::raw('count(*) as c'))
    ->groupBy('accion', 'estado_aplicacion')
    ->get()
    ->pluck('c', 'accion')
    ->toArray();

$totalStaging = DB::table('loc_import_staging_rows')->where('import_batch_id', 15)->count();
$pendingStaging = DB::table('loc_import_staging_rows')->where('import_batch_id', 15)->where('estado_aplicacion', 'pendiente')->count();

if ($totalStaging !== 33431) {
    throw new \RuntimeException("Precondición 1.4 fallida: Total staging esperado 33,431, encontrado {$totalStaging}.");
}
if ($pendingStaging !== 33431) {
    throw new \RuntimeException("Precondición 1.4 fallida: Todas las filas de staging deben estar en 'pendiente', encontradas {$pendingStaging}.");
}
if (($stagingCounts['sin_cambios'] ?? 0) !== 32044) {
    throw new \RuntimeException("Precondición 1.4 fallida: 'sin_cambios' esperado 32,044, encontrado " . ($stagingCounts['sin_cambios'] ?? 0));
}
if (($stagingCounts['actualizar'] ?? 0) !== 1147) {
    throw new \RuntimeException("Precondición 1.4 fallida: 'actualizar' esperado 1,147, encontrado " . ($stagingCounts['actualizar'] ?? 0));
}
if (($stagingCounts['nuevo'] ?? 0) !== 239) {
    throw new \RuntimeException("Precondición 1.4 fallida: 'nuevo' esperado 239, encontrado " . ($stagingCounts['nuevo'] ?? 0));
}
if (($stagingCounts['omitido'] ?? 0) !== 1) {
    throw new \RuntimeException("Precondición 1.4 fallida: 'omitido' esperado 1, encontrado " . ($stagingCounts['omitido'] ?? 0));
}
if (($stagingCounts['conflicto'] ?? 0) !== 0 || ($stagingCounts['rechazado'] ?? 0) !== 0) {
    throw new \RuntimeException("Precondición 1.4 fallida: Existen conflictos o rechazados en staging.");
}
$evidence['precondiciones']['staging_counts_validos'] = true;
echo "    ✓ Staging lote #15 validado: 32,044 sin cambios, 1,147 actualizar, 239 nuevo, 1 omitido, 0 conflicto, 0 rechazado (33,431 pendientes).\n";

// 1.5 Cero registros operativos previos del lote #15
$existingOperativeBatch15 = DB::table('loc_vigencias_credenciales')->where('import_batch_id', 15)->count();
if ($existingOperativeBatch15 !== 0) {
    throw new \RuntimeException("Precondición 1.5 fallida: Existen {$existingOperativeBatch15} registros operativos con import_batch_id = 15.");
}
if ($batch->fecha_confirmacion !== null) {
    throw new \RuntimeException("Precondición 1.5 fallida: El lote ya posee fecha_confirmacion ({$batch->fecha_confirmacion}).");
}
$evidence['precondiciones']['sin_confirmacion_previa'] = true;
echo "    ✓ No existen registros operativos del lote #15 en loc_vigencias_credenciales ni fecha de confirmación previa.\n";

// 1.6 Lotes #13 y #14 conservan sus estados
$batch13 = DB::table('loc_import_batches')->where('id', 13)->first();
$batch14 = DB::table('loc_import_batches')->where('id', 14)->first();
if (!$batch13 || $batch13->estado !== 'completado' || $batch13->total_leidas !== 34855) {
    throw new \RuntimeException("Precondición 1.6 fallida: Lote #13 alterado o no encontrado.");
}
if (!$batch14 || $batch14->estado !== 'en_revision' || $batch14->total_leidas !== 55967) {
    throw new \RuntimeException("Precondición 1.6 fallida: Lote #14 alterado o no encontrado.");
}
$evidence['precondiciones']['lotes_13_14_preservados'] = true;
echo "    ✓ Lote #13 (completado, 34,855) y Lote #14 (en_revision, 55,967) confirmados.\n";

// 1.7 Conteos de línea base operativa
$baseVigencias = DB::table('loc_vigencias_credenciales')->count();
$basePersonas = DB::table('loc_personas')->count();
$baseArmas = DB::table('loc_armas')->count();

if ($baseVigencias !== 33200) {
    throw new \RuntimeException("Precondición 1.7 fallida: loc_vigencias_credenciales esperado 33,200, encontrado {$baseVigencias}.");
}
if ($basePersonas !== 34858) {
    throw new \RuntimeException("Precondición 1.7 fallida: loc_personas esperado 34,858, encontrado {$basePersonas}.");
}
if ($baseArmas !== 55773) {
    throw new \RuntimeException("Precondición 1.7 fallida: loc_armas esperado 55,773, encontrado {$baseArmas}.");
}
$evidence['precondiciones']['linea_base_operativa_valida'] = true;
echo "    ✓ Línea base operativa confirmada: 33,200 vigencias, 34,858 personas, 55,773 armas.\n";

// Registrar snapshot Pre-Confirmación
$evidence['pre_confirmacion'] = [
    'conteos' => [
        'loc_vigencias_credenciales' => $baseVigencias,
        'loc_personas' => $basePersonas,
        'loc_armas' => $baseArmas,
    ],
    'lote_15' => (array) $batch->toArray(),
    'lote_13' => (array) $batch13,
    'lote_14' => (array) $batch14,
    'vigencia_18480' => (array) DB::table('loc_vigencias_credenciales')->where('id', 18480)->first(),
    'vigencia_851' => (array) DB::table('loc_vigencias_credenciales')->where('id', 851)->first(),
    'vigencia_19980' => (array) DB::table('loc_vigencias_credenciales')->where('id', 19980)->first(),
    'vigencia_20123' => (array) DB::table('loc_vigencias_credenciales')->where('id', 20123)->first(),
    'persona_2116' => (array) DB::table('loc_personas')->where('id', 2116)->first(),
    'persona_34854' => (array) DB::table('loc_personas')->where('id', 34854)->first(),
];

echo "\n>>> TODAS LAS PRECONDICIONES CUMPLIDAS AL 100%. PROCEDIENDO A CONFIRMACION REAL <<<\n\n";

// -------------------------------------------------------------
// 2. EJECUCION DE LA CONFIRMACION REAL
// -------------------------------------------------------------
echo "[2] Invocando LocCredentialsXlsImporter::confirm() de forma permanente...\n";
$startConfirmTime = microtime(true);

$confirmResult = $importer->confirm($batch);

$confirmDuration = round(microtime(true) - $startConfirmTime, 3);
$evidence['resultado_confirm'] = $confirmResult;
$evidence['resultado_confirm']['duracion_segundos'] = $confirmDuration;

echo "    ✓ confirm() completado en {$confirmDuration} segundos.\n";
echo "      - Insertados:   {$confirmResult['insertados']}\n";
echo "      - Actualizados: {$confirmResult['actualizados']}\n";
echo "      - Sin cambios:  {$confirmResult['sin_cambios']}\n\n";

// -------------------------------------------------------------
// 3. AUDITORIA POSTERIOR OBLIGATORIA EN MARIADB
// -------------------------------------------------------------
echo "[3] Ejecutando auditoría posterior en MariaDB...\n";

// 3.1 Estado final del Lote #15
$postBatch = LocImportBatch::find(15);
$evidence['post_confirmacion']['lote_15'] = (array) $postBatch->toArray();

echo "    - Lote #15 estado: {$postBatch->estado}\n";
echo "    - Lote #15 fecha_confirmacion: {$postBatch->fecha_confirmacion}\n";
echo "    - Lote #15 total_insertadas: {$postBatch->total_insertadas}\n";
echo "    - Lote #15 total_actualizadas: {$postBatch->total_actualizadas}\n";

// 3.2 Filas de staging del Lote #15
$postStagingCounts = DB::table('loc_import_staging_rows')
    ->where('import_batch_id', 15)
    ->select('accion', 'estado_aplicacion', DB::raw('count(*) as c'))
    ->groupBy('accion', 'estado_aplicacion')
    ->get();

$evidence['post_confirmacion']['staging_15'] = $postStagingCounts->toArray();
$stagingMap = [];
foreach ($postStagingCounts as $sc) {
    $stagingMap[$sc->accion . ':' . $sc->estado_aplicacion] = $sc->c;
    echo "    - Staging [{$sc->accion} -> {$sc->estado_aplicacion}]: {$sc->c}\n";
}

$pendingCountPost = DB::table('loc_import_staging_rows')
    ->where('import_batch_id', 15)
    ->where('estado_aplicacion', 'pendiente')
    ->count();
echo "    - Staging pendientes: {$pendingCountPost}\n";

// 3.3 Conteo total de loc_vigencias_credenciales
$postVigencias = DB::table('loc_vigencias_credenciales')->count();
$postPersonas = DB::table('loc_personas')->count();
$postArmas = DB::table('loc_armas')->count();
$newBatch15Records = DB::table('loc_vigencias_credenciales')->where('import_batch_id', 15)->count();

$evidence['post_confirmacion']['conteos'] = [
    'loc_vigencias_credenciales' => $postVigencias,
    'loc_personas' => $postPersonas,
    'loc_armas' => $postArmas,
    'nuevos_en_operativa_lote_15' => $newBatch15Records,
];

echo "    - Conteo final vigencias: {$postVigencias} (delta: +" . ($postVigencias - $baseVigencias) . ")\n";
echo "    - Registros operativos creados por lote #15: {$newBatch15Records}\n";

// 3.4 Fila 3798 (OM2527E05934) y Vigencia ID 18480
$omittedInOperative = DB::table('loc_vigencias_credenciales')
    ->where('import_batch_id', 15)
    ->where('numero_credencial', 'OM2527E05934')
    ->count();

$postVig18480 = DB::table('loc_vigencias_credenciales')->where('id', 18480)->first();
$vig18480Intact = ($postVig18480->updated_at === $evidence['pre_confirmacion']['vigencia_18480']['updated_at']
    && $postVig18480->cuip === $evidence['pre_confirmacion']['vigencia_18480']['cuip']
    && $postVig18480->persona_id === $evidence['pre_confirmacion']['vigencia_18480']['persona_id']
    && $postVig18480->estatus_credencial === $evidence['pre_confirmacion']['vigencia_18480']['estatus_credencial']);

$evidence['auditoria_post']['fila_3798_omitida'] = [
    'insertada_en_operativa_con_lote_15' => $omittedInOperative,
    'vigencia_18480_intacta' => $vig18480Intact,
    'vigencia_18480_cuip' => $postVig18480->cuip,
    'vigencia_18480_persona_id' => $postVig18480->persona_id,
    'vigencia_18480_updated_at' => $postVig18480->updated_at,
];
echo "    - Fila 3798 omitida: en operativa lote 15 = {$omittedInOperative}, vigencia 18480 intacta = " . ($vig18480Intact ? 'SI' : 'NO') . "\n";

// 3.5 Vigencias operativas 851, 19980 y 20123 y su trazabilidad
$postVig851 = DB::table('loc_vigencias_credenciales')->where('id', 851)->first();
$postVig19980 = DB::table('loc_vigencias_credenciales')->where('id', 19980)->first();
$postVig20123 = DB::table('loc_vigencias_credenciales')->where('id', 20123)->first();

$origen851 = json_decode($postVig851->datos_origen ?? '{}', true);
$origen19980 = json_decode($postVig19980->datos_origen ?? '{}', true);
$origen20123 = json_decode($postVig20123->datos_origen ?? '{}', true);

$evidence['auditoria_post']['identidades_conciliadas'] = [
    'fila_1603_vigencia_851' => [
        'id' => $postVig851->id,
        'numero_credencial' => $postVig851->numero_credencial,
        'cuip' => $postVig851->cuip,
        'persona_id' => $postVig851->persona_id,
        'es_referencia_loc' => $postVig851->es_referencia_loc,
        'estatus_credencial' => $postVig851->estatus_credencial,
        'trazabilidad_cuip' => $origen851['_trazabilidad_cuip'] ?? null,
    ],
    'fila_6816_vigencia_19980' => [
        'id' => $postVig19980->id,
        'numero_credencial' => $postVig19980->numero_credencial,
        'cuip' => $postVig19980->cuip,
        'persona_id' => $postVig19980->persona_id,
        'es_referencia_loc' => $postVig19980->es_referencia_loc,
        'estatus_credencial' => $postVig19980->estatus_credencial,
        'motivo_situacion_especial' => $postVig19980->motivo_situacion_especial,
        'trazabilidad_cuip' => $origen19980['_trazabilidad_cuip'] ?? null,
    ],
    'fila_6959_vigencia_20123' => [
        'id' => $postVig20123->id,
        'numero_credencial' => $postVig20123->numero_credencial,
        'cuip' => $postVig20123->cuip,
        'persona_id' => $postVig20123->persona_id,
        'es_referencia_loc' => $postVig20123->es_referencia_loc,
        'estatus_credencial' => $postVig20123->estatus_credencial,
        'motivo_situacion_especial' => $postVig20123->motivo_situacion_especial,
        'trazabilidad_cuip' => $origen20123['_trazabilidad_cuip'] ?? null,
    ],
];

echo "    - Vigencia 851 (OM2527M11905): CUIP={$postVig851->cuip}, persona={$postVig851->persona_id}, estatus={$postVig851->estatus_credencial}\n";
echo "    - Vigencia 19980 (OM2527M04365): CUIP={$postVig19980->cuip}, persona={$postVig19980->persona_id}, estatus={$postVig19980->estatus_credencial}\n";
echo "    - Vigencia 20123 (OM2527M17592): CUIP={$postVig20123->cuip}, persona=" . var_export($postVig20123->persona_id, true) . ", ref_loc={$postVig20123->es_referencia_loc}, estatus={$postVig20123->estatus_credencial}\n";

// 3.6 Integridad referencial
$orphanPersonas = DB::table('loc_vigencias_credenciales as v')
    ->leftJoin('loc_personas as p', 'v.persona_id', '=', 'p.id')
    ->where('v.import_batch_id', 15)
    ->whereNotNull('v.persona_id')
    ->whereNull('p.id')
    ->count();

$orphanArmas = DB::table('loc_vigencias_credenciales as v')
    ->leftJoin('loc_armas as a', 'v.arma_id', '=', 'a.id')
    ->where('v.import_batch_id', 15)
    ->whereNotNull('v.arma_id')
    ->whereNull('a.id')
    ->count();

$evidence['auditoria_post']['integridad_referencial'] = [
    'huerfanos_personas' => $orphanPersonas,
    'huerfanos_armas' => $orphanArmas,
];
echo "    - Integridad referencial nuevos lote 15: huérfanos personas = {$orphanPersonas}, huérfanos armas = {$orphanArmas}\n";

// 3.7 Lotes #13 y #14 intactos
$postBatch13 = (array) DB::table('loc_import_batches')->where('id', 13)->first();
$postBatch14 = (array) DB::table('loc_import_batches')->where('id', 14)->first();
$batch13Intact = ($postBatch13 === $evidence['pre_confirmacion']['lote_13']);
$batch14Intact = ($postBatch14 === $evidence['pre_confirmacion']['lote_14']);

$evidence['auditoria_post']['lotes_preservados'] = [
    'lote_13_intacto' => $batch13Intact,
    'lote_14_intacto' => $batch14Intact,
];
echo "    - Lote 13 intacto: " . ($batch13Intact ? 'SI' : 'NO') . " | Lote 14 intacto: " . ($batch14Intact ? 'SI' : 'NO') . "\n";

// 3.8 Nivel de transacciones activas
$txLevel = DB::transactionLevel();
$evidence['auditoria_post']['nivel_transaccion_final'] = $txLevel;
echo "    - Nivel de transacciones abiertas en conexión: {$txLevel} (debe ser 0)\n";

// 3.9 Personas 2116 y 34854
$postP2116 = (array) DB::table('loc_personas')->where('id', 2116)->first();
$postP34854 = (array) DB::table('loc_personas')->where('id', 34854)->first();
$p2116Intact = ($postP2116 === $evidence['pre_confirmacion']['persona_2116']);
$p34854Intact = ($postP34854 === $evidence['pre_confirmacion']['persona_34854']);
$evidence['auditoria_post']['personas_preservadas'] = [
    'persona_2116_intacta' => $p2116Intact,
    'persona_34854_intacta' => $p34854Intact,
];
echo "    - Personas 2116 y 34854 intactas: " . ($p2116Intact && $p34854Intact ? 'SI' : 'NO') . "\n";

$evidence['fecha_finalizacion'] = now()->toIso8601String();

// -------------------------------------------------------------
// 4. GUARDAR EVIDENCIA JSON
// -------------------------------------------------------------
$evidencePath = __DIR__ . '/scratch/fase_3_4_6_confirmation_evidence.json';
file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n=================================================================\n";
echo "CONFIRMACION REAL FINALIZADA CON EXITO Y PERSISTIDA EN MARIADB\n";
echo "Archivo de evidencia guardado en:\n{$evidencePath}\n";
echo "=================================================================\n";

