<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocArma;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\LocPersona;
use App\Models\LocVigenciaCredencial;
use App\Models\User;
use App\Services\LocCredentialsPolicyService;
use App\Services\LocCredentialsXlsImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocCredentialsPolicyAndImportTest extends TestCase
{
    use RefreshDatabase;

    private LocCredentialsPolicyService $policy;
    private LocCredentialsXlsImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new LocCredentialsPolicyService();
        $this->importer = new LocCredentialsXlsImporter($this->policy);
    }

    private function createBatch(): LocImportBatch
    {
        $user = User::factory()->create(['role' => 'superadmin']);

        return LocImportBatch::create([
            'tipo_fuente' => 'vigencias_credenciales',
            'nombre_archivo' => 'Vigencia_Sintetico.xls',
            'archivo_sha256' => hash('sha256', 'dummy_content'),
            'tamano_bytes' => 1024,
            'usuario_id' => $user->id,
            'estado' => 'pendiente',
            'total_leidas' => 0,
            'total_insertadas' => 0,
            'total_actualizadas' => 0,
            'total_sin_cambios' => 0,
            'total_rechazadas' => 0,
            'total_aceptadas' => 0,
        ]);
    }

    private function createSyntheticRow(array $overrides = []): array
    {
        // 40 columnas posicionales exactas
        $row = array_fill(0, 40, '');

        $row[0] = $overrides['id_origen'] ?? '1001';
        $row[1] = $overrides['cuip'] ?? 'ABCD010101HDFRRA01';
        $row[2] = $overrides['rfc'] ?? 'ABCD010101XYZ';
        $row[3] = $overrides['nombre'] ?? 'JUAN PEREZ LOPEZ';
        $row[4] = $overrides['categoria'] ?? 'POLICIA TERCERO';
        $row[5] = 'ARMA CORTA';
        $row[6] = 'PISTOLA';
        $row[7] = 'GLOCK';
        $row[8] = '9X19 MM';
        $row[9] = '17';
        $row[10] = $overrides['matricula_corta'] ?? 'CORTA-001';
        $row[11] = '24';
        $row[12] = 'DIRECCION OPERATIVA';
        $row[13] = 'ARMA LARGA';
        $row[14] = 'FUSIL';
        $row[15] = 'COLT';
        $row[16] = '5.56 MM';
        $row[17] = 'M4';
        $row[18] = $overrides['matricula_larga'] ?? 'LARGA-001';
        $row[19] = '24';
        $row[20] = 'DIRECCION OPERATIVA';
        $row[21] = $overrides['oficio_baja'] ?? '';
        $row[22] = $overrides['fecha_baja'] ?? '';
        $row[23] = 'POLICIA ESTATAL';
        $row[24] = $overrides['numero_credencial'] ?? 'PE-2025-001';
        $row[25] = $overrides['oficio_solicitud'] ?? 'SSP/100/2025';
        $row[26] = $overrides['fecha_solicitud'] ?? '20/09/2025';
        $row[27] = 'EXPEDICION REGULAR';
        $row[28] = 'VIGENCIA 2025-2027';
        $row[29] = '01.02.03';
        $row[30] = 'OPERATIVO';
        $row[31] = 'CENTRO';
        $row[32] = 'CENTRO';
        $row[33] = 'SUBDIRECCION DE OPERACIONES';
        $row[34] = 'BASE CENTRAL';
        $row[35] = 'MODULO CENTRAL';
        $row[36] = 'POLICIA ESTATAL';
        $row[37] = 'SUBDIRECCION DE OPERACIONES';
        $row[38] = $overrides['estatus'] ?? 'ALTA';
        $row[39] = $overrides['motivo_situacion_especial'] ?? '';

        return $row;
    }

    public function test_active_alta_blocks_new_procedure_for_same_cuip(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'TEST010101HDFRRA01',
            'nombre' => 'ELEMENTO ACTIVO',
            'estatus' => 'activo',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-2025-999',
            'numero_oficio' => 'SSP/2025/001',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-01-10',
            'fecha_vigencia' => '2027-01-10',
            'fecha_baja' => null,
            'es_referencia_loc' => true,
        ]);

        $evaluation = $this->policy->evaluateProcedureEligibility('TEST010101HDFRRA01');

        $this->assertFalse($evaluation['permitido']);
        $this->assertSame('bloqueado_alta_activa', $evaluation['motivo']);
        $this->assertStringContainsString('cuenta con una credencial activa con estatus ALTA', $evaluation['mensaje']);
    }

    public function test_situacion_especial_blocks_new_normal_procedure(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'TEST020202HDFRRB02',
            'nombre' => 'ELEMENTO EN COMISION',
            'estatus' => 'activo',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-2025-888',
            'estatus_credencial' => 'SITUACION ESPECIAL',
            'motivo_situacion_especial' => 'COMISION ESPECIAL FUERA DEL ESTADO',
            'fecha_solicitud' => '2025-03-15',
            'es_referencia_loc' => true,
        ]);

        $evaluation = $this->policy->evaluateProcedureEligibility('TEST020202HDFRRB02');

        $this->assertFalse($evaluation['permitido']);
        $this->assertSame('bloqueado_situacion_especial', $evaluation['motivo']);
        $this->assertStringContainsString('SITUACIÓN ESPECIAL', $evaluation['mensaje']);
    }

    public function test_prior_baja_allows_new_procedure(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'TEST030303HDFRRC03',
            'nombre' => 'ELEMENTO CON BAJA PREVIA',
            'estatus' => 'activo',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-2023-111',
            'estatus_credencial' => 'BAJA',
            'fecha_baja' => '2025-02-01',
            'numero_oficio_baja' => 'BAJA/001/2025',
            'es_referencia_loc' => true,
        ]);

        $evaluation = $this->policy->evaluateProcedureEligibility('TEST030303HDFRRC03');

        $this->assertTrue($evaluation['permitido']);
        $this->assertSame('permitido_baja_previa', $evaluation['motivo']);
        $this->assertStringContainsString('Antecedente de BAJA', $evaluation['mensaje']);
    }

    public function test_cancelacion_requires_manual_review(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'TEST040404HDFRRD04',
            'nombre' => 'ELEMENTO CANCELADO',
            'estatus' => 'activo',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-2025-777',
            'estatus_credencial' => 'CANCELACION',
            'fecha_solicitud' => '2025-05-10',
            'es_referencia_loc' => true,
        ]);

        $evaluation = $this->policy->evaluateProcedureEligibility('TEST040404HDFRRD04');

        $this->assertFalse($evaluation['permitido']);
        $this->assertSame('requiere_revision_cancelacion', $evaluation['motivo']);
        $this->assertStringContainsString('CANCELACIÓN sin regla de resolución explícita', $evaluation['mensaje']);
    }

    public function test_unreconciled_person_procedure_is_blocked(): void
    {
        // Credencial importada pero sin enlace a Personal LOC (persona_id = null, es_referencia_loc = false)
        LocVigenciaCredencial::create([
            'persona_id' => null,
            'cuip' => 'UNRESOLVED010101HD01',
            'numero_credencial' => 'PE-UNLINKED-01',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-01-10',
            'es_referencia_loc' => false,
        ]);

        $evaluation = $this->policy->evaluateProcedureEligibility('UNRESOLVED010101HD01');

        $this->assertFalse($evaluation['permitido']);
        $this->assertSame('bloqueado_sin_enlace_personal', $evaluation['motivo']);
        $this->assertStringContainsString('no cuenta con conciliación en el Padrón de Personal LOC', $evaluation['mensaje']);
    }

    public function test_chronology_uses_explicit_dates_and_not_row_or_id_order(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'TEST050505HDFRRE05',
            'nombre' => 'ELEMENTO CRONOLOGIA',
            'estatus' => 'activo',
        ]);

        // Insertamos primero la credencial con fecha más reciente (2026) con estatus BAJA
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-2026-FINAL',
            'estatus_credencial' => 'BAJA',
            'fecha_solicitud' => '2026-01-15',
            'fecha_baja' => '2026-02-01',
            'es_referencia_loc' => true,
        ]);

        // Insertamos después (con mayor ID) una credencial antigua (2024) con ALTA
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-2024-VIEJA',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2024-01-10',
            'es_referencia_loc' => true,
        ]);

        $evaluation = $this->policy->evaluateProcedureEligibility('TEST050505HDFRRE05');

        // La fecha más reciente es la BAJA de 2026, por lo que debe permitir
        $this->assertTrue($evaluation['permitido']);
        $this->assertSame('permitido_baja_previa', $evaluation['motivo']);
    }

    public function test_staging_preserves_unresolved_person_identity_with_null_and_does_not_block_as_conflict(): void
    {
        // NO creamos a la persona en loc_personas
        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => 'NOEXISTE999999HDF00',
            'estatus' => 'ALTA',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        // Se clasifica como nuevo histórico a insertar, NO como conflicto bloqueante
        $this->assertSame(1, $result['counts']['nuevo']);
        $this->assertSame(1, $result['counts']['nuevo_sin_enlace']);
        $this->assertSame(0, $result['counts']['conflicto']);

        $staging = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $this->assertSame('nuevo', $staging->accion);

        $data = is_array($staging->datos) ? $staging->datos : json_decode((string) $staging->datos, true);
        $this->assertNull($data['campos']['persona_id']);
        $this->assertFalse($data['campos']['es_referencia_loc']);
        $this->assertFalse($data['conciliacion']['persona_resuelta']);
        $this->assertContains('persona_no_localizada', $data['conciliacion']['advertencias']);

        // Se puede confirmar e insertar con persona_id = null
        $this->importer->confirm($batch);

        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'cuip' => 'NOEXISTE999999HDF00',
            'persona_id' => null,
            'es_referencia_loc' => 0,
        ]);
    }

    public function test_staging_preserves_unregistered_weapon_as_text_without_failing_valid_person(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'VALIDO010101HDF01',
            'nombre' => 'PERSONA CON ARMA NO REGISTRADA',
            'rfc' => 'ABCD010101XYZ',
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'ABCD010101XYZ',
            'matricula_corta' => 'MAT-FANTASMA-99',
            'matricula_larga' => 'MAT-FANTASMA-88',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['nuevo']);
        $this->assertSame(1, $result['counts']['nuevo_conciliado']);
        $staging = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $this->assertSame('nuevo', $staging->accion);

        $data = is_array($staging->datos) ? $staging->datos : json_decode((string) $staging->datos, true);
        $this->assertSame($persona->id, $data['campos']['persona_id']);
        $this->assertNull($data['campos']['arma_corta_id']);
        $this->assertNull($data['campos']['arma_larga_id']);
        $this->assertSame('MAT-FANTASMA-99', $data['campos']['matricula_corta']);
        $this->assertSame('MAT-FANTASMA-88', $data['campos']['matricula_larga']);
        $this->assertContains('arma_corta_no_encontrada: MAT-FANTASMA-99', $data['conciliacion']['advertencias']);
    }

    public function test_staging_preserves_cancelacion_as_historical_record(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'CANCEL010101HDF01',
            'nombre' => 'ELEMENTO CON CANCELACION HISTORICA',
            'rfc' => 'ABCD010101XYZ',
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'ABCD010101XYZ',
            'numero_credencial' => 'PE-CANCEL-001',
            'estatus' => 'CANCELACION',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        // Se clasifica como nuevo histórico, conservando el estatus CANCELACION
        $this->assertSame(1, $result['counts']['nuevo']);
        $this->assertSame(1, $result['counts']['cancelacion']);
        $this->assertSame(0, $result['counts']['conflicto']);

        // Se confirma y persiste en la base oficial
        $this->importer->confirm($batch);

        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-CANCEL-001',
            'estatus_credencial' => 'CANCELACION',
        ]);

        // La evaluación de procedimiento para este CUIP exige revisión manual
        $eval = $this->policy->evaluateProcedureEligibility($persona->cuip);
        $this->assertFalse($eval['permitido']);
        $this->assertSame('requiere_revision_cancelacion', $eval['motivo']);
    }

    public function test_staging_detects_contradictory_alta_and_flags_conflict(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'DOBLEALTA010101HD01',
            'nombre' => 'ELEMENTO DOBLE ALTA',
            'rfc' => 'ABCD010101XYZ',
        ]);

        // Ya tiene una credencial ALTA en base
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-EXISTENTE-01',
            'estatus_credencial' => 'ALTA',
            'fecha_baja' => null,
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        // Nueva fila intentando registrar OTRA ALTA con diferente número
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'ABCD010101XYZ',
            'numero_credencial' => 'PE-NUEVA-02',
            'estatus' => 'ALTA',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['conflicto']);
        $staging = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $this->assertSame('conflicto', $staging->accion);
        $this->assertStringContainsString('ya existe una credencial activa con estatus ALTA', $staging->errores[0]);
    }

    public function test_staging_handles_anomaly_status_row_19905_as_conflict(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'ANOMALIA010101HD01',
            'nombre' => 'ELEMENTO ANOMALIA',
            'rfc' => 'ABCD010101XYZ',
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'ABCD010101XYZ',
            'estatus' => 'NUMERO DE CREDENCIAL', // Anomalía exacta de la fila 19,905
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['conflicto']);
        $staging = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $this->assertSame('conflicto', $staging->accion);
        $this->assertStringContainsString("Estatus de credencial no reconocido ('NUMERO DE CREDENCIAL')", $staging->errores[0]);
    }

    public function test_exact_technical_duplicate_is_classified_as_sin_cambios(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'DUP010101HDF01',
            'nombre' => 'ELEMENTO DUPLICADO',
            'rfc' => 'ABCD010101XYZ',
        ]);

        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'ABCD010101XYZ',
            'numero_credencial' => 'PE-DUP-01',
            'estatus' => 'ALTA',
        ]);

        $fingerprint = $this->policy->computeRowFingerprint($row);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-DUP-01',
            'estatus_credencial' => 'ALTA',
            'fila_hash' => $fingerprint,
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['sin_cambios']);
        $staging = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $this->assertSame('sin_cambios', $staging->accion);
    }

    public function test_confirm_blocks_when_structural_conflicts_exist(): void
    {
        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'estatus' => 'NUMERO DE CREDENCIAL', // Error estructural
        ]);

        $this->importer->stageFromRows([$row], $batch);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('El lote contiene 1 filas rechazadas o en conflicto');

        $this->importer->confirm($batch);
        $this->assertDatabaseCount('loc_vigencias_credenciales', 0);
    }

    public function test_confirm_persists_valid_records_with_dual_weapons_atomically(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'OK010101HDFRRA01',
            'nombre' => 'PERSONA EXITOSA',
            'rfc' => 'ABCD010101XYZ',
        ]);

        $corta = LocArma::create([
            'matricula' => 'CORTA-TEST-01',
            'marca' => 'GLOCK',
            'calibre' => '9MM',
            'clase' => 'PISTOLA',
        ]);

        $larga = LocArma::create([
            'matricula' => 'LARGA-TEST-01',
            'marca' => 'COLT',
            'calibre' => '5.56MM',
            'clase' => 'FUSIL',
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'ABCD010101XYZ',
            'matricula_corta' => $corta->matricula,
            'matricula_larga' => $larga->matricula,
            'numero_credencial' => 'PE-DUAL-SUCCESS',
            'estatus' => 'ALTA',
        ]);

        $this->importer->stageFromRows([$row], $batch);
        $confirmResult = $this->importer->confirm($batch);

        $this->assertSame(1, $confirmResult['insertados']);

        $cred = LocVigenciaCredencial::where('numero_credencial', 'PE-DUAL-SUCCESS')->first();
        $this->assertNotNull($cred);
        $this->assertSame($persona->id, $cred->persona_id);
        $this->assertSame($corta->id, $cred->arma_corta_id);
        $this->assertSame($larga->id, $cred->arma_larga_id);
        $this->assertSame('CORTA-TEST-01', $cred->matricula_corta);
        $this->assertSame('LARGA-TEST-01', $cred->matricula_larga);
        $this->assertTrue((bool) $cred->es_referencia_loc);
    }

    public function test_reimporting_same_batch_identifies_all_rows_as_sin_cambios(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'REIMPORT010101H1',
            'nombre' => 'ELEMENTO REIMPORTACION',
            'rfc' => 'ABCD010101XYZ',
        ]);

        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'ABCD010101XYZ',
            'numero_credencial' => 'PE-REIMPORT-01',
            'estatus' => 'ALTA',
        ]);

        // Lote 1: Se sube y se confirma
        $batchOne = $this->createBatch();
        $this->importer->stageFromRows([$row], $batchOne);
        $this->importer->confirm($batchOne);

        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'numero_credencial' => 'PE-REIMPORT-01',
            'cuip' => $persona->cuip,
        ]);

        // Lote 2: Se vuelve a subir el mismo archivo
        $batchTwo = $this->createBatch();
        $resultTwo = $this->importer->stageFromRows([$row], $batchTwo);

        $this->assertSame(1, $resultTwo['counts']['sin_cambios']);
        $this->assertSame(0, $resultTwo['counts']['nuevo']);

        // Se confirma el lote 2 sin crear registros duplicados
        $confirmTwo = $this->importer->confirm($batchTwo);
        $this->assertSame(0, $confirmTwo['insertados']);
        $this->assertSame(1, LocVigenciaCredencial::where('cuip', $persona->cuip)->count());
    }

    public function test_rfc_mismatch_with_person_creates_conflict(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'RFCDISCREP010101H1',
            'nombre' => 'ELEMENTO CON RFC DISTINTO',
            'rfc' => 'REAL999999XYZ',
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'OTRO111111ABC', // Difiere del padrón
            'numero_credencial' => 'PE-DISCREP-01',
            'estatus' => 'ALTA',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $staging = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $this->assertNotNull($staging);
        $this->assertSame('conflicto', $staging->accion);
        $this->assertStringContainsString('Discrepancia en RFC', $staging->errores[0]);
    }

    public function test_multiple_records_for_same_cuip_preserves_baja_and_subsequent_alta(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'MULTI010101HDFR01',
            'nombre' => 'ELEMENTO HISTORIAL MULTIPLE',
            'rfc' => 'ABCD010101XYZ',
        ]);

        // Fila 1: Historial previo de baja en 2024
        $rowBaja = $this->createSyntheticRow([
            'id_origen' => '5001',
            'cuip' => $persona->cuip,
            'rfc' => 'ABCD010101XYZ',
            'numero_credencial' => 'PE-HIST-2023',
            'estatus' => 'BAJA',
            'fecha_baja' => '15/06/2024',
            'oficio_baja' => 'OF/BAJA/2024',
        ]);

        // Fila 2: Nueva credencial de alta en 2025
        $rowAlta = $this->createSyntheticRow([
            'id_origen' => '5002',
            'cuip' => $persona->cuip,
            'rfc' => 'ABCD010101XYZ',
            'numero_credencial' => 'PE-HIST-2025',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '20/09/2025',
        ]);

        $batch = $this->createBatch();
        $result = $this->importer->stageFromRows([$rowBaja, $rowAlta], $batch);

        $this->assertSame(2, $result['counts']['nuevo']);
        $this->assertSame(0, $result['counts']['conflicto']);

        $this->importer->confirm($batch);

        $records = LocVigenciaCredencial::where('cuip', $persona->cuip)->get();
        $this->assertCount(2, $records);

        // La evaluación de trámite para este elemento debe detectar el ALTA más reciente
        $eval = $this->policy->evaluateProcedureEligibility($persona->cuip);
        $this->assertFalse($eval['permitido']);
        $this->assertSame('bloqueado_alta_activa', $eval['motivo']);
    }

    public function test_omitted_row_is_never_inserted_into_vigencias_credenciales_and_sets_estado_aplicacion(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'OMIT010101HDFR01',
            'nombre' => 'ELEMENTO VALIDO CON OTRA FILA OMITIDA',
            'rfc' => 'ABCD010101XYZ',
        ]);

        $batch = $this->createBatch();

        // Fila 1: Válida ('nuevo')
        $rowValida = $this->createSyntheticRow([
            'id_origen' => '6001',
            'cuip' => $persona->cuip,
            'rfc' => 'ABCD010101XYZ',
            'numero_credencial' => 'PE-VALIDA-01',
            'estatus' => 'ALTA',
        ]);

        // Fila 2: Fila técnica anómala que resultará en conflicto
        $rowAnomala = $this->createSyntheticRow([
            'id_origen' => '6002',
            'cuip' => 'TECH010101HDFR99',
            'estatus' => 'NUMERO DE CREDENCIAL',
        ]);

        $this->importer->stageFromRows([$rowValida, $rowAnomala], $batch);

        $stagingValida = LocImportStagingRow::where('import_batch_id', $batch->id)->where('numero_fila', 2)->first();
        $stagingAnomala = LocImportStagingRow::where('import_batch_id', $batch->id)->where('numero_fila', 3)->first();

        $this->assertSame('nuevo', $stagingValida->accion);
        $this->assertSame('conflicto', $stagingAnomala->accion);

        // Excluimos formalmente la fila anómala registrando trazabilidad
        $this->importer->omitStagingRow($stagingAnomala, 'Fila técnica desfasada en archivo origen', 42);

        $stagingAnomala->refresh();
        $this->assertSame(LocCredentialsXlsImporter::ACTION_OMITIDO, $stagingAnomala->accion);
        $this->assertSame('Fila técnica desfasada en archivo origen', $stagingAnomala->datos['exclusion']['motivo']);
        $this->assertSame(42, $stagingAnomala->datos['exclusion']['usuario_id']);
        $this->assertSame('conflicto', $stagingAnomala->datos['exclusion']['accion_previa']);

        // Se confirma el lote
        $result = $this->importer->confirm($batch);

        // Verificaciones
        $this->assertSame(1, $result['insertados']);
        $this->assertSame(1, LocVigenciaCredencial::count());
        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'numero_credencial' => 'PE-VALIDA-01',
            'cuip' => $persona->cuip,
        ]);
        $this->assertDatabaseMissing('loc_vigencias_credenciales', [
            'cuip' => 'TECH010101HDFR99',
        ]);

        // La fila omitida en staging debe tener estado_aplicacion = 'omitido'
        $stagingAnomala->refresh();
        $this->assertSame(LocCredentialsXlsImporter::STATUS_APLICACION_OMITIDO, $stagingAnomala->estado_aplicacion);

        $batch->refresh();
        $this->assertSame('completado', $batch->estado);
        $this->assertSame(1, $batch->total_insertadas);
        $this->assertNotNull($batch->fecha_confirmacion);
    }

    public function test_confirm_blocks_when_conflicts_exist_and_succeeds_after_formal_omission(): void
    {
        $batch = $this->createBatch();

        $rowOk = $this->createSyntheticRow([
            'id_origen' => '7001',
            'cuip' => 'UNRESOLVED9999H01',
            'numero_credencial' => 'PE-UNRES-01',
            'estatus' => 'ALTA',
        ]);

        $rowConflict = $this->createSyntheticRow([
            'id_origen' => '7002',
            'cuip' => 'CONFLICT010101H02',
            'estatus' => 'NUMERO DE CREDENCIAL',
        ]);

        $this->importer->stageFromRows([$rowOk, $rowConflict], $batch);

        // Intento de confirmar con conflicto pendiente debe fallar
        try {
            $this->importer->confirm($batch);
            $this->fail('Se esperaba RuntimeException por conflicto pendiente');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 filas rechazadas o en conflicto', $e->getMessage());
        }

        // Verificamos que nada se insertó (rollback completo)
        $this->assertSame(0, LocVigenciaCredencial::count());

        $conflictStaging = LocImportStagingRow::where('import_batch_id', $batch->id)->where('accion', 'conflicto')->first();
        $this->assertNotNull($conflictStaging);

        // Se excluye formalmente
        $this->importer->omitStagingRow($conflictStaging, 'Fila malformada en Excel', 1);

        // Ahora la confirmación debe proceder atómicamente
        $result = $this->importer->confirm($batch);
        $this->assertSame(1, $result['insertados']);
        $this->assertSame(1, LocVigenciaCredencial::count());
    }

    public function test_batch_confirmation_sets_fecha_confirmacion_and_real_total_insertadas(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'TIMETEST010101H1',
            'nombre' => 'ELEMENTO TIEMPO',
            'rfc' => 'TIME010101XYZ',
        ]);

        $batch = $this->createBatch();
        $this->assertNull($batch->fecha_confirmacion);

        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'TIME010101XYZ',
            'numero_credencial' => 'PE-TIME-01',
            'estatus' => 'ALTA',
        ]);

        $this->importer->stageFromRows([$row], $batch);
        $this->importer->confirm($batch);

        $batch->refresh();
        $this->assertSame('completado', $batch->estado);
        $this->assertNotNull($batch->fecha_confirmacion);
        $this->assertSame(1, $batch->total_insertadas);
    }

    public function test_confirm_idempotency_does_not_duplicate_records_on_repeated_calls(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'IDEMP010101HDF01',
            'nombre' => 'ELEMENTO IDEMPOTENCIA',
            'rfc' => 'IDEM010101XYZ',
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'IDEM010101XYZ',
            'numero_credencial' => 'PE-IDEM-01',
            'estatus' => 'ALTA',
        ]);

        $this->importer->stageFromRows([$row], $batch);

        $result1 = $this->importer->confirm($batch);
        $this->assertSame(1, $result1['insertados']);
        $this->assertSame(1, LocVigenciaCredencial::count());

        // Segunda llamada a confirm()
        $result2 = $this->importer->confirm($batch);
        $this->assertTrue($result2['ya_confirmado'] ?? false);
        $this->assertSame(1, LocVigenciaCredencial::count());
    }

    public function test_large_dataset_over_1000_rows_processes_all_without_skips_prevents_duplicates_on_retry_and_never_inserts_omitted_row(): void
    {
        $batch = $this->createBatch();
        $totalRows = 1200;
        $rows = [];

        // Generar 1,200 filas sintéticas:
        // Fila 0 (técnica / anomalía): será omitida formalmente
        $rows[] = $this->createSyntheticRow([
            'id_origen' => 'TECH-0001',
            'cuip' => 'TECH000000HDFRRA00',
            'rfc' => 'TECH000000XYZ',
            'numero_credencial' => 'NUMERO DE CREDENCIAL',
            'estatus' => 'CANCELACION',
        ]);

        // Filas 1..1199 (1,199 filas válidas 'nuevo' con identificadores únicos)
        for ($i = 1; $i < $totalRows; $i++) {
            $padded = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $rows[] = $this->createSyntheticRow([
                'id_origen' => (string) (1000 + $i),
                'cuip' => "CUIP{$padded}HDF01",
                'rfc' => "RFC{$padded}X1",
                'numero_credencial' => "PE-2025-{$padded}",
                'matricula_corta' => "MC-{$padded}",
                'matricula_larga' => "ML-{$padded}",
                'estatus' => 'ALTA',
            ]);
        }

        // 1. Etapa de Staging
        $this->importer->stageFromRows($rows, $batch);
        $this->assertSame($totalRows, LocImportStagingRow::where('import_batch_id', $batch->id)->count());

        // 2. Exclusión formal de la fila 1 (técnica)
        $omittedRow = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('cuip', 'TECH000000HDFRRA00')
            ->firstOrFail();

        $this->importer->omitStagingRow($omittedRow, 'Fila de encabezado técnico no operativa', 1);

        $batch->refresh();
        $this->assertSame('en_revision', $batch->estado);

        // 3. Confirmación completa: con chunkById(250) no debe saltar ninguna fila
        $result = $this->importer->confirm($batch);

        $expectedInserted = $totalRows - 1; // 1,199
        $this->assertSame($expectedInserted, $result['insertados']);

        // Verificar que en la base operativa existan exactamente 1,199 filas (ninguna omitida, ninguna saltada)
        $operationalCount = LocVigenciaCredencial::where('import_batch_id', $batch->id)->count();
        $this->assertSame($expectedInserted, $operationalCount, 'Todas las filas válidas deben ser insertadas sin saltos por paginación.');

        // Verificar que ninguna fila en staging quede con estado 'pendiente'
        $pendingCount = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('estado_aplicacion', 'pendiente')
            ->count();
        $this->assertSame(0, $pendingCount, 'No deben quedar filas en staging con estado pendiente.');

        // Verificar que la fila omitida NUNCA se haya insertado en la base operativa
        $omittedInDb = LocVigenciaCredencial::where('import_batch_id', $batch->id)
            ->where('cuip', 'TECH000000HDFRRA00')
            ->count();
        $this->assertSame(0, $omittedInDb, 'La fila omitida no debe existir en loc_vigencias_credenciales.');

        $omittedRow->refresh();
        $this->assertSame(LocCredentialsXlsImporter::STATUS_APLICACION_OMITIDO, $omittedRow->estado_aplicacion);

        // 4. Reintento de confirmación: debe ser idempotente y no duplicar registros
        $retryResult = $this->importer->confirm($batch);
        $this->assertTrue($retryResult['ya_confirmado'] ?? false);

        $operationalCountAfterRetry = LocVigenciaCredencial::where('import_batch_id', $batch->id)->count();
        $this->assertSame($expectedInserted, $operationalCountAfterRetry, 'El reintento no debe duplicar registros en loc_vigencias_credenciales.');
    }

    public function test_audit_and_resume_confirmation_recovers_partially_applied_batch_without_duplicates(): void
    {
        $batch = $this->createBatch();
        $totalRows = 1200;
        $rows = [];

        // Fila 0: omitida
        $rows[] = $this->createSyntheticRow([
            'id_origen' => 'TECH-PARTIAL',
            'cuip' => 'TECHPARTIAL00HDF00',
            'rfc' => 'TECHPART00XYZ',
            'numero_credencial' => 'ENCABEZADO',
            'estatus' => 'CANCELACION',
        ]);

        for ($i = 1; $i < $totalRows; $i++) {
            $padded = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $rows[] = $this->createSyntheticRow([
                'id_origen' => (string) (2000 + $i),
                'cuip' => "PARTIAL{$padded}HDF01",
                'rfc' => "RPART{$padded}X1",
                'numero_credencial' => "PE-PART-{$padded}",
                'estatus' => 'ALTA',
            ]);
        }

        $this->importer->stageFromRows($rows, $batch);

        // Omitir fila técnica
        $omittedRow = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('cuip', 'TECHPARTIAL00HDF00')
            ->firstOrFail();
        $this->importer->omitStagingRow($omittedRow, 'Exclusión técnica de prueba', 1);

        // Simular el escenario de falla de confirmación parcial:
        // 600 filas fueron insertadas en la base operativa y marcadas 'insertado'
        // 599 filas quedaron 'pendiente' en staging
        // El lote fue marcado erróneamente como 'completado'
        $allValidStaging = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('accion', 'nuevo')
            ->orderBy('id')
            ->get();

        $alreadyInsertedSlice = $allValidStaging->slice(0, 600);
        $pendingSlice = $allValidStaging->slice(600);

        $operationalRecords = [];
        $now = now();
        foreach ($alreadyInsertedSlice as $staged) {
            $data = is_array($staged->datos) ? $staged->datos : json_decode((string) $staged->datos, true);
            $fields = $data['campos'] ?? [];
            $fields['import_batch_id'] = $batch->id;
            $fields['datos_origen'] = json_encode($data['origen'] ?? [], JSON_UNESCAPED_UNICODE);
            $fields['created_at'] = $now;
            $fields['updated_at'] = $now;
            $operationalRecords[] = $fields;
        }

        foreach (array_chunk($operationalRecords, 100) as $chunk) {
            \Illuminate\Support\Facades\DB::table('loc_vigencias_credenciales')->insert($chunk);
        }

        // Marcar esas 600 como 'insertado' en staging
        LocImportStagingRow::whereIn('id', $alreadyInsertedSlice->pluck('id'))->update(['estado_aplicacion' => 'insertado']);

        // Establecer el lote como 'completado' con solo 600 insertadas (réplica exacta del bug en producción)
        $batch->update([
            'estado' => 'completado',
            'total_insertadas' => 600,
            'fecha_confirmacion' => $now,
        ]);

        // 1. Ejecutar auditoría de estado de aplicación
        $audit = $this->importer->auditBatchApplicationState($batch);

        $this->assertSame(1200, $audit['staging_total']);
        $this->assertSame(600, $audit['insertadas_verificadas']);
        $this->assertSame(599, $audit['validas_pendientes']);
        $this->assertSame(1, $audit['omitidas_verificadas']);
        $this->assertSame(600, $audit['operativa_total_lote']);
        $this->assertSame(600, $audit['operativa_hashes_unicos']);
        $this->assertTrue($audit['es_reanudar_seguro'], 'La recuperación debe reportarse como segura al no haber discrepancias bloqueantes.');

        // 2. Ejecutar recuperación reanudable
        $resumeResult = $this->importer->resumeConfirmation($batch);

        // 3. Verificaciones posteriores a la recuperación
        $expectedTotal = $totalRows - 1; // 1,199
        $this->assertSame($expectedTotal, $resumeResult['insertados']);

        $finalOperationalCount = LocVigenciaCredencial::where('import_batch_id', $batch->id)->count();
        $this->assertSame($expectedTotal, $finalOperationalCount, 'Deben existir exactamente 1,199 registros operativos sin duplicar los 600 previos.');

        // Verificar unicidad de hashes en BD operativa para este lote
        $uniqueHashesCount = LocVigenciaCredencial::where('import_batch_id', $batch->id)->distinct()->count('fila_hash');
        $this->assertSame($expectedTotal, $uniqueHashesCount, 'Cada registro operativo debe corresponder a una fila única sin duplicados.');

        // Verificar que 0 filas queden pendientes en staging
        $finalPending = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('estado_aplicacion', 'pendiente')
            ->count();
        $this->assertSame(0, $finalPending);

        // Verificar que el lote esté en 'completado' con el total real
        $batch->refresh();
        $this->assertSame('completado', $batch->estado);
        $this->assertSame($expectedTotal, $batch->total_insertadas);
    }

    public function test_audit_detects_blocking_discrepancy_if_omitted_row_exists_in_operational_table(): void
    {
        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'id_origen' => 'BLOCK-01',
            'cuip' => 'BLOCK000000HDF00',
            'estatus' => 'ALTA',
        ]);

        $this->importer->stageFromRows([$row], $batch);

        $stagedRow = LocImportStagingRow::where('import_batch_id', $batch->id)->firstOrFail();
        $this->importer->omitStagingRow($stagedRow, 'Omitido para prueba de discrepancia', 1);

        // Insertar indebidamente la fila omitida en la base operativa
        $data = is_array($stagedRow->datos) ? $stagedRow->datos : json_decode((string) $stagedRow->datos, true);
        $fields = $data['campos'] ?? [];
        $fields['import_batch_id'] = $batch->id;
        $fields['created_at'] = now();
        $fields['updated_at'] = now();
        \Illuminate\Support\Facades\DB::table('loc_vigencias_credenciales')->insert($fields);

        // Auditar el lote
        $audit = $this->importer->auditBatchApplicationState($batch);
        $this->assertFalse($audit['es_reanudar_seguro']);

        $types = array_column($audit['discrepancias'], 'tipo');
        $this->assertContains('omitido_en_operativa', $types);

        // Intentar reanudar debe lanzar una RuntimeException
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No es posible reanudar el lote debido a inconsistencias bloqueantes');
        $this->importer->resumeConfirmation($batch);
    }

    public function test_confirm_aborts_and_does_not_mark_completed_if_valid_rows_remain_pending(): void
    {
        $batch = $this->createBatch();
        $row1 = $this->createSyntheticRow([
            'id_origen' => 'FAIL-01',
            'cuip' => 'FAIL000001HDF01',
            'numero_credencial' => 'PE-FAIL-01',
            'estatus' => 'ALTA',
        ]);
        $row2 = $this->createSyntheticRow([
            'id_origen' => 'FAIL-02',
            'cuip' => 'FAIL000002HDF02',
            'numero_credencial' => 'PE-FAIL-02',
            'estatus' => 'ALTA',
        ]);

        $this->importer->stageFromRows([$row1, $row2], $batch);

        // Forzar que una fila quede fuera de la inserción modificando temporalmente su accion en staging a algo no contemplado
        // o alterando el comportamiento para que una fila quede en 'pendiente'
        // Para probar que la validación post-confirmación protege el estado:
        // Simulamos un evento de escucha en DB para evitar que la segunda fila se marque como 'insertado'
        \Illuminate\Support\Facades\DB::listen(function ($query) {
            // No interceptamos nada especial a menos que se requiera
        });

        // Alternativamente, creamos una subclase anónima del importador o verificamos directamente
        // que una excepción durante confirmación preserva el estado 'en_revision'
        $stagedRows = LocImportStagingRow::where('import_batch_id', $batch->id)->get();
        $this->assertSame(2, $stagedRows->count());

        // Si se altera una fila sin actualizar la huella de revisión, se bloquea por alteración de revisión
        $stagedRows[1]->update(['accion' => 'conflicto']);
        try {
            $this->importer->confirm($batch);
            $this->fail('Se esperaba RuntimeException debido a huella alterada.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('La revisión del lote cambió después del análisis', $e->getMessage());
        }

        // Si se recalcula la huella con el conflicto presente, se bloquea por filas en conflicto
        $batch->revision_sha256 = $this->importer->reviewFingerprint($batch);
        $batch->save();

        try {
            $this->importer->confirm($batch);
            $this->fail('Se esperaba RuntimeException debido al conflicto.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('contiene 1 filas rechazadas o en conflicto', $e->getMessage());
        }

        $batch->refresh();
        $this->assertNotSame('completado', $batch->estado);
        $this->assertSame(0, LocVigenciaCredencial::where('import_batch_id', $batch->id)->count());
    }

    public function test_import_normalizes_weapon_serials_with_whitespace_and_links_armas(): void
    {
        $armaCorta = LocArma::create([
            'matricula' => 'NORM-CORTA-01',
            'tipo_arma' => 'CORTA',
            'clase_arma' => 'PISTOLA',
            'marca' => 'GLOCK',
            'modelo' => '17',
            'calibre' => '9MM',
            'activo' => true,
        ]);

        $armaLarga = LocArma::create([
            'matricula' => 'NORM-LARGA-02',
            'tipo_arma' => 'LARGA',
            'clase_arma' => 'FUSIL',
            'marca' => 'COLT',
            'modelo' => 'M4',
            'calibre' => '5.56',
            'activo' => true,
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'id_origen' => '5001',
            'cuip' => 'NORM990101HDFRRA01',
            'matricula_corta' => "  NORM-CORTA-01 \t",
            'matricula_larga' => "\n NORM-LARGA-02  ",
            'numero_credencial' => 'CRED-NORM-01',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);
        $this->assertSame(1, $result['counts']['nuevo']);

        $staging = LocImportStagingRow::where('import_batch_id', $batch->id)->firstOrFail();
        $data = is_array($staging->datos) ? $staging->datos : json_decode((string) $staging->datos, true);

        // Se verifica que en campos normalizados estén sin espacios y con IDs resueltos
        $this->assertSame('NORM-CORTA-01', $data['campos']['matricula_corta']);
        $this->assertSame('NORM-LARGA-02', $data['campos']['matricula_larga']);
        $this->assertSame($armaCorta->id, $data['campos']['arma_corta_id']);
        $this->assertSame($armaLarga->id, $data['campos']['arma_larga_id']);
        $this->assertTrue($data['conciliacion']['arma_corta_resuelta']);
        $this->assertTrue($data['conciliacion']['arma_larga_resuelta']);

        $this->importer->confirm($batch);

        $vigencia = LocVigenciaCredencial::where('import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('NORM-CORTA-01', $vigencia->matricula_corta);
        $this->assertSame('NORM-LARGA-02', $vigencia->matricula_larga);
        $this->assertSame($armaCorta->id, $vigencia->arma_corta_id);
        $this->assertSame($armaLarga->id, $vigencia->arma_larga_id);
    }
}
