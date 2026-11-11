<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessLocCredentialsImportBatch;
use App\Models\LocArma;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\LocPersona;
use App\Models\LocVigenciaCredencial;
use App\Models\User;
use App\Services\LocCredentialsPolicyService;
use App\Services\LocCredentialsXlsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Tests\TestCase;

class LocCredentialsImportWebFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private LocCredentialsPolicyService $policy;
    private LocCredentialsXlsImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new LocCredentialsPolicyService();
        $this->importer = new LocCredentialsXlsImporter($this->policy);

        $this->admin = User::factory()->create(['role' => 'superadmin']);

        config([
            'loc_import.credentials_queue_connection' => 'loc_credentials_import',
            'loc_import.credentials_queue' => 'loc-credentials-imports',
            'loc_import.credentials_read_chunk_size' => 100,
            'loc_import.credentials_write_chunk_size' => 100,
            'loc_import.credentials_reuse_ole' => false,
        ]);
    }

    private function createSyntheticRow(array $overrides = []): array
    {
        $row = array_fill(0, 40, '');

        $row[0] = $overrides['id_origen'] ?? '1001';
        $row[1] = $overrides['cuip'] ?? 'ABCD010101HDFRRA01';
        $row[2] = $overrides['rfc'] ?? 'ABCD010101XYZ';
        $row[3] = $overrides['nombre'] ?? 'JUAN PEREZ LOPEZ';
        $row[4] = $overrides['categoria'] ?? 'POLICIA';
        $row[5] = 'ARMA CORTA';
        $row[6] = 'PISTOLA';
        $row[7] = 'GLOCK';
        $row[8] = '9X19 MM';
        $row[9] = '17';
        $row[10] = $overrides['matricula_corta'] ?? 'MAT-CORTA-01';
        $row[11] = '24';
        $row[12] = 'OPERACIONES';
        $row[13] = 'ARMA LARGA';
        $row[14] = 'FUSIL';
        $row[15] = 'COLT';
        $row[16] = '5.56 MM';
        $row[17] = 'M4';
        $row[18] = $overrides['matricula_larga'] ?? 'MAT-LARGA-01';
        $row[19] = '24';
        $row[20] = 'OPERACIONES';
        $row[21] = $overrides['oficio_baja'] ?? '';
        $row[22] = $overrides['fecha_baja'] ?? '';
        $row[23] = 'POLICIA ESTATAL';
        $row[24] = $overrides['numero_credencial'] ?? 'PE-2025-WEB-01';
        $row[25] = $overrides['oficio_solicitud'] ?? 'SSP/WEB/2025';
        $row[26] = $overrides['fecha_solicitud'] ?? '20/09/2025';
        $row[27] = 'EXPEDICION REGULAR';
        $row[28] = 'VIGENCIA 2025-2027';
        $row[29] = '01.02.03';
        $row[30] = 'OPERATIVO';
        $row[31] = 'CENTRO';
        $row[32] = 'CENTRO';
        $row[33] = 'DIRECCION DE OPERACIONES';
        $row[34] = 'BASE CENTRAL';
        $row[35] = 'MODULO 1';
        $row[36] = 'POLICIA ESTATAL';
        $row[37] = 'DIRECCION DE OPERACIONES';
        $row[38] = $overrides['estatus'] ?? 'ALTA';
        $row[39] = $overrides['motivo_situacion_especial'] ?? '';

        return $row;
    }

    private function createSyntheticSpreadsheetFile(array $dataRows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'loc-cred-test-') . '.xls';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = array_fill(0, 40, 'HEADER');
        $headers[0] = 'ID_ORIGEN';
        $headers[1] = 'CUIP';
        $headers[2] = 'RFC';
        $headers[3] = 'NOMBRE';
        $headers[4] = 'CATEGORIA';
        $headers[10] = 'MATRICULA_CORTA';
        $headers[18] = 'MATRICULA_LARGA';
        $headers[24] = 'NUMERO_CREDENCIAL';
        $headers[26] = 'FECHA_SOLICITUD';
        $headers[38] = 'ESTATUS';

        $sheet->fromArray(array_merge([$headers], $dataRows), null, 'A1', true);

        $writer = new Xls($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    public function test_upload_requires_authentication(): void
    {
        $response = $this->post(route('gestion.vigencias.importaciones.subir'), []);
        $response->assertRedirect(route('login'));
    }

    public function test_upload_requires_administration_privileges(): void
    {
        $operator = User::factory()->create(['role' => 'operador']);

        $response = $this->actingAs($operator)
            ->post(route('gestion.vigencias.importaciones.subir'), []);

        $response->assertForbidden();
    }

    public function test_upload_validates_file_presence_and_extension(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('gestion.vigencias.importaciones.subir'), []);

        $response->assertSessionHasErrors(['archivo_vigencias']);

        $invalidFile = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $responseInvalid = $this->actingAs($this->admin)
            ->post(route('gestion.vigencias.importaciones.subir'), [
                'archivo_vigencias' => $invalidFile,
            ]);

        $responseInvalid->assertSessionHasErrors(['archivo_vigencias']);
    }

    public function test_upload_stores_file_creates_batch_and_dispatches_job(): void
    {
        Storage::fake('local');
        Queue::fake();

        $syntheticPath = $this->createSyntheticSpreadsheetFile([$this->createSyntheticRow()]);
        $uploadedFile = new UploadedFile(
            $syntheticPath,
            'Vigencia_Test.xls',
            'application/vnd.ms-excel',
            null,
            true
        );

        $response = $this->actingAs($this->admin)
            ->post(route('gestion.vigencias.importaciones.subir'), [
                'archivo_vigencias' => $uploadedFile,
            ]);

        $batch = LocImportBatch::query()
            ->where('tipo_fuente', LocCredentialsXlsImporter::SOURCE_TYPE)
            ->first();

        $this->assertNotNull($batch);
        $this->assertSame('pendiente', $batch->estado);
        $this->assertSame($this->admin->id, $batch->usuario_id);
        $this->assertNotEmpty($batch->archivo_sha256);

        $response->assertRedirect(route('gestion.vigencias.importaciones.preview', $batch));
        $response->assertSessionHas('success');

        Queue::assertPushed(ProcessLocCredentialsImportBatch::class, function ($job) use ($batch) {
            return $job->batchId === $batch->id
                && $job->queue === 'loc-credentials-imports'
                && $job->connection === 'loc_credentials_import';
        });

        if (is_file($syntheticPath)) {
            @unlink($syntheticPath);
        }
    }

    public function test_duplicate_upload_redirects_to_existing_batch(): void
    {
        Storage::fake('local');
        Queue::fake();

        $syntheticPath = $this->createSyntheticSpreadsheetFile([$this->createSyntheticRow()]);
        $fileHash = hash_file('sha256', $syntheticPath);

        $existingBatch = LocImportBatch::create([
            'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => 'Vigencia_Previo.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'archivo_sha256' => $fileHash,
        ]);

        $uploadedFile = new UploadedFile(
            $syntheticPath,
            'Vigencia_Dup.xls',
            'application/vnd.ms-excel',
            null,
            true
        );

        $response = $this->actingAs($this->admin)
            ->post(route('gestion.vigencias.importaciones.subir'), [
                'archivo_vigencias' => $uploadedFile,
            ]);

        $response->assertRedirect(route('gestion.vigencias.importaciones.preview', $existingBatch));
        $this->assertSame(1, LocImportBatch::where('tipo_fuente', LocCredentialsXlsImporter::SOURCE_TYPE)->count());
        Queue::assertNothingPushed();

        if (is_file($syntheticPath)) {
            @unlink($syntheticPath);
        }
    }

    public function test_progress_endpoint_returns_json_and_enforces_source_type(): void
    {
        $batch = LocImportBatch::create([
            'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => 'Vigencia.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'procesando',
            'total_leidas' => 1500,
            'total_aceptadas' => 1400,
            'total_rechazadas' => 100,
            'total_insertadas' => 1400,
            'total_actualizadas' => 0,
            'total_sin_cambios' => 0,
            'resumen_errores' => json_encode(['porcentaje' => 45.2, 'filas_estimadas' => 33193]),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('gestion.vigencias.importaciones.progreso', $batch));

        $response->assertOk();
        $response->assertJson([
            'status' => 'procesando',
            'rows_read' => 1500,
            'accepted' => 1400,
            'rejected' => 100,
            'new' => 1400,
            'percentage' => 45.2,
            'estimated_rows' => 33193,
        ]);

        // Wrong source type returns 404
        $wrongBatch = LocImportBatch::create([
            'tipo_fuente' => 'armamento_loc',
            'nombre_archivo' => 'ARMAS.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'procesando',
        ]);

        $responseWrong = $this->actingAs($this->admin)
            ->getJson(route('gestion.vigencias.importaciones.progreso', $wrongBatch));

        $responseWrong->assertNotFound();
    }

    public function test_preview_renders_when_in_revision_with_counts_and_filters(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'PREVIEW010101HDF01',
            'nombre' => 'ELEMENTO PREVIEW',
            'rfc' => 'PREV010101XYZ',
        ]);

        $batch = LocImportBatch::create([
            'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => 'Vigencia_Preview.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_leidas' => 2,
            'total_insertadas' => 1,
            'total_actualizadas' => 0,
            'total_sin_cambios' => 0,
            'total_rechazadas' => 1,
            'total_aceptadas' => 1,
        ]);

        LocImportStagingRow::create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => $persona->cuip,
            'matricula' => 'MAT-OK-01',
            'id_armamento' => 'CORTA-OK',
            'accion' => 'nuevo',
            'datos' => [
                'campos' => ['numero_credencial' => 'PE-OK-01', 'cuip' => $persona->cuip, 'estatus_credencial' => 'ALTA', 'persona_id' => $persona->id],
                'conciliacion' => ['persona_resuelta' => true, 'arma_corta_resuelta' => true],
                'origen' => ['PE-OK-01'],
                'errores' => [],
            ],
            'errores' => [],
            'fila_hash' => hash('sha256', 'row2'),
            'estado_aplicacion' => 'pendiente',
        ]);

        LocImportStagingRow::create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 3,
            'cuip' => 'UNKNOWN999999HDF01',
            'matricula' => 'MAT-ERR-01',
            'id_armamento' => 'CORTA-ERR',
            'accion' => 'conflicto',
            'datos' => [
                'campos' => ['numero_credencial' => 'PE-ERR-01', 'cuip' => 'UNKNOWN999999HDF01'],
                'conciliacion' => ['persona_resuelta' => false],
                'origen' => ['PE-ERR-01'],
                'errores' => ['Discrepancia crítica en estatus o RFC.'],
            ],
            'errores' => ['Discrepancia crítica en estatus o RFC.'],
            'fila_hash' => hash('sha256', 'row3'),
            'estado_aplicacion' => 'pendiente',
        ]);

        // Vista normal sin filtros
        $response = $this->actingAs($this->admin)
            ->get(route('gestion.vigencias.importaciones.preview', $batch));

        $response->assertOk();
        $response->assertSee('Validación previa de vigencias');
        $response->assertSee('PE-OK-01');
        $response->assertSee('UNKNOWN999999HDF01');
        $response->assertSee('Discrepancia crítica en estatus o RFC.');

        // Filtro por conflicto
        $responseConflict = $this->actingAs($this->admin)
            ->get(route('gestion.vigencias.importaciones.preview', [$batch, 'accion' => 'conflicto']));

        $responseConflict->assertOk();
        $responseConflict->assertSee('UNKNOWN999999HDF01');
        $responseConflict->assertDontSee('PE-OK-01');

        // Filtro por búsqueda de CUIP
        $responseSearch = $this->actingAs($this->admin)
            ->get(route('gestion.vigencias.importaciones.preview', [$batch, 'buscar' => $persona->cuip]));

        $responseSearch->assertOk();
        $responseSearch->assertSee('PE-OK-01');
        $responseSearch->assertDontSee('UNKNOWN999999HDF01');

        // Petición AJAX (X-Requested-With / JSON)
        $responseAjax = $this->actingAs($this->admin)
            ->getJson(route('gestion.vigencias.importaciones.preview', [$batch, 'accion' => 'conflicto']));

        $responseAjax->assertOk()
            ->assertJsonStructure(['table_html', 'chips_html', 'total_coincidentes'])
            ->assertJsonPath('total_coincidentes', 1);

        $this->assertStringContainsString('UNKNOWN999999HDF01', (string) $responseAjax->json('table_html'));
        $this->assertStringNotContainsString('PE-OK-01', (string) $responseAjax->json('table_html'));
    }

    public function test_confirm_blocks_when_conflicts_exist(): void
    {
        $batch = LocImportBatch::create([
            'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => 'Vigencia_Conflict.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_leidas' => 1,
            'total_rechazadas' => 1,
        ]);

        LocImportStagingRow::create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'NOCUIP010101HDF01',
            'accion' => 'conflicto',
            'datos' => [
                'campos' => ['cuip' => 'NOCUIP010101HDF01', 'estatus_credencial' => 'NUMERO DE CREDENCIAL'],
                'errores' => ['Estatus de credencial no reconocido'],
            ],
            'errores' => ['Estatus de credencial no reconocido'],
            'fila_hash' => hash('sha256', 'err_row'),
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->revision_sha256 = $this->importer->reviewFingerprint($batch);
        $batch->save();

        $response = $this->actingAs($this->admin)
            ->post(route('gestion.vigencias.importaciones.confirmar', $batch));

        $response->assertSessionHasErrors(['importacion_vigencias']);
        $this->assertDatabaseCount('loc_vigencias_credenciales', 0);
        $this->assertSame('en_revision', $batch->fresh()->estado);
    }

    public function test_confirm_commits_clean_batch_and_redirects_to_history(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'CONFIRM010101HDF01',
            'nombre' => 'ELEMENTO PARA CONFIRMACION',
            'rfc' => 'CONF010101XYZ',
        ]);

        $corta = LocArma::create([
            'matricula' => 'MAT-CONF-01',
            'marca' => 'GLOCK',
            'calibre' => '9MM',
            'clase' => 'PISTOLA',
        ]);

        $batch = LocImportBatch::create([
            'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => 'Vigencia_Clean.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_leidas' => 1,
            'total_insertadas' => 1,
            'total_actualizadas' => 0,
            'total_sin_cambios' => 0,
            'total_rechazadas' => 0,
            'total_aceptadas' => 1,
        ]);

        $stagingRow = LocImportStagingRow::create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => $persona->cuip,
            'matricula' => 'MAT-CONF-01',
            'id_armamento' => 'CORTA-CONF',
            'accion' => 'nuevo',
            'datos' => [
                'campos' => [
                    'cuip' => $persona->cuip,
                    'numero_credencial' => 'PE-CONFIRMED-01',
                    'estatus_credencial' => 'ALTA',
                    'arma_corta_id' => $corta->id,
                    'matricula_corta' => $corta->matricula,
                    'arma_larga_id' => null,
                    'matricula_larga' => null,
                    'periodo_credencial' => '2025-2027',
                    'fila_hash' => hash('sha256', 'clean_row'),
                ],
                'origen' => ['dummy_data'],
                'errores' => [],
            ],
            'errores' => [],
            'fila_hash' => hash('sha256', 'clean_row'),
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->revision_sha256 = $this->importer->reviewFingerprint($batch);
        $batch->save();

        $response = $this->actingAs($this->admin)
            ->post(route('gestion.vigencias.importaciones.confirmar', $batch));

        $response->assertRedirect(route('gestion.historial', ['tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE]));
        $response->assertSessionHas('success');

        $this->assertSame('completado', $batch->fresh()->estado);
        $this->assertSame('insertado', $stagingRow->fresh()->estado_aplicacion);

        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'cuip' => $persona->cuip,
            'numero_credencial' => 'PE-CONFIRMED-01',
            'arma_corta_id' => $corta->id,
            'matricula_corta' => 'MAT-CONF-01',
            'estatus_credencial' => 'ALTA',
        ]);
    }

    public function test_confirm_unauthorized_user_is_forbidden(): void
    {
        $otherUser = User::factory()->create(['role' => 'supervisor']);

        $batch = LocImportBatch::create([
            'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => 'Vigencia_Forbidden.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
        ]);

        $response = $this->actingAs($otherUser)
            ->post(route('gestion.vigencias.importaciones.confirmar', $batch));

        $response->assertForbidden();
    }

    public function test_end_to_end_job_processing_with_synthetic_xls_and_confirmation(): void
    {
        // 1. Preparar catálogos conciliables
        $persona = LocPersona::create([
            'cuip' => 'E2E010101HDFRRA01',
            'nombre' => 'ELEMENTO END TO END',
            'rfc' => 'E2E010101XYZ',
        ]);

        $armaCorta = LocArma::create([
            'matricula' => 'CORTA-E2E-01',
            'marca' => 'GLOCK',
            'calibre' => '9X19 MM',
            'clase' => 'PISTOLA',
        ]);

        $armaLarga = LocArma::create([
            'matricula' => 'LARGA-E2E-01',
            'marca' => 'COLT',
            'calibre' => '5.56 MM',
            'clase' => 'FUSIL',
        ]);

        // 2. Generar libro sintético con 2 filas: 1 con dos armas, 1 solo con corta
        $row1 = $this->createSyntheticRow([
            'id_origen' => '9001',
            'cuip' => $persona->cuip,
            'rfc' => 'E2E010101XYZ',
            'nombre' => 'ELEMENTO END TO END',
            'matricula_corta' => $armaCorta->matricula,
            'matricula_larga' => $armaLarga->matricula,
            'numero_credencial' => 'PE-E2E-DUAL',
            'estatus' => 'ALTA',
            'fecha_solicitud' => '01/10/2025',
        ]);

        $xlsPath = $this->createSyntheticSpreadsheetFile([$row1]);

        // Guardar archivo temporal simulado en Storage disk local
        $storageDir = Storage::disk('local')->path('loc-imports');
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        $storedFilename = 'loc-imports/e2e-test-' . uniqid() . '.xls';
        copy($xlsPath, Storage::disk('local')->path($storedFilename));

        $batch = LocImportBatch::create([
            'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => 'Vigencia_E2E.xls',
            'archivo_sha256' => hash_file('sha256', $xlsPath),
            'usuario_id' => $this->admin->id,
            'estado' => 'pendiente',
        ]);

        // 3. Ejecutar el Job directamente
        $job = new ProcessLocCredentialsImportBatch($batch->id, $storedFilename);
        $job->handle($this->importer);

        $batch->refresh();
        $this->assertSame('en_revision', $batch->estado);
        $this->assertSame(1, $batch->total_leidas);
        $this->assertSame(1, $batch->total_insertadas);
        $this->assertSame(0, $batch->total_rechazadas);

        // Verificar staging rows
        $staging = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $this->assertNotNull($staging);
        $this->assertSame('nuevo', $staging->accion);
        $this->assertSame($persona->cuip, $staging->cuip);

        // 4. Confirmar a través de la ruta web
        $response = $this->actingAs($this->admin)
            ->post(route('gestion.vigencias.importaciones.confirmar', $batch));

        $response->assertRedirect(route('gestion.historial', ['tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE]));

        // 5. Verificar persistencia oficial en loc_vigencias_credenciales
        $savedCred = LocVigenciaCredencial::where('numero_credencial', 'PE-E2E-DUAL')->first();
        $this->assertNotNull($savedCred);
        $this->assertSame($persona->cuip, $savedCred->cuip);
        $this->assertSame($armaCorta->id, $savedCred->arma_corta_id);
        $this->assertSame($armaLarga->id, $savedCred->arma_larga_id);
        $this->assertSame('ALTA', $savedCred->estatus_credencial);

        // Limpieza de archivos temporales
        if (is_file($xlsPath)) {
            @unlink($xlsPath);
        }
    }

    public function test_web_flow_preview_and_confirm_with_formally_omitted_row(): void
    {
        $batch = LocImportBatch::create([
            'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => 'Vigencias_Con_Omision.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_leidas' => 2,
            'total_insertadas' => 1,
            'total_actualizadas' => 0,
            'total_sin_cambios' => 0,
            'total_rechazadas' => 0,
            'total_aceptadas' => 1,
        ]);

        $persona = LocPersona::create([
            'cuip' => 'OMITTEST010101H1',
            'nombre' => 'PERSONA WEB FLOW',
            'rfc' => 'OMIT010101XYZ',
        ]);

        $rowNuevo = LocImportStagingRow::create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => $persona->cuip,
            'accion' => 'nuevo',
            'datos' => [
                'campos' => ['cuip' => $persona->cuip, 'numero_credencial' => 'PE-OMIT-01', 'estatus_credencial' => 'ALTA'],
                'conciliacion' => ['persona_resuelta' => true],
                'origen' => ['row_nuevo'],
                'errores' => [],
            ],
            'errores' => [],
            'fila_hash' => hash('sha256', 'row_nuevo'),
            'estado_aplicacion' => 'pendiente',
        ]);

        $rowOmitido = LocImportStagingRow::create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 3,
            'cuip' => 'TECH999999HDF01',
            'accion' => LocCredentialsXlsImporter::ACTION_OMITIDO,
            'datos' => [
                'campos' => ['cuip' => 'TECH999999HDF01'],
                'conciliacion' => ['persona_resuelta' => false],
                'origen' => ['row_tech'],
                'errores' => ['Estatus no reconocido'],
                'exclusion' => [
                    'motivo' => 'Cabecera técnica desfasada en archivo fuente',
                    'usuario_id' => $this->admin->id,
                    'fecha' => '2026-10-25 02:00:00',
                ],
            ],
            'errores' => ['Estatus no reconocido'],
            'fila_hash' => hash('sha256', 'row_tech'),
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->revision_sha256 = $this->importer->reviewFingerprint($batch);
        $batch->save();

        // 1. Probar preview y filtro 'omitido'
        $responsePreview = $this->actingAs($this->admin)
            ->get(route('gestion.vigencias.importaciones.preview', [$batch, 'accion' => 'omitido']));

        $responsePreview->assertOk();
        $responsePreview->assertSee('Cabecera técnica desfasada en archivo fuente');
        $responsePreview->assertSee('Omitida (Excluida)');
        $responsePreview->assertSee('TECH999999HDF01');
        $responsePreview->assertDontSee('PE-OMIT-01');

        // 2. Confirmar lote
        $responseConfirm = $this->actingAs($this->admin)
            ->post(route('gestion.vigencias.importaciones.confirmar', $batch));

        $responseConfirm->assertRedirect(route('gestion.historial', ['tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE]));

        // 3. Verificaciones de base de datos
        $batch->refresh();
        $this->assertSame('completado', $batch->estado);
        $this->assertSame(1, $batch->total_insertadas);
        $this->assertNotNull($batch->fecha_confirmacion);

        $this->assertSame('insertado', $rowNuevo->fresh()->estado_aplicacion);
        $this->assertSame(LocCredentialsXlsImporter::STATUS_APLICACION_OMITIDO, $rowOmitido->fresh()->estado_aplicacion);

        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'numero_credencial' => 'PE-OMIT-01',
            'cuip' => $persona->cuip,
        ]);
        $this->assertDatabaseMissing('loc_vigencias_credenciales', [
            'cuip' => 'TECH999999HDF01',
        ]);
    }

    public function test_omit_row_endpoint_marks_row_as_omitted_and_updates_batch(): void
    {
        $batch = LocImportBatch::create([
            'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => 'omit-test.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_rechazadas' => 1,
            'total_leidas' => 1,
            'total_aceptadas' => 0,
        ]);
        $row = LocImportStagingRow::create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 10,
            'cuip' => 'TESTCUIP999999H01',
            'datos' => ['campos' => ['cuip' => 'TESTCUIP999999H01']],
            'errores' => ['Error bloqueante de prueba'],
            'accion' => 'conflicto',
            'estado_aplicacion' => 'pendiente',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('gestion.vigencias.importaciones.omitir', [$batch, $row]), [
                'motivo' => 'Exclusión formal por inconsistencia irreconciliable de prueba',
            ]);

        $response->assertRedirect();
        $row->refresh();
        $this->assertSame(LocCredentialsXlsImporter::ACTION_OMITIDO, $row->accion);
        $this->assertSame('Exclusión formal por inconsistencia irreconciliable de prueba', $row->datos['exclusion']['motivo'] ?? null);

        $batch->refresh();
        $this->assertSame(0, $batch->total_rechazadas);
    }
}
