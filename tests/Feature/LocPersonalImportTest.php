<?php

namespace Tests\Feature;

use App\Exceptions\LocImportDiagnosticException;
use App\Jobs\ProcessLocImportBatch;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\LocPersona;
use App\Models\User;
use App\Services\LocPersonalXlsImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Tests\TestCase;

class LocPersonalImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['loc_import.queue_connection' => 'sync']);
    }

    public function test_authenticated_superadministrator_can_import_while_local_demo_flag_is_enabled(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);
        config([
            'app.env' => 'local',
            'local-demo.enabled' => true,
        ]);
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE'],
                    ['REAL-ADMIN-01', 'Persona sintética autorizada'],
                ]),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $this->assertSame($administrator->getKey(), $batch->usuario_id);
        $this->assertDatabaseCount('loc_personas', 0);

        $this->post(route('gestion.importaciones.confirmar', $batch))
            ->assertRedirect(route('gestion.historial'));

        $this->assertDatabaseHas('loc_personas', [
            'cuip' => 'REAL-ADMIN-01',
            'nombre' => 'Persona sintética autorizada',
        ]);
    }

    public function test_personnel_xls_is_staged_reviewed_and_committed_only_after_confirmation(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();
        $this->assertGreaterThanOrEqual(30 * 1024, config('loc_import.max_file_kilobytes'));

        $this->actingAs($administrator)
            ->get(route('gestion'))
            ->assertOk()
            ->assertSee('Últimas importaciones')
            ->assertSee('Analizar archivo')
            ->assertSee('action="'.route('gestion.importaciones.subir').'"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="archivo_loc"', false)
            ->assertSee('accept=".xls,application/vnd.ms-excel"', false)
            ->assertSee('Excel binario .xls')
            ->assertSee('no se admiten .xlsx')
            ->assertSee('Analizando archivo', false)
            ->assertSee('loc-upload-spinner', false);

        $response = $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE', 'RFC', 'ESTATUS', 'FECHA DE BAJA'],
                    ['LOC-001', 'Ana Ejemplo', 'RFC-001', 'ACTIVO', ''],
                    ['LOC-002', 'Luis Ejemplo', 'RFC-002', 'BAJA', ''],
                    ['', 'Sin CUIP', '', 'ACTIVO', ''],
                    ['LOC-001', 'Duplicado', '', 'ACTIVO', ''],
                    ['LOC-004', 'Mara Ejemplo', '', 'ACTIVO', '21/10/2026'],
                    ['LOC-005', 'Fecha inválida', '', 'ACTIVO', '31/02/2026'],
                ]),
            ]);

        $batch = LocImportBatch::query()->sole();
        $response->assertRedirect(route('gestion.importaciones.preview', $batch));
        $this->assertSame('en_revision', $batch->estado);
        $this->assertSame(6, $batch->total_leidas);
        $this->assertSame(2, $batch->total_aceptadas);
        $this->assertSame(4, $batch->total_rechazadas);
        $this->getJson(route('gestion.importaciones.progreso', $batch))
            ->assertOk()
            ->assertJsonPath('status', 'en_revision')
            ->assertJsonPath('rows_read', 6)
            ->assertJsonPath('new', 2)
            ->assertJsonPath('rejected', 4);
        $this->assertDatabaseCount('loc_personas', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('loc-imports'));

        $this->actingAs($administrator)
            ->get(route('gestion.importaciones.preview', $batch))
            ->assertOk()
            ->assertSee('Validación previa de importación')
            ->assertSee('Ana Ejemplo')
            ->assertSee('CUIP obligatorio')
            ->assertSee('FECHA DE BAJA no contiene una fecha válida')
            ->assertSee('Confirmar importación');

        $this->post(route('gestion.importaciones.confirmar', $batch))
            ->assertRedirect(route('gestion.historial'));

        $this->assertDatabaseCount('loc_personas', 2);
        $this->assertDatabaseMissing('loc_personas', ['cuip' => 'LOC-001']);
        $this->assertDatabaseMissing('loc_personas', ['nombre' => 'Sin CUIP']);
        $this->assertDatabaseMissing('loc_personas', ['nombre' => 'Fecha inválida']);
        $this->assertDatabaseHas('loc_personas', [
            'cuip' => 'LOC-002',
            'nombre' => 'Luis Ejemplo',
            'activo' => true,
        ]);
        $terminatedPerson = LocPersona::query()->where('cuip', 'LOC-004')->sole();
        $this->assertSame('Mara Ejemplo', $terminatedPerson->nombre);
        $this->assertFalse($terminatedPerson->activo);
        $this->assertSame('2026-10-21', $terminatedPerson->fecha_baja->format('Y-m-d'));
        $this->assertSame('completado', $batch->fresh()->estado);

        $this->get(route('gestion.historial'))
            ->assertOk()
            ->assertSee('personal.xls')
            ->assertSee('Completado')
            ->assertSee('Ver filas');

        $this->post(route('gestion.importaciones.confirmar', $batch))
            ->assertSessionHasErrors('importacion');
        $this->assertDatabaseCount('loc_personas', 2);
    }

    public function test_upload_queues_processing_and_reports_live_status_without_applying_people(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['loc_import.queue_connection' => 'loc_import']);
        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();
        $file = $this->xlsUpload([
            ['CUIP', 'NOMBRE'],
            ['QUEUE-01', 'Persona de prueba'],
        ]);

        $response = $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), ['archivo_loc' => $file])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $response->assertRedirect(route('gestion.importaciones.preview', $batch));
        $this->assertSame('pendiente', $batch->estado);
        $this->assertDatabaseCount('loc_personas', 0);
        Queue::assertPushedOn('loc-imports', ProcessLocImportBatch::class);

        $this->get(route('gestion.importaciones.preview', $batch))
            ->assertOk()
            ->assertSee('Análisis en cola')
            ->assertSee(route('gestion.importaciones.progreso', $batch), false);

        $this->getJson(route('gestion.importaciones.progreso', $batch))
            ->assertOk()
            ->assertJsonPath('status', 'pendiente')
            ->assertJsonPath('rows_read', 0)
            ->assertJsonStructure(['queued_seconds', 'updated_seconds']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), ['archivo_loc' => $file])
            ->assertRedirect(route('gestion.importaciones.preview', $batch));

        $this->assertDatabaseCount('loc_import_batches', 1);
        Queue::assertPushed(ProcessLocImportBatch::class, 1);
    }

    public function test_reuploading_a_confirmed_file_reuses_the_existing_batch(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();
        $file = $this->xlsUpload([
            ['CUIP', 'NOMBRE'],
            ['IDEMPOTENT-01', 'Persona de prueba'],
        ]);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), ['archivo_loc' => $file])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $this->post(route('gestion.importaciones.confirmar', $batch))
            ->assertRedirect(route('gestion.historial'));
        $this->assertSame('completado', $batch->fresh()->estado);
        $this->assertDatabaseCount('loc_personas', 1);

        $this->post(route('gestion.importaciones.subir'), ['archivo_loc' => $file])
            ->assertRedirect(route('gestion.importaciones.preview', $batch))
            ->assertSessionHas('success', 'Este archivo ya fue confirmado; se abrió el lote existente para consultar el resultado.');

        $this->assertDatabaseCount('loc_import_batches', 1);
        $this->assertDatabaseCount('loc_personas', 1);
    }

    public function test_missing_loc_staging_migrations_block_upload_before_creating_a_batch(): void
    {
        Schema::drop('loc_import_staging_rows');
        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();

        $this->actingAs($administrator)
            ->get(route('gestion'))
            ->assertOk()
            ->assertSee('faltan las migraciones LOC 2026_10_21_000002')
            ->assertSee('No cargues archivos');

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE'],
                    ['SCHEMA-01', 'Persona de prueba'],
                ]),
            ])
            ->assertSessionHasErrors('archivo_loc');

        $this->assertDatabaseCount('loc_import_batches', 0);
        $this->assertDatabaseCount('loc_personas', 0);
    }

    public function test_failed_background_analysis_persists_a_safe_error_and_cleans_the_temporary_file(): void
    {
        Storage::fake('local');
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'datos-sinteticos.xls',
            'fecha_procesamiento' => now(),
            'estado' => 'procesando',
            'archivo_sha256' => hash('sha256', 'synthetic-file'),
        ]);
        $path = 'loc-imports/synthetic.xls';
        Storage::disk('local')->put($path, 'temporary synthetic content');

        $job = new ProcessLocImportBatch($batch->id, $path);
        $job->failed(new \RuntimeException('synthetic exception with private row value'));

        $failedBatch = $batch->fresh();
        $summary = json_decode($failedBatch->resumen_errores, true);

        $this->assertSame('fallido', $failedBatch->estado);
        $this->assertStringContainsString('No fue posible analizar el archivo', $summary['error']);
        $this->assertStringNotContainsString('private row value', $failedBatch->resumen_errores);
        $this->assertNotEmpty($summary['diagnostico']['incident_id']);
        $this->assertSame('procesamiento_job', $summary['diagnostico']['stage']);
        $this->assertSame('LOC_IMPORT_JOB_FAILED', $summary['diagnostico']['error_code']);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('loc_personas', 0);
    }

    public function test_failed_partial_staging_is_preserved_but_cannot_be_confirmed(): void
    {
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'partial-synthetic.xls',
            'estado' => 'procesando',
        ]);
        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'PARTIAL-01',
            'datos' => ['campos' => ['cuip' => 'PARTIAL-01'], 'origen' => []],
            'errores' => [],
            'accion' => 'pendiente',
            'estado_aplicacion' => 'pendiente',
        ]);

        (new ProcessLocImportBatch($batch->id, 'loc-imports/partial.xls'))
            ->failed(new \Error('Allowed memory size of 536870912 bytes exhausted (tried to allocate 2048 bytes)'));

        $failedBatch = $batch->fresh();
        $diagnostic = json_decode($failedBatch->resumen_errores, true)['diagnostico'];
        $this->assertSame('fallido', $failedBatch->estado);
        $this->assertSame('PHP_MEMORY_LIMIT_EXCEEDED', $diagnostic['error_code']);
        $this->assertSame(1, $diagnostic['staging_rows_preserved']);
        $this->assertDatabaseCount('loc_import_staging_rows', 1);

        try {
            app(LocPersonalXlsImporter::class)->commit($failedBatch);
            $this->fail('Un lote fallido no debe estar disponible para confirmación.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Esta importación no está disponible para confirmación o reanudación.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('loc_personas', 0);
    }

    public function test_importer_persists_actual_row_progress_after_each_read_chunk(): void
    {
        config(['loc_import.read_chunk_size' => 1]);
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'synthetic-progress.xls',
            'fecha_procesamiento' => now(),
            'estado' => 'procesando',
            'archivo_sha256' => hash('sha256', 'synthetic-progress-file'),
        ]);
        $file = $this->xlsUpload([
            ['CUIP', 'NOMBRE'],
            ['PROGRESS-01', 'Persona sintética uno'],
            ['PROGRESS-02', 'Persona sintética dos'],
            ['PROGRESS-03', 'Persona sintética tres'],
        ]);
        $observedRows = [];
        $batchId = $batch->id;
        LocImportBatch::updated(function (LocImportBatch $updated) use (&$observedRows, $batchId): void {
            if ($updated->id === $batchId && $updated->estado === 'procesando' && $updated->total_leidas > 0) {
                $rowsRead = $updated->total_leidas;
                if (end($observedRows) !== $rowsRead) {
                    $observedRows[] = $rowsRead;
                }
            }
        });

        app(LocPersonalXlsImporter::class)->stage($file->getRealPath(), $batch);

        $this->assertSame([1, 2, 3], $observedRows);
        $this->assertSame('en_revision', $batch->fresh()->estado);
        $summary = json_decode($batch->fresh()->resumen_errores, true);
        $this->assertSame(3, $summary['diagnostico']['resumen_bloques']['bloques_completados']);
        $this->assertSame(1, $summary['diagnostico']['ultimo_bloque']['rows_processed']);
        $this->assertArrayHasKey('open_seconds', $summary['diagnostico']['ultimo_bloque']);
        $this->assertArrayHasKey('read_seconds', $summary['diagnostico']['ultimo_bloque']);
        $this->assertArrayHasKey('map_validate_seconds', $summary['diagnostico']['ultimo_bloque']);
        $this->assertArrayHasKey('staging_write_seconds', $summary['diagnostico']['ultimo_bloque']);
        $this->assertArrayHasKey('peak_memory_mb', $summary['diagnostico']['ultimo_bloque']);
        $this->assertArrayHasKey('peak_memory_actual_mb', $summary['diagnostico']['ultimo_bloque']);
        $this->assertArrayHasKey('revision_total_seconds', $summary['diagnostico']);
        $this->assertArrayHasKey('revision_rows_per_second', $summary['diagnostico']);
        $this->assertDatabaseCount('loc_personas', 0);
    }

    public function test_cuip_longer_than_twenty_characters_is_preserved_through_staging_and_confirmation(): void
    {
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'synthetic-long-cuip.xls',
            'estado' => 'procesando',
        ]);
        $longCuip = str_repeat('L', 22);
        $file = $this->xlsUpload([
            ['CUIP', 'NOMBRE'],
            [$longCuip, 'Persona sintética'],
        ]);

        app(LocPersonalXlsImporter::class)->stage($file->getRealPath(), $batch);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame($longCuip, $row->cuip);
        $this->assertSame($longCuip, $row->datos['campos']['cuip']);
        $this->assertSame('nuevo', $row->accion);
        $this->assertSame('en_revision', $batch->fresh()->estado);
        $this->assertDatabaseCount('loc_personas', 0);

        $result = app(LocPersonalXlsImporter::class)->commit($batch->fresh());

        $this->assertSame(1, $result['inserted']);
        $this->assertDatabaseHas('loc_personas', [
            'cuip' => $longCuip,
            'nombre' => 'Persona sintética',
        ]);
        $this->assertSame('completado', $batch->fresh()->estado);
        $this->assertArrayHasKey(
            'confirmacion',
            json_decode((string) $batch->fresh()->resumen_errores, true)['diagnostico']
        );
    }

    public function test_empty_cuip_is_still_rejected_and_not_staged_as_an_identifier(): void
    {
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'synthetic-empty-cuip.xls',
            'estado' => 'procesando',
        ]);
        $file = $this->xlsUpload([
            ['CUIP', 'NOMBRE'],
            ['', 'Persona sin identificador'],
        ]);

        app(LocPersonalXlsImporter::class)->stage($file->getRealPath(), $batch);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertNull($row->cuip);
        $this->assertSame('', $row->datos['campos']['cuip']);
        $this->assertSame('rechazado', $row->accion);
        $this->assertStringContainsString('CUIP obligatorio', implode(' ', $row->errores));

        app(LocPersonalXlsImporter::class)->commit($batch->fresh());

        $this->assertDatabaseCount('loc_personas', 0);
    }

    public function test_duplicate_long_cuips_are_detected_using_the_complete_staged_value(): void
    {
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'synthetic-duplicate-long-cuip.xls',
            'estado' => 'procesando',
        ]);
        $longCuip = str_repeat('D', 22);
        $file = $this->xlsUpload([
            ['CUIP', 'NOMBRE'],
            [$longCuip, 'Persona sintética uno'],
            [$longCuip, 'Persona sintética dos'],
        ]);

        app(LocPersonalXlsImporter::class)->stage($file->getRealPath(), $batch);

        $rows = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->orderBy('numero_fila')->get();
        $this->assertSame([$longCuip, $longCuip], $rows->pluck('cuip')->all());
        $this->assertSame(['conflicto', 'conflicto'], $rows->pluck('accion')->all());
        $this->assertSame(2, $batch->fresh()->total_rechazadas);

        $result = app(LocPersonalXlsImporter::class)->commit($batch->fresh());

        $this->assertSame(2, $result['rejected']);
        $this->assertDatabaseCount('loc_personas', 0);
    }

    public function test_cuip_above_one_hundred_characters_is_rejected_without_truncation(): void
    {
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'synthetic-cuip-over-limit.xls',
            'estado' => 'procesando',
        ]);
        $cuip = str_repeat('X', 101);
        $file = $this->xlsUpload([
            ['CUIP', 'NOMBRE'],
            [$cuip, 'Persona sintética'],
        ]);

        app(LocPersonalXlsImporter::class)->stage($file->getRealPath(), $batch);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertNull($row->cuip);
        $this->assertSame($cuip, $row->datos['campos']['cuip']);
        $this->assertSame('rechazado', $row->accion);
        $this->assertStringContainsString('capacidad de almacenamiento de 100 caracteres', implode(' ', $row->errores));
    }

    public function test_retrying_same_batch_hits_unique_staging_constraint_and_logs_safe_query_diagnostics(): void
    {
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'synthetic-retry.xls',
            'estado' => 'procesando',
        ]);
        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => null,
            'datos' => ['campos' => [], 'origen' => ['NOMBRE' => 'Sensitive Retry Name']],
            'errores' => [],
            'accion' => 'rechazado',
            'estado_aplicacion' => 'pendiente',
        ]);
        $file = $this->xlsUpload([
            ['CUIP', 'NOMBRE'],
            ['SENSITIVE-CUIP-RETRY', 'Sensitive Retry Name'],
        ]);
        Log::spy();

        try {
            app(LocPersonalXlsImporter::class)->stage($file->getRealPath(), $batch);
            $this->fail('Reintentar la misma fila del mismo lote debe chocar con la restricción única.');
        } catch (LocImportDiagnosticException $exception) {
            $this->assertSame('STAGING_DATABASE_WRITE_FAILED', $exception->errorCode);
            $this->assertTrue(is_a($exception->causeExceptionType, QueryException::class, true));
            $this->assertSame('Duplicate staging row conflicts with the unique constraint (import_batch_id, numero_fila).', $exception->technicalMessage);
            $this->assertSame('Duplicate staging row conflicts with the unique constraint (import_batch_id, numero_fila).', $exception->diagnosticDetails['database_error']['driver_message']);
            $this->assertNotEmpty($exception->diagnosticDetails['database_error']['sqlstate']);
            $this->assertNotNull($exception->diagnosticDetails['database_error']['driver_code']);
        } finally {
            @unlink($file->getRealPath());
        }

        $this->assertDatabaseCount('loc_import_staging_rows', 1);
        $this->assertDatabaseCount('loc_personas', 0);
        $summary = json_decode($batch->fresh()->resumen_errores, true);
        $this->assertSame(
            'Duplicate staging row conflicts with the unique constraint (import_batch_id, numero_fila).',
            $summary['diagnostico']['database_error']['driver_message']
        );
        $this->assertStringNotContainsString('SENSITIVE-CUIP-RETRY', $batch->fresh()->resumen_errores);
        $this->assertStringNotContainsString('Sensitive Retry Name', $batch->fresh()->resumen_errores);
        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
            if ($message !== 'Falló un bloque del análisis de importación LOC.') {
                return false;
            }

            $serialized = json_encode($context, JSON_UNESCAPED_UNICODE);

            return ($context['database_error']['sqlstate'] ?? '') !== ''
                && isset($context['database_error']['driver_code'])
                && $context['database_error']['driver_message']
                    === 'Duplicate staging row conflicts with the unique constraint (import_batch_id, numero_fila).'
                && ! str_contains((string) $serialized, 'SENSITIVE-CUIP-RETRY')
                && ! str_contains((string) $serialized, 'Sensitive Retry Name')
                && ! str_contains(strtolower((string) $serialized), 'insert into');
        });
    }

    public function test_import_routes_require_an_authenticated_super_administrator(): void
    {
        $this->get(route('gestion'))->assertRedirect(route('login'));
        $this->post(route('gestion.importaciones.subir'))->assertRedirect(route('login'));
        $this->get(route('gestion.importaciones.progreso', 1))->assertRedirect(route('login'));

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('gestion'))
            ->assertForbidden();

        $this->get(route('gestion.historial'))->assertForbidden();
    }

    public function test_existing_person_is_updated_by_cuip_and_keeps_all_source_columns(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();
        LocPersona::query()->create([
            'cuip' => ' loc-003 ',
            'nombre' => 'Nombre anterior',
            'rfc' => 'RFC-ANTERIOR',
            'estatus' => 'activo',
            'datos_loc' => ['CAMPO PREVIO' => 'Conservar'],
        ]);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE', 'RFC', 'CAMPO EXTRA'],
                    ['LOC-003', 'Nombre actualizado', '', 'Valor adicional'],
                ]),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $this->post(route('gestion.importaciones.confirmar', $batch))
            ->assertRedirect(route('gestion.historial'));

        $persona = LocPersona::query()->where('cuip', 'LOC-003')->sole();
        $this->assertSame('Nombre actualizado', $persona->nombre);
        $this->assertSame('RFC-ANTERIOR', $persona->rfc);
        $this->assertSame('Valor adicional', $persona->datos_loc['CAMPO EXTRA']);
        $this->assertSame('Conservar', $persona->datos_loc['CAMPO PREVIO']);
        $this->assertSame(1, $batch->fresh()->total_actualizadas);
        $this->assertSame('actualizar', LocImportStagingRow::query()->sole()->accion);
    }

    public function test_missing_required_headers_are_rejected_before_review(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['ID_PERSONAL', 'NOMBRE'],
                    ['00017', 'Persona sintética'],
                ]),
            ])
            ->assertSessionHasErrors('archivo_loc');

        $this->assertSame('fallido', LocImportBatch::query()->sole()->estado);
        $this->assertDatabaseCount('loc_personas', 0);
    }

    public function test_duplicate_cuips_are_all_conflicts_and_ambiguous_headers_are_rejected(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE', 'CATEGORIA', 'CATEGORÍA'],
                    ['DUP-01', 'Persona uno', 'Categoria A', 'Categoria B'],
                    ['DUP-01', 'Persona dos', 'Categoria C', 'Categoria D'],
                    ['UNICO-01', 'Persona tres', 'Categoria E', 'Categoria F'],
                ]),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $rows = LocImportStagingRow::query()->orderBy('numero_fila')->get();
        $this->assertSame(['conflicto', 'conflicto', 'rechazado'], $rows->pluck('accion')->all());
        $categoryValues = array_filter(
            $rows[0]->datos['origen'],
            static fn (string $value, string $key): bool => str_starts_with($key, 'CATEGOR'),
            ARRAY_FILTER_USE_BOTH
        );
        $this->assertSame(['Categoria A', 'Categoria B'], array_values($categoryValues));
        $this->assertSame(3, $batch->total_rechazadas);

        $this->post(route('gestion.importaciones.confirmar', $batch))->assertRedirect(route('gestion.historial'));
        $this->assertDatabaseCount('loc_personas', 0);
        $this->assertDatabaseMissing('loc_personas', ['cuip' => 'DUP-01']);
        $this->assertDatabaseMissing('loc_personas', ['cuip' => 'UNICO-01']);
    }

    public function test_identical_duplicate_category_columns_are_removed_before_mapping(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE', 'Categoria', 'Categoria', 'CLAVE', 'ESTATUS'],
                    ['CLEAN-01', 'Persona limpia', 'Operativo', 'Operativo', 'CL-07', 'ACTIVO'],
                ]),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $row = LocImportStagingRow::query()->sole();
        $summary = json_decode($batch->resumen_errores, true);

        $this->assertSame('en_revision', $batch->estado);
        $this->assertSame(1, $summary['limpieza']['columnas_duplicadas_detectadas']);
        $this->assertSame(1, $summary['limpieza']['columnas_redundantes_descartadas']);
        $this->assertSame(0, $summary['limpieza']['filas_con_discrepancias']);
        $this->assertSame('Operativo', $row->datos['campos']['categoria']);
        $this->assertSame('CL-07', $row->datos['campos']['clave_seguridad']);
        $this->assertSame('ACTIVO', $row->datos['campos']['estatus']);
        $this->assertSame('Operativo', $row->datos['origen']['Categoria']);
        $this->assertArrayNotHasKey('Categoria [columna D]', $row->datos['origen']);
        $this->assertSame(6, $batch->total_columnas);
        $this->assertDatabaseCount('loc_personas', 0);

        $this->get(route('gestion.importaciones.preview', $batch))
            ->assertOk()
            ->assertSee('Columnas redundantes descartadas')
            ->assertSee('Encabezados afectados')
            ->assertSee('Redundantes descartadas: D');
    }

    public function test_duplicate_values_that_only_differ_by_outer_spaces_are_equivalent(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE', 'Categoria', ' CATEGORÍA '],
                    ['SPACE-01', 'Persona con espacios', 'Nivel 1', '  Nivel 1  '],
                ]),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $row = LocImportStagingRow::query()->sole();
        $summary = json_decode($batch->resumen_errores, true);

        $this->assertSame('en_revision', $batch->estado);
        $this->assertSame(1, $summary['limpieza']['columnas_duplicadas_detectadas']);
        $this->assertSame(1, $summary['limpieza']['columnas_redundantes_descartadas']);
        $this->assertSame(0, $summary['limpieza']['filas_con_discrepancias']);
        $this->assertSame('Nivel 1', $row->datos['campos']['categoria']);
        $this->assertSame('Nivel 1', $row->datos['origen']['Categoria']);
        $this->assertDatabaseCount('loc_personas', 0);
    }

    public function test_duplicate_columns_with_differences_are_preserved_and_discrepant_rows_rejected(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE', 'Categoria', 'Categoria', 'CLAVE'],
                    ['DIFF-01', 'Persona discrepante', 'Operativo', 'Administrativo', 'CL-01'],
                    ['DIFF-02', 'Persona consistente', 'Operativo', 'Operativo', 'CL-02'],
                ]),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $rows = LocImportStagingRow::query()->orderBy('numero_fila')->get();
        $summary = json_decode($batch->resumen_errores, true);

        $this->assertSame(['rechazado', 'nuevo'], $rows->pluck('accion')->all());
        $this->assertSame(1, $summary['limpieza']['columnas_duplicadas_detectadas']);
        $this->assertSame(0, $summary['limpieza']['columnas_redundantes_descartadas']);
        $this->assertSame(1, $summary['limpieza']['filas_con_discrepancias']);
        $this->assertSame(1, $summary['limpieza']['filas_con_errores']);
        $this->assertNotEmpty($summary['limpieza']['errores']);
        $this->assertSame('Operativo', $rows[0]->datos['origen']['Categoria']);
        $this->assertSame('Administrativo', $rows[0]->datos['origen']['Categoria [columna D]']);
        $this->assertStringContainsString('valores diferentes', implode(' ', $rows[0]->errores));
        $this->assertSame('CL-01', $rows[0]->datos['campos']['clave_seguridad']);
        $this->assertDatabaseCount('loc_personas', 0);

        $this->get(route('gestion.importaciones.preview', $batch))
            ->assertOk()
            ->assertSee('Requiere intervención')
            ->assertSee('Filas con discrepancias')
            ->assertSee('valores diferentes');
    }

    public function test_header_case_accents_and_repeated_whitespace_are_normalized_before_duplicate_detection(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE', 'Cuerpo   de Seguridad', 'cuerpo de seguridad'],
                    ['HEADER-01', 'Persona encabezada', 'Seguridad Estatal', 'Seguridad Estatal'],
                ]),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $row = LocImportStagingRow::query()->sole();
        $summary = json_decode($batch->resumen_errores, true);

        $this->assertSame(1, $summary['limpieza']['columnas_duplicadas_detectadas']);
        $this->assertSame(1, $summary['limpieza']['columnas_redundantes_descartadas']);
        $this->assertSame('Seguridad Estatal', $row->datos['campos']['cuerpo_seguridad']);
        $this->assertSame('nuevo', $row->accion);
    }

    public function test_import_without_duplicate_headers_keeps_all_source_columns_and_mappings(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE', 'Categoria', 'CLAVE', 'ESTATUS', 'CAMPO EXTRA'],
                    ['PLAIN-01', 'Persona sin duplicados', 'Operativo', 'CL-09', 'ACTIVO', 'Conservar'],
                ]),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $row = LocImportStagingRow::query()->sole();
        $summary = json_decode($batch->resumen_errores, true);

        $this->assertSame(0, $summary['limpieza']['columnas_duplicadas_detectadas']);
        $this->assertSame(0, $summary['limpieza']['columnas_redundantes_descartadas']);
        $this->assertSame(0, $summary['limpieza']['filas_con_discrepancias']);
        $this->assertSame('Operativo', $row->datos['campos']['categoria']);
        $this->assertSame('CL-09', $row->datos['campos']['clave_seguridad']);
        $this->assertSame('ACTIVO', $row->datos['campos']['estatus']);
        $this->assertSame('Conservar', $row->datos['origen']['CAMPO EXTRA']);
        $this->assertCount(6, $row->datos['origen']);
    }

    public function test_empty_header_is_reported_and_its_column_is_preserved_without_mapping(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE', '', 'Categoria'],
                    ['EMPTY-01', 'Persona con columna vacía', 'dato sin encabezado', 'Operativo'],
                ]),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $row = LocImportStagingRow::query()->sole();
        $summary = json_decode($batch->resumen_errores, true);

        $this->assertSame(1, $summary['limpieza']['encabezados_vacios_detectados']);
        $this->assertSame('(vacío)', $summary['limpieza']['encabezados_afectados'][0]['encabezado']);
        $this->assertContains('C', $summary['limpieza']['encabezados_afectados'][0]['columnas']);
        $this->assertSame('dato sin encabezado', $row->datos['origen']['COLUMNA SIN NOMBRE [C]']);
        $this->assertSame('Operativo', $row->datos['campos']['categoria']);
        $this->assertNotEmpty($summary['limpieza']['advertencias']);
    }

    public function test_xls_serial_dates_and_unchanged_rows_are_classified_correctly(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);
        LocPersona::query()->create([
            'cuip' => 'UNCHANGED-01',
            'nombre' => 'Persona existente',
            'estatus' => 'ACTIVO',
            'datos_loc' => [
                'CUIP' => 'UNCHANGED-01',
                'NOMBRE' => 'Persona existente',
                'ESTATUS' => 'ACTIVO',
                'FECHA DE BAJA' => '',
            ],
        ]);
        $serialDate = Date::PHPToExcel(new \DateTimeImmutable('2026-10-21'));

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE', 'ESTATUS', 'FECHA DE BAJA'],
                    ['UNCHANGED-01', 'Persona existente', 'ACTIVO', ''],
                    ['SERIAL-01', 'Persona nueva', 'ACTIVO', $serialDate],
                ], ['D3' => 'm/d/yy']),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $this->assertSame(1, $batch->total_sin_cambios);
        $this->assertDatabaseCount('loc_personas', 1);
        $this->post(route('gestion.importaciones.confirmar', $batch))->assertRedirect();
        $person = LocPersona::query()->where('cuip', 'SERIAL-01')->sole();
        $this->assertSame('2026-10-21', $person->fecha_baja->format('Y-m-d'));
        $this->assertFalse($person->activo);
        $this->assertDatabaseCount('loc_personas', 2);
    }

    public function test_formatted_numeric_personal_ids_keep_leading_zeroes(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE', 'ID_PERSONAL'],
                    [17, 'Persona con ID formateado', 19],
                ], ['A2' => '00000', 'C2' => '00000']),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $this->post(route('gestion.importaciones.confirmar', $batch))->assertRedirect();

        $person = LocPersona::query()->where('cuip', '00017')->sole();
        $this->assertSame('00019', $person->personal_id);
    }

    public function test_corrupt_excel_content_is_rejected_without_creating_personnel(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => UploadedFile::fake()->createWithContent('personal.xls', 'not an Excel workbook'),
            ])
            ->assertSessionHasErrors('archivo_loc');

        $this->assertDatabaseCount('loc_personas', 0);
        $this->assertSame('fallido', LocImportBatch::query()->sole()->estado);
        $summary = json_decode((string) LocImportBatch::query()->sole()->resumen_errores, true);
        $this->assertSame('INVALID_XLS_FORMAT', $summary['diagnostico']['error_code']);
        $this->assertSame('identificacion_libro', $summary['diagnostico']['stage']);
        $this->assertNotEmpty($summary['diagnostico']['incident_id']);
        $this->assertSame(\RuntimeException::class, $summary['diagnostico']['exception_type']);
        $this->assertArrayHasKey('elapsed_seconds', $summary['diagnostico']['stage_metrics']['identificacion_libro']);
    }

    public function test_displaced_headers_are_not_guessed_from_an_arbitrary_row(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['Reporte mensual'],
                    ['CUIP', 'NOMBRE'],
                    ['SHIFTED-01', 'Persona de prueba'],
                ]),
            ])
            ->assertSessionHasErrors('archivo_loc');

        $batch = LocImportBatch::query()->sole();
        $summary = json_decode((string) $batch->resumen_errores, true);
        $this->assertSame('fallido', $batch->estado);
        $this->assertSame('MISSING_REQUIRED_HEADERS', $summary['diagnostico']['error_code']);
        $this->assertSame('limpieza_normalizacion_encabezados', $summary['diagnostico']['stage']);
        $this->assertSame(1, $summary['diagnostico']['location']['header_row']);
        $this->assertSame(2, $summary['diagnostico']['location']['column_count']);
        $this->assertSame(0, $batch->total_leidas);
        $this->assertDatabaseCount('loc_personas', 0);
    }

    public function test_confirmation_rejects_staging_changed_after_review(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE'],
                    ['HASH-01', 'Persona de prueba'],
                ]),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $row = LocImportStagingRow::query()->sole();
        $row->update(['datos' => array_replace($row->datos, ['modificado' => true])]);

        $this->post(route('gestion.importaciones.confirmar', $batch))
            ->assertSessionHasErrors('importacion');

        $this->assertSame('fallido', $batch->fresh()->estado);
        $this->assertDatabaseCount('loc_personas', 0);
    }

    public function test_partial_batch_resume_skips_rows_already_applied(): void
    {
        Storage::fake('local');
        $administrator = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($administrator)
            ->post(route('gestion.importaciones.subir'), [
                'archivo_loc' => $this->xlsUpload([
                    ['CUIP', 'NOMBRE'],
                    ['RESUME-01', 'Persona ya aplicada'],
                    ['RESUME-02', 'Persona pendiente'],
                ]),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $firstRow = LocImportStagingRow::query()->where('numero_fila', 2)->sole();
        LocPersona::query()->create([
            'import_batch_id' => $batch->id,
            'cuip' => $firstRow->cuip,
            'nombre' => $firstRow->datos['campos']['nombre'],
            'datos_loc' => $firstRow->datos['origen'],
        ]);
        $firstRow->update(['estado_aplicacion' => 'insertado']);
        $batch->update([
            'estado' => 'fallido_parcial',
            'total_aceptadas' => 1,
            'total_insertadas' => 1,
            'total_actualizadas' => 0,
            'total_sin_cambios' => 0,
        ]);

        $this->post(route('gestion.importaciones.confirmar', $batch))
            ->assertRedirect(route('gestion.historial'));

        $this->assertDatabaseCount('loc_personas', 2);
        $this->assertSame('completado', $batch->fresh()->estado);
        $this->assertSame(2, $batch->fresh()->total_insertadas);
    }

    public function test_history_filters_real_batches_and_keeps_pagination_query(): void
    {
        $administrator = User::factory()->create(['role' => 'superadmin']);
        LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'synthetic.xls',
            'usuario_id' => $administrator->id,
            'estado' => 'completado',
            'total_leidas' => 2,
            'total_aceptadas' => 2,
        ]);
        LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'failed.xls',
            'usuario_id' => $administrator->id,
            'estado' => 'fallido',
        ]);

        $this->actingAs($administrator)
            ->get(route('gestion.historial', ['estado' => 'completado']))
            ->assertOk()
            ->assertSee('synthetic.xls')
            ->assertDontSee('failed.xls')
            ->assertSee('Filtrar');
    }

    public function test_preview_filters_error_rows_keeps_pagination_and_preserves_global_totals(): void
    {
        $administrator = User::factory()->create(['role' => 'superadmin']);
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'synthetic-preview-errors.xls',
            'usuario_id' => $administrator->id,
            'estado' => 'en_revision',
            'total_leidas' => 28,
            'total_aceptadas' => 1,
            'total_rechazadas' => 27,
            'resumen_errores' => json_encode([
                'limpieza' => ['filas_con_errores' => 27],
            ]),
        ]);

        for ($rowNumber = 2; $rowNumber <= 28; $rowNumber++) {
            LocImportStagingRow::query()->create([
                'import_batch_id' => $batch->id,
                'numero_fila' => $rowNumber,
                'cuip' => null,
                'datos' => [
                    'campos' => [
                        'cuip' => '',
                        'nombre' => $rowNumber === 2 ? '<script>alert(1)</script>' : "Persona inválida {$rowNumber}",
                        'personal_id' => sprintf('%06d', $rowNumber),
                        'categoria' => 'Operativo',
                        'municipio' => 'Municipio de prueba',
                    ],
                    'origen' => [],
                ],
                'errores' => ['CUIP obligatorio: no se puede identificar de forma segura a la persona.'],
                'accion' => 'rechazado',
                'estado_aplicacion' => 'pendiente',
            ]);
        }

        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 29,
            'cuip' => 'VALID-01',
            'datos' => ['campos' => ['cuip' => 'VALID-01', 'nombre' => 'Persona válida'], 'origen' => []],
            'errores' => [],
            'accion' => 'nuevo',
            'estado_aplicacion' => 'pendiente',
        ]);

        $this->actingAs($administrator)
            ->get(route('gestion.importaciones.preview', [$batch, 'solo_errores' => 1]))
            ->assertOk()
            ->assertSee('Filas que requieren corrección')
            ->assertSee('27 fila(s) rechazadas o en conflicto')
            ->assertSee('CUIP obligatorio: no se puede identificar de forma segura a la persona.')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('solo_errores=1#excepciones', false)
            ->assertSee('Ver todas las filas')
            ->assertSee('28 filas leídas')
            ->assertSee('1 válidas')
            ->assertSee('27 rechazadas o en conflicto')
            ->assertDontSee('Persona válida');

        $pageTwo = $this->actingAs($administrator)
            ->get(route('gestion.importaciones.preview', [
                $batch,
                'solo_errores' => 1,
                'errores_page' => 2,
            ]))
            ->assertOk()
            ->assertSee('solo_errores=1', false)
            ->assertSee('errores_page=1', false)
            ->assertDontSee('Persona válida');

        $this->assertStringContainsString('Fila Excel', $pageTwo->getContent());
        $this->assertStringContainsString('Persona inválida 28', $pageTwo->getContent());
    }

    public function test_preview_supports_action_filtering_and_contextual_search(): void
    {
        $administrator = User::factory()->create(['role' => 'superadmin']);
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'preview-filters-search.xls',
            'usuario_id' => $administrator->id,
            'estado' => 'en_revision',
            'total_leidas' => 4,
            'total_aceptadas' => 3,
            'total_rechazadas' => 1,
            'total_insertadas' => 1,
            'total_actualizadas' => 1,
            'total_sin_cambios' => 1,
        ]);

        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'FILTER-NUEVO-01',
            'datos' => ['campos' => ['cuip' => 'FILTER-NUEVO-01', 'nombre' => 'ALBERTO PEREZ', 'rfc' => 'PERA800101'], 'origen' => []],
            'errores' => [],
            'accion' => 'nuevo',
            'estado_aplicacion' => 'pendiente',
        ]);

        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 3,
            'cuip' => 'FILTER-ACTUAL-02',
            'datos' => ['campos' => ['cuip' => 'FILTER-ACTUAL-02', 'nombre' => 'BEATRIZ GOMEZ', 'rfc' => 'GOMB850202'], 'origen' => []],
            'errores' => [],
            'accion' => 'actualizar',
            'estado_aplicacion' => 'pendiente',
        ]);

        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 4,
            'cuip' => 'FILTER-SINCH-03',
            'datos' => ['campos' => ['cuip' => 'FILTER-SINCH-03', 'nombre' => 'CARLOS LOPEZ', 'rfc' => 'LOPC900303'], 'origen' => []],
            'errores' => [],
            'accion' => 'sin_cambios',
            'estado_aplicacion' => 'pendiente',
        ]);

        LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 5,
            'cuip' => 'FILTER-CONF-04',
            'datos' => ['campos' => ['cuip' => 'FILTER-CONF-04', 'nombre' => 'DANIEL MENDEZ', 'rfc' => 'MEND950404'], 'origen' => []],
            'errores' => ['Conflicto de identidad'],
            'accion' => 'conflicto',
            'estado_aplicacion' => 'pendiente',
        ]);

        // 1. Filtrar por accion=nuevo
        $this->actingAs($administrator)
            ->get(route('gestion.importaciones.preview', [$batch, 'accion' => 'nuevo']))
            ->assertOk()
            ->assertSee('ALBERTO PEREZ')
            ->assertDontSee('BEATRIZ GOMEZ')
            ->assertDontSee('CARLOS LOPEZ')
            ->assertDontSee('DANIEL MENDEZ');

        // 2. Filtrar por accion=actualizar
        $this->actingAs($administrator)
            ->get(route('gestion.importaciones.preview', [$batch, 'accion' => 'actualizar']))
            ->assertOk()
            ->assertSee('BEATRIZ GOMEZ')
            ->assertDontSee('ALBERTO PEREZ')
            ->assertDontSee('CARLOS LOPEZ');

        // 3. Filtrar por accion=requieren_correccion
        $this->actingAs($administrator)
            ->get(route('gestion.importaciones.preview', [$batch, 'accion' => 'requieren_correccion']))
            ->assertOk()
            ->assertSee('DANIEL MENDEZ')
            ->assertDontSee('ALBERTO PEREZ');

        // 4. Buscar por nombre
        $this->actingAs($administrator)
            ->get(route('gestion.importaciones.preview', [$batch, 'buscar' => 'BEATRIZ']))
            ->assertOk()
            ->assertSee('BEATRIZ GOMEZ')
            ->assertDontSee('ALBERTO PEREZ');

        // 5. Buscar por RFC
        $this->actingAs($administrator)
            ->get(route('gestion.importaciones.preview', [$batch, 'buscar' => 'PERA800101']))
            ->assertOk()
            ->assertSee('ALBERTO PEREZ')
            ->assertDontSee('BEATRIZ GOMEZ');

        // 6. Petición AJAX (X-Requested-With / JSON)
        $responseAjax = $this->actingAs($administrator)
            ->getJson(route('gestion.importaciones.preview', [$batch, 'accion' => 'nuevo']));

        $responseAjax->assertOk()
            ->assertJsonStructure(['table_html', 'chips_html', 'total_coincidentes'])
            ->assertJsonPath('total_coincidentes', 1);

        $this->assertStringContainsString('ALBERTO PEREZ', (string) $responseAjax->json('table_html'));
        $this->assertStringNotContainsString('BEATRIZ GOMEZ', (string) $responseAjax->json('table_html'));
    }

    public function test_personal_import_omit_row_endpoint_marks_row_as_omitted_and_updates_batch(): void
    {
        $administrator = User::factory()->create(['role' => 'superadmin']);
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'omit-test.xls',
            'usuario_id' => $administrator->id,
            'estado' => 'en_revision',
            'total_leidas' => 1,
            'total_aceptadas' => 0,
            'total_rechazadas' => 1,
        ]);

        $row = LocImportStagingRow::query()->create([
            'import_batch_id' => $batch->id,
            'numero_fila' => 2,
            'cuip' => 'OMIT-CUIP-01',
            'datos' => ['campos' => ['cuip' => 'OMIT-CUIP-01', 'nombre' => 'ELEMENTO PARA OMITIR'], 'origen' => []],
            'errores' => ['Error irresoluble'],
            'accion' => 'conflicto',
            'estado_aplicacion' => 'pendiente',
        ]);

        $response = $this->actingAs($administrator)
            ->post(route('gestion.importaciones.omitir', [$batch, $row]), [
                'motivo' => 'Exclusión formal por duplicidad en origen con expediente depurado',
            ]);

        $response->assertRedirect();
        $row->refresh();
        $this->assertSame('omitido', $row->accion);
        $this->assertSame('omitido', $row->estado_aplicacion);
        $this->assertSame('Exclusión formal por duplicidad en origen con expediente depurado', $row->datos['exclusion']['motivo'] ?? null);

        $batch->refresh();
        $this->assertSame(0, $batch->total_rechazadas);
    }

    public function test_more_than_ten_thousand_rows_are_staged_in_chunks_without_loss(): void
    {
        config(['loc_import.read_chunk_size' => 1000, 'loc_import.write_chunk_size' => 250]);
        $rows = [['CUIP', 'NOMBRE', 'ID_PERSONAL']];
        for ($index = 1; $index <= 10001; $index++) {
            $rows[] = [sprintf('CUIP-%05d', $index), "Persona sintetica {$index}", sprintf('%06d', $index)];
        }

        $path = $this->writeXls($rows);
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'synthetic-large.xls',
            'estado' => 'procesando',
        ]);

        try {
            app(LocPersonalXlsImporter::class)->stage($path, $batch);

            $this->assertSame(10001, $batch->fresh()->total_leidas);
            $this->assertSame(10001, $batch->fresh()->total_nuevas);
            $this->assertSame(10001, LocImportStagingRow::query()->where('import_batch_id', $batch->id)->count());
            $this->assertSame(0, $batch->fresh()->total_rechazadas);

            $result = app(LocPersonalXlsImporter::class)->commit($batch);
            $this->assertSame(10001, $result['inserted']);
            $this->assertDatabaseCount('loc_personas', 10001);
            $this->assertSame('completado', $batch->fresh()->estado);
        } finally {
            @unlink($path);
        }
    }

    public function test_thirty_five_thousand_synthetic_rows_preserve_duplicate_column_mapping(): void
    {
        config(['loc_import.read_chunk_size' => 2500, 'loc_import.write_chunk_size' => 250]);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [];
        for ($index = 0; $index < 38; $index++) {
            $headers[] = match ($index) {
                0 => 'CUIP',
                1 => 'NOMBRE',
                2 => 'ID_PERSONAL',
                7, 34 => 'CATEGORIA',
                default => 'CAMPO_'.($index + 1),
            };
        }
        $sheet->fromArray([$headers], null, 'A1');
        for ($index = 1; $index <= 35000; $index++) {
            $rowNumber = $index + 1;
            $category = $index % 2 === 0 ? 'Operativo' : 'Administrativo';
            $sheet->setCellValue("A{$rowNumber}", sprintf('CUIP-%05d', $index));
            $sheet->setCellValue("B{$rowNumber}", "Persona sintetica {$index}");
            $sheet->setCellValue("C{$rowNumber}", sprintf('%06d', $index));
            $sheet->setCellValue("H{$rowNumber}", $category);
            $sheet->setCellValue("AI{$rowNumber}", $category);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'loc-xls-');
        $this->assertNotFalse($tempPath);
        $path = $tempPath.'.xls';
        (new Xls($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet);
        @unlink($tempPath);
        gc_collect_cycles();
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'synthetic-35000.xls',
            'estado' => 'procesando',
        ]);

        try {
            app(LocPersonalXlsImporter::class)->stage($path, $batch);

            $processed = $batch->fresh();
            $summary = json_decode($processed->resumen_errores, true);
            $firstRow = LocImportStagingRow::query()
                ->where('import_batch_id', $batch->id)
                ->where('numero_fila', 2)
                ->sole();
            $lastRow = LocImportStagingRow::query()
                ->where('import_batch_id', $batch->id)
                ->where('numero_fila', 35001)
                ->sole();

            $this->assertSame('en_revision', $processed->estado);
            $this->assertSame(35000, $processed->total_leidas);
            $this->assertSame(35000, LocImportStagingRow::query()->where('import_batch_id', $batch->id)->count());
            $this->assertSame(1, $summary['limpieza']['columnas_redundantes_descartadas']);
            $this->assertSame('Administrativo', $firstRow->datos['origen']['CATEGORIA']);
            $this->assertSame('000001', $firstRow->datos['campos']['personal_id']);
            $this->assertSame('035000', $lastRow->datos['campos']['personal_id']);
            $this->assertSame(14, $summary['diagnostico']['resumen_bloques']['bloques_completados']);
            $this->assertArrayHasKey('pico_maximo_memoria_real_mb', $summary['diagnostico']['resumen_bloques']);
            $this->assertDatabaseCount('loc_personas', 0);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function xlsUpload(array $rows, array $numberFormats = []): UploadedFile
    {
        $xlsPath = $this->writeXls($rows, $numberFormats);

        try {
            $contents = file_get_contents($xlsPath);
            $this->assertNotFalse($contents);

            return UploadedFile::fake()->createWithContent('personal.xls', $contents);
        } finally {
            @unlink($xlsPath);
        }
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function writeXls(array $rows, array $numberFormats = []): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');
        foreach ($numberFormats as $cell => $format) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
        }
        $path = tempnam(sys_get_temp_dir(), 'loc-xls-');

        if ($path === false) {
            $this->fail('No fue posible crear el archivo temporal de prueba.');
        }

        $xlsPath = $path.'.xls';

        try {
            (new Xls($spreadsheet))->save($xlsPath);

            return $xlsPath;
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($path);
        }
    }
}
