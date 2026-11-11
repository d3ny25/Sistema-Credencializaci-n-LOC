<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\User;
use App\Services\LocCatalogoXlsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class LocCatalogoImportTest extends TestCase
{
    use RefreshDatabase;

    private LocCatalogoXlsImporter $importer;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new LocCatalogoXlsImporter();
        $this->adminUser = User::factory()->create([
            'role' => 'superadmin',
        ]);
    }

    private function validHeaders(): array
    {
        return [
            'DIRECCION GENERAL', // 0
            'COORDINACION',      // 1
            'SUBDIRECCION',      // 2
            'REGION',            // 3
            'AGRUPAMIENTO',       // 4
            'MUNICIPIO',          // 5
            'COORDINACION',      // 6 (dup)
            'CLAVE',             // 7
            'ZONA MILITAR',      // 8
            'BATALLON',          // 9
        ];
    }

    private function validRow(string $clave = 'A-001', string $agrupamiento = 'AGRUPAMIENTO 1', string $municipio = 'TOLUCA', ?string $batallon = '10/o. BATALLON'): array
    {
        return [
            'DIRECCION GENERAL DE SEGURIDAD', // 0
            'COORDINACION TOLUCA',             // 1
            'SUBDIRECCION TOLUCA',             // 2
            'REGION TOLUCA',                   // 3
            $agrupamiento,                     // 4
            $municipio,                        // 5
            'COORDINACION TOLUCA',             // 6 (dup de 1)
            $clave,                            // 7
            '22/a. Z.M. (TOLUCA)',             // 8
            $batallon,                         // 9
        ];
    }

    private function createBatch(string $fileName = 'catalogo_test.xlsx'): LocImportBatch
    {
        return LocImportBatch::create([
            'tipo_fuente' => LocCatalogoXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => $fileName,
            'fecha_procesamiento' => now(),
            'usuario_id' => $this->adminUser->id,
            'estado' => 'pendiente',
            'archivo_sha256' => hash('sha256', $fileName . uniqid()),
            'total_leidas' => 0,
            'total_aceptadas' => 0,
            'total_rechazadas' => 0,
            'total_insertadas' => 0,
            'total_actualizadas' => 0,
            'total_sin_cambios' => 0,
        ]);
    }

    /**
     * 1. Importación inicial válida
     */
    public function test_01_importacion_inicial_valida(): void
    {
        $batch = $this->createBatch();
        $rows = [
            $this->validHeaders(),
            $this->validRow('A-001', 'AGRUPAMIENTO NORTE', 'TOLUCA', '10/o. BATALLON'),
            $this->validRow('A-002', 'AGRUPAMIENTO SUR', 'METEPEC', null),
        ];

        $stageResult = $this->importer->stageFromRows($rows, $batch);

        $this->assertEquals(2, $stageResult['total_read']);
        $this->assertEquals(2, $stageResult['counts']['nuevo']);
        $this->assertEquals(0, $stageResult['counts']['conflicto']);
        $this->assertEquals(0, $stageResult['counts']['rechazado']);
        $this->assertEquals('en_revision', $batch->fresh()->estado);

        // Confirmar y publicar
        $commitResult = $this->importer->commit($batch, $this->adminUser->id);

        $this->assertEquals(1, $commitResult['version']);
        $this->assertEquals(2, $commitResult['inserted']);
        $this->assertFalse($commitResult['already_confirmed']);
        $this->assertEquals('completado', $batch->fresh()->estado);

        // Verificar que los registros están activos y con import_batch_id correcto
        $this->assertEquals(2, LocCatalogoOrganizacional::vigente()->count());
        $this->assertEquals(2, LocCatalogoOrganizacional::where('import_batch_id', $batch->id)->where('activo', true)->count());

        $v1 = LocCatalogoVersion::where('es_vigente', true)->first();
        $this->assertNotNull($v1);
        $this->assertEquals(1, $v1->version_numero);
        $this->assertEquals(2, $v1->total_registros);
    }

    /**
     * 2. Duplicados de clave dentro de un mismo archivo
     */
    public function test_02_duplicados_de_clave_en_mismo_archivo_bloquean_confirmacion(): void
    {
        $batch = $this->createBatch();
        $rows = [
            $this->validHeaders(),
            $this->validRow('A-001', 'AGRUPAMIENTO NORTE', 'TOLUCA'),
            $this->validRow('A-001', 'AGRUPAMIENTO SUR', 'METEPEC'), // Duplicado de A-001
        ];

        $stageResult = $this->importer->stageFromRows($rows, $batch);

        $this->assertEquals(1, $stageResult['counts']['conflicto']);
        $this->assertEquals(1, $stageResult['counts']['nuevo']);

        $conflictRow = LocImportStagingRow::where('import_batch_id', $batch->id)->where('accion', 'conflicto')->first();
        $this->assertNotNull($conflictRow);
        $this->assertStringContainsString("Clave 'A-001' duplicada dentro del archivo", json_encode($conflictRow->errores));

        // La confirmación debe ser bloqueada
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El lote contiene 1 filas rechazadas o en conflicto');
        $this->importer->commit($batch, $this->adminUser->id);
    }

    /**
     * 3. Reutilización de una misma clave en una versión posterior
     */
    public function test_03_reutilizacion_de_misma_clave_en_version_posterior(): void
    {
        // V1
        $batch1 = $this->createBatch('cat_v1.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('A-001', 'AGRUPAMIENTO ANTIGUO', 'TOLUCA'),
        ], $batch1);
        $this->importer->commit($batch1, $this->adminUser->id);

        $this->assertEquals(1, LocCatalogoOrganizacional::vigente()->count());
        $v1Record = LocCatalogoOrganizacional::vigente()->first();
        $this->assertEquals('AGRUPAMIENTO ANTIGUO', $v1Record->agrupamiento);

        // V2 con la misma clave pero descripción actualizada
        $batch2 = $this->createBatch('cat_v2.xlsx');
        $stageResult2 = $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('A-001', 'AGRUPAMIENTO NUEVO', 'LERMA'),
        ], $batch2);

        $this->assertEquals(1, $stageResult2['counts']['actualizar']);

        // Confirmar V2
        $commitResult2 = $this->importer->commit($batch2, $this->adminUser->id);
        $this->assertEquals(2, $commitResult2['version']);

        // V1 debe seguir existiendo intacto pero con activo = false
        $v1OldRecord = LocCatalogoOrganizacional::where('import_batch_id', $batch1->id)->first();
        $this->assertNotNull($v1OldRecord);
        $this->assertFalse($v1OldRecord->activo);
        $this->assertEquals('AGRUPAMIENTO ANTIGUO', $v1OldRecord->agrupamiento);

        // V2 debe ser el vigente activo
        $v2ActiveRecord = LocCatalogoOrganizacional::vigente()->first();
        $this->assertNotNull($v2ActiveRecord);
        $this->assertEquals($batch2->id, $v2ActiveRecord->import_batch_id);
        $this->assertTrue($v2ActiveRecord->activo);
        $this->assertEquals('AGRUPAMIENTO NUEVO', $v2ActiveRecord->agrupamiento);
        $this->assertEquals('LERMA', $v2ActiveRecord->municipio);
    }

    /**
     * 4. Cambio de descripción de una clave sin alterar la versión anterior
     */
    public function test_04_cambio_de_descripcion_de_clave_sin_alterar_version_anterior(): void
    {
        $batch1 = $this->createBatch('cat_v1.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('B-100', 'AGRUPAMIENTO BASE', 'METEPEC'),
        ], $batch1);
        $this->importer->commit($batch1, $this->adminUser->id);

        $v1Item = LocCatalogoOrganizacional::where('import_batch_id', $batch1->id)->first();
        $v1UpdatedAt = $v1Item->updated_at;

        // V2 modifica región y batallón
        $batch2 = $this->createBatch('cat_v2.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('B-100', 'AGRUPAMIENTO MODIFICADO', 'METEPEC', 'NUEVO BATALLON'),
        ], $batch2);
        $this->importer->commit($batch2, $this->adminUser->id);

        $v1ItemAfter = LocCatalogoOrganizacional::where('import_batch_id', $batch1->id)->first();
        $this->assertEquals('AGRUPAMIENTO BASE', $v1ItemAfter->agrupamiento);
        $this->assertEquals($v1Item->direccion_general, $v1ItemAfter->direccion_general);
        $this->assertFalse($v1ItemAfter->activo);
    }

    /**
     * 5. Archivo con estructura incorrecta
     */
    public function test_05_archivo_con_estructura_incorrecta_es_rechazado(): void
    {
        $batch = $this->createBatch();
        $badHeaders = [
            'COLUMNA_X',
            'COLUMNA_Y',
            'OTRA_COLUMNA',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Estructura inválida: Falta la columna obligatoria');
        $this->importer->stageFromRows([$badHeaders, ['val1', 'val2', 'val3']], $batch);

        $this->assertEquals('fallido', $batch->fresh()->estado);
    }

    /**
     * 6. Clave vacía es rechazada y bloquea confirmación
     */
    public function test_06_clave_vacia_es_rechazada_y_bloquea_confirmacion(): void
    {
        $batch = $this->createBatch();
        $rows = [
            $this->validHeaders(),
            $this->validRow('', 'AGRUPAMIENTO SIN CLAVE', 'TOLUCA'), // Clave vacía
        ];

        $res = $this->importer->stageFromRows($rows, $batch);
        $this->assertEquals(1, $res['counts']['rechazado']);

        $stagingRow = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $this->assertEquals('rechazado', $stagingRow->accion);
        $this->assertStringContainsString('La columna CLAVE es obligatoria', json_encode($stagingRow->errores));

        $this->expectException(RuntimeException::class);
        $this->importer->commit($batch, $this->adminUser->id);
    }

    /**
     * 7. Campos opcionales vacíos normalizan a null
     */
    public function test_07_campos_opcionales_vacios_normalizan_a_null(): void
    {
        $batch = $this->createBatch();
        $rows = [
            $this->validHeaders(),
            $this->validRow('C-001', 'AGRUPAMIENTO SIN BATALLON', 'ZINACANTEPEC', ''), // Batallón string vacío
        ];

        $this->importer->stageFromRows($rows, $batch);
        $this->importer->commit($batch, $this->adminUser->id);

        $record = LocCatalogoOrganizacional::where('clave_origen', 'C-001')->first();
        $this->assertNotNull($record);
        $this->assertNull($record->batallon);
    }

    /**
     * 8. Reintento de un lote confirmado (idempotencia)
     */
    public function test_08_reintento_de_lote_confirmado_es_idempotente(): void
    {
        $batch = $this->createBatch();
        $rows = [
            $this->validHeaders(),
            $this->validRow('A-010', 'AGRUPAMIENTO REINTENTO', 'TOLUCA'),
        ];

        $this->importer->stageFromRows($rows, $batch);
        $firstCommit = $this->importer->commit($batch, $this->adminUser->id);
        $this->assertFalse($firstCommit['already_confirmed']);

        $countBefore = LocCatalogoOrganizacional::count();

        // Segundo intento de commit
        $secondCommit = $this->importer->commit($batch, $this->adminUser->id);
        $this->assertTrue($secondCommit['already_confirmed']);
        $this->assertEquals($countBefore, LocCatalogoOrganizacional::count());
    }

    /**
     * 9. Fallo durante confirmación ejecuta rollback completo
     */
    public function test_09_fallo_durante_confirmacion_ejecuta_rollback_completo(): void
    {
        // 1. Establecer V1
        $batch1 = $this->createBatch('cat_v1.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('A-001', 'AGRUPAMIENTO V1', 'TOLUCA'),
        ], $batch1);
        $this->importer->commit($batch1, $this->adminUser->id);

        $this->assertEquals(1, LocCatalogoOrganizacional::vigente()->count());

        // 2. Preparar V2
        $batch2 = $this->createBatch('cat_v2.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('A-002', 'AGRUPAMIENTO V2', 'LERMA'),
        ], $batch2);

        // Forzar error durante el commit simulando fallo de DB
        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new RuntimeException('Error simulado en base de datos'));

        try {
            $this->importer->commit($batch2, $this->adminUser->id);
            $this->fail('Se esperaba una excepción en commit');
        } catch (RuntimeException $e) {
            $this->assertEquals('Error simulado en base de datos', $e->getMessage());
        }

        // V1 debe seguir vigente y V2 no debe estar activa
        $v1StillActive = LocCatalogoVersion::where('es_vigente', true)->first();
        $this->assertEquals($batch1->id, $v1StillActive->import_batch_id);
    }

    /**
     * 10. Conservación de la versión vigente si la nueva falla
     */
    public function test_10_conservacion_de_version_vigente_si_nueva_falla(): void
    {
        $batch1 = $this->createBatch('cat_v1.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('A-001', 'AGRUPAMIENTO ESTABLE', 'TOLUCA'),
        ], $batch1);
        $this->importer->commit($batch1, $this->adminUser->id);

        // V2 con error estructural
        $batch2 = $this->createBatch('cat_v2_broken.xlsx');
        try {
            $this->importer->stageFromRows([
                ['CABECERA_INCORRECTA'],
                ['dato'],
            ], $batch2);
        } catch (RuntimeException) {}

        // V1 sigue intacta
        $activeRows = LocCatalogoOrganizacional::vigente()->get();
        $this->assertCount(1, $activeRows);
        $this->assertEquals('AGRUPAMIENTO ESTABLE', $activeRows->first()->agrupamiento);
    }

    /**
     * 11. Consulta aislada de versiones anteriores
     */
    public function test_11_consulta_aislada_de_versiones_anteriores(): void
    {
        $batch1 = $this->createBatch('cat_v1.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('KEY-1', 'V1 VAL', 'TOLUCA'),
        ], $batch1);
        $this->importer->commit($batch1, $this->adminUser->id);

        $batch2 = $this->createBatch('cat_v2.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('KEY-1', 'V2 VAL', 'TOLUCA'),
        ], $batch2);
        $this->importer->commit($batch2, $this->adminUser->id);

        // Consultar explícitamente V1
        $v1Rows = LocCatalogoOrganizacional::deVersion($batch1->id)->get();
        $this->assertCount(1, $v1Rows);
        $this->assertEquals('V1 VAL', $v1Rows->first()->agrupamiento);

        // Consultar explícitamente V2
        $v2Rows = LocCatalogoOrganizacional::deVersion($batch2->id)->get();
        $this->assertCount(1, $v2Rows);
        $this->assertEquals('V2 VAL', $v2Rows->first()->agrupamiento);
    }

    /**
     * 12. Confirmaciones simultáneas o repetidas (lock de concurrencia)
     */
    public function test_12_confirmaciones_simultaneas_controladas_por_lock(): void
    {
        $batch = $this->createBatch();
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('L-001', 'AGRUPAMIENTO LOCK', 'TOLUCA'),
        ], $batch);

        // Adquirir el lock manualmente simulando proceso concurrente
        $lock = Cache::lock('loc_catalogo_confirm_lock', 10);
        $lock->acquire();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Otra confirmación del catálogo organizacional se encuentra en ejecución');
            $this->importer->commit($batch, $this->adminUser->id);
        } finally {
            $lock->release();
        }
    }

    /**
     * 13. Archivo idéntico a uno previamente importado (detección de SHA-256 en Controller)
     */
    public function test_13_archivo_identico_a_uno_previamente_importado(): void
    {
        Storage::fake('local');
        $this->actingAs($this->adminUser);

        // Crear archivo Excel real
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            $this->validHeaders(),
            $this->validRow('A-100', 'AGRUPAMIENTO ARCHIVO', 'TOLUCA'),
        ]);
        $writer = new Xlsx($spreadsheet);
        $tempPath = tempnam(sys_get_temp_dir(), 'test_cat');
        $writer->save($tempPath);

        $file1 = new UploadedFile($tempPath, 'catalogo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // Primer upload
        $response1 = $this->post(route('gestion.catalogo.importaciones.subir'), [
            'archivo_catalogo' => $file1,
        ]);
        $response1->assertRedirect();

        $batch1 = LocImportBatch::where('tipo_fuente', LocCatalogoXlsImporter::SOURCE_TYPE)->first();
        $this->assertNotNull($batch1);

        // Segundo upload del archivo idéntico
        $file2 = new UploadedFile($tempPath, 'catalogo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $response2 = $this->post(route('gestion.catalogo.importaciones.subir'), [
            'archivo_catalogo' => $file2,
        ]);

        $response2->assertRedirect(route('gestion.catalogo.importaciones.preview', $batch1));
        $this->assertEquals(1, LocImportBatch::where('tipo_fuente', LocCatalogoXlsImporter::SOURCE_TYPE)->count());

        @unlink($tempPath);
    }

    /**
     * 14. Imposibilidad de publicar lote con conflictos o rechazos
     */
    public function test_14_imposibilidad_de_publicar_lote_con_conflictos_o_rechazos(): void
    {
        $batch = $this->createBatch();
        $rows = [
            $this->validHeaders(),
            $this->validRow('VALID-01', 'AGRUPAMIENTO VALIDO', 'TOLUCA'),
            $this->validRow('VALID-01', 'AGRUPAMIENTO CONFLICTO', 'LERMA'), // Clave duplicada
        ];

        $this->importer->stageFromRows($rows, $batch);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('El lote contiene 1 filas rechazadas o en conflicto');
        $this->importer->commit($batch, $this->adminUser->id);

        $this->assertEquals(0, LocCatalogoOrganizacional::count());
    }

    /**
     * 15. Detección de alteraciones de staging después de la vista previa (revision_sha256 mismatch)
     */
    public function test_15_deteccion_de_alteraciones_de_staging_despues_de_vista_previa(): void
    {
        $batch = $this->createBatch();
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('A-999', 'AGRUPAMIENTO FINGERPRINT', 'TOLUCA'),
        ], $batch);

        // Alterar maliciosamente o accidentalmente una fila en staging
        $stagingRow = LocImportStagingRow::where('import_batch_id', $batch->id)->first();
        $stagingRow->clave_origen = 'A-HACKED';
        $stagingRow->save();

        // El commit debe detectar el cambio en la huella de revisión
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('La revisión del lote cambió después del análisis inicial');
        $this->importer->commit($batch, $this->adminUser->id);
    }

    /**
     * 16. Consultas del catálogo vigente no mezclan versiones
     */
    public function test_16_consultas_del_catalogo_vigente_no_mezclan_versiones(): void
    {
        // V1 tiene 2 registros
        $b1 = $this->createBatch('v1.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('K-1', 'AGR 1', 'TOLUCA'),
            $this->validRow('K-2', 'AGR 2', 'TOLUCA'),
        ], $b1);
        $this->importer->commit($b1, $this->adminUser->id);

        // V2 elimina K-2 y agrega K-3
        $b2 = $this->createBatch('v2.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('K-1', 'AGR 1', 'TOLUCA'),
            $this->validRow('K-3', 'AGR 3', 'TOLUCA'),
        ], $b2);
        $this->importer->commit($b2, $this->adminUser->id);

        // Consulta autoritativa vigente
        $vigentes = LocCatalogoOrganizacional::vigente()->pluck('clave_origen')->all();

        $this->assertEqualsCanonicalizing(['K-1', 'K-3'], $vigentes);
        $this->assertNotContains('K-2', $vigentes);
        $this->assertEquals(2, LocCatalogoOrganizacional::vigente()->count());
    }

    /**
     * 17. Integridad de la referencia al lote vigente
     */
    public function test_17_integridad_de_la_referencia_al_lote_vigente(): void
    {
        $this->assertNull(LocCatalogoOrganizacional::versionVigente());

        $b1 = $this->createBatch('v1.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('REF-1', 'REF V1', 'TOLUCA'),
        ], $b1);
        $this->importer->commit($b1, $this->adminUser->id);

        $v1 = LocCatalogoOrganizacional::versionVigente();
        $this->assertNotNull($v1);
        $this->assertEquals(1, $v1->version_numero);
        $this->assertEquals($b1->id, $v1->import_batch_id);
        $this->assertTrue($v1->es_vigente);

        $b2 = $this->createBatch('v2.xlsx');
        $this->importer->stageFromRows([
            $this->validHeaders(),
            $this->validRow('REF-1', 'REF V2', 'TOLUCA'),
        ], $b2);
        $this->importer->commit($b2, $this->adminUser->id);

        $v2 = LocCatalogoOrganizacional::versionVigente();
        $this->assertNotNull($v2);
        $this->assertEquals(2, $v2->version_numero);
        $this->assertEquals($b2->id, $v2->import_batch_id);
        $this->assertTrue($v2->es_vigente);

        // Comprobar que V1 ya no tiene es_vigente = true
        $v1Fresh = LocCatalogoVersion::where('version_numero', 1)->first();
        $this->assertFalse($v1Fresh->es_vigente);
    }
}
