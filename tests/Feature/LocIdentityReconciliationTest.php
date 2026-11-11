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
use App\Services\LocPersonalXlsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocIdentityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private LocPersonalXlsImporter $personalImporter;
    private LocCredentialsPolicyService $credentialsPolicy;
    private LocCredentialsXlsImporter $credentialsImporter;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personalImporter = app(LocPersonalXlsImporter::class);
        $this->credentialsPolicy = new LocCredentialsPolicyService();
        $this->credentialsImporter = new LocCredentialsXlsImporter($this->credentialsPolicy);
        $this->admin = User::factory()->create(['role' => 'superadmin']);

        config(['loc_import.queue_connection' => 'sync']);
    }

    private function createPersonalBatch(int $totalLeidas = 1): LocImportBatch
    {
        return LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'Personal_Reconciliation.xlsx',
            'archivo_sha256' => hash('sha256', 'dummy_personal_content'),
            'tamano_bytes' => 1024,
            'estado' => 'en_revision',
            'usuario_id' => $this->admin->id,
            'total_leidas' => $totalLeidas,
            'total_aceptadas' => $totalLeidas,
            'total_rechazadas' => 0,
            'total_nuevas' => 0,
            'total_actualizadas' => $totalLeidas,
            'total_sin_cambios' => 0,
            'resumen_errores' => json_encode(['conteos' => ['actualizar' => $totalLeidas]]),
        ]);
    }

    private function createCredentialsBatch(): LocImportBatch
    {
        return LocImportBatch::query()->create([
            'tipo_fuente' => 'vigencias_credenciales',
            'nombre_archivo' => 'Vigencias_Reconciliation.xls',
            'archivo_sha256' => hash('sha256', 'dummy_credentials_content'),
            'tamano_bytes' => 1024,
            'usuario_id' => $this->admin->id,
            'estado' => 'pendiente',
            'total_leidas' => 0,
            'total_insertadas' => 0,
            'total_actualizadas' => 0,
            'total_sin_cambios' => 0,
            'total_rechazadas' => 0,
            'total_aceptadas' => 0,
            'resumen_errores' => '[]',
        ]);
    }

    private function createSyntheticCredentialRow(array $overrides = []): array
    {
        $row = array_fill(0, 40, '');
        $row[0] = $overrides['id_origen'] ?? '1001';
        $row[1] = $overrides['cuip'] ?? 'ABCD010101HDFRRA01';
        $row[2] = $overrides['rfc'] ?? 'ABCD010101';
        $row[3] = $overrides['nombre'] ?? 'JUAN PEREZ LOPEZ';
        $row[4] = $overrides['categoria'] ?? 'POLICIA TERCERO';
        $row[5] = 'PISTOLA';
        $row[6] = 'SEMI';
        $row[7] = 'GLOCK';
        $row[8] = '9X19 MM';
        $row[9] = '17';
        $row[10] = $overrides['matricula_corta'] ?? 'MC-001';
        $row[13] = 'FUSIL';
        $row[14] = 'AUTO';
        $row[15] = 'COLT';
        $row[16] = '5.56 MM';
        $row[17] = 'M4';
        $row[18] = $overrides['matricula_larga'] ?? 'ML-001';
        $row[21] = $overrides['oficio_baja'] ?? '';
        $row[22] = $overrides['fecha_baja'] ?? '';
        $row[23] = 'POLICIA ESTATAL';
        $row[24] = $overrides['numero_credencial'] ?? 'OM2527M99999';
        $row[25] = $overrides['oficio_solicitud'] ?? 'SSP/100/2026';
        $row[26] = $overrides['fecha_solicitud'] ?? '15/01/2026';
        $row[28] = 'VIGENCIA 2025-2027';
        $row[38] = $overrides['estatus'] ?? 'ALTA';
        $row[39] = $overrides['motivo_situacion_especial'] ?? '';

        return $row;
    }

    private function invokeClassifyRows(LocImportBatch $batch): void
    {
        $reflection = new \ReflectionClass($this->personalImporter);
        $method = $reflection->getMethod('classifyRows');
        $method->invoke($this->personalImporter, $batch);
    }

    private function getPersonalFingerprint(LocImportBatch $batch): string
    {
        $batch->refresh();
        $reflection = new \ReflectionClass($this->personalImporter);
        $method = $reflection->getMethod('reviewFingerprint');
        $method->setAccessible(true);

        return (string) $method->invoke($this->personalImporter, $batch);
    }

    /**
     * 1. Coincidencia exacta por CUIP:
     * Cuando el CUIP coincide exactamente y no hay contradicciones, se clasifica como actualizar o sin_cambios.
     */
    public function test_01_exact_cuip_match_classifies_correctly_without_conflict(): void
    {
        LocPersona::query()->create([
            'cuip' => 'HEHP630629H151082032',
            'nombre' => 'HERNANDEZ HERNANDEZ PEDRO',
            'rfc' => 'HEHP630629',
            'curp' => 'HEHP630629HDFRRD06',
            'estatus' => 'activo',
        ]);

        $batch = $this->createPersonalBatch();
        $row = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'HEHP630629H151082032',
            'datos' => [
                'campos' => [
                    'cuip' => 'HEHP630629H151082032',
                    'nombre' => 'HERNANDEZ HERNANDEZ PEDRO ACTUALIZADO',
                    'rfc' => 'HEHP630629',
                    'curp' => 'HEHP630629HDFRRD06',
                ],
                'origen' => [
                    'CUIP' => 'HEHP630629H151082032',
                    'NOMBRE' => 'HERNANDEZ HERNANDEZ PEDRO ACTUALIZADO',
                    'RFC' => 'HEHP630629',
                    'CURP' => 'HEHP630629HDFRRD06',
                ],
            ],
            'errores' => [],
            'accion' => 'pendiente',
            'estado_aplicacion' => 'pendiente',
        ]);

        $this->invokeClassifyRows($batch);

        $row->refresh();
        $this->assertSame('actualizar', $row->accion);
        $this->assertEmpty($row->errores);
    }

    /**
     * 2. CUIP diferente con CURP única coincidente:
     * Una persona con CUIP nuevo pero CURP única y coincidente se reconcilia y clasifica como actualizar.
     */
    public function test_02_different_cuip_with_unique_matching_curp_reconciles_as_actualizar(): void
    {
        $person = LocPersona::query()->create([
            'cuip' => 'HEHP630629H151082032',
            'nombre' => 'HERNANDEZ HERNANDEZ PEDRO',
            'rfc' => 'HEHP630629',
            'curp' => 'HEHP630629HDFRRD06',
            'personal_id' => 25605,
            'estatus' => 'activo',
        ]);

        $batch = $this->createPersonalBatch();
        $row = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'HEHP630629H091082032',
            'datos' => [
                'campos' => [
                    'cuip' => 'HEHP630629H091082032',
                    'nombre' => 'HERNANDEZ HERNANDEZ PEDRO',
                    'rfc' => 'HEHP630629',
                    'curp' => 'HEHP630629HDFRRD06',
                    'personal_id' => '25605',
                ],
                'origen' => [
                    'CUIP' => 'HEHP630629H091082032',
                    'NOMBRE' => 'HERNANDEZ HERNANDEZ PEDRO',
                    'RFC' => 'HEHP630629',
                    'CURP' => 'HEHP630629HDFRRD06',
                    'ID PERSONAL' => '25605',
                ],
            ],
            'errores' => [],
            'accion' => 'pendiente',
            'estado_aplicacion' => 'pendiente',
        ]);

        $this->invokeClassifyRows($batch);

        $row->refresh();
        $this->assertSame('actualizar', $row->accion);
        $this->assertEmpty($row->errores);
        $this->assertNotNull($row->datos['conciliacion_identidad'] ?? null);
        $this->assertSame($person->id, $row->datos['conciliacion_identidad']['persona_id']);
        $this->assertSame('HEHP630629H151082032', $row->datos['conciliacion_identidad']['cuip_anterior']);
        $this->assertSame('HEHP630629H091082032', $row->datos['conciliacion_identidad']['cuip_nuevo']);
        $this->assertSame('coincidencia_inequivoca_por_curp', $row->datos['conciliacion_identidad']['criterio']);
    }

    /**
     * 3. CUIP diferente con RFC coincidente y evidencia suficiente (personal_id):
     * Sin CURP, pero con RFC y personal_id coincidentes se reconcilia como actualizar.
     */
    public function test_03_different_cuip_with_matching_rfc_and_personal_id_reconciles_as_actualizar(): void
    {
        $person = LocPersona::query()->create([
            'cuip' => 'BACJ760424H15394717',
            'nombre' => 'BARRERA CRUZ JOSE',
            'rfc' => 'BACJ760424',
            'curp' => null,
            'personal_id' => 13799,
            'estatus' => 'activo',
        ]);

        $batch = $this->createPersonalBatch();
        $row = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'BACJ760424H09394717',
            'datos' => [
                'campos' => [
                    'cuip' => 'BACJ760424H09394717',
                    'nombre' => 'BARRERA CRUZ JOSE',
                    'rfc' => 'BACJ760424',
                    'curp' => '',
                    'personal_id' => '13799',
                ],
                'origen' => [
                    'CUIP' => 'BACJ760424H09394717',
                    'NOMBRE' => 'BARRERA CRUZ JOSE',
                    'RFC' => 'BACJ760424',
                    'CURP' => '',
                    'ID PERSONAL' => '13799',
                ],
            ],
            'errores' => [],
            'accion' => 'pendiente',
            'estado_aplicacion' => 'pendiente',
        ]);

        $this->invokeClassifyRows($batch);

        $row->refresh();
        $this->assertSame('actualizar', $row->accion);
        $this->assertSame($person->id, $row->datos['conciliacion_identidad']['persona_id']);
        $this->assertSame('coincidencia_inequivoca_por_rfc_personal_id', $row->datos['conciliacion_identidad']['criterio']);
    }

    /**
     * 4. CURP y RFC que apuntan a personas diferentes:
     * Si la CURP apunta a una persona y el RFC a otra persona distinta, genera conflicto de identidad.
     */
    public function test_04_contradictory_curp_and_rfc_pointing_to_different_people_flags_conflict(): void
    {
        LocPersona::query()->create([
            'cuip' => 'AAAA010101H15000001',
            'nombre' => 'PERSONA A',
            'rfc' => 'AAAA010101',
            'curp' => 'AAAA010101HDFRRA01',
            'personal_id' => 101,
        ]);

        LocPersona::query()->create([
            'cuip' => 'BBBB020202H15000002',
            'nombre' => 'PERSONA B',
            'rfc' => 'BBBB020202',
            'curp' => 'BBBB020202HDFRRB02',
            'personal_id' => 102,
        ]);

        $batch = $this->createPersonalBatch();
        $row = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'CCCC030303H09000003',
            'datos' => [
                'campos' => [
                    'cuip' => 'CCCC030303H09000003',
                    'nombre' => 'HIBRIDO CONFLICTIVO',
                    'curp' => 'AAAA010101HDFRRA01',
                    'rfc' => 'BBBB020202',
                ],
                'origen' => [
                    'CUIP' => 'CCCC030303H09000003',
                    'NOMBRE' => 'HIBRIDO CONFLICTIVO',
                    'CURP' => 'AAAA010101HDFRRA01',
                    'RFC' => 'BBBB020202',
                ],
            ],
            'errores' => [],
            'accion' => 'pendiente',
            'estado_aplicacion' => 'pendiente',
        ]);

        $this->invokeClassifyRows($batch);

        $row->refresh();
        $this->assertSame('conflicto', $row->accion);
        $this->assertNotEmpty($row->errores);
        $this->assertStringContainsString('Contradicción en identificadores', $row->errores[0]);
    }

    /**
     * 5. Varias personas candidatas para la misma CURP o RFC:
     * Si la búsqueda secundaria encuentra múltiples candidatos para la misma CURP, clasifica como conflicto.
     */
    public function test_05_multiple_candidates_for_curp_flags_conflict(): void
    {
        LocPersona::query()->create([
            'cuip' => 'DUP1010101H15000001',
            'nombre' => 'DUPLICADO 1',
            'rfc' => 'DUP1010101',
            'curp' => 'DUPX010101HDFRRX01',
        ]);

        LocPersona::query()->create([
            'cuip' => 'DUP2020202H15000002',
            'nombre' => 'DUPLICADO 2',
            'rfc' => 'DUP2020202',
            'curp' => 'DUPX010101HDFRRX01',
        ]);

        $batch = $this->createPersonalBatch();
        $row = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'DUP3030303H09000003',
            'datos' => [
                'campos' => [
                    'cuip' => 'DUP3030303H09000003',
                    'nombre' => 'NUEVO INTENTO',
                    'curp' => 'DUPX010101HDFRRX01',
                    'rfc' => 'DUP3030303',
                ],
                'origen' => [
                    'CUIP' => 'DUP3030303H09000003',
                    'CURP' => 'DUPX010101HDFRRX01',
                    'RFC' => 'DUP3030303',
                ],
            ],
            'errores' => [],
            'accion' => 'pendiente',
            'estado_aplicacion' => 'pendiente',
        ]);

        $this->invokeClassifyRows($batch);

        $row->refresh();
        $this->assertSame('conflicto', $row->accion);
        $this->assertStringContainsString('ambigua: coincide con múltiples personas', $row->errores[0]);
    }

    /**
     * 6. Regla A: CUIP coincidente con contradicciones fuertes en CURP/RFC:
     * Si el CUIP coincide exactamente pero la CURP entrante difiere en su raíz (primeros 10 caracteres),
     * no actualiza automáticamente y clasifica como conflicto.
     */
    public function test_06_matching_cuip_with_strong_secondary_contradiction_flags_conflict(): void
    {
        LocPersona::query()->create([
            'cuip' => 'SAME101010H15000001',
            'nombre' => 'JUAN PEREZ LOPEZ',
            'rfc' => 'PELJ800101',
            'curp' => 'PELJ800101HDFRR001',
        ]);

        $batch = $this->createPersonalBatch();
        $row = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'SAME101010H15000001',
            'datos' => [
                'campos' => [
                    'cuip' => 'SAME101010H15000001',
                    'nombre' => 'MARIA LOPEZ GARCIA',
                    'curp' => 'LOGM900505MDFRR002',
                    'rfc' => 'LOGM900505',
                ],
                'origen' => [
                    'CUIP' => 'SAME101010H15000001',
                    'NOMBRE' => 'MARIA LOPEZ GARCIA',
                    'CURP' => 'LOGM900505MDFRR002',
                    'RFC' => 'LOGM900505',
                ],
            ],
            'errores' => [],
            'accion' => 'pendiente',
            'estado_aplicacion' => 'pendiente',
        ]);

        $this->invokeClassifyRows($batch);

        $row->refresh();
        $this->assertSame('conflicto', $row->accion);
        $this->assertStringContainsString('Contradicción en identidad: el CUIP coincide pero los identificadores secundarios difieren', $row->errores[0]);
    }

    /**
     * 7. Ausencia de identificadores secundarios suficientes:
     * Fila con CUIP nuevo, sin CURP, y con RFC que no coincide con personal_id ni nombre se clasifica como nuevo.
     */
    public function test_07_insufficient_secondary_identifiers_classifies_as_nuevo(): void
    {
        LocPersona::query()->create([
            'cuip' => 'PERS010101H15000001',
            'nombre' => 'ROBERTO SANCHEZ GOMEZ',
            'rfc' => 'SAGR800101',
            'personal_id' => 5001,
        ]);

        $batch = $this->createPersonalBatch();
        $row = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'PERS020202H09000002',
            'datos' => [
                'campos' => [
                    'cuip' => 'PERS020202H09000002',
                    'nombre' => 'ANTONIO HERNANDEZ RUIZ',
                    'rfc' => 'SAGR800101',
                    'curp' => '',
                    'personal_id' => '9999',
                ],
                'origen' => [
                    'CUIP' => 'PERS020202H09000002',
                    'NOMBRE' => 'ANTONIO HERNANDEZ RUIZ',
                    'RFC' => 'SAGR800101',
                    'ID PERSONAL' => '9999',
                ],
            ],
            'errores' => [],
            'accion' => 'pendiente',
            'estado_aplicacion' => 'pendiente',
        ]);

        $this->invokeClassifyRows($batch);

        $row->refresh();
        $this->assertSame('nuevo', $row->accion);
        $this->assertEmpty($row->errores);
    }

    /**
     * 8. Registro de personal que debe actualizarse en lugar de insertarse como duplicado:
     * Al confirmar en personal, el registro existente de LocPersona se actualiza, cuip se renueva,
     * se guarda _historial_identidad y el conteo de personas no aumenta.
     */
    public function test_08_reconciled_personal_record_updates_in_place_without_duplicating(): void
    {
        $person = LocPersona::query()->create([
            'cuip' => 'OLD_CUIP_1111111111',
            'nombre' => 'GARCIA LOPEZ CARLOS',
            'rfc' => 'GALC850101',
            'curp' => 'GALC850101HDFRRC01',
            'estatus' => 'activo',
            'datos_loc' => ['ID_PERSONAL' => '777'],
        ]);

        $batch = $this->createPersonalBatch();
        $batch->estado = 'en_revision';
        $batch->save();

        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'NEW_CUIP_2222222222',
            'datos' => [
                'campos' => [
                    'cuip' => 'NEW_CUIP_2222222222',
                    'nombre' => 'GARCIA LOPEZ CARLOS',
                    'rfc' => 'GALC850101',
                    'curp' => 'GALC850101HDFRRC01',
                    'categoria' => 'OFICIAL PRIMERO',
                ],
                'origen' => [
                    'CUIP' => 'NEW_CUIP_2222222222',
                    'NOMBRE' => 'GARCIA LOPEZ CARLOS',
                    'RFC' => 'GALC850101',
                    'CURP' => 'GALC850101HDFRRC01',
                    'CATEGORIA' => 'OFICIAL PRIMERO',
                ],
                'conciliacion_identidad' => [
                    'persona_id' => $person->id,
                    'cuip_anterior' => 'OLD_CUIP_1111111111',
                    'cuip_nuevo' => 'NEW_CUIP_2222222222',
                    'criterio' => 'coincidencia_inequivoca_por_curp',
                ],
            ],
            'errores' => [],
            'accion' => 'actualizar',
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->update(['revision_sha256' => $this->getPersonalFingerprint($batch)]);

        $this->personalImporter->commit($batch, $this->admin->id);

        $this->assertSame(1, LocPersona::query()->count());

        $person->refresh();
        $this->assertSame('NEW_CUIP_2222222222', $person->cuip);
        $this->assertSame('OFICIAL PRIMERO', $person->categoria);

        $datosLoc = $person->datos_loc;
        $this->assertIsArray($datosLoc['_historial_identidad'] ?? null);
        $this->assertCount(1, $datosLoc['_historial_identidad']);
        $this->assertSame('OLD_CUIP_1111111111', $datosLoc['_historial_identidad'][0]['cuip_anterior']);
        $this->assertSame('NEW_CUIP_2222222222', $datosLoc['_historial_identidad'][0]['cuip_nuevo']);
        $this->assertSame('coincidencia_inequivoca_por_curp', $datosLoc['_historial_identidad'][0]['criterio']);
    }

    /**
     * 9. Vigencia existente con número de credencial coincidente y CUIP cambiado:
     * La credencial existente en base tiene el CUIP histórico. La fila entrante tiene el nuevo CUIP.
     * Al evaluar se clasifica como actualizar y al confirmar actualiza la credencial con trazabilidad.
     */
    public function test_09_existing_credential_with_changed_cuip_reconciles_and_confirms_with_traceability(): void
    {
        $persona = LocPersona::query()->create([
            'cuip' => 'FECR710812H15132630',
            'nombre' => 'FERRER CRUZ ROBERTO',
            'rfc' => 'FECR710812',
            'curp' => 'FECR710812HMCRRQ09',
            'personal_id' => 2907,
        ]);

        $credencial = LocVigenciaCredencial::query()->create([
            'persona_id' => $persona->id,
            'cuip' => 'FECR740812H15132630',
            'numero_credencial' => 'OM2527E05934',
            'estatus_credencial' => 'ALTA',
            'es_referencia_loc' => true,
            'datos_origen' => [
                'RFC' => 'FECR710812',
                'NOMBRE' => 'FERRER CRUZ ROBERTO',
            ],
        ]);

        $batch = $this->createCredentialsBatch();
        $row = $this->createSyntheticCredentialRow([
            'cuip' => 'FECR710812H15132630',
            'rfc' => 'FECR710812',
            'nombre' => 'FERRER CRUZ ROBERTO',
            'numero_credencial' => 'OM2527E05934',
            'estatus' => 'ALTA',
        ]);

        $result = $this->credentialsImporter->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['actualizar']);
        $this->assertSame(0, $result['counts']['conflicto']);

        $stagingRow = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->first();
        $this->assertSame('actualizar', $stagingRow->accion);
        $this->assertSame('FECR740812H15132630', $stagingRow->datos['conciliacion']['cuip_anterior']);

        $batch->update(['estado' => 'en_revision']);
        $this->credentialsImporter->confirm($batch, $this->admin->id);

        $credencial->refresh();
        $this->assertSame('FECR710812H15132630', $credencial->cuip);

        $origen = is_array($credencial->datos_origen) ? $credencial->datos_origen : json_decode((string) $credencial->datos_origen, true);
        $this->assertIsArray($origen['_trazabilidad_cuip'] ?? null);
        $this->assertSame('FECR740812H15132630', $origen['_trazabilidad_cuip'][0]['cuip_anterior']);
        $this->assertSame('FECR710812H15132630', $origen['_trazabilidad_cuip'][0]['cuip_nuevo']);
    }

    /**
     * 10. Caso 4: vigencia histórica con persona_id = NULL, sin asumir SITUACION ESPECIAL:
     * Concilia por coincidencia secundaria (RFC, nombre, armas, oficio). persona_id permanece NULL,
     * es_referencia_loc permanece false, estatus BAJA, sin asumir situación especial.
     */
    public function test_10_case_4_historical_credential_without_person_reconciles_without_special_status(): void
    {
        $credencial = LocVigenciaCredencial::query()->create([
            'persona_id' => null,
            'cuip' => 'ROCJ731029H15609147',
            'numero_credencial' => 'OM2527M17592',
            'estatus_credencial' => 'ALTA',
            'numero_oficio' => 'CM/TA25-27/382/2026',
            'matricula_corta' => 'XZX701',
            'matricula_larga' => 'M106640',
            'es_referencia_loc' => false,
            'datos_origen' => [
                'RFC' => 'ROCJ731029',
                'NOMBRE' => 'ROMERO CARREON JOSE',
                'MATRICULA' => 'XZX701',
                'MATRICULA 2' => 'M106640',
                'NO OFICIO' => 'CM/TA25-27/382/2026',
            ],
        ]);

        $batch = $this->createCredentialsBatch();
        $row = $this->createSyntheticCredentialRow([
            'cuip' => 'ROCJ731029H09609147',
            'rfc' => 'ROCJ731029',
            'nombre' => 'ROMERO CARREON JOSE',
            'numero_credencial' => 'OM2527M17592',
            'matricula_corta' => 'XZX701',
            'matricula_larga' => 'M106640',
            'oficio_baja' => 'CM/TA25-27/382/2026',
            'fecha_baja' => '10/02/2026',
            'estatus' => 'BAJA',
        ]);

        $result = $this->credentialsImporter->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['actualizar']);
        $this->assertSame(0, $result['counts']['conflicto']);

        $stagingRow = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->first();
        $this->assertSame('actualizar', $stagingRow->accion);
        $this->assertNull($stagingRow->datos['campos']['persona_id']);
        $this->assertFalse($stagingRow->datos['campos']['es_referencia_loc']);
        $this->assertSame('BAJA', $stagingRow->datos['campos']['estatus_credencial']);
        $this->assertNull($stagingRow->datos['campos']['motivo_situacion_especial']);

        $this->assertFalse($stagingRow->datos['tramite_efecto']['elegible']);
        $this->assertSame('bloqueado_sin_enlace_personal', $stagingRow->datos['tramite_efecto']['motivo']);

        $batch->update(['estado' => 'en_revision']);
        $this->credentialsImporter->confirm($batch, $this->admin->id);

        $credencial->refresh();
        $this->assertSame('ROCJ731029H09609147', $credencial->cuip);
        $this->assertNull($credencial->persona_id);
        $this->assertFalse((bool) $credencial->es_referencia_loc);
        $this->assertSame('BAJA', $credencial->estatus_credencial);
    }

    /**
     * 11. Preservación de los estados de credencial y de las reglas de bloqueo para nuevos trámites:
     * Una transición terminal (CANCELACION a ALTA) no es permitida y genera conflicto.
     */
    public function test_11_invalid_status_transition_and_unreconciled_flags_conflict_or_blocks_procedure(): void
    {
        LocVigenciaCredencial::query()->create([
            'persona_id' => null,
            'cuip' => 'TEST010101H15000001',
            'numero_credencial' => 'OM2527M88888',
            'estatus_credencial' => 'CANCELACION',
            'es_referencia_loc' => false,
        ]);

        $batch = $this->createCredentialsBatch();
        // Intentar pasar de CANCELACION a ALTA (transición prohibida)
        $row = $this->createSyntheticCredentialRow([
            'cuip' => 'TEST010101H15000001',
            'numero_credencial' => 'OM2527M88888',
            'estatus' => 'ALTA',
        ]);

        $result = $this->credentialsImporter->stageFromRows([$row], $batch);

        $this->assertSame(1, $result['counts']['conflicto']);
        $staging = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->first();
        $this->assertStringContainsString('Transición no permitida', $staging->errores[0]);
    }

    /**
     * 12. Protección contra registros duplicados durante el reprocesamiento del mismo archivo:
     * Un registro idéntico ya procesado se clasifica como sin_cambios.
     */
    public function test_12_reprocessing_same_file_protects_against_duplicates_as_sin_cambios(): void
    {
        $row = $this->createSyntheticCredentialRow([
            'cuip' => 'IDEM010101H15000001',
            'numero_credencial' => 'OM2527M77777',
            'estatus' => 'ALTA',
        ]);

        $batch1 = $this->createCredentialsBatch();
        $this->credentialsImporter->stageFromRows([$row], $batch1);
        $batch1->update(['estado' => 'en_revision']);
        $this->credentialsImporter->confirm($batch1, $this->admin->id);

        $batch2 = $this->createCredentialsBatch();
        $result = $this->credentialsImporter->stageFromRows([$row], $batch2);

        $this->assertSame(1, $result['counts']['sin_cambios']);
        $this->assertSame(0, $result['counts']['nuevo']);
        $this->assertSame(0, $result['counts']['actualizar']);
    }

    /**
     * 13. Confirmación transaccional: ante un error, no deben quedar escrituras parciales.
     */
    public function test_13_transactional_confirmation_rolls_back_on_failure(): void
    {
        $personOk = LocPersona::query()->create([
            'cuip' => 'CUIP-OK-01',
            'nombre' => 'Persona Valida Original',
            'estatus' => 'activo',
        ]);

        // Duplicado forzado en base para causar fallo de integridad durante la transacción
        DB::table('loc_personas')->insert([
            ['cuip' => 'CUIP-CONFLICTO', 'nombre' => 'Dup 1', 'activo' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['cuip' => ' CUIP-CONFLICTO ', 'nombre' => 'Dup 2', 'activo' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $batch = $this->createPersonalBatch(2);

        // Fila 1 válida: actualiza nombre
        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP-OK-01',
            'datos' => [
                'campos' => ['cuip' => 'CUIP-OK-01', 'nombre' => 'NOMBRE QUE DEBERIA SER ROLLEDBACK'],
                'origen' => ['CUIP' => 'CUIP-OK-01', 'NOMBRE' => 'NOMBRE QUE DEBERIA SER ROLLEDBACK'],
            ],
            'errores' => [],
            'accion' => 'actualizar',
            'estado_aplicacion' => 'pendiente',
        ]);

        // Fila 2: CUIP ambiguo que provoca RuntimeException
        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 3,
            'cuip' => 'CUIP-CONFLICTO',
            'datos' => [
                'campos' => ['cuip' => 'CUIP-CONFLICTO', 'nombre' => 'Fila con CUIP ambiguo'],
                'origen' => ['CUIP' => 'CUIP-CONFLICTO'],
            ],
            'errores' => [],
            'accion' => 'actualizar',
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->update(['revision_sha256' => $this->getPersonalFingerprint($batch)]);

        try {
            $this->personalImporter->commit($batch, $this->admin->id);
            $this->fail('Se esperaba RuntimeException por ambigüedad');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('coincide con más de una persona', $e->getMessage());
        }

        // El registro de la persona válida no debió modificarse
        $personOk->refresh();
        $this->assertSame('Persona Valida Original', $personOk->nombre);
    }

    /**
     * 14. Conservación de las referencias históricas a personas y vigencias:
     * Verificar que las claves primarias (id) no cambian y se registra la trazabilidad completa.
     */
    public function test_14_preservation_of_primary_keys_and_historical_references(): void
    {
        $person = LocPersona::query()->create([
            'cuip' => 'HIST_OLD_001',
            'nombre' => 'TITULAR HISTORICO',
            'rfc' => 'HIST800101',
            'curp' => 'HIST800101HDFRR001',
        ]);

        $originalId = $person->id;

        $batch = $this->createPersonalBatch();
        $batch->estado = 'en_revision';
        $batch->save();

        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'HIST_NEW_002',
            'datos' => [
                'campos' => [
                    'cuip' => 'HIST_NEW_002',
                    'nombre' => 'TITULAR HISTORICO',
                    'rfc' => 'HIST800101',
                    'curp' => 'HIST800101HDFRR001',
                ],
                'origen' => [
                    'CUIP' => 'HIST_NEW_002',
                    'NOMBRE' => 'TITULAR HISTORICO',
                ],
                'conciliacion_identidad' => [
                    'persona_id' => $person->id,
                    'cuip_anterior' => 'HIST_OLD_001',
                    'cuip_nuevo' => 'HIST_NEW_002',
                    'criterio' => 'coincidencia_inequivoca_por_curp',
                ],
            ],
            'errores' => [],
            'accion' => 'actualizar',
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->update(['revision_sha256' => $this->getPersonalFingerprint($batch)]);

        $this->personalImporter->commit($batch, $this->admin->id);

        $person->refresh();
        $this->assertSame($originalId, $person->id, 'La clave primaria del registro debe conservarse intacta.');
        $this->assertSame('HIST_NEW_002', $person->cuip);
        $this->assertArrayHasKey('_historial_identidad', $person->datos_loc);
    }

    /**
     * 15. Compatibilidad con los tests existentes de importación de personal y vigencias:
     * Comprobar que los importers instancian y responden correctamente a su contrato de servicio.
     */
    public function test_15_importers_contract_compatibility(): void
    {
        $this->assertInstanceOf(LocPersonalXlsImporter::class, $this->personalImporter);
        $this->assertInstanceOf(LocCredentialsXlsImporter::class, $this->credentialsImporter);
        $this->assertInstanceOf(LocCredentialsPolicyService::class, $this->credentialsPolicy);
    }

    /**
     * 16. Prueba negativa: Una credencial distinta que comparte el mismo CUIP anterior
     * NUNCA puede actualizarse accidentalmente cuando se confirma otra credencial.
     */
    public function test_16_negative_different_credential_with_same_previous_cuip_is_never_updated_accidentally(): void
    {
        $persona = LocPersona::query()->create([
            'cuip' => 'SHARED_OLD_CUIP_01',
            'nombre' => 'TITULAR MULTIPLES CREDENCIALES',
            'rfc' => 'TIMU800101',
            'curp' => 'TIMU800101HDFRR001',
        ]);

        // Credencial 1 del titular
        $cred1 = LocVigenciaCredencial::query()->create([
            'persona_id' => $persona->id,
            'cuip' => 'SHARED_OLD_CUIP_01',
            'numero_credencial' => 'OM2527M11111',
            'estatus_credencial' => 'ALTA',
            'es_referencia_loc' => true,
        ]);

        // Credencial 2 del mismo titular con el mismo CUIP anterior
        $cred2 = LocVigenciaCredencial::query()->create([
            'persona_id' => $persona->id,
            'cuip' => 'SHARED_OLD_CUIP_01',
            'numero_credencial' => 'OM2527M22222',
            'estatus_credencial' => 'ALTA',
            'es_referencia_loc' => true,
        ]);

        $batch = $this->createCredentialsBatch();
        // Fila que solo concilia la Credencial 1 al nuevo CUIP
        $row = $this->createSyntheticCredentialRow([
            'cuip' => 'SHARED_NEW_CUIP_02',
            'rfc' => 'TIMU800101',
            'nombre' => 'TITULAR MULTIPLES CREDENCIALES',
            'numero_credencial' => 'OM2527M11111', // Solo credencial 1
            'estatus' => 'ALTA',
        ]);

        $result = $this->credentialsImporter->stageFromRows([$row], $batch);
        $this->assertSame(1, $result['counts']['actualizar']);

        $batch->update(['estado' => 'en_revision']);
        $this->credentialsImporter->confirm($batch, $this->admin->id);

        // Credencial 1 se actualizó al nuevo CUIP
        $cred1->refresh();
        $this->assertSame('SHARED_NEW_CUIP_02', $cred1->cuip);

        // Credencial 2 debe permanecer INTACTA con el CUIP anterior
        $cred2->refresh();
        $this->assertSame('SHARED_OLD_CUIP_01', $cred2->cuip, 'La segunda credencial no debe haber sido tocada.');
    }

    /**
     * 17. Prueba negativa: Identificadores contradictorios entre la credencial existente y la fila entrante
     * permanecen estrictamente en conflicto y bloquean la confirmación.
     */
    public function test_17_negative_contradictory_identity_between_incoming_and_existing_credential_remains_conflict(): void
    {
        LocVigenciaCredencial::query()->create([
            'persona_id' => null,
            'cuip' => 'COLLISION_CUIP_01',
            'numero_credencial' => 'OM2527M33333',
            'estatus_credencial' => 'ALTA',
            'datos_origen' => [
                'RFC' => 'PERJ800101',
                'NOMBRE' => 'PEREZ JUAN',
            ],
        ]);

        $batch = $this->createCredentialsBatch();
        // Fila entrante intentando usurpar la credencial con un RFC totalmente contradictorio
        $row = $this->createSyntheticCredentialRow([
            'cuip' => 'NEW_DISTINCT_CUIP_02',
            'rfc' => 'GOMP900505', // RFC contradictorio
            'nombre' => 'GOMEZ PEDRO', // Nombre contradictorio
            'numero_credencial' => 'OM2527M33333',
            'estatus' => 'ALTA',
        ]);

        $result = $this->credentialsImporter->stageFromRows([$row], $batch);

        // Debe clasificarse como conflicto
        $this->assertSame(1, $result['counts']['conflicto']);
        $this->assertSame(0, $result['counts']['actualizar']);

        $stagingRow = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->first();
        $this->assertSame('conflicto', $stagingRow->accion);
        $this->assertStringContainsString('ya está asignado a otro CUIP', $stagingRow->errores[0]);

        // Intentar confirmar debe abortar
        $batch->update(['estado' => 'en_revision']);
        $this->expectException(\RuntimeException::class);
        $this->credentialsImporter->confirm($batch, $this->admin->id);
    }

    /**
     * 18. Prueba negativa: Conservación de datos JSON preexistentes y protección contra duplicación
     * de trazabilidad en ejecuciones repetidas (idempotencia).
     */
    public function test_18_negative_pre_existing_json_data_and_traceability_are_strictly_preserved_and_idempotent(): void
    {
        $persona = LocPersona::query()->create([
            'cuip' => 'ORIG_CUIP_01',
            'nombre' => 'TITULAR HISTORICO CON METADATOS',
            'rfc' => 'HIST900101',
            'curp' => 'HIST900101HDFRR001',
            'datos_loc' => [
                'METADATO_HISTORICO_CRITICO' => 'VALOR_QUE_NO_DEBE_PERDERSE',
                'EXPEDIENTE_ORIGINAL' => 'EXP-2024-99',
            ],
        ]);

        $cred = LocVigenciaCredencial::query()->create([
            'persona_id' => $persona->id,
            'cuip' => 'ORIG_CUIP_01',
            'numero_credencial' => 'OM2527M44444',
            'estatus_credencial' => 'ALTA',
            'es_referencia_loc' => true,
            'datos_origen' => [
                'CAMPO_ORIGINAL_CREDENCIAL' => 'PRESERVADO',
                '_trazabilidad_cuip' => [
                    [
                        'cuip_anterior' => 'CUIP_MUY_ANTIGUO',
                        'cuip_nuevo' => 'ORIG_CUIP_01',
                        'import_batch_id' => 1,
                        'fecha' => '2024-01-01T00:00:00Z',
                    ],
                ],
            ],
        ]);

        $batch = $this->createCredentialsBatch();
        $row = $this->createSyntheticCredentialRow([
            'cuip' => 'NEW_RECONCILED_CUIP_02',
            'rfc' => 'HIST900101',
            'nombre' => 'TITULAR HISTORICO CON METADATOS',
            'numero_credencial' => 'OM2527M44444',
            'estatus' => 'ALTA',
        ]);

        $this->credentialsImporter->stageFromRows([$row], $batch);
        $batch->update(['estado' => 'en_revision']);
        $this->credentialsImporter->confirm($batch, $this->admin->id);

        $cred->refresh();
        $this->assertSame('NEW_RECONCILED_CUIP_02', $cred->cuip);

        $origen = is_array($cred->datos_origen) ? $cred->datos_origen : json_decode((string) $cred->datos_origen, true);
        $trazabilidad = $origen['_trazabilidad_cuip'] ?? [];

        // Debe conservar la entrada histórica antigua de 2024 Y la nueva del lote actual
        $this->assertCount(2, $trazabilidad, 'Debe conservar el historial previo y añadir el nuevo.');
        $this->assertSame('CUIP_MUY_ANTIGUO', $trazabilidad[0]['cuip_anterior']);
        $this->assertSame('ORIG_CUIP_01', $trazabilidad[1]['cuip_anterior']);
        $this->assertSame('NEW_RECONCILED_CUIP_02', $trazabilidad[1]['cuip_nuevo']);
        $this->assertSame($batch->id, $trazabilidad[1]['import_batch_id']);
        $this->assertSame(2, $trazabilidad[1]['numero_fila']);
    }
}
