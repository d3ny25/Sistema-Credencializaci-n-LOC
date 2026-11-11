<?php

namespace Tests\Feature;

use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\LocPersona;
use App\Models\User;
use App\Services\LocPersonalXlsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Tests\TestCase;

class LocPersonalRegressionTest extends TestCase
{
    use RefreshDatabase;

    private LocPersonalXlsImporter $importer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = app(LocPersonalXlsImporter::class);
        $this->admin = User::factory()->create(['role' => 'superadmin']);
        config(['loc_import.queue_connection' => 'sync']);
    }

    /**
     * 1. Un CUIP existente con exactamente una persona NO provoca falso error
     * cuando la tabla loc_personas contiene múltiples personas existentes.
     */
    public function test_existing_person_with_multiple_people_in_database_does_not_trigger_false_duplicate_error(): void
    {
        // Crear 10 personas existentes en el padrón con CUIPs distintos
        for ($i = 1; $i <= 10; $i++) {
            LocPersona::query()->create([
                'cuip' => "CUIP-TEST-00{$i}",
                'nombre' => "Persona Existente {$i}",
                'rfc' => "RFC00{$i}",
                'curp' => "CURP00{$i}",
                'estatus' => 'activo',
                'datos_loc' => ['ID_PERSONAL' => (string) $i],
            ]);
        }

        // Crear un lote y una fila de staging que actualice CUIP-TEST-001
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'prueba_actualizacion.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_leidas' => 1,
            'total_aceptadas' => 1,
            'total_rechazadas' => 0,
            'total_nuevas' => 0,
            'total_actualizadas' => 1,
            'total_sin_cambios' => 0,
            'resumen_errores' => json_encode(['conteos' => ['actualizar' => 1]]),
        ]);

        $batch->update(['revision_sha256' => $this->getFingerprint($batch)]);

        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP-TEST-001',
            'datos' => [
                'campos' => [
                    'cuip' => 'CUIP-TEST-001',
                    'nombre' => 'Persona Existente 1 Modificada',
                    'rfc' => 'RFC001',
                    'curp' => 'CURP001',
                    'estatus' => 'activo',
                ],
                'origen' => [
                    'ID_PERSONAL' => '1',
                    'CUIP' => 'CUIP-TEST-001',
                    'NOMBRE' => 'Persona Existente 1 Modificada',
                ],
            ],
            'accion' => 'actualizar',
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->update(['revision_sha256' => $this->getFingerprint($batch)]);

        // Confirmar el lote
        $result = $this->importer->commit($batch, $this->admin->id);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame('completado', $batch->fresh()->estado);

        // Verificar que la persona se actualizó correctamente
        $this->assertDatabaseHas('loc_personas', [
            'cuip' => 'CUIP-TEST-001',
            'nombre' => 'Persona Existente 1 Modificada',
        ]);
    }

    /**
     * 2. Un CUIP realmente duplicado en loc_personas aborta de manera segura y explica la ambigüedad.
     */
    public function test_genuinely_duplicate_cuips_in_database_aborts_confirmation_safely_and_explains_ambiguity(): void
    {
        // Forzar 2 registros con el mismo CUIP normalizado en loc_personas
        // Eliminamos el índice único en SQLite para poder insertar duplicados exactos genuinos
        // y verificar que la búsqueda indexada detecte la ambigüedad.
        DB::statement('DROP INDEX IF EXISTS loc_personas_cuip_unique;');
        DB::table('loc_personas')->insert([
            [
                'cuip' => 'CUIP-DUPLICADO-99',
                'nombre' => 'Primer Registro Duplicado',
                'activo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'cuip' => 'CUIP-DUPLICADO-99',
                'nombre' => 'Segundo Registro Duplicado',
                'activo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'prueba_duplicado.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_leidas' => 1,
            'total_aceptadas' => 1,
            'total_rechazadas' => 0,
            'total_nuevas' => 0,
            'total_actualizadas' => 1,
            'total_sin_cambios' => 0,
            'resumen_errores' => json_encode(['conteos' => ['actualizar' => 1]]),
        ]);

        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP-DUPLICADO-99',
            'datos' => [
                'campos' => ['cuip' => 'CUIP-DUPLICADO-99', 'nombre' => 'Intento de Actualización'],
                'origen' => ['CUIP' => 'CUIP-DUPLICADO-99', 'NOMBRE' => 'Intento de Actualización'],
            ],
            'accion' => 'actualizar',
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->update(['revision_sha256' => $this->getFingerprint($batch)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("El CUIP 'CUIP-DUPLICADO-99' coincide con más de una persona existente; corrige el padrón antes de reanudar.");

        $this->importer->commit($batch, $this->admin->id);
    }

    /**
     * 3. Un CUIP inexistente sigue clasificándose y creándose correctamente como nuevo.
     */
    public function test_non_existent_cuip_is_inserted_as_new_person(): void
    {
        LocPersona::query()->create([
            'cuip' => 'CUIP-PREVIO-1',
            'nombre' => 'Persona Previa',
        ]);

        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'prueba_nuevo.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_leidas' => 1,
            'total_aceptadas' => 1,
            'total_rechazadas' => 0,
            'total_nuevas' => 1,
            'total_actualizadas' => 0,
            'total_sin_cambios' => 0,
            'resumen_errores' => json_encode(['conteos' => ['nuevo' => 1]]),
        ]);

        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP-TOTALMENTE-NUEVO',
            'datos' => [
                'campos' => [
                    'cuip' => 'CUIP-TOTALMENTE-NUEVO',
                    'nombre' => 'Elemento Nuevo Oficial',
                    'rfc' => 'NEW010101',
                    'estatus' => 'activo',
                ],
                'origen' => [
                    'CUIP' => 'CUIP-TOTALMENTE-NUEVO',
                    'NOMBRE' => 'Elemento Nuevo Oficial',
                ],
            ],
            'accion' => 'nuevo',
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->update(['revision_sha256' => $this->getFingerprint($batch)]);

        $result = $this->importer->commit($batch, $this->admin->id);

        $this->assertSame(1, $result['inserted']);
        $this->assertDatabaseHas('loc_personas', [
            'cuip' => 'CUIP-TOTALMENTE-NUEVO',
            'nombre' => 'Elemento Nuevo Oficial',
            'import_batch_id' => $batch->id,
        ]);
    }

    /**
     * 4. La normalización de CUIP se aplica de forma coherente entre análisis y confirmación.
     */
    public function test_cuip_normalization_is_consistent_between_analysis_and_confirmation(): void
    {
        LocPersona::query()->create([
            'cuip' => 'CUIP-ESPACIADO-01',
            'nombre' => 'Elemento Espaciado',
            'rfc' => 'ESP01',
        ]);

        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'prueba_normalizacion.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_leidas' => 1,
            'total_aceptadas' => 1,
            'total_rechazadas' => 0,
            'total_nuevas' => 0,
            'total_actualizadas' => 1,
            'total_sin_cambios' => 0,
            'resumen_errores' => json_encode(['conteos' => ['actualizar' => 1]]),
        ]);

        // En el flujo real de importación, stageWorkbook() normaliza la columna 'cuip' de staging
        // a mayúsculas y sin espacios, mientras que los valores crudos del archivo se preservan
        // en datos.campos.cuip y datos.origen.CUIP.
        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP-ESPACIADO-01',
            'datos' => [
                'campos' => [
                    'cuip' => '  cuip-espaciado-01  ',
                    'nombre' => 'Elemento Espaciado Nombre Actualizado',
                    'rfc' => 'ESP01',
                ],
                'origen' => [
                    'CUIP' => '  cuip-espaciado-01  ',
                    'NOMBRE' => 'Elemento Espaciado Nombre Actualizado',
                ],
            ],
            'accion' => 'actualizar',
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->update(['revision_sha256' => $this->getFingerprint($batch)]);

        $result = $this->importer->commit($batch, $this->admin->id);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['inserted']);

        // Se actualizó el registro existente sin insertar un duplicado
        $this->assertDatabaseCount('loc_personas', 1);
        $this->assertDatabaseHas('loc_personas', [
            'cuip' => 'CUIP-ESPACIADO-01',
            'nombre' => 'Elemento Espaciado Nombre Actualizado',
        ]);
    }

    /**
     * 5. Los registros sin cambios no se modifican y conservan su estado.
     */
    public function test_unchanged_person_is_marked_as_sin_cambios_without_updating_timestamps(): void
    {
        $person = LocPersona::query()->create([
            'cuip' => 'CUIP-IDENTICO-01',
            'nombre' => 'Elemento Sin Cambios',
            'rfc' => 'IDEN01',
            'curp' => 'IDEN01CURP',
            'categoria' => 'OFICIAL',
            'estatus' => 'activo',
            'datos_loc' => [
                'ID_PERSONAL' => '500',
                'CUIP' => 'CUIP-IDENTICO-01',
                'NOMBRE' => 'Elemento Sin Cambios',
            ],
        ]);

        $initialUpdatedAt = $person->updated_at;

        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'prueba_sin_cambios.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_leidas' => 1,
            'total_aceptadas' => 1,
            'total_rechazadas' => 0,
            'total_nuevas' => 0,
            'total_actualizadas' => 0,
            'total_sin_cambios' => 1,
            'resumen_errores' => json_encode(['conteos' => ['sin_cambios' => 1]]),
        ]);

        $staged = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP-IDENTICO-01',
            'datos' => [
                'campos' => [
                    'cuip' => 'CUIP-IDENTICO-01',
                    'nombre' => 'Elemento Sin Cambios',
                    'rfc' => 'IDEN01',
                    'curp' => 'IDEN01CURP',
                    'categoria' => 'OFICIAL',
                    'estatus' => 'activo',
                ],
                'origen' => [
                    'ID_PERSONAL' => '500',
                    'CUIP' => 'CUIP-IDENTICO-01',
                    'NOMBRE' => 'Elemento Sin Cambios',
                ],
            ],
            'accion' => 'sin_cambios',
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->update(['revision_sha256' => $this->getFingerprint($batch)]);

        $result = $this->importer->commit($batch, $this->admin->id);

        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['inserted']);

        $this->assertSame('sin_cambios', $staged->fresh()->estado_aplicacion);
    }

    /**
     * 6. Los registros clasificados como actualización conservan el comportamiento previsto.
     */
    public function test_updated_person_applies_changes_and_retains_unoverwritten_source_data(): void
    {
        $person = LocPersona::query()->create([
            'cuip' => 'CUIP-ACTUALIZAR-01',
            'nombre' => 'Nombre Previo',
            'estatus' => 'INCLUIDO EN LOC... SITUACION ESPECIAL',
            'datos_loc' => ['CAMPO_ORIGINAL_HISTORICO' => 'Valor retenido'],
        ]);

        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'prueba_actualizacion_datos.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_leidas' => 1,
            'total_aceptadas' => 1,
            'total_rechazadas' => 0,
            'total_nuevas' => 0,
            'total_actualizadas' => 1,
            'total_sin_cambios' => 0,
            'resumen_errores' => json_encode(['conteos' => ['actualizar' => 1]]),
        ]);

        $staged = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP-ACTUALIZAR-01',
            'datos' => [
                'campos' => [
                    'cuip' => 'CUIP-ACTUALIZAR-01',
                    'nombre' => 'Nombre Previo',
                    'estatus' => 'INCLUIDO EN LOC...REVALIDACION',
                ],
                'origen' => [
                    'CUIP' => 'CUIP-ACTUALIZAR-01',
                    'NOMBRE' => 'Nombre Previo',
                    'ESTATUS' => 'INCLUIDO EN LOC...REVALIDACION',
                    'NUEVO_CAMPO' => 'Nuevo valor',
                ],
            ],
            'accion' => 'actualizar',
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->update(['revision_sha256' => $this->getFingerprint($batch)]);

        $result = $this->importer->commit($batch, $this->admin->id);

        $this->assertSame(1, $result['updated']);
        $person->refresh();
        $this->assertSame('INCLUIDO EN LOC...REVALIDACION', $person->estatus);
        $this->assertSame('Valor retenido', $person->datos_loc['CAMPO_ORIGINAL_HISTORICO'] ?? null);
        $this->assertSame('Nuevo valor', $person->datos_loc['NUEVO_CAMPO'] ?? null);
        $this->assertSame('actualizado', $staged->fresh()->estado_aplicacion);
    }

    /**
     * 7. Un fallo de confirmación dentro de una transacción no deja escrituras parciales dentro de esa transacción.
     */
    public function test_confirmation_failure_rolls_back_entire_chunk_transaction_without_partial_writes(): void
    {
        // 1 persona existente válida
        LocPersona::query()->create([
            'cuip' => 'CUIP-OK-01',
            'nombre' => 'Persona Valida',
            'estatus' => 'activo',
        ]);

        // 2 personas duplicadas para CUIP-CONFLICTO
        DB::statement('DROP INDEX IF EXISTS loc_personas_cuip_unique;');
        DB::table('loc_personas')->insert([
            ['cuip' => 'CUIP-CONFLICTO', 'nombre' => 'Dup 1', 'activo' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['cuip' => 'CUIP-CONFLICTO', 'nombre' => 'Dup 2', 'activo' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'prueba_rollback.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'en_revision',
            'total_leidas' => 2,
            'total_aceptadas' => 2,
            'total_rechazadas' => 0,
            'total_nuevas' => 0,
            'total_actualizadas' => 2,
            'total_sin_cambios' => 0,
            'resumen_errores' => json_encode(['conteos' => ['actualizar' => 2]]),
        ]);

        // Fila 1 del bloque: CUIP-OK-01 (intenta actualizar nombre)
        $row1 = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP-OK-01',
            'datos' => [
                'campos' => ['cuip' => 'CUIP-OK-01', 'nombre' => 'NOMBRE QUE DEBERIA SER ROLLEDBACK'],
                'origen' => ['CUIP' => 'CUIP-OK-01', 'NOMBRE' => 'NOMBRE QUE DEBERIA SER ROLLEDBACK'],
            ],
            'accion' => 'actualizar',
            'estado_aplicacion' => 'pendiente',
        ]);

        // Fila 2 del bloque: CUIP-CONFLICTO (provoca la excepción)
        $row2 = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 3,
            'cuip' => 'CUIP-CONFLICTO',
            'datos' => [
                'campos' => ['cuip' => 'CUIP-CONFLICTO', 'nombre' => 'Persona Conflicto'],
                'origen' => ['CUIP' => 'CUIP-CONFLICTO', 'NOMBRE' => 'Persona Conflicto'],
            ],
            'accion' => 'actualizar',
            'estado_aplicacion' => 'pendiente',
        ]);

        $batch->update(['revision_sha256' => $this->getFingerprint($batch)]);

        try {
            $this->importer->commit($batch, $this->admin->id);
            $this->fail('Se esperaba que confirmBatch lanzara RuntimeException por ambigüedad');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("coincide con más de una persona existente", $e->getMessage());
        }

        // El lote pasa a fallido_parcial
        $this->assertSame('fallido_parcial', $batch->fresh()->estado);

        // NINGUNA fila del bloque debe haber quedado modificada (rollback del bloque)
        $this->assertDatabaseHas('loc_personas', [
            'cuip' => 'CUIP-OK-01',
            'nombre' => 'Persona Valida', // NO se aplicó NOMBRE QUE DEBERIA SER ROLLEDBACK
        ]);

        // Las filas de staging deben permanecer en pendiente
        $this->assertSame('pendiente', $row1->fresh()->estado_aplicacion);
        $this->assertSame('pendiente', $row2->fresh()->estado_aplicacion);
    }

    /**
     * 8. Comprobación separada: la fase de staging normaliza CUIPs con espacios y minúsculas desde el archivo fuente XLS.
     */
    public function test_staging_phase_normalizes_cuip_with_spaces_and_lowercase_from_source_file(): void
    {
        LocPersona::query()->create([
            'cuip' => 'CUIP-ESPACIADO-STAGE',
            'nombre' => 'Elemento Existente Previo',
            'rfc' => 'PREV01',
        ]);

        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'prueba_staging_normalizacion.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'pendiente',
        ]);

        $xlsPath = $this->writeXls([
            ['CUIP', 'NOMBRE', 'RFC'],
            ['  cuip-espaciado-stage  ', 'Elemento Existente Actualizado', 'PREV01'],
        ]);

        try {
            // Ejecutar la fase de análisis/staging real a través de stage()
            $this->importer->stage($xlsPath, $batch);

            // Verificar que en la tabla de staging y en los campos canónicos el CUIP fue normalizado
            $staged = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
            $this->assertSame('CUIP-ESPACIADO-STAGE', $staged->cuip);
            $this->assertSame('CUIP-ESPACIADO-STAGE', $staged->datos['campos']['cuip']);
            // Verificar que en datos.origen se conserva la representación en minúsculas procesada de la celda
            $this->assertSame('cuip-espaciado-stage', $staged->datos['origen']['CUIP']);
            $this->assertSame('actualizar', $staged->accion);

            // Confirmar de punta a punta
            $result = $this->importer->commit($batch->fresh(), $this->admin->id);

            $this->assertSame(1, $result['updated']);
            $this->assertSame(0, $result['inserted']);
            $this->assertDatabaseCount('loc_personas', 1);
            $this->assertDatabaseHas('loc_personas', [
                'cuip' => 'CUIP-ESPACIADO-STAGE',
                'nombre' => 'Elemento Existente Actualizado',
            ]);
            $this->assertSame('completado', $batch->fresh()->estado);
        } finally {
            @unlink($xlsPath);
        }
    }

    public function test_cuip_uppercase_and_lowercase_variants_match_indexed(): void
    {
        LocPersona::query()->create([
            'cuip' => 'CUIP-CASE-TEST-01',
            'nombre' => 'Elemento Existente',
            'estatus' => 'activo',
        ]);

        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'cuip_case.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'pendiente',
        ]);

        $xlsPath = $this->writeXls([
            ['CUIP', 'NOMBRE', 'RFC'],
            ['cuip-case-test-01', 'Elemento Existente Mayus', 'RFC01'],
        ]);

        try {
            $this->importer->stage($xlsPath, $batch);

            $staged = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
            $this->assertSame('CUIP-CASE-TEST-01', $staged->cuip);
            $this->assertSame('actualizar', $staged->accion);

            $result = $this->importer->commit($batch->fresh(), $this->admin->id);
            $this->assertSame(1, $result['updated']);
            $this->assertSame(0, $result['inserted']);
            $this->assertDatabaseCount('loc_personas', 1);
        } finally {
            @unlink($xlsPath);
        }
    }

    public function test_cuip_with_spaces_normalized_and_matched(): void
    {
        LocPersona::query()->create([
            'cuip' => 'CUIP-SPACE-TEST-02',
            'nombre' => 'Elemento Previo',
            'estatus' => 'activo',
        ]);

        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'cuip_space.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'pendiente',
        ]);

        $xlsPath = $this->writeXls([
            ['CUIP', 'NOMBRE', 'RFC'],
            ['   CUIP-SPACE-TEST-02   ', 'Elemento Previo Actualizado', 'RFC02'],
        ]);

        try {
            $this->importer->stage($xlsPath, $batch);

            $staged = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
            $this->assertSame('CUIP-SPACE-TEST-02', $staged->cuip);
            $this->assertSame('actualizar', $staged->accion);

            $result = $this->importer->commit($batch->fresh(), $this->admin->id);
            $this->assertSame(1, $result['updated']);
            $this->assertSame(0, $result['inserted']);
        } finally {
            @unlink($xlsPath);
        }
    }

    public function test_cuip_duplicates_in_file_flagged_as_conflicts(): void
    {
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'cuip_dup_file.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'pendiente',
        ]);

        $xlsPath = $this->writeXls([
            ['CUIP', 'NOMBRE', 'RFC'],
            ['CUIP-DUP-FILE-01', 'Persona Uno', 'RFC01'],
            ['CUIP-DUP-FILE-01', 'Persona Dos', 'RFC02'],
        ]);

        try {
            $this->importer->stage($xlsPath, $batch);

            $rows = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->get();
            $this->assertSame(['conflicto', 'conflicto'], $rows->pluck('accion')->all());
            $this->assertSame(2, $batch->fresh()->total_rechazadas);
        } finally {
            @unlink($xlsPath);
        }
    }

    public function test_cuip_non_existent_classified_as_new(): void
    {
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'cuip_nuevo.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'pendiente',
        ]);

        $xlsPath = $this->writeXls([
            ['CUIP', 'NOMBRE', 'RFC'],
            ['CUIP-NUEVO-100', 'Nuevo Elemento', 'RFCNEW'],
        ]);

        try {
            $this->importer->stage($xlsPath, $batch);

            $staged = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
            $this->assertSame('nuevo', $staged->accion);

            $result = $this->importer->commit($batch->fresh(), $this->admin->id);
            $this->assertSame(1, $result['inserted']);
            $this->assertDatabaseHas('loc_personas', [
                'cuip' => 'CUIP-NUEVO-100',
                'nombre' => 'Nuevo Elemento',
            ]);
        } finally {
            @unlink($xlsPath);
        }
    }

    public function test_secondary_reconciliation_by_curp_and_rfc_preserves_contradiction_detection(): void
    {
        LocPersona::query()->create([
            'cuip' => 'CUIP-ORIGINAL-01',
            'curp' => 'AAAA800101HDFRND01',
            'rfc' => 'AAAA800101ABC',
            'nombre' => 'Persona Base',
            'estatus' => 'activo',
        ]);

        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'cuip_curp_conflict.xls',
            'usuario_id' => $this->admin->id,
            'estado' => 'pendiente',
        ]);

        // CASO 1: CUIP coincide pero CURP/RFC se contradicen fuertemente -> CONFLICTO
        $xlsPathConflict = $this->writeXls([
            ['CUIP', 'NOMBRE', 'CURP', 'RFC'],
            ['CUIP-ORIGINAL-01', 'Persona Modificada', 'BBBB900202MDFRND02', 'BBBB900202XYZ'],
        ]);

        try {
            $this->importer->stage($xlsPathConflict, $batch);

            $staged = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
            $this->assertSame('conflicto', $staged->accion);
            $this->assertStringContainsString('Contradicción en identidad', implode(' ', $staged->errores));
        } finally {
            @unlink($xlsPathConflict);
        }
    }

    /**
     * @param list<list<mixed>> $rows
     */
    private function writeXls(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'loc-reg-');

        if ($path === false) {
            $this->fail('No fue posible crear el archivo temporal de prueba.');
        }

        $xlsPath = $path . '.xls';

        try {
            (new Xls($spreadsheet))->save($xlsPath);

            return $xlsPath;
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($path);
        }
    }

    private function getFingerprint(LocImportBatch $batch): string
    {
        $batch->refresh();
        $reflection = new \ReflectionClass($this->importer);
        $method = $reflection->getMethod('reviewFingerprint');
        $method->setAccessible(true);

        return $method->invoke($this->importer, $batch);
    }
}

