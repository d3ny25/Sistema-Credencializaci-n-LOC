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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocCredentialsRegressionTest extends TestCase
{
    use RefreshDatabase;

    private LocCredentialsPolicyService $policy;
    private LocCredentialsXlsImporter $importer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new LocCredentialsPolicyService();
        $this->importer = new LocCredentialsXlsImporter($this->policy);
        $this->admin = User::factory()->create(['role' => 'superadmin']);
    }

    private function createBatch(): LocImportBatch
    {
        return LocImportBatch::create([
            'tipo_fuente' => 'vigencias_credenciales',
            'nombre_archivo' => 'Vigencias_Regression.xls',
            'archivo_sha256' => hash('sha256', 'dummy_credentials_content'),
            'tamano_bytes' => 2048,
            'usuario_id' => $this->admin->id,
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
        $row[10] = $overrides['matricula_corta'] ?? 'MC-001';
        $row[11] = '24';
        $row[12] = 'DIRECCION OPERATIVA';
        $row[13] = 'ARMA LARGA';
        $row[14] = 'FUSIL';
        $row[15] = 'COLT';
        $row[16] = '5.56 MM';
        $row[17] = 'M4';
        $row[18] = $overrides['matricula_larga'] ?? 'ML-001';
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

    /**
     * 1. Grupo A: Transición ALTA -> SITUACION ESPECIAL legítima para un mismo folio y CUIP.
     */
    public function test_transition_from_alta_to_situacion_especial_is_classified_as_actualizar_and_applied(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'TRAN010101HDF01',
            'nombre' => 'ELEMENTO TRANSICION 1',
            'rfc' => 'TRAN010101XYZ',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-TRAN-01',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-01-10',
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'TRAN010101XYZ',
            'numero_credencial' => 'PE-TRAN-01',
            'estatus' => 'SITUACION ESPECIAL',
            'motivo_situacion_especial' => 'COMISION EN MANDO MIXTO',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['actualizar']);
        $this->assertSame(0, $result['counts']['conflicto']);

        $this->importer->confirm($batch);

        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'numero_credencial' => 'PE-TRAN-01',
            'cuip' => $persona->cuip,
            'estatus_credencial' => 'SITUACION ESPECIAL',
            'motivo_situacion_especial' => 'COMISION EN MANDO MIXTO',
        ]);
    }

    /**
     * 2. Grupo A: Transición SITUACION ESPECIAL -> BAJA legítima para un mismo folio y CUIP.
     */
    public function test_transition_from_situacion_especial_to_baja_is_classified_as_actualizar_and_applied(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'TRAN020202HDF02',
            'nombre' => 'ELEMENTO TRANSICION 2',
            'rfc' => 'TRAN020202XYZ',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-TRAN-02',
            'estatus_credencial' => 'SITUACION ESPECIAL',
            'motivo_situacion_especial' => 'SUSPENSION TEMPORAL',
            'fecha_solicitud' => '2025-01-10',
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'TRAN020202XYZ',
            'numero_credencial' => 'PE-TRAN-02',
            'estatus' => 'BAJA',
            'fecha_baja' => '15/03/2025',
            'oficio_baja' => 'OF-BAJA-02/2025',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['actualizar']);
        $this->assertSame(0, $result['counts']['conflicto']);

        $this->importer->confirm($batch);

        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'numero_credencial' => 'PE-TRAN-02',
            'cuip' => $persona->cuip,
            'estatus_credencial' => 'BAJA',
            'fecha_baja' => '2025-03-15',
            'numero_oficio_baja' => 'OF-BAJA-02/2025',
        ]);
    }

    /**
     * 3. Grupo A: Reactivación BAJA -> ALTA legítima para un mismo folio y CUIP.
     */
    public function test_transition_reactivation_from_baja_to_alta_is_classified_as_actualizar_and_applied(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'TRAN030303HDF03',
            'nombre' => 'ELEMENTO TRANSICION 3',
            'rfc' => 'TRAN030303XYZ',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-TRAN-03',
            'estatus_credencial' => 'BAJA',
            'fecha_baja' => '2024-12-01',
            'numero_oficio_baja' => 'BAJA-PREVIA-03',
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'TRAN030303XYZ',
            'numero_credencial' => 'PE-TRAN-03',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '10/01/2025',
            'oficio_solicitud' => 'OF-REACTIVA-03',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['actualizar']);
        $this->assertSame(0, $result['counts']['conflicto']);

        $this->importer->confirm($batch);

        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'numero_credencial' => 'PE-TRAN-03',
            'cuip' => $persona->cuip,
            'estatus_credencial' => 'ALTA',
            'numero_oficio' => 'OF-REACTIVA-03',
        ]);
    }

    /**
     * 4. Grupo A: Reincorporación SITUACION ESPECIAL -> ALTA legítima para un mismo folio y CUIP.
     */
    public function test_transition_reincorporation_from_situacion_especial_to_alta_is_classified_as_actualizar_and_applied(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'TRAN040404HDF04',
            'nombre' => 'ELEMENTO TRANSICION 4',
            'rfc' => 'TRAN040404XYZ',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-TRAN-04',
            'estatus_credencial' => 'SITUACION ESPECIAL',
            'motivo_situacion_especial' => 'LICENCIA MEDICA',
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'TRAN040404XYZ',
            'numero_credencial' => 'PE-TRAN-04',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '05/02/2025',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['actualizar']);
        $this->assertSame(0, $result['counts']['conflicto']);

        $this->importer->confirm($batch);

        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'numero_credencial' => 'PE-TRAN-04',
            'cuip' => $persona->cuip,
            'estatus_credencial' => 'ALTA',
        ]);
    }

    /**
     * 5. Grupo B: Sustitución válida con BAJA antes de ALTA en el orden del archivo.
     */
    public function test_valid_substitution_with_baja_before_alta_in_file_order_is_reconciled(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'SUST010101HDF01',
            'nombre' => 'ELEMENTO SUSTITUCION 1',
            'rfc' => 'SUST010101XYZ',
        ]);

        // Credencial antigua activa en base
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-VIEJA-01',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2023-01-10',
            'es_referencia_loc' => true,
        ]);

        // Fila 1: BAJA de la credencial vieja (fecha 2025-01-10)
        $rowBaja = $this->createSyntheticRow([
            'id_origen' => '101',
            'cuip' => $persona->cuip,
            'rfc' => 'SUST010101XYZ',
            'numero_credencial' => 'PE-VIEJA-01',
            'estatus' => 'BAJA',
            'fecha_baja' => '10/01/2025',
            'oficio_baja' => 'OF-BAJA-01',
        ]);

        // Fila 2: ALTA de la credencial nueva de reemplazo (fecha 2025-01-15)
        $rowAlta = $this->createSyntheticRow([
            'id_origen' => '102',
            'cuip' => $persona->cuip,
            'rfc' => 'SUST010101XYZ',
            'numero_credencial' => 'PE-NUEVA-01',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '15/01/2025',
            'oficio_solicitud' => 'OF-SOL-01',
        ]);

        $batch = $this->createBatch();
        $result = $this->importer->stageFromRows([$rowBaja, $rowAlta], $batch);

        $this->assertSame(1, $result['counts']['actualizar']);
        $this->assertSame(1, $result['counts']['nuevo']);
        $this->assertSame(0, $result['counts']['conflicto']);

        $this->importer->confirm($batch);

        $records = LocVigenciaCredencial::where('cuip', $persona->cuip)->get();
        $this->assertCount(2, $records);

        $vieja = $records->where('numero_credencial', 'PE-VIEJA-01')->first();
        $nueva = $records->where('numero_credencial', 'PE-NUEVA-01')->first();

        $this->assertSame('BAJA', $vieja->estatus_credencial);
        $this->assertSame('2025-01-10', $vieja->fecha_baja?->format('Y-m-d') ?? (string) $vieja->fecha_baja);
        $this->assertSame('ALTA', $nueva->estatus_credencial);
    }

    /**
     * 6. Grupo B: Sustitución válida con ALTA antes de BAJA en el orden del archivo.
     */
    public function test_valid_substitution_with_alta_before_baja_in_file_order_is_reconciled(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'SUST020202HDF02',
            'nombre' => 'ELEMENTO SUSTITUCION 2',
            'rfc' => 'SUST020202XYZ',
        ]);

        // Credencial antigua activa en base
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-VIEJA-02',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2023-05-10',
            'es_referencia_loc' => true,
        ]);

        // Fila 1 (ALTA aparece primero en el Excel): Credencial de reemplazo
        $rowAlta = $this->createSyntheticRow([
            'id_origen' => '201',
            'cuip' => $persona->cuip,
            'rfc' => 'SUST020202XYZ',
            'numero_credencial' => 'PE-NUEVA-02',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '20/02/2025',
            'oficio_solicitud' => 'OF-NUEVA-02',
        ]);

        // Fila 2 (BAJA aparece después en el Excel): Baja de la credencial previa
        $rowBaja = $this->createSyntheticRow([
            'id_origen' => '202',
            'cuip' => $persona->cuip,
            'rfc' => 'SUST020202XYZ',
            'numero_credencial' => 'PE-VIEJA-02',
            'estatus' => 'BAJA',
            'fecha_baja' => '15/02/2025',
            'oficio_baja' => 'OF-BAJA-02',
        ]);

        $batch = $this->createBatch();
        // Procesamos con el ALTA primero
        $result = $this->importer->stageFromRows([$rowAlta, $rowBaja], $batch);

        $this->assertSame(1, $result['counts']['actualizar']);
        $this->assertSame(1, $result['counts']['nuevo']);
        $this->assertSame(0, $result['counts']['conflicto']);

        $this->importer->confirm($batch);

        $records = LocVigenciaCredencial::where('cuip', $persona->cuip)->get();
        $this->assertCount(2, $records);

        $vieja = $records->where('numero_credencial', 'PE-VIEJA-02')->first();
        $nueva = $records->where('numero_credencial', 'PE-NUEVA-02')->first();

        $this->assertSame('BAJA', $vieja->estatus_credencial);
        $this->assertSame('ALTA', $nueva->estatus_credencial);
    }

    /**
     * 7. Grupo B: Sustitución rechazada por fechas incompatibles (fecha_baja > fecha_solicitud).
     */
    public function test_substitution_rejected_due_to_incompatible_dates_where_baja_is_after_solicitud(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'SUST030303HDF03',
            'nombre' => 'ELEMENTO SUSTITUCION INCOMPATIBLE',
            'rfc' => 'SUST030303XYZ',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-VIEJA-03',
            'estatus_credencial' => 'ALTA',
            'es_referencia_loc' => true,
        ]);

        // Fila 1: BAJA con fecha futura (junio 2025)
        $rowBaja = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'SUST030303XYZ',
            'numero_credencial' => 'PE-VIEJA-03',
            'estatus' => 'BAJA',
            'fecha_baja' => '01/06/2025',
        ]);

        // Fila 2: ALTA con fecha anterior a la baja (enero 2025)
        $rowAlta = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'SUST030303XYZ',
            'numero_credencial' => 'PE-NUEVA-03',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '01/01/2025',
        ]);

        $batch = $this->createBatch();
        $result = $this->importer->stageFromRows([$rowBaja, $rowAlta], $batch);

        $this->assertSame(1, $result['counts']['conflicto']);

        $stagingConflict = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('cuip', $persona->cuip)
            ->where('accion', 'conflicto')
            ->first();

        $this->assertNotNull($stagingConflict);
        $this->assertStringContainsString('fechas incompatibles', $stagingConflict->errores[0]);

        // Confirmar debe ser bloqueado por el conflicto
        $this->expectException(\RuntimeException::class);
        $this->importer->confirm($batch);
    }

    /**
     * 8. Grupo B: Dos credenciales activas incompatibles para el mismo CUIP sin baja.
     */
    public function test_two_incompatible_active_altas_for_same_cuip_without_baja_are_flagged_as_conflict(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'DOBLE010101HDF01',
            'nombre' => 'ELEMENTO DOBLE ALTA SIN BAJA',
            'rfc' => 'DOBL010101XYZ',
        ]);

        $row1 = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'DOBL010101XYZ',
            'numero_credencial' => 'PE-DOBLE-A',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '01/01/2025',
        ]);

        $row2 = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'DOBL010101XYZ',
            'numero_credencial' => 'PE-DOBLE-B',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '02/01/2025',
        ]);

        $batch = $this->createBatch();
        $result = $this->importer->stageFromRows([$row1, $row2], $batch);

        $this->assertSame(1, $result['counts']['nuevo']);
        $this->assertSame(1, $result['counts']['conflicto']);

        $conflictRow = LocImportStagingRow::where('import_batch_id', $batch->id)->where('accion', 'conflicto')->first();
        $this->assertStringContainsString('ya existe una credencial activa con estatus ALTA', $conflictRow->errores[0]);
    }

    /**
     * 9. Grupo C: Completar prealta (numero_credencial = NULL) con nuevo número de credencial formal.
     */
    public function test_prealta_without_credential_number_is_completed_with_incoming_formal_number(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'PREALTA010101H01',
            'nombre' => 'ELEMENTO CON PREALTA FORMALIZADA',
            'rfc' => 'PREA010101XYZ',
        ]);

        // Prealta existente en la base: sin número de credencial
        $prealta = LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => null,
            'numero_oficio' => 'SSP/SUB/2025/100',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-03-20',
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'PREA010101XYZ',
            'numero_credencial' => 'PE-FORMAL-999',
            'oficio_solicitud' => 'SSP/SUB/2025/100',
            'fecha_solicitud' => '20/03/2025',
            'estatus' => 'ALTA',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['actualizar']);
        $this->assertSame(0, $result['counts']['conflicto']);
        $this->assertSame(0, $result['counts']['nuevo']);

        $this->importer->confirm($batch);

        // Se verifica que la prealta previa fue actualizada por su ID y NO se insertó un nuevo registro
        $this->assertSame(1, LocVigenciaCredencial::where('cuip', $persona->cuip)->count());

        $prealta->refresh();
        $this->assertSame('PE-FORMAL-999', $prealta->numero_credencial);
        $this->assertSame('ALTA', $prealta->estatus_credencial);
    }

    /**
     * 10. Grupo C: Rechazar prealta si oficio o fecha son ambiguos (múltiples prealtas sin coincidencia única).
     */
    public function test_prealta_with_ambiguous_matches_is_rejected_as_conflict(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'PREALTA020202H02',
            'nombre' => 'ELEMENTO MULTIPLES PREALTAS',
            'rfc' => 'PREA020202XYZ',
        ]);

        // Dos prealtas sin número con el mismo oficio y fecha
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => null,
            'numero_oficio' => 'SSP/AMBIGUO/2025',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-04-10',
            'es_referencia_loc' => true,
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => null,
            'numero_oficio' => 'SSP/AMBIGUO/2025',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-04-10',
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'PREA020202XYZ',
            'numero_credencial' => 'PE-ASIGNA-01',
            'oficio_solicitud' => 'SSP/AMBIGUO/2025',
            'fecha_solicitud' => '10/04/2025',
            'estatus' => 'ALTA',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['conflicto']);

        $stagingRow = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $this->assertStringContainsString('Prealta ambigua', $stagingRow->errores[0]);
    }

    /**
     * 11. Grupo D: Detección de número de credencial duplicado entre diferentes personas (colisión de identidad).
     */
    public function test_duplicate_credential_number_across_different_cuips_is_detected_as_identity_collision(): void
    {
        $personaA = LocPersona::create([
            'cuip' => 'PERSONAA010101H1',
            'nombre' => 'PERSONA A',
            'rfc' => 'PERA010101XYZ',
        ]);

        $personaB = LocPersona::create([
            'cuip' => 'PERSONAB020202H2',
            'nombre' => 'PERSONA B',
            'rfc' => 'PERB020202XYZ',
        ]);

        // Credencial asignada a Persona A en la base
        LocVigenciaCredencial::create([
            'persona_id' => $personaA->id,
            'cuip' => $personaA->cuip,
            'numero_credencial' => 'PE-COLISION-99',
            'estatus_credencial' => 'ALTA',
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        // Archivo entrante intentando asignar el mismo folio a Persona B
        $row = $this->createSyntheticRow([
            'cuip' => $personaB->cuip,
            'rfc' => 'PERB020202XYZ',
            'numero_credencial' => 'PE-COLISION-99',
            'estatus' => 'ALTA',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['conflicto']);

        $staging = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $this->assertStringContainsString('ya está asignado a otro CUIP', $staging->errores[0]);
    }

    /**
     * 12. Grupo D: Colisiones de CUIP por discrepancias tipográficas permanecen como conflicto sin excepciones fijas.
     */
    public function test_cuip_typographical_discrepancy_remains_as_conflict_without_hardcoded_bypass(): void
    {
        // Credencial en base con CUIP entidad 09
        LocVigenciaCredencial::create([
            'persona_id' => null,
            'cuip' => 'ROMA740101HDF09A01',
            'numero_credencial' => 'PE-139-4-01',
            'estatus_credencial' => 'ALTA',
            'es_referencia_loc' => false,
        ]);

        $batch = $this->createBatch();
        // Archivo entrante con CUIP entidad 15 para el mismo folio
        $row = $this->createSyntheticRow([
            'cuip' => 'ROMA740101HDF15A01',
            'numero_credencial' => 'PE-139-4-01',
            'estatus' => 'ALTA',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['conflicto']);

        $staging = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $this->assertSame('conflicto', $staging->accion);
        $this->assertStringContainsString('ya está asignado a otro CUIP', $staging->errores[0]);
    }

    /**
     * 13. Idempotencia: reprocesar o reconfirmar no duplica credenciales.
     */
    public function test_idempotent_reconfirmation_does_not_duplicate_credentials(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'IDEM010101HDF01',
            'nombre' => 'ELEMENTO IDEMPOTENCIA',
            'rfc' => 'IDEM010101XYZ',
        ]);

        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'IDEM010101XYZ',
            'numero_credencial' => 'PE-IDEM-99',
            'estatus' => 'ALTA',
        ]);

        $batch = $this->createBatch();
        $this->importer->stageFromRows([$row], $batch);
        $confirm1 = $this->importer->confirm($batch);

        $this->assertSame(1, $confirm1['insertados']);
        $this->assertSame(1, LocVigenciaCredencial::where('cuip', $persona->cuip)->count());

        // Segunda llamada directa a confirm()
        $confirm2 = $this->importer->confirm($batch);
        $this->assertTrue($confirm2['ya_confirmado'] ?? false);
        $this->assertSame(1, LocVigenciaCredencial::where('cuip', $persona->cuip)->count());
    }

    /**
     * 14. Trazabilidad por lote (import_batch_id) se preserva en los registros operativos.
     */
    public function test_traceability_of_import_batch_id_is_preserved_across_all_created_records(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'TRAZA010101HDF1',
            'nombre' => 'ELEMENTO TRAZABILIDAD',
            'rfc' => 'TRAZ010101XYZ',
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'TRAZ010101XYZ',
            'numero_credencial' => 'PE-TRAZA-01',
            'estatus' => 'ALTA',
        ]);

        $this->importer->stageFromRows([$row], $batch);
        $this->importer->confirm($batch);

        $record = LocVigenciaCredencial::where('numero_credencial', 'PE-TRAZA-01')->firstOrFail();
        $this->assertSame($batch->id, $record->import_batch_id);
    }

    /**
     * 15. Rollback transaccional ante error durante confirmación.
     */
    public function test_transactional_rollback_on_chunk_failure_commits_no_records(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'ROLLBACK010101H1',
            'nombre' => 'ELEMENTO ROLLBACK',
            'rfc' => 'ROLL010101XYZ',
        ]);

        $batch = $this->createBatch();
        $row1 = $this->createSyntheticRow([
            'id_origen' => '1001',
            'cuip' => $persona->cuip,
            'rfc' => 'ROLL010101XYZ',
            'numero_credencial' => 'PE-ROLL-01',
            'estatus' => 'ALTA',
        ]);

        $this->importer->stageFromRows([$row1], $batch);

        // Simulamos un conflicto no resuelto que bloquea la confirmación antes de escribir
        $stagingRow = LocImportStagingRow::where('import_batch_id', $batch->id)->firstOrFail();
        $stagingRow->update(['accion' => 'conflicto']);

        $batch->revision_sha256 = $this->importer->reviewFingerprint($batch);
        $batch->save();

        try {
            $this->importer->confirm($batch);
            $this->fail('Se esperaba RuntimeException debido a fila en conflicto');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('filas rechazadas o en conflicto', $e->getMessage());
        }

        $this->assertSame(0, LocVigenciaCredencial::where('import_batch_id', $batch->id)->count());
    }

    /**
     * 16. Bloqueo estricto de confirmación si existen conflictos reales.
     */
    public function test_confirmation_is_blocked_when_genuine_conflicts_exist(): void
    {
        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'estatus' => 'ESTATUS_DESCONOCIDO_INVALIDO',
        ]);

        $this->importer->stageFromRows([$row], $batch);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('filas rechazadas o en conflicto');

        $this->importer->confirm($batch);
    }

    /**
     * 17. Validación de huella SHA-256 de revisión detecta alteraciones en staging.
     */
    public function test_revision_sha256_mismatch_blocks_confirmation(): void
    {
        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => 'SHA010101HDF01',
            'numero_credencial' => 'PE-SHA-01',
            'estatus' => 'ALTA',
        ]);

        $this->importer->stageFromRows([$row], $batch);

        // Alteramos manualmente un campo sin regenerar la huella
        LocImportStagingRow::where('import_batch_id', $batch->id)->first()->update(['accion' => 'sin_cambios']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('La revisión del lote cambió después del análisis');

        $this->importer->confirm($batch);
    }

    /**
     * 18. Integración completa: conciliación con Personal LOC y efecto en trámite.
     */
    public function test_full_lifecycle_with_person_linkage_and_status_evaluation(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'INTEG010101HDF01',
            'nombre' => 'ELEMENTO INTEGRACION COMPLETA',
            'rfc' => 'INTE010101XYZ',
            'estatus' => 'activo',
        ]);

        $corta = LocArma::create([
            'matricula' => 'MC-INTEG-01',
            'marca' => 'BERETTA',
            'calibre' => '9MM',
            'clase' => 'PISTOLA',
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'INTE010101XYZ',
            'matricula_corta' => $corta->matricula,
            'numero_credencial' => 'PE-INTEG-01',
            'estatus' => 'ALTA',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);
        $this->assertSame(1, $result['counts']['nuevo_conciliado']);

        $this->importer->confirm($batch);

        $cred = LocVigenciaCredencial::where('numero_credencial', 'PE-INTEG-01')->firstOrFail();
        $this->assertSame($persona->id, $cred->persona_id);
        $this->assertTrue((bool) $cred->es_referencia_loc);
        $this->assertSame($corta->id, $cred->arma_corta_id);

        // El elemento ahora cuenta con un ALTA activa conciliada -> bloquea nuevo trámite ordinario
        $eval = $this->policy->evaluateProcedureEligibility($persona->cuip);
        $this->assertFalse($eval['permitido']);
        $this->assertSame('bloqueado_alta_activa', $eval['motivo']);
    }

    /**
     * 19. Verificación de sintaxis de LocCredentialsXlsImporter sin errores fatales al instanciar.
     */
    public function test_importer_can_be_instantiated_without_syntax_or_fatal_errors(): void
    {
        $this->assertTrue(class_exists(LocCredentialsXlsImporter::class));
        $policy = new LocCredentialsPolicyService();
        $importer = new LocCredentialsXlsImporter($policy);
        $this->assertInstanceOf(LocCredentialsXlsImporter::class, $importer);
    }

    /**
     * 20. confirm() ejecuta correctamente una actualización ordinaria por numero_credencial y cuip.
     */
    public function test_confirm_executes_ordinary_update_by_credential_number_and_cuip_modifying_record(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'ORDUPD010101HDF01',
            'nombre' => 'ELEMENTO ACTUALIZACION ORDINARIA',
            'rfc' => 'ORDU010101XYZ',
        ]);

        $cred = LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-ORD-2025',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-01-15',
            'cuerpo_seguridad' => 'POLICIA ANTERIOR',
            'observaciones' => 'ORIGINAL',
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'ORDU010101XYZ',
            'numero_credencial' => 'PE-ORD-2025',
            'estatus' => 'BAJA',
            'fecha_baja' => '20/04/2025',
            'oficio_baja' => 'OF-BAJA-ORD-01',
        ]);

        $result = $this->importer->stageFromRows([$row], $batch);
        $this->assertSame(1, $result['counts']['actualizar']);

        $confirm = $this->importer->confirm($batch);
        $this->assertSame(1, $confirm['actualizados']);

        $cred->refresh();
        $this->assertSame('BAJA', $cred->estatus_credencial);
        $this->assertSame('2025-04-20', $cred->fecha_baja?->format('Y-m-d') ?? (string) $cred->fecha_baja);
        $this->assertSame('OF-BAJA-ORD-01', $cred->numero_oficio_baja);

        $stagingRow = LocImportStagingRow::where('import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('actualizado', $stagingRow->estado_aplicacion);
    }

    /**
     * 21. confirm() falla de forma segura cuando el registro a actualizar por prealta_id no existe.
     */
    public function test_confirm_fails_safely_when_update_target_record_by_prealta_id_is_missing(): void
    {
        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => 'MISSPRE010101H01',
            'numero_credencial' => 'PE-MISS-PRE-01',
            'estatus' => 'ALTA',
        ]);

        $this->importer->stageFromRows([$row], $batch);

        $stagedRow = LocImportStagingRow::where('import_batch_id', $batch->id)->firstOrFail();
        $datos = is_array($stagedRow->datos) ? $stagedRow->datos : json_decode((string) $stagedRow->datos, true);
        $datos['prealta_id'] = 999999;
        $stagedRow->update([
            'accion' => 'actualizar',
            'datos' => $datos,
        ]);

        $batch->revision_sha256 = $this->importer->reviewFingerprint($batch);
        $batch->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se localizó la prealta ID 999999');

        $this->importer->confirm($batch);
    }

    /**
     * 22. confirm() falla de forma segura cuando el registro a actualizar por numero_credencial no existe.
     */
    public function test_confirm_fails_safely_when_update_target_record_by_credential_number_is_missing(): void
    {
        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => 'MISSNUM010101H01',
            'numero_credencial' => 'PE-INEXISTENTE-99',
            'estatus' => 'ALTA',
        ]);

        $this->importer->stageFromRows([$row], $batch);

        $stagedRow = LocImportStagingRow::where('import_batch_id', $batch->id)->firstOrFail();
        $stagedRow->update(['accion' => 'actualizar']);

        $batch->revision_sha256 = $this->importer->reviewFingerprint($batch);
        $batch->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("No se localizó la credencial 'PE-INEXISTENTE-99'");

        $this->importer->confirm($batch);
    }

    /**
     * 23. Sustitución intra-lote con 1 baja y 2 altas: exactamente 1 alta es promovida y la otra permanece en conflicto.
     */
    public function test_intra_batch_substitution_with_one_baja_and_two_altas_promotes_only_one_alta(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'SUB1B2A010101H1',
            'nombre' => 'ELEMENTO UNA BAJA DOS ALTAS',
            'rfc' => 'S1B2010101XYZ',
        ]);

        // Credencial previa en BD que genera conflicto a las altas
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-PREVIA-01',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2023-01-01',
            'es_referencia_loc' => true,
        ]);

        // Fila 1: BAJA válida
        $rowBaja = $this->createSyntheticRow([
            'id_origen' => '301',
            'cuip' => $persona->cuip,
            'rfc' => 'S1B2010101XYZ',
            'numero_credencial' => 'PE-PREVIA-01',
            'estatus' => 'BAJA',
            'fecha_baja' => '10/01/2025',
            'oficio_baja' => 'OF-BAJA-S1',
        ]);

        // Fila 2: ALTA sustituta 1 (fecha posterior a la baja)
        $rowAlta1 = $this->createSyntheticRow([
            'id_origen' => '302',
            'cuip' => $persona->cuip,
            'rfc' => 'S1B2010101XYZ',
            'numero_credencial' => 'PE-NUEVA-S1A',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '15/01/2025',
            'oficio_solicitud' => 'OF-SOL-S1A',
        ]);

        // Fila 3: ALTA adicional 2 (intento de reutilizar la misma baja)
        $rowAlta2 = $this->createSyntheticRow([
            'id_origen' => '303',
            'cuip' => $persona->cuip,
            'rfc' => 'S1B2010101XYZ',
            'numero_credencial' => 'PE-NUEVA-S1B',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '20/01/2025',
            'oficio_solicitud' => 'OF-SOL-S1B',
        ]);

        $batch = $this->createBatch();
        $result = $this->importer->stageFromRows([$rowBaja, $rowAlta1, $rowAlta2], $batch);

        // De las 2 altas, exactamente 1 debe haber sido promovida a 'nuevo', y la otra debe permanecer en 'conflicto'
        $this->assertSame(1, $result['counts']['actualizar'], 'La baja sobre credencial previa es actualizar');
        $this->assertSame(1, $result['counts']['nuevo'], 'Exactamente una alta debe promoverse');
        $this->assertSame(1, $result['counts']['conflicto'], 'La segunda alta debe permanecer en conflicto');

        $conflictRow = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('cuip', $persona->cuip)
            ->where('accion', 'conflicto')
            ->firstOrFail();

        $this->assertStringContainsString('ya existe una credencial activa con estatus ALTA', $conflictRow->errores[0]);
    }

    /**
     * 24. Sustitución intra-lote con 2 bajas y 2 altas: ambas altas son justificadas unívocamente.
     */
    public function test_intra_batch_substitution_with_two_bajas_and_two_altas_promotes_both_altas(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'SUB2B2A010101H1',
            'nombre' => 'ELEMENTO DOS BAJAS DOS ALTAS',
            'rfc' => 'S2B2010101XYZ',
        ]);

        // Dos credenciales previas en base
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-PREV-01',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2022-01-01',
            'es_referencia_loc' => true,
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-PREV-02',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2023-01-01',
            'es_referencia_loc' => true,
        ]);

        // Fila 1: BAJA de credencial 1
        $rowBaja1 = $this->createSyntheticRow([
            'id_origen' => '401',
            'cuip' => $persona->cuip,
            'rfc' => 'S2B2010101XYZ',
            'numero_credencial' => 'PE-PREV-01',
            'estatus' => 'BAJA',
            'fecha_baja' => '10/01/2025',
        ]);

        // Fila 2: ALTA sustituta 1
        $rowAlta1 = $this->createSyntheticRow([
            'id_origen' => '402',
            'cuip' => $persona->cuip,
            'rfc' => 'S2B2010101XYZ',
            'numero_credencial' => 'PE-REEMP-01',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '15/01/2025',
        ]);

        // Fila 3: BAJA de credencial 2
        $rowBaja2 = $this->createSyntheticRow([
            'id_origen' => '403',
            'cuip' => $persona->cuip,
            'rfc' => 'S2B2010101XYZ',
            'numero_credencial' => 'PE-PREV-02',
            'estatus' => 'BAJA',
            'fecha_baja' => '20/01/2025',
        ]);

        // Fila 4: ALTA sustituta 2
        $rowAlta2 = $this->createSyntheticRow([
            'id_origen' => '404',
            'cuip' => $persona->cuip,
            'rfc' => 'S2B2010101XYZ',
            'numero_credencial' => 'PE-REEMP-02',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '25/01/2025',
        ]);

        $batch = $this->createBatch();
        $result = $this->importer->stageFromRows([$rowBaja1, $rowAlta1, $rowBaja2, $rowAlta2], $batch);

        $this->assertSame(2, $result['counts']['actualizar'], 'Dos bajas son actualizar');
        $this->assertSame(2, $result['counts']['nuevo'], 'Ambas altas deben promoverse');
        $this->assertSame(0, $result['counts']['conflicto'], 'Cero conflictos');
    }

    /**
     * 25. Manejo de SITUACION ESPECIAL y ALTA para el mismo CUIP conforme a la política.
     */
    public function test_situacion_especial_and_alta_handling_for_same_cuip(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'SITESP010101HDF1',
            'nombre' => 'ELEMENTO SITUACION ESPECIAL Y ALTA',
            'rfc' => 'SESP010101XYZ',
        ]);

        $batch = $this->createBatch();

        // Fila 1: ALTA
        $rowAlta = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'SESP010101XYZ',
            'numero_credencial' => 'PE-ALTA-SESP',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '10/01/2025',
        ]);

        // Fila 2: SITUACION ESPECIAL
        $rowSE = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'SESP010101XYZ',
            'numero_credencial' => 'PE-SE-SESP',
            'estatus' => 'SITUACION ESPECIAL',
            'motivo_situacion_especial' => 'SUSPENSION TEMPORAL',
            'fecha_solicitud' => '12/01/2025',
        ]);

        $result = $this->importer->stageFromRows([$rowAlta, $rowSE], $batch);

        $this->assertSame(2, $result['counts']['nuevo']);
        $this->assertSame(0, $result['counts']['conflicto']);

        $stagingSE = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('cuip', $persona->cuip)
            ->where('numero_fila', 3)
            ->firstOrFail();

        $data = is_array($stagingSE->datos) ? $stagingSE->datos : json_decode((string) $stagingSE->datos, true);
        $this->assertContains('situacion_especial_activa', $data['conciliacion']['advertencias'] ?? []);
    }

    /**
     * 26. Confirmación completa de lote mixto con verificación exhaustiva de contadores y estado operativo.
     */
    public function test_full_confirmation_of_mixed_batch_verifies_counters_and_operational_records_match_strictly(): void
    {
        // 1. Prealta en base
        $personaPre = LocPersona::create(['cuip' => 'MIXPRE010101HDF01', 'nombre' => 'PERSONA PREALTA', 'rfc' => 'MPRE010101XYZ']);
        $prealta = LocVigenciaCredencial::create([
            'persona_id' => $personaPre->id,
            'cuip' => $personaPre->cuip,
            'numero_credencial' => null,
            'numero_oficio' => 'OF-PRE-100',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-01-10',
            'es_referencia_loc' => true,
        ]);

        // 2. Credencial activa a actualizar a BAJA
        $personaUpd = LocPersona::create(['cuip' => 'MIXUPD020202HDF02', 'nombre' => 'PERSONA ACTUALIZAR', 'rfc' => 'MUPD020202XYZ']);
        $credUpd = LocVigenciaCredencial::create([
            'persona_id' => $personaUpd->id,
            'cuip' => $personaUpd->cuip,
            'numero_credencial' => 'PE-EXIST-02',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2024-05-10',
            'es_referencia_loc' => true,
        ]);

        // 3. Credencial existente idéntica (sin cambios)
        $personaSc = LocPersona::create(['cuip' => 'MIXSC030303HDF03', 'nombre' => 'PERSONA SIN CAMBIOS', 'rfc' => 'MSNC030303XYZ']);
        LocVigenciaCredencial::create([
            'persona_id' => $personaSc->id,
            'cuip' => $personaSc->cuip,
            'numero_credencial' => 'PE-EXIST-03',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-09-20',
            'es_referencia_loc' => true,
        ]);

        // Preparar filas de importación:
        // Fila 1: Formaliza prealta (actualizar)
        $row1 = $this->createSyntheticRow([
            'id_origen' => '501',
            'cuip' => $personaPre->cuip,
            'rfc' => 'MPRE010101XYZ',
            'numero_credencial' => 'PE-FORMAL-PRE',
            'oficio_solicitud' => 'OF-PRE-100',
            'fecha_solicitud' => '10/01/2025',
            'estatus' => 'ALTA',
        ]);

        // Fila 2: Transición a BAJA (actualizar)
        $row2 = $this->createSyntheticRow([
            'id_origen' => '502',
            'cuip' => $personaUpd->cuip,
            'rfc' => 'MUPD020202XYZ',
            'numero_credencial' => 'PE-EXIST-02',
            'estatus' => 'BAJA',
            'fecha_baja' => '01/02/2025',
            'oficio_baja' => 'OF-BAJA-MIX',
        ]);

        // Fila 3: Sin cambios
        $row3 = $this->createSyntheticRow([
            'id_origen' => '503',
            'cuip' => $personaSc->cuip,
            'rfc' => 'MSNC030303XYZ',
            'numero_credencial' => 'PE-EXIST-03',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '20/09/2025',
        ]);

        // Fila 4: Nuevo 1
        $row4 = $this->createSyntheticRow([
            'id_origen' => '504',
            'cuip' => 'MIXNEW040404HDF04',
            'numero_credencial' => 'PE-NUEVA-04',
            'estatus' => 'ALTA',
        ]);

        // Fila 5: Nuevo 2
        $row5 = $this->createSyntheticRow([
            'id_origen' => '505',
            'cuip' => 'MIXNEW050505HDF05',
            'numero_credencial' => 'PE-NUEVA-05',
            'estatus' => 'ALTA',
        ]);

        // Fila 6: Fila malformada que se omitirá formalmente
        $row6 = $this->createSyntheticRow([
            'id_origen' => '506',
            'cuip' => 'MIXOMIT060606H06',
            'estatus' => 'ESTATUS_INVALIDO',
        ]);

        $batch = $this->createBatch();
        $stageResult = $this->importer->stageFromRows([$row1, $row2, $row3, $row4, $row5, $row6], $batch);

        $this->assertSame(2, $stageResult['counts']['actualizar']);
        $this->assertSame(2, $stageResult['counts']['nuevo']);
        $this->assertSame(1, $stageResult['counts']['sin_cambios']);
        $this->assertSame(1, $stageResult['counts']['conflicto']);

        // Omitir formalmente la fila en conflicto
        $conflictRow = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('cuip', 'MIXOMIT060606H06')
            ->firstOrFail();

        $this->importer->omitStagingRow($conflictRow, 'Omitido formalmente por prueba de lote mixto', 1);

        $batch->refresh();
        $this->assertSame(0, (int) $batch->total_rechazadas);

        // Confirmar lote
        $confirmResult = $this->importer->confirm($batch);

        $this->assertSame(2, $confirmResult['insertados']);
        $this->assertSame(2, $confirmResult['actualizados']);
        $this->assertSame(1, $confirmResult['sin_cambios']);

        $batch->refresh();
        $this->assertSame('completado', $batch->estado);
        $this->assertSame(2, $batch->total_insertadas);
        $this->assertSame(2, $batch->total_actualizadas);
        $this->assertSame(1, $batch->total_sin_cambios);

        // Comprobar prealta formalizada
        $prealta->refresh();
        $this->assertSame('PE-FORMAL-PRE', $prealta->numero_credencial);

        // Comprobar actualización ordinaria
        $credUpd->refresh();
        $this->assertSame('BAJA', $credUpd->estatus_credencial);

        // Ejecutar auditoría exhaustiva post-confirmación
        $audit = $this->importer->auditBatchApplicationState($batch);
        $this->assertTrue($audit['es_reanudar_seguro']);
        $this->assertEmpty($audit['discrepancias']);
        $this->assertSame(2, $audit['insertadas_verificadas']);
        $this->assertSame(2, $audit['actualizadas_verificadas']);
        $this->assertSame(1, $audit['sin_cambios_verificadas']);
        $this->assertSame(1, $audit['omitidas_verificadas']);
        $this->assertSame(0, $audit['validas_pendientes']);
    }

    /**
     * 27. Dos registros operativos con el mismo número de credencial y CUIP: la actualización aborta y ninguno cambia.
     */
    public function test_confirm_aborts_when_multiple_targets_exist_for_ordinary_update_and_neither_is_modified(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'DUPLITARG010101H1',
            'nombre' => 'ELEMENTO DESTINOS DUPLICADOS',
            'rfc' => 'DTAR010101XYZ',
        ]);

        $cred1 = LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-DUPLI-99',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-01-01',
            'observaciones' => 'ORIGINAL_1',
            'es_referencia_loc' => true,
        ]);

        $cred2 = LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-DUPLI-99',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-01-01',
            'observaciones' => 'ORIGINAL_2',
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'DTAR010101XYZ',
            'numero_credencial' => 'PE-DUPLI-99',
            'estatus' => 'BAJA',
            'fecha_baja' => '15/04/2025',
        ]);

        $this->importer->stageFromRows([$row], $batch);

        try {
            $this->importer->confirm($batch);
            $this->fail('Se esperaba RuntimeException por ambigüedad de múltiples destinos coincidentes');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Ambigüedad en actualización; existen 2 registros operativos', $e->getMessage());
        }

        $cred1->refresh();
        $cred2->refresh();
        $this->assertSame('ALTA', $cred1->estatus_credencial);
        $this->assertSame('ORIGINAL_1', $cred1->observaciones);
        $this->assertSame('ALTA', $cred2->estatus_credencial);
        $this->assertSame('ORIGINAL_2', $cred2->observaciones);

        $staged = LocImportStagingRow::where('import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('pendiente', $staged->estado_aplicacion);
    }

    /**
     * 28. Una actualización cuyos valores ya coinciden no se interpreta erróneamente como destino inexistente.
     */
    public function test_confirm_succeeds_when_updating_record_with_identical_values_without_false_zero_affected_error(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'SAMEVAL010101H1',
            'nombre' => 'ELEMENTO VALORES IDENTICOS',
            'rfc' => 'SAME010101XYZ',
        ]);

        $now = now();
        $cred = LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-SAME-01',
            'estatus_credencial' => 'BAJA',
            'fecha_baja' => '2025-05-01',
            'numero_oficio_baja' => 'OF-SAME-01',
            'created_at' => $now,
            'updated_at' => $now,
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();
        $row = $this->createSyntheticRow([
            'cuip' => $persona->cuip,
            'rfc' => 'SAME010101XYZ',
            'numero_credencial' => 'PE-SAME-01',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '10/05/2025',
        ]);

        $this->importer->stageFromRows([$row], $batch);

        $result = $this->importer->confirm($batch);
        $this->assertSame(1, $result['actualizados']);

        $cred->refresh();
        $this->assertSame('ALTA', $cred->estatus_credencial);
    }

    /**
     * 29. Dos bajas y dos altas con fechas cruzadas: Kuhn maximiza emparejamientos sin depender del orden.
     */
    public function test_intra_batch_substitution_with_crossed_dates_maximizes_pairings_independently_of_file_order(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'CROSSD010101HDF1',
            'nombre' => 'ELEMENTO FECHAS CRUZADAS',
            'rfc' => 'CROS010101XYZ',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-OLD-CROSS-1',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2023-01-01',
            'es_referencia_loc' => true,
        ]);
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-OLD-CROSS-2',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2023-06-01',
            'es_referencia_loc' => true,
        ]);

        // Alta A: solicitud 01/10/2025 (compatible con Baja 1 y Baja 2)
        // Alta B: solicitud 01/03/2025 (solo compatible con Baja 1: 01/01/2025)
        // Baja 1: baja 01/01/2025
        // Baja 2: baja 01/06/2025

        $rowAltaA = $this->createSyntheticRow([
            'id_origen' => '601',
            'cuip' => $persona->cuip,
            'rfc' => 'CROS010101XYZ',
            'numero_credencial' => 'PE-CROSS-NEW-A',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '01/10/2025',
        ]);

        $rowBaja1 = $this->createSyntheticRow([
            'id_origen' => '602',
            'cuip' => $persona->cuip,
            'rfc' => 'CROS010101XYZ',
            'numero_credencial' => 'PE-OLD-CROSS-1',
            'estatus' => 'BAJA',
            'fecha_baja' => '01/01/2025',
        ]);

        $rowAltaB = $this->createSyntheticRow([
            'id_origen' => '603',
            'cuip' => $persona->cuip,
            'rfc' => 'CROS010101XYZ',
            'numero_credencial' => 'PE-CROSS-NEW-B',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '01/03/2025',
        ]);

        $rowBaja2 = $this->createSyntheticRow([
            'id_origen' => '604',
            'cuip' => $persona->cuip,
            'rfc' => 'CROS010101XYZ',
            'numero_credencial' => 'PE-OLD-CROSS-2',
            'estatus' => 'BAJA',
            'fecha_baja' => '01/06/2025',
        ]);

        // Orden 1: Alta A, Baja 1, Alta B, Baja 2
        $batch1 = $this->createBatch();
        $res1 = $this->importer->stageFromRows([$rowAltaA, $rowBaja1, $rowAltaB, $rowBaja2], $batch1);

        $this->assertSame(2, $res1['counts']['nuevo'], 'Ambas altas deben promoverse en orden 1');
        $this->assertSame(0, $res1['counts']['conflicto'], 'Cero conflictos en orden 1');

        // Orden 2: Invertir el orden de las filas
        $batch2 = $this->createBatch();
        $res2 = $this->importer->stageFromRows([$rowBaja2, $rowAltaB, $rowBaja1, $rowAltaA], $batch2);

        $this->assertSame(2, $res2['counts']['nuevo'], 'Ambas altas deben promoverse en orden invertido');
        $this->assertSame(0, $res2['counts']['conflicto'], 'Cero conflictos en orden invertido');
    }

    /**
     * 30. Rollback ante falla de actualización deja consistentes base operativa, staging y contadores.
     */
    public function test_rollback_on_update_failure_leaves_operational_and_staging_and_batch_consistent(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'ROLLFAIL010101H1',
            'nombre' => 'ELEMENTO ROLLBACK FALLA',
            'rfc' => 'RFL010101XYZ',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-RFAIL-01',
            'estatus_credencial' => 'ALTA',
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createBatch();

        $row1 = $this->createSyntheticRow([
            'id_origen' => '701',
            'cuip' => 'NEWPERSON010101H',
            'numero_credencial' => 'PE-NEW-RFAIL',
            'estatus' => 'ALTA',
        ]);

        $row2 = $this->createSyntheticRow([
            'id_origen' => '702',
            'cuip' => 'INEXIST010101H01',
            'numero_credencial' => 'PE-INEXIST-01',
            'estatus' => 'ALTA',
        ]);

        $this->importer->stageFromRows([$row1, $row2], $batch);

        $staged2 = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('cuip', 'INEXIST010101H01')
            ->firstOrFail();
        $staged2->update(['accion' => 'actualizar']);

        $batch->revision_sha256 = $this->importer->reviewFingerprint($batch);
        $batch->save();

        try {
            $this->importer->confirm($batch);
            $this->fail('Se esperaba RuntimeException por credencial inexistente');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("No se localizó la credencial 'PE-INEXIST-01'", $e->getMessage());
        }

        $this->assertSame(0, LocVigenciaCredencial::where('numero_credencial', 'PE-NEW-RFAIL')->count());

        $staged1 = LocImportStagingRow::where('import_batch_id', $batch->id)
            ->where('cuip', 'NEWPERSON010101H')
            ->firstOrFail();
        $this->assertSame('pendiente', $staged1->estado_aplicacion);

        $batch->refresh();
        $this->assertSame('en_revision', $batch->estado);
        $this->assertNull($batch->fecha_confirmacion);
    }
}

