<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\LocVigenciaCredencial;
use App\Services\LocCredentialsPolicyService;

echo "=================================================================\n";
echo "FASE 3.4.7 - COMPROBACION DE SOLO LECTURA EN MARIADB\n";
echo "=================================================================\n\n";

// 1. Lote #15
$b15 = DB::table('loc_import_batches')->where('id', 15)->first();
echo "[1] Lote #15 en MariaDB:\n";
echo "    - Estado: {$b15->estado}\n";
echo "    - Fecha procesamiento: {$b15->fecha_procesamiento}\n";
echo "    - Fecha confirmación: {$b15->fecha_confirmacion}\n";
echo "    - Total leídas: {$b15->total_leidas}\n";
echo "    - Total insertadas: {$b15->total_insertadas}\n";
echo "    - Total actualizadas: {$b15->total_actualizadas}\n";
echo "    - Total sin cambios: {$b15->total_sin_cambios}\n";
echo "    - Total rechazadas: {$b15->total_rechazadas}\n";
echo "    - Revision SHA256: {$b15->revision_sha256}\n";

// 2. Staging Lote #15
$stagingCounts = DB::table('loc_import_staging_rows')
    ->where('import_batch_id', 15)
    ->select('accion', 'estado_aplicacion', DB::raw('count(*) as c'))
    ->groupBy('accion', 'estado_aplicacion')
    ->get();

echo "\n[2] Staging Lote #15:\n";
foreach ($stagingCounts as $sc) {
    echo "    - {$sc->accion} -> {$sc->estado_aplicacion}: {$sc->c}\n";
}

$pendingCount = DB::table('loc_import_staging_rows')
    ->where('import_batch_id', 15)
    ->where('estado_aplicacion', 'pendiente')
    ->count();

$conflictCount = DB::table('loc_import_staging_rows')
    ->where('import_batch_id', 15)
    ->whereIn('accion', ['conflicto', 'rechazado'])
    ->count();

echo "    - Total pendientes: {$pendingCount}\n";
echo "    - Total conflicto/rechazado: {$conflictCount}\n";

// 3. Totales operativos
$totalVigencias = DB::table('loc_vigencias_credenciales')->count();
$batch15InVigencias = DB::table('loc_vigencias_credenciales')->where('import_batch_id', 15)->count();
$totalPersonas = DB::table('loc_personas')->count();
$totalArmas = DB::table('loc_armas')->count();

echo "\n[3] Conteos Operativos Globales:\n";
echo "    - loc_vigencias_credenciales: {$totalVigencias} (nuevos lote 15: {$batch15InVigencias})\n";
echo "    - loc_personas: {$totalPersonas}\n";
echo "    - loc_armas: {$totalArmas}\n";

// 4. Lotes 13 y 14
$b13 = DB::table('loc_import_batches')->where('id', 13)->first();
$b14 = DB::table('loc_import_batches')->where('id', 14)->first();
echo "\n[4] Lotes 13 y 14:\n";
echo "    - Lote 13: estado={$b13->estado}, total_leidas={$b13->total_leidas}, total_actualizadas={$b13->total_actualizadas}\n";
echo "    - Lote 14: estado={$b14->estado}, total_leidas={$b14->total_leidas}, total_nuevas={$b14->total_nuevas}\n";

// 5. Casos funcionales específicos
echo "\n[5] Casos Específicos:\n";

// 5.1 Caso Omitido: Fila 3798 / OM2527E05934 / Vigencia 18480
$row3798 = DB::table('loc_import_staging_rows')->where('import_batch_id', 15)->where('numero_fila', 3798)->first();
$vig18480 = DB::table('loc_vigencias_credenciales')->where('id', 18480)->first();
$newForOM2527E05934 = DB::table('loc_vigencias_credenciales')->where('import_batch_id', 15)->where('numero_credencial', 'OM2527E05934')->count();

echo "  5.1 Credencial OM2527E05934 (Fila 3798):\n";
echo "      - Staging fila 3798: accion={$row3798->accion}, estado_aplicacion={$row3798->estado_aplicacion}\n";
echo "      - Registros nuevos en lote 15: {$newForOM2527E05934}\n";
echo "      - Vigencia 18480: CUIP={$vig18480->cuip}, Cred={$vig18480->numero_credencial}, persona_id={$vig18480->persona_id}, estatus={$vig18480->estatus_credencial}, updated_at={$vig18480->updated_at}\n";

// 5.2 Caso 1: Fila 1603 / OM2527M11905 / Vigencia 851
$vig851 = DB::table('loc_vigencias_credenciales')->where('id', 851)->first();
$origen851 = json_decode($vig851->datos_origen ?? '{}', true);
echo "  5.2 Credencial OM2527M11905 (Vigencia 851):\n";
echo "      - CUIP actual: {$vig851->cuip}\n";
echo "      - Persona ID: {$vig851->persona_id}\n";
echo "      - Es ref LOC: {$vig851->es_referencia_loc}\n";
echo "      - Estatus: {$vig851->estatus_credencial}\n";
echo "      - Trazabilidad CUIP: " . json_encode($origen851['_trazabilidad_cuip'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";

// 5.3 Caso 3: Fila 6816 / OM2527M04365 / Vigencia 19980
$vig19980 = DB::table('loc_vigencias_credenciales')->where('id', 19980)->first();
$origen19980 = json_decode($vig19980->datos_origen ?? '{}', true);
echo "  5.3 Credencial OM2527M04365 (Vigencia 19980):\n";
echo "      - CUIP actual: {$vig19980->cuip}\n";
echo "      - Persona ID: {$vig19980->persona_id}\n";
echo "      - Es ref LOC: {$vig19980->es_referencia_loc}\n";
echo "      - Estatus: {$vig19980->estatus_credencial}\n";
echo "      - Motivo especial: {$vig19980->motivo_situacion_especial}\n";
echo "      - Trazabilidad CUIP: " . json_encode($origen19980['_trazabilidad_cuip'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";

// 5.4 Caso 4: Fila 6959 / OM2527M17592 / Vigencia 20123
$vig20123 = DB::table('loc_vigencias_credenciales')->where('id', 20123)->first();
$origen20123 = json_decode($vig20123->datos_origen ?? '{}', true);
echo "  5.4 Credencial OM2527M17592 (Vigencia 20123):\n";
echo "      - CUIP actual: {$vig20123->cuip}\n";
echo "      - Persona ID: " . var_export($vig20123->persona_id, true) . "\n";
echo "      - Es ref LOC: " . var_export($vig20123->es_referencia_loc, true) . "\n";
echo "      - Estatus: {$vig20123->estatus_credencial}\n";
echo "      - Motivo especial: {$vig20123->motivo_situacion_especial}\n";
echo "      - Trazabilidad CUIP: " . json_encode($origen20123['_trazabilidad_cuip'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";

// 6. Evaluación de Reglas de Negocio con LocCredentialsPolicyService
echo "\n[6] Evaluación de Trámites Ordinarios con LocCredentialsPolicyService:\n";
$policy = app(LocCredentialsPolicyService::class);

// Evaluar caso 1: CUIP HEHP630629H091082032 (ALTA activa)
$resAlta = $policy->evaluateProcedureEligibility($vig851->cuip, 'normal');
echo "    - CUIP {$vig851->cuip} (ALTA): Permitido=" . ($resAlta['permitido'] ? 'SI' : 'NO') . " | Motivo={$resAlta['motivo']} | Mensaje={$resAlta['mensaje']}\n";

// Evaluar caso 3: CUIP BACJ760424H09394717 (SITUACION ESPECIAL)
$resEsp = $policy->evaluateProcedureEligibility($vig19980->cuip, 'normal');
echo "    - CUIP {$vig19980->cuip} (SITUACION ESPECIAL): Permitido=" . ($resEsp['permitido'] ? 'SI' : 'NO') . " | Motivo={$resEsp['motivo']} | Mensaje={$resEsp['mensaje']}\n";

// Evaluar caso 4: CUIP ROCJ731029H09609147 (persona_id = NULL)
$resNull = $policy->evaluateProcedureEligibility($vig20123->cuip, 'normal');
echo "    - CUIP {$vig20123->cuip} (persona_id=NULL): Permitido=" . ($resNull['permitido'] ? 'SI' : 'NO') . " | Motivo={$resNull['motivo']} | Mensaje={$resNull['mensaje']}\n";

// Buscar un CUIP con CANCELACION
$cancelRecord = DB::table('loc_vigencias_credenciales')->where('estatus_credencial', 'CANCELACION')->first();
if ($cancelRecord) {
    $resCanc = $policy->evaluateProcedureEligibility($cancelRecord->cuip, 'normal');
    echo "    - CUIP {$cancelRecord->cuip} (CANCELACION): Permitido=" . ($resCanc['permitido'] ? 'SI' : 'NO') . " | Motivo={$resCanc['motivo']} | Mensaje={$resCanc['mensaje']}\n";
}

// Buscar un CUIP con BAJA exclusiva (sin ALTA activa)
$bajaCuip = DB::table('loc_vigencias_credenciales')
    ->select('cuip')
    ->where('estatus_credencial', 'BAJA')
    ->whereNotIn('cuip', function ($q) {
        $q->select('cuip')->from('loc_vigencias_credenciales')->where('estatus_credencial', 'ALTA')->whereNull('fecha_baja');
    })
    ->whereNotIn('cuip', function ($q) {
        $q->select('cuip')->from('loc_vigencias_credenciales')->where('estatus_credencial', 'SITUACION ESPECIAL');
    })
    ->whereNotNull('persona_id')
    ->where('es_referencia_loc', 1)
    ->first();

if ($bajaCuip) {
    $resBaja = $policy->evaluateProcedureEligibility($bajaCuip->cuip, 'normal');
    echo "    - CUIP {$bajaCuip->cuip} (BAJA): Permitido=" . ($resBaja['permitido'] ? 'SI' : 'NO') . " | Motivo={$resBaja['motivo']} | Mensaje={$resBaja['mensaje']}\n";
}

// 7. Verificación de transiciones de estatus
echo "\n[7] Verificación de Transiciones de Estatus (LocCredentialsPolicyService):\n";
echo "    - ALTA -> BAJA: " . ($policy->canTransitionStatus('ALTA', 'BAJA') ? 'PERMITIDO' : 'BLOQUEADO') . "\n";
echo "    - ALTA -> SITUACION ESPECIAL: " . ($policy->canTransitionStatus('ALTA', 'SITUACION ESPECIAL') ? 'PERMITIDO' : 'BLOQUEADO') . "\n";
echo "    - SITUACION ESPECIAL -> ALTA: " . ($policy->canTransitionStatus('SITUACION ESPECIAL', 'ALTA') ? 'PERMITIDO' : 'BLOQUEADO') . "\n";
echo "    - SITUACION ESPECIAL -> BAJA: " . ($policy->canTransitionStatus('SITUACION ESPECIAL', 'BAJA') ? 'PERMITIDO' : 'BLOQUEADO') . "\n";
echo "    - BAJA -> ALTA: " . ($policy->canTransitionStatus('BAJA', 'ALTA') ? 'PERMITIDO' : 'BLOQUEADO') . "\n";
echo "    - CANCELACION -> CUALQUIERA: " . ($policy->canTransitionStatus('CANCELACION', 'ALTA') ? 'PERMITIDO' : 'BLOQUEADO') . "\n";
echo "    - CUALQUIERA -> CANCELACION: " . ($policy->canTransitionStatus('ALTA', 'CANCELACION') ? 'PERMITIDO' : 'BLOQUEADO') . "\n";
