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
echo "FASE 3.4.5 - PRUEBA DE VIABILIDAD (DRY-RUN TRANSACCIONAL)\n";
echo "LOTE #15: VIGENCIAS_CREDENCIALES EN MARIADB (loc_credencializacion)\n";
echo "=================================================================\n\n";

$evidence = [
    'fecha_ejecucion' => now()->toIso8601String(),
    'conexion' => DB::getDefaultConnection(),
    'base_datos' => config('database.connections.' . DB::getDefaultConnection() . '.database'),
    'host' => config('database.connections.' . DB::getDefaultConnection() . '.host') . ':' . config('database.connections.' . DB::getDefaultConnection() . '.port'),
    'motores' => [],
    'pre_trial' => [],
    'durante_transaccion' => [],
    'post_rollback' => [],
    'comparacion_invariantes' => [],
    'resultado_final' => null,
];

// 1. VERIFICAR MOTORES Y CONEXION
$tables = ['loc_import_batches', 'loc_import_staging_rows', 'loc_vigencias_credenciales', 'loc_personas', 'loc_armas'];
foreach ($tables as $t) {
    $res = DB::select("SHOW TABLE STATUS WHERE Name = ?", [$t]);
    $engine = $res[0]->Engine ?? 'UNKNOWN';
    $evidence['motores'][$t] = $engine;
    if ($engine !== 'InnoDB') {
        throw new \RuntimeException("La tabla {$t} no utiliza InnoDB (motor detectado: {$engine}). Ensayo abortado.");
    }
}
echo "[1] Motores verificados: Todas las tablas utilizan InnoDB.\n";

// 2. SNAPSHOT PRE-TRIAL
$evidence['pre_trial']['conteos'] = [
    'loc_vigencias_credenciales' => DB::table('loc_vigencias_credenciales')->count(),
    'loc_personas' => DB::table('loc_personas')->count(),
    'loc_armas' => DB::table('loc_armas')->count(),
];

$batch15Pre = DB::table('loc_import_batches')->where('id', 15)->first();
$evidence['pre_trial']['lote_15'] = (array) $batch15Pre;

$evidence['pre_trial']['lote_13'] = (array) DB::table('loc_import_batches')->where('id', 13)->first();
$evidence['pre_trial']['lote_14'] = (array) DB::table('loc_import_batches')->where('id', 14)->first();

$evidence['pre_trial']['staging_15_resumen'] = DB::table('loc_import_staging_rows')
    ->where('import_batch_id', 15)
    ->select('accion', 'estado_aplicacion', DB::raw('count(*) as c'))
    ->groupBy('accion', 'estado_aplicacion')
    ->get()
    ->toArray();

$evidence['pre_trial']['vigencia_18480'] = (array) DB::table('loc_vigencias_credenciales')->where('id', 18480)->first();
$evidence['pre_trial']['vigencia_851'] = (array) DB::table('loc_vigencias_credenciales')->where('id', 851)->first();
$evidence['pre_trial']['vigencia_19980'] = (array) DB::table('loc_vigencias_credenciales')->where('id', 19980)->first();
$evidence['pre_trial']['vigencia_20123'] = (array) DB::table('loc_vigencias_credenciales')->where('id', 20123)->first();

$evidence['pre_trial']['persona_2116'] = (array) DB::table('loc_personas')->where('id', 2116)->first();
$evidence['pre_trial']['persona_34854'] = (array) DB::table('loc_personas')->where('id', 34854)->first();

echo "[2] Snapshot Pre-Trial registrado exitosamente:\n";
echo "    - Vigencias: {$evidence['pre_trial']['conteos']['loc_vigencias_credenciales']}\n";
echo "    - Personas:  {$evidence['pre_trial']['conteos']['loc_personas']}\n";
echo "    - Armas:     {$evidence['pre_trial']['conteos']['loc_armas']}\n";
echo "    - Lote #15 estado: {$batch15Pre->estado}, revision_sha256: {$batch15Pre->revision_sha256}\n";

// 3. VERIFICAR HUELLA DE REVISION ANTES DE INICIAR
$importer = app(LocCredentialsXlsImporter::class);
$batchModel = LocImportBatch::findOrFail(15);
$fingerprintPre = $importer->reviewFingerprint($batchModel);
if ($fingerprintPre !== $batch15Pre->revision_sha256) {
    throw new \RuntimeException("La huella de revisión calculada ({$fingerprintPre}) no coincide con la registrada ({$batch15Pre->revision_sha256}).");
}
echo "[3] Huella de revisión verificada con éxito: {$fingerprintPre}\n";

// 4. EJECUCION CONTROLADA DENTRO DE TRANSACCION CON ROLLBACK GARANTIZADO
echo "[4] Iniciando ensayo transaccional con DB::beginTransaction()...\n";

$executionError = null;
DB::beginTransaction();

try {
    echo "    Nivel de transacción inicial: " . DB::transactionLevel() . "\n";
    
    // Invocación del método oficial confirm()
    $confirmResult = $importer->confirm($batchModel);
    echo "    confirm() ejecutado exitosamente:\n";
    echo "      Insertados:   {$confirmResult['insertados']}\n";
    echo "      Actualizados: {$confirmResult['actualizados']}\n";
    echo "      Sin cambios:  {$confirmResult['sin_cambios']}\n";

    $evidence['durante_transaccion']['confirm_result'] = $confirmResult;

    // A. Conteos operativos durante la transacción
    $duringVigencias = DB::table('loc_vigencias_credenciales')->count();
    $duringPersonas = DB::table('loc_personas')->count();
    $duringArmas = DB::table('loc_armas')->count();
    $duringNuevosBatch15 = DB::table('loc_vigencias_credenciales')->where('import_batch_id', 15)->count();

    $evidence['durante_transaccion']['conteos'] = [
        'loc_vigencias_credenciales' => $duringVigencias,
        'loc_personas' => $duringPersonas,
        'loc_armas' => $duringArmas,
        'nuevos_en_operativa_lote_15' => $duringNuevosBatch15,
    ];
    echo "    Conteos en MariaDB durante transacción:\n";
    echo "      Total vigencias: {$duringVigencias} (delta = " . ($duringVigencias - $evidence['pre_trial']['conteos']['loc_vigencias_credenciales']) . ")\n";
    echo "      Nuevas creadas lote 15: {$duringNuevosBatch15}\n";

    // B. Estados de aplicación en staging
    $duringStaging = DB::table('loc_import_staging_rows')
        ->where('import_batch_id', 15)
        ->select('accion', 'estado_aplicacion', DB::raw('count(*) as c'))
        ->groupBy('accion', 'estado_aplicacion')
        ->get();
    $evidence['durante_transaccion']['staging_resumen'] = $duringStaging->toArray();

    // C. Estado del lote durante la transacción
    $duringBatch15 = DB::table('loc_import_batches')->where('id', 15)->first();
    $evidence['durante_transaccion']['lote_15'] = (array) $duringBatch15;

    // D. Verificación del registro omitido (Fila 3798, OM2527E05934)
    $omittedInBatch15 = DB::table('loc_vigencias_credenciales')
        ->where('import_batch_id', 15)
        ->where('numero_credencial', 'OM2527E05934')
        ->count();
    $vig18480During = DB::table('loc_vigencias_credenciales')->where('id', 18480)->first();
    $evidence['durante_transaccion']['fila_3798_omitida'] = [
        'en_operativa_con_lote_15' => $omittedInBatch15,
        'vigencia_18480_cuip' => $vig18480During->cuip,
        'vigencia_18480_persona_id' => $vig18480During->persona_id,
        'vigencia_18480_updated_at' => $vig18480During->updated_at,
        'vigencia_18480_intacta' => ($vig18480During->updated_at === $evidence['pre_trial']['vigencia_18480']['updated_at']
            && $vig18480During->cuip === $evidence['pre_trial']['vigencia_18480']['cuip']
            && $vig18480During->persona_id === $evidence['pre_trial']['vigencia_18480']['persona_id']),
    ];

    // E. Verificación de identidades conciliadas
    // Caso 1: Fila 1603 -> Vigencia ID 851
    $vig851During = DB::table('loc_vigencias_credenciales')->where('id', 851)->first();
    $vig851Origen = json_decode($vig851During->datos_origen ?? '{}', true);

    // Caso 3: Fila 6816 -> Vigencia ID 19980
    $vig19980During = DB::table('loc_vigencias_credenciales')->where('id', 19980)->first();
    $vig19980Origen = json_decode($vig19980During->datos_origen ?? '{}', true);

    // Caso 4: Fila 6959 -> Vigencia ID 20123
    $vig20123During = DB::table('loc_vigencias_credenciales')->where('id', 20123)->first();
    $vig20123Origen = json_decode($vig20123During->datos_origen ?? '{}', true);

    $evidence['durante_transaccion']['identidades_conciliadas'] = [
        'caso_1_fila_1603' => [
            'vigencia_id' => $vig851During->id,
            'credencial' => $vig851During->numero_credencial,
            'cuip_nuevo' => $vig851During->cuip,
            'persona_id' => $vig851During->persona_id,
            'es_ref_loc' => $vig851During->es_referencia_loc,
            'estatus' => $vig851During->estatus_credencial,
            'trazabilidad_cuip' => $vig851Origen['_trazabilidad_cuip'] ?? null,
        ],
        'caso_3_fila_6816' => [
            'vigencia_id' => $vig19980During->id,
            'credencial' => $vig19980During->numero_credencial,
            'cuip_nuevo' => $vig19980During->cuip,
            'persona_id' => $vig19980During->persona_id,
            'es_ref_loc' => $vig19980During->es_referencia_loc,
            'estatus' => $vig19980During->estatus_credencial,
            'motivo_especial' => $vig19980During->motivo_situacion_especial,
            'trazabilidad_cuip' => $vig19980Origen['_trazabilidad_cuip'] ?? null,
        ],
        'caso_4_fila_6959' => [
            'vigencia_id' => $vig20123During->id,
            'credencial' => $vig20123During->numero_credencial,
            'cuip_nuevo' => $vig20123During->cuip,
            'persona_id' => $vig20123During->persona_id,
            'es_ref_loc' => $vig20123During->es_referencia_loc,
            'estatus' => $vig20123During->estatus_credencial,
            'motivo_especial' => $vig20123During->motivo_situacion_especial,
            'trazabilidad_cuip' => $vig20123Origen['_trazabilidad_cuip'] ?? null,
        ],
    ];

    // F. Verificación de Foreign Keys / Integridad referencial de los 239 registros insertados
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

    $orphanArmasCortas = DB::table('loc_vigencias_credenciales as v')
        ->leftJoin('loc_armas as a', 'v.arma_corta_id', '=', 'a.id')
        ->where('v.import_batch_id', 15)
        ->whereNotNull('v.arma_corta_id')
        ->whereNull('a.id')
        ->count();

    $orphanArmasLargas = DB::table('loc_vigencias_credenciales as v')
        ->leftJoin('loc_armas as a', 'v.arma_larga_id', '=', 'a.id')
        ->where('v.import_batch_id', 15)
        ->whereNotNull('v.arma_larga_id')
        ->whereNull('a.id')
        ->count();

    $evidence['durante_transaccion']['integridad_referencial'] = [
        'huerfanos_personas' => $orphanPersonas,
        'huerfanos_armas' => $orphanArmas,
        'huerfanos_armas_cortas' => $orphanArmasCortas,
        'huerfanos_armas_largas' => $orphanArmasLargas,
    ];

    // G. Verificación de desglose por estatus de los 239 nuevos
    $estatusNuevos = DB::table('loc_vigencias_credenciales')
        ->where('import_batch_id', 15)
        ->select('estatus_credencial', DB::raw('count(*) as c'))
        ->groupBy('estatus_credencial')
        ->pluck('c', 'estatus_credencial')
        ->toArray();
    $evidence['durante_transaccion']['estatus_nuevos'] = $estatusNuevos;

    // H. Verificación de lotes 13 y 14 intactos
    $batch13During = (array) DB::table('loc_import_batches')->where('id', 13)->first();
    $batch14During = (array) DB::table('loc_import_batches')->where('id', 14)->first();
    $evidence['durante_transaccion']['lote_13_intacto'] = ($batch13During === $evidence['pre_trial']['lote_13']);
    $evidence['durante_transaccion']['lote_14_intacto'] = ($batch14During === $evidence['pre_trial']['lote_14']);

} catch (\Throwable $e) {
    $executionError = [
        'mensaje' => $e->getMessage(),
        'archivo' => $e->getFile(),
        'linea' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ];
    $evidence['durante_transaccion']['error'] = $executionError;
    echo "    [ERROR EN TRANSACCION]: " . $e->getMessage() . "\n";
} finally {
    // 5. ROLLBACK FORZADO INCONDICIONAL
    echo "[5] Ejecutando ROLLBACK explícito incondicional...\n";
    DB::rollBack();
    $evidence['post_rollback']['nivel_transaccion_final'] = DB::transactionLevel();
    echo "    Nivel de transacción posterior: {$evidence['post_rollback']['nivel_transaccion_final']}\n";
}

// 6. VERIFICACION POST-ROLLBACK
$evidence['post_rollback']['conteos'] = [
    'loc_vigencias_credenciales' => DB::table('loc_vigencias_credenciales')->count(),
    'loc_personas' => DB::table('loc_personas')->count(),
    'loc_armas' => DB::table('loc_armas')->count(),
    'nuevos_en_operativa_lote_15' => DB::table('loc_vigencias_credenciales')->where('import_batch_id', 15)->count(),
];

$batch15Post = DB::table('loc_import_batches')->where('id', 15)->first();
$evidence['post_rollback']['lote_15'] = (array) $batch15Post;
$evidence['post_rollback']['lote_13'] = (array) DB::table('loc_import_batches')->where('id', 13)->first();
$evidence['post_rollback']['lote_14'] = (array) DB::table('loc_import_batches')->where('id', 14)->first();

$evidence['post_rollback']['staging_15_resumen'] = DB::table('loc_import_staging_rows')
    ->where('import_batch_id', 15)
    ->select('accion', 'estado_aplicacion', DB::raw('count(*) as c'))
    ->groupBy('accion', 'estado_aplicacion')
    ->get()
    ->toArray();

$evidence['post_rollback']['vigencia_18480'] = (array) DB::table('loc_vigencias_credenciales')->where('id', 18480)->first();
$evidence['post_rollback']['vigencia_851'] = (array) DB::table('loc_vigencias_credenciales')->where('id', 851)->first();
$evidence['post_rollback']['vigencia_19980'] = (array) DB::table('loc_vigencias_credenciales')->where('id', 19980)->first();
$evidence['post_rollback']['vigencia_20123'] = (array) DB::table('loc_vigencias_credenciales')->where('id', 20123)->first();

$evidence['post_rollback']['persona_2116'] = (array) DB::table('loc_personas')->where('id', 2116)->first();
$evidence['post_rollback']['persona_34854'] = (array) DB::table('loc_personas')->where('id', 34854)->first();

// 7. COMPARACION DE INVARIANTES ANTES vs DESPUES
$invariants = [
    'conteos_vigencias_identicos' => ($evidence['post_rollback']['conteos']['loc_vigencias_credenciales'] === $evidence['pre_trial']['conteos']['loc_vigencias_credenciales']),
    'conteos_personas_identicos' => ($evidence['post_rollback']['conteos']['loc_personas'] === $evidence['pre_trial']['conteos']['loc_personas']),
    'conteos_armas_identicos' => ($evidence['post_rollback']['conteos']['loc_armas'] === $evidence['pre_trial']['conteos']['loc_armas']),
    'cero_registros_lote_15_en_operativa' => ($evidence['post_rollback']['conteos']['nuevos_en_operativa_lote_15'] === 0),
    'lote_15_estado_en_revision' => ($evidence['post_rollback']['lote_15']['estado'] === 'en_revision'),
    'lote_15_revision_sha256_identica' => ($evidence['post_rollback']['lote_15']['revision_sha256'] === $evidence['pre_trial']['lote_15']['revision_sha256']),
    'lote_15_fecha_confirmacion_null' => ($evidence['post_rollback']['lote_15']['fecha_confirmacion'] === null),
    'staging_15_todos_pendientes' => (count($evidence['post_rollback']['staging_15_resumen']) === 4
        && collect($evidence['post_rollback']['staging_15_resumen'])->every(fn($r) => $r->estado_aplicacion === 'pendiente')),
    'vigencia_18480_intacta' => ($evidence['post_rollback']['vigencia_18480'] === $evidence['pre_trial']['vigencia_18480']),
    'vigencia_851_intacta' => ($evidence['post_rollback']['vigencia_851'] === $evidence['pre_trial']['vigencia_851']),
    'vigencia_19980_intacta' => ($evidence['post_rollback']['vigencia_19980'] === $evidence['pre_trial']['vigencia_19980']),
    'vigencia_20123_intacta' => ($evidence['post_rollback']['vigencia_20123'] === $evidence['pre_trial']['vigencia_20123']),
    'persona_2116_intacta' => ($evidence['post_rollback']['persona_2116'] === $evidence['pre_trial']['persona_2116']),
    'persona_34854_intacta' => ($evidence['post_rollback']['persona_34854'] === $evidence['pre_trial']['persona_34854']),
    'lote_13_intacto' => ($evidence['post_rollback']['lote_13'] === $evidence['pre_trial']['lote_13']),
    'lote_14_intacto' => ($evidence['post_rollback']['lote_14'] === $evidence['pre_trial']['lote_14']),
];

$evidence['comparacion_invariantes'] = $invariants;

$allPassed = !in_array(false, $invariants, true) && $executionError === null;
$evidence['resultado_final'] = $allPassed ? 'APTO_PARA_CONFIRMACION_REAL' : 'NO_APTO';

echo "\n[6] Invariantes de Reversibilidad Post-Rollback:\n";
foreach ($invariants as $k => $v) {
    echo "    - {$k}: " . ($v ? 'OK (CUMPLE)' : 'FALLO (DISCREPANCIA)') . "\n";
}

echo "\n=================================================================\n";
echo "DICTAMEN DEL ENSAYO: " . ($allPassed ? "APTO PARA CONFIRMACION REAL" : "NO APTO") . "\n";
echo "=================================================================\n";

// Guardar evidencia en scratch
$evidenceJsonPath = __DIR__ . '/scratch/fase_3_4_5_dry_run_evidence.json';
if (!is_dir(__DIR__ . '/scratch')) {
    mkdir(__DIR__ . '/scratch', 0777, true);
}
file_put_contents($evidenceJsonPath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nEvidencia técnica detallada guardada en: {$evidenceJsonPath}\n";

