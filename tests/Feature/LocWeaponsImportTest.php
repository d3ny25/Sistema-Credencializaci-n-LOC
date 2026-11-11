<?php

namespace Tests\Feature;

use App\Jobs\ProcessLocWeaponsImportBatch;
use App\Models\LocArma;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\User;
use App\Services\LocWeaponsOleReuseReader;
use App\Services\LocWeaponsXlsChunkReadFilter;
use App\Services\LocWeaponsXlsImporter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Tests\TestCase;

class LocWeaponsImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'loc_import.queue_connection' => 'sync',
            'loc_import.weapons_read_chunk_size' => 2,
            'loc_import.weapons_write_chunk_size' => 2,
        ]);
    }

    public function test_configured_upload_limit_covers_armas_request_size_with_headroom(): void
    {
        $this->assertGreaterThanOrEqual(55 * 1024, config('loc_import.max_file_kilobytes'));
    }

    public function test_armas_upload_is_authenticated_queued_and_does_not_change_the_inventory(): void
    {
        Storage::fake('local');
        Queue::fake();
        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();

        $this->actingAs($administrator)
            ->get(route('gestion'))
            ->assertOk()
            ->assertSee('LOC · Armamento')
            ->assertSee('Analizar armamento');

        $this->actingAs($administrator)
            ->post(route('gestion.armamento.importaciones.subir'), [
                'archivo_armas' => UploadedFile::fake()->create('ARMAS.xls', 5, 'application/vnd.ms-excel'),
            ])
            ->assertRedirect();

        $batch = LocImportBatch::query()->sole();
        $this->assertSame('armamento_loc', $batch->tipo_fuente);
        $this->assertSame('pendiente', $batch->estado);
        $this->assertDatabaseCount('loc_armas', 0);
        Queue::assertPushedOn('loc-weapons-imports', ProcessLocWeaponsImportBatch::class);
        Queue::assertPushed(ProcessLocWeaponsImportBatch::class, fn (ProcessLocWeaponsImportBatch $job): bool => $job->connection === 'loc_weapons_import'
            && $job->queue === 'loc-weapons-imports');
        $this->get(route('gestion.armamento.importaciones.preview', $batch))
            ->assertOk()
            ->assertSee('Análisis pendiente en cola');
    }

    public function test_weapon_import_job_is_not_dispatched_until_the_database_transaction_commits(): void
    {
        $jobConnection = DB::connection()->getName();
        config([
            'queue.connections.loc_weapons_import.connection' => $jobConnection,
            'queue.connections.loc_weapons_import.table' => 'jobs',
            'queue.connections.loc_weapons_import.after_commit' => true,
        ]);
        $storedPath = 'loc-imports/after-commit-test.xls';

        DB::beginTransaction();
        ProcessLocWeaponsImportBatch::dispatch(987654, $storedPath)
            ->onConnection('loc_weapons_import')
            ->onQueue('loc-weapons-imports')
            ->afterCommit();
        $this->assertDatabaseCount('jobs', 0);

        DB::commit();
        $this->assertDatabaseCount('jobs', 1);
        $job = DB::table('jobs')->sole();
        $this->assertSame('loc-weapons-imports', $job->queue);
        $this->assertStringContainsString('987654', $job->payload);
        $this->assertDatabaseCount('loc_import_batches', 0);
        $this->assertDatabaseCount('loc_armas', 0);
    }

    public function test_explicit_reanalysis_creates_one_new_batch_and_keeps_previous_batches_unchanged(): void
    {
        Storage::fake('local');
        Queue::fake();
        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();
        $fileContents = $this->syntheticWorkbookContents();
        $sourceBatch = LocImportBatch::query()->create([
            'tipo_fuente' => 'armamento_loc',
            'nombre_archivo' => 'ARMAS.xls',
            'usuario_id' => $administrator->id,
            'estado' => 'en_revision',
            'archivo_sha256' => hash('sha256', $fileContents),
            'total_leidas' => 55765,
            'total_aceptadas' => 55763,
            'total_rechazadas' => 2,
            'total_nuevas' => 55763,
            'resumen_errores' => json_encode(['original' => 'preservar'], JSON_THROW_ON_ERROR),
        ]);
        $requestKeyOne = (string) Str::uuid();
        $requestKeyTwo = (string) Str::uuid();

        $this->actingAs($administrator)->post(route('gestion.armamento.importaciones.subir'), [
            'archivo_armas' => UploadedFile::fake()->createWithContent('ARMAS.xls', $fileContents),
        ])->assertRedirect(route('gestion.armamento.importaciones.preview', $sourceBatch));
        $this->assertDatabaseCount('loc_import_batches', 1);
        Queue::assertNothingPushed();

        $firstResponse = $this->actingAs($administrator)->post(route('gestion.armamento.importaciones.subir'), [
            'archivo_armas' => UploadedFile::fake()->createWithContent('ARMAS.xls', $fileContents),
            'reanalyze' => '1',
            'reanalysis_request_key' => $requestKeyOne,
        ]);

        $newBatch = LocImportBatch::query()->where('id', '!=', $sourceBatch->id)->sole();
        $firstResponse->assertRedirect(route('gestion.armamento.importaciones.preview', $newBatch));
        $this->assertSame('pendiente', $newBatch->estado);
        $this->assertSame($sourceBatch->archivo_sha256, $newBatch->archivo_sha256);
        $this->assertSame($sourceBatch->id, json_decode((string) $newBatch->resumen_errores, true)['reanalyze']['source_batch_id']);

        $this->post(route('gestion.armamento.importaciones.subir'), [
            'archivo_armas' => UploadedFile::fake()->createWithContent('ARMAS.xls', $fileContents),
            'reanalyze' => '1',
            'reanalysis_request_key' => $requestKeyTwo,
        ])->assertRedirect(route('gestion.armamento.importaciones.preview', $newBatch));

        $this->assertDatabaseCount('loc_import_batches', 2);
        $this->assertSame('en_revision', $sourceBatch->fresh()->estado);
        $this->assertSame(55765, $sourceBatch->fresh()->total_leidas);
        $this->assertSame(['original' => 'preservar'], json_decode((string) $sourceBatch->fresh()->resumen_errores, true));
        $this->assertContains($requestKeyOne, json_decode((string) $newBatch->fresh()->resumen_errores, true)['reanalyze']['request_keys']);
        $this->assertContains($requestKeyTwo, json_decode((string) $newBatch->fresh()->resumen_errores, true)['reanalyze']['request_keys']);
        Queue::assertPushed(ProcessLocWeaponsImportBatch::class, 1);
        $this->assertDatabaseCount('loc_armas', 0);
    }

    public function test_reanalysis_metadata_survives_staging_review_summary_replacement(): void
    {
        $metadata = [
            'source_batch_id' => 42,
            'request_keys' => [(string) Str::uuid()],
            'requested_by' => 7,
            'requested_at' => now()->toIso8601String(),
        ];
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'armamento_loc',
            'nombre_archivo' => 'ARMAS-sintetico.xls',
            'estado' => 'procesando',
            'archivo_sha256' => hash('sha256', 'synthetic'),
            'resumen_errores' => json_encode(['reanalyze' => $metadata], JSON_THROW_ON_ERROR),
        ]);

        $reviewedBatch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA'],
            ['0001', 'TEST-0001'],
        ], $batch);

        $summary = json_decode((string) $reviewedBatch->resumen_errores, true);
        $this->assertSame('en_revision', $reviewedBatch->estado);
        $this->assertSame($metadata, $summary['reanalyze']);
        $this->assertDatabaseCount('loc_armas', 0);
    }

    public function test_xls_chunk_sizes_can_be_compared_with_an_isolated_synthetic_workbook(): void
    {
        class_exists(LocWeaponsXlsImporter::class);
        $path = tempnam(sys_get_temp_dir(), 'loc-weapons-memory-');
        if ($path === false) {
            $this->fail('No se pudo crear el archivo temporal del benchmark.');
        }

        try {
            $sourceRows = [];
            for ($row = 0; $row <= 360; $row++) {
                $sourceRows[] = array_map(
                    static fn (int $column): string => $row === 0 ? "HEADER_{$column}" : "VALUE_{$row}_{$column}",
                    range(1, 66),
                );
            }
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray($sourceRows, null, 'A1', true);
            (new Xls($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $sourceRows);
            gc_collect_cycles();

            $reader = IOFactory::createReader('Xls');
            $worksheetInfo = $reader->listWorksheetInfo($path)[0];
            $worksheetName = $worksheetInfo['worksheetName'];
            $lastColumn = $worksheetInfo['lastColumnLetter'];
            $results = [];

            foreach ([100, 250, 500] as $chunkSize) {
                $processedRows = 0;
                $peakDeltaBytes = 0;
                $startedAt = microtime(true);

                for ($start = 2; $start <= 361; $start += $chunkSize) {
                    memory_reset_peak_usage();
                    $memoryStart = memory_get_usage(false);
                    $end = min($start + $chunkSize - 1, 361);
                    $chunkReader = IOFactory::createReader('Xls');
                    $chunkReader->setReadFilter(new LocWeaponsXlsChunkReadFilter($start, $end));
                    $chunkReader->setLoadSheetsOnly([$worksheetName]);
                    $workbook = $chunkReader->load($path);
                    $sheet = $workbook->getSheetByName($worksheetName);
                    $matrix = $sheet->rangeToArray("A{$start}:{$lastColumn}{$end}", null, true, true);
                    $processedRows += count($matrix);
                    $peakDeltaBytes = max($peakDeltaBytes, memory_get_peak_usage(false) - $memoryStart);
                    $workbook->disconnectWorksheets();
                    unset($matrix, $sheet, $workbook, $chunkReader);
                    gc_collect_cycles();
                }

                $results[] = [
                    'chunk_size' => $chunkSize,
                    'rows' => $processedRows,
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
                    'peak_delta_mb' => round($peakDeltaBytes / 1048576, 2),
                ];
            }

            foreach ($results as $result) {
                $this->assertSame(360, $result['rows']);
                $this->assertGreaterThan(0, $result['elapsed_seconds']);
                $this->assertGreaterThan(0, $result['peak_delta_mb']);
            }

            if (getenv('LOC_WEAPONS_XLS_BENCHMARK') === '1') {
                fwrite(STDOUT, 'Synthetic XLS chunk benchmark: '.json_encode($results, JSON_UNESCAPED_UNICODE).PHP_EOL);
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_full_synthetic_analysis_can_be_compared_across_read_chunk_sizes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'loc-weapons-full-benchmark-');
        if ($path === false) {
            $this->fail('No se pudo crear el archivo temporal del benchmark completo.');
        }

        try {
            $sourceRows = [['ID_ARMAMENTO', 'MATRICULA', 'MARCA']];
            for ($row = 1; $row <= 360; $row++) {
                $sourceRows[] = [
                    'EXT-'.str_pad((string) $row, 4, '0', STR_PAD_LEFT),
                    'MAT-'.str_pad((string) $row, 4, '0', STR_PAD_LEFT),
                    'Marca sintética',
                ];
            }
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray($sourceRows, null, 'A1', true);
            (new Xls($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $sourceRows);
            gc_collect_cycles();

            config(['loc_import.weapons_write_chunk_size' => 100]);
            $results = [];
            foreach ([100, 250, 500] as $chunkSize) {
                config(['loc_import.weapons_read_chunk_size' => $chunkSize]);
                DB::beginTransaction();
                try {
                    $batch = LocImportBatch::query()->create([
                        'tipo_fuente' => 'armamento_loc',
                        'nombre_archivo' => 'ARMAS-benchmark-sintetico.xls',
                        'estado' => 'procesando',
                    ]);
                    app(LocWeaponsXlsImporter::class)->stage($path, $batch);

                    $reviewedBatch = $batch->fresh();
                    $summary = json_decode((string) $reviewedBatch->resumen_errores, true);
                    $this->assertSame('en_revision', $reviewedBatch->estado);
                    $this->assertSame(360, $reviewedBatch->total_leidas);
                    $this->assertSame(360, $reviewedBatch->total_aceptadas);
                    $this->assertSame(0, $reviewedBatch->total_rechazadas);
                    $this->assertSame(360, LocImportStagingRow::query()->where('import_batch_id', $batch->id)->count());
                    $this->assertDatabaseCount('loc_armas', 0);

                    $metrics = $summary['metricas_revision'];
                    $stageKeys = [
                        'open_seconds',
                        'read_seconds',
                        'map_validate_seconds',
                        'existing_compare_seconds',
                        'staging_write_seconds',
                    ];
                    $stages = array_fill_keys($stageKeys, 0.0);
                    foreach ($metrics['blocks'] as $block) {
                        foreach ($stageKeys as $key) {
                            $stages[$key] += (float) ($block[$key] ?? 0);
                        }
                    }
                    $measuredSeconds = array_sum($stages);
                    $stages['other_seconds'] = round(max(0, (float) $metrics['total_seconds'] - $measuredSeconds), 3);
                    $results[] = [
                        'read_chunk_size' => $chunkSize,
                        'rows' => $reviewedBatch->total_leidas,
                        'candidates' => $reviewedBatch->total_nuevas,
                        'conflicts_and_errors' => $reviewedBatch->total_rechazadas,
                        'total_seconds' => $metrics['total_seconds'],
                        'peak_memory_mb' => $metrics['peak_memory_mb'],
                        'stages_seconds' => array_map(static fn (float $seconds): float => round($seconds, 3), $stages),
                    ];
                } finally {
                    DB::rollBack();
                }
            }

            $this->assertSame([100, 250, 500], array_column($results, 'read_chunk_size'));
            $this->assertSame([360, 360, 360], array_column($results, 'candidates'));
            $this->assertSame([0, 0, 0], array_column($results, 'conflicts_and_errors'));

            if (getenv('LOC_WEAPONS_XLS_BENCHMARK') === '1') {
                fwrite(STDOUT, 'Synthetic full-analysis benchmark on SQLite :memory:: '.json_encode($results, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_upload_stays_disabled_if_unique_matricula_index_is_missing(): void
    {
        Schema::table('loc_armas', function (Blueprint $table): void {
            $table->dropUnique('loc_armas_matricula_unique');
        });

        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();

        $this->actingAs($administrator)
            ->get(route('gestion'))
            ->assertOk()
            ->assertSee('La importación no está habilitada')
            ->assertDontSee('Analizar armamento');

        $this->actingAs($administrator)
            ->post(route('gestion.armamento.importaciones.subir'), [
                'archivo_armas' => UploadedFile::fake()->create('ARMAS.xls', 5, 'application/vnd.ms-excel'),
            ])
            ->assertSessionHasErrors('archivo_armas');

        $this->assertDatabaseCount('loc_import_batches', 0);
        $this->assertDatabaseCount('loc_armas', 0);
    }

    public function test_preview_preserves_the_full_source_and_leading_zero_identifiers_without_writing_arms(): void
    {
        $headers = ['ID_ARMAMENTO', 'TIPO DE ARMA', 'CLASE', 'MARCA', 'CALIBRE', 'MODELO', 'MATRICULA', 'RFA', 'FECHA DE MOVIMIENTO', 'CAMPO EXTRA'];
        $rows = [
            $headers,
            ['00012', 'PISTOLA', 'CORTA', 'Marca sintética', '9 MM', 'Modelo A', '00004567001234', 'RFA-01', '21/10/2026', 'Dato de origen'],
        ];
        $batch = $this->stageWorkbook($rows);
        $staging = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();

        $this->assertSame('00004567001234', $staging->matricula);
        $this->assertSame('00012', $staging->id_armamento);
        $this->assertSame('00012', $staging->datos['campos']['id_armamento']);
        $this->assertSame('00004567001234', $staging->datos['campos']['matricula']);
        $this->assertSame('Dato de origen', $staging->datos['origen']['CAMPO EXTRA']);
        $this->assertSame('21/10/2026', $staging->datos['origen']['FECHA DE MOVIMIENTO']);
        $this->assertCount(count($headers), $staging->datos['origen']);
        $this->assertSame('nuevo', $staging->accion);
        $this->assertSame('en_revision', $batch->estado);
        $this->assertDatabaseCount('loc_armas', 0);
        $summary = json_decode((string) $batch->resumen_errores, true);
        $this->assertSame(1, $summary['metricas_revision']['blocks'][0]['rows_in_block']);
        $this->assertArrayHasKey('open_seconds', $summary['metricas_revision']['blocks'][0]);
        $this->assertArrayHasKey('read_seconds', $summary['metricas_revision']['blocks'][0]);
        $this->assertArrayHasKey('map_validate_seconds', $summary['metricas_revision']['blocks'][0]);
        $this->assertArrayHasKey('staging_write_seconds', $summary['metricas_revision']['blocks'][0]);
        $this->assertArrayHasKey('peak_memory_mb', $summary['metricas_revision']['blocks'][0]);
        $this->assertGreaterThan(0, $summary['metricas_revision']['total_seconds']);
    }

    public function test_only_unavailable_matricula_markers_normalize_to_null_and_source_text_is_preserved(): void
    {
        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['EXT-001', 'S/M', 'Marca uno'],
            ['EXT-002', 's/m', 'Marca dos'],
            ['EXT-003', '  S/M  ', 'Marca tres'],
            ['EXT-004', '-', 'Marca cuatro'],
            ['EXT-005', 'N/A', 'Marca cinco'],
        ]);

        $rows = LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->orderBy('numero_fila')
            ->get();

        $this->assertSame([null, null, null, '-', 'N/A'], $rows->pluck('matricula')->all());
        $this->assertSame([null, null, null, '-', 'N/A'], $rows->map(fn (LocImportStagingRow $row) => $row->datos['campos']['matricula'])->all());
        $this->assertSame(['S/M', 's/m', '  S/M  ', '-', 'N/A'], $rows->map(fn (LocImportStagingRow $row) => $row->datos['origen']['MATRICULA'])->all());
        $this->assertSame(['nuevo', 'nuevo', 'nuevo', 'nuevo', 'nuevo'], $rows->pluck('accion')->all());
    }

    public function test_two_unavailable_matriculas_with_distinct_external_ids_are_independent_and_store_null(): void
    {
        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['EXT-NULL-001', 'S/M', 'Marca uno'],
            ['EXT-NULL-002', 'S/M', 'Marca dos'],
        ]);
        $this->assertSame(
            ['nuevo', 'nuevo'],
            LocImportStagingRow::query()->where('import_batch_id', $batch->id)->orderBy('numero_fila')->pluck('accion')->all()
        );
        $this->assertSame(2, $batch->total_aceptadas);
        $this->assertSame(0, $batch->total_rechazadas);
        $this->assertDatabaseCount('loc_armas', 0);

        $result = app(LocWeaponsXlsImporter::class)->commit($batch, 1);

        $this->assertSame(['inserted' => 2, 'unchanged' => 0, 'rejected' => 0], $result);
        $this->assertDatabaseCount('loc_armas', 2);
        $this->assertSame(2, LocArma::query()->whereNull('matricula')->count());
        $this->assertEqualsCanonicalizing(
            ['EXT-NULL-001', 'EXT-NULL-002'],
            LocArma::query()->orderBy('id')->pluck('id_armamento')->all()
        );
        $this->assertSame(['S/M', 'S/M'], LocArma::query()->orderBy('id')->get()->map(fn (LocArma $arma) => $arma->datos_origen['MATRICULA'])->all());
    }

    public function test_missing_and_duplicate_matriculas_are_rejected_and_block_confirmation(): void
    {
        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['001', 'DUP-001', 'Marca uno'],
            ['002', 'DUP-001', 'Marca dos'],
            ['003', '', 'Marca sin identidad'],
            ['', 'S/M', 'Sin referencia externa'],
        ]);

        $rows = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->orderBy('numero_fila')->get();
        $this->assertSame(['conflicto', 'conflicto', 'rechazado', 'rechazado'], $rows->pluck('accion')->all());
        $this->assertSame(4, $batch->total_rechazadas);
        $this->assertStringContainsString('Falta la matrícula obligatoria', implode(' ', $rows[2]->errores));
        $this->assertStringContainsString('requiere ID_ARMAMENTO', implode(' ', $rows[3]->errores));
        $this->assertContains('La matrícula se repite dentro del lote; requiere revisión.', $rows[0]->errores);
        $this->assertContains('La matrícula se repite dentro del lote; requiere revisión.', $rows[1]->errores);
        $this->assertDatabaseCount('loc_armas', 0);

        try {
            app(LocWeaponsXlsImporter::class)->commit($batch, 1);
            $this->fail('No debe confirmarse un lote con errores o duplicados.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('contiene filas rechazadas o en conflicto', $exception->getMessage());
        }
        $this->assertDatabaseCount('loc_armas', 0);
    }

    public function test_external_id_is_not_used_as_identity_and_conflicting_ids_are_flagged(): void
    {
        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['EXTERNAL-1', 'MAT-001', 'Marca uno'],
            ['EXTERNAL-1', 'MAT-002', 'Marca dos'],
        ]);

        $this->assertDatabaseCount('loc_import_staging_rows', 2);
        $this->assertSame(
            ['conflicto', 'conflicto'],
            LocImportStagingRow::query()->where('import_batch_id', $batch->id)->orderBy('numero_fila')->pluck('accion')->all()
        );
        foreach (LocImportStagingRow::query()->where('import_batch_id', $batch->id)->get() as $row) {
            $this->assertContains('El ID_ARMAMENTO se repite dentro del lote; requiere revisión.', $row->errores);
        }
        $this->assertDatabaseCount('loc_armas', 0);
    }

    public function test_duplicate_matricula_conflict_keeps_preexisting_external_id_conflict_reasons(): void
    {
        LocArma::query()->create(['matricula' => 'INVENTORY-001', 'id_armamento' => 'EXISTING-001']);
        LocArma::query()->create(['matricula' => 'INVENTORY-002', 'id_armamento' => 'EXISTING-002']);
        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['EXISTING-001', 'DUPLICATE-REAL', 'Marca uno'],
            ['EXISTING-002', 'DUPLICATE-REAL', 'Marca dos'],
        ]);

        $rows = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->orderBy('numero_fila')->get();
        foreach ($rows as $row) {
            $this->assertSame('conflicto', $row->accion);
            $this->assertContains('El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.', $row->errores);
            $this->assertContains('La matrícula se repite dentro del lote; requiere revisión.', $row->errores);
        }
        $this->assertDatabaseCount('loc_armas', 2);
    }

    public function test_external_id_already_in_inventory_conflicts_without_overwriting_existing_weapon(): void
    {
        LocArma::query()->create([
            'matricula' => 'EXISTING-MAT-001',
            'id_armamento' => 'EXTERNAL-EXISTING-001',
            'marca' => 'Marca original',
        ]);

        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['EXTERNAL-EXISTING-001', 'S/M', 'Marca distinta'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertNull($row->matricula);
        $this->assertSame('conflicto', $row->accion);
        $this->assertContains('El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.', $row->errores);
        $this->assertSame('Marca original', LocArma::query()->where('id_armamento', 'EXTERNAL-EXISTING-001')->value('marca'));
        $this->assertDatabaseCount('loc_armas', 1);
    }

    public function test_inventory_and_progress_queries_are_reduced_without_changing_classification(): void
    {
        config([
            'loc_import.weapons_read_chunk_size' => 4,
            'loc_import.weapons_write_chunk_size' => 2,
        ]);
        LocArma::query()->create([
            'matricula' => 'EXISTING-MAT',
            'id_armamento' => 'EXISTING-ID',
            'marca' => 'Marca original',
            'datos_origen' => ['MATRICULA' => 'EXISTING-MAT', 'ID_ARMAMENTO' => 'EXISTING-ID', 'MARCA' => 'Marca original'],
        ]);
        $inventorySelects = 0;
        $batchProgressUpdates = 0;
        DB::listen(function ($query) use (&$inventorySelects, &$batchProgressUpdates): void {
            if (str_contains(strtolower($query->sql), 'from "loc_armas"')) {
                $inventorySelects++;
            }
            if (str_contains(strtolower($query->sql), 'update "loc_import_batches"')) {
                $batchProgressUpdates++;
            }
        });

        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['EXISTING-ID', 'DIFFERENT-MAT', 'Marca distinta'],
            ['NEW-02', 'EXISTING-MAT', 'Marca distinta'],
            ['00003', '000000003', 'Marca nueva'],
            ['00004', 'S/M', 'Marca sin matrícula'],
        ]);

        $rows = LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->orderBy('numero_fila')
            ->get();

        $this->assertSame(['conflicto', 'conflicto', 'nuevo', 'nuevo'], $rows->pluck('accion')->all());
        $this->assertContains('El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.', $rows[0]->errores);
        $this->assertContains('La matrícula ya existe con información distinta. No se actualizará automáticamente.', $rows[1]->errores);
        $this->assertSame('000000003', $rows[2]->matricula);
        $this->assertSame('00003', $rows[2]->id_armamento);
        $this->assertNull($rows[3]->matricula);
        $this->assertSame('S/M', $rows[3]->datos['origen']['MATRICULA']);
        $this->assertSame(2, $inventorySelects);
        $this->assertSame(4, $batchProgressUpdates);
        $this->assertDatabaseCount('loc_armas', 1);
    }

    public function test_zero_string_external_id_is_still_checked_for_inventory_conflicts(): void
    {
        LocArma::query()->create([
            'matricula' => 'EXISTING-MAT-002',
            'id_armamento' => '0',
            'marca' => 'Marca original',
        ]);

        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['0', 'S/M', 'Marca distinta'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame('0', $row->id_armamento);
        $this->assertSame('conflicto', $row->accion);
        $this->assertContains('El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.', $row->errores);
        $this->assertSame('Marca original', LocArma::query()->where('id_armamento', '0')->value('marca'));
        $this->assertDatabaseCount('loc_armas', 1);
    }

    public function test_confirmation_inserts_only_new_weapons_and_never_overwrites_existing_records(): void
    {
        LocArma::query()->create([
            'matricula' => 'EXIST-001',
            'id_armamento' => null,
            'marca' => 'Marca previa',
            'datos_origen' => ['ID_ARMAMENTO' => '', 'MATRICULA' => 'EXIST-001', 'MARCA' => 'Marca previa'],
        ]);
        LocArma::query()->create([
            'matricula' => 'EXIST-002',
            'id_armamento' => 'OLD-2',
            'marca' => 'Marca anterior',
            'datos_origen' => ['ID_ARMAMENTO' => 'OLD-2', 'MATRICULA' => 'EXIST-002', 'MARCA' => 'Marca anterior'],
        ]);

        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['', 'EXIST-001', 'Marca previa'],
            ['NEW-1', 'NEW-001', 'Marca nueva'],
            ['OLD-2', 'EXIST-002', 'Marca distinta'],
        ]);

        $rows = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->orderBy('numero_fila')->get();
        $this->assertSame(['sin_cambios', 'nuevo', 'conflicto'], $rows->pluck('accion')->all());
        $this->assertSame('Marca previa', LocArma::query()->where('matricula', 'EXIST-001')->value('marca'));
        $this->assertSame('Marca anterior', LocArma::query()->where('matricula', 'EXIST-002')->value('marca'));
        $this->assertDatabaseCount('loc_armas', 2);

        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();
        $response = $this->actingAs($administrator)
            ->get(route('gestion.armamento.importaciones.preview', $batch));
        $response->assertOk()
            ->assertSee('La matrícula ya existe con información distinta')
            ->assertSee('3 columnas · ver origen completo');

        $this->assertDatabaseCount('loc_armas', 2);
        $this->assertSame('en_revision', $batch->fresh()->estado);
    }

    public function test_confirming_a_valid_review_preserves_the_external_id_and_complete_origin_json(): void
    {
        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();
        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA', 'CAMPO EXTRA'],
            ['00008', '000000123', 'Marca sintética', 'Valor íntegro'],
        ]);

        $this->actingAs($administrator)
            ->post(route('gestion.armamento.importaciones.confirmar', $batch))
            ->assertRedirect(route('gestion.historial'));

        $arma = LocArma::query()->where('matricula', '000000123')->sole();
        $this->assertSame('00008', $arma->id_armamento);
        $this->assertSame(['ID_ARMAMENTO' => '00008', 'MATRICULA' => '000000123', 'MARCA' => 'Marca sintética', 'CAMPO EXTRA' => 'Valor íntegro'], $arma->datos_origen);
        $this->assertSame('completado', $batch->fresh()->estado);
        $this->assertSame(1, $batch->fresh()->total_insertadas);
        $this->assertDatabaseHas('loc_import_staging_rows', [
            'import_batch_id' => $batch->id,
            'estado_aplicacion' => 'insertado',
        ]);
    }

    public function test_unchanged_records_are_revalidated_and_can_be_confirmed_without_an_insert(): void
    {
        $source = ['MATRICULA' => 'SAME-MAT-01', 'MARCA' => 'Marca estable'];
        LocArma::query()->create([
            'matricula' => 'SAME-MAT-01',
            'marca' => 'Marca estable',
            'datos_origen' => $source,
        ]);
        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA'],
            ['SAME-MAT-01', 'Marca estable'],
        ]);
        $this->assertSame('sin_cambios', LocImportStagingRow::query()->where('import_batch_id', $batch->id)->value('accion'));

        $result = app(LocWeaponsXlsImporter::class)->commit($batch, 1);

        $this->assertSame(['inserted' => 0, 'unchanged' => 1, 'rejected' => 0], $result);
        $this->assertSame('completado', $batch->fresh()->estado);
        $this->assertDatabaseCount('loc_armas', 1);
    }

    public function test_confirmation_stops_if_reviewed_inventory_changes_before_commit(): void
    {
        LocArma::query()->create([
            'matricula' => 'STALE-MAT',
            'marca' => 'Marca revisada',
            'datos_origen' => ['MATRICULA' => 'STALE-MAT', 'MARCA' => 'Marca revisada'],
        ]);
        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA'],
            ['STALE-MAT', 'Marca revisada'],
        ]);
        LocArma::query()->where('matricula', 'STALE-MAT')->update(['marca' => 'Marca modificada']);

        try {
            app(LocWeaponsXlsImporter::class)->commit($batch, 1);
            $this->fail('No debe confirmarse una revisión que quedó obsoleta.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('modificado o eliminado después de la revisión', $exception->getMessage());
        }

        $this->assertSame('en_revision', $batch->fresh()->estado);
        $this->assertSame('Marca modificada', LocArma::query()->where('matricula', 'STALE-MAT')->value('marca'));
    }

    public function test_confirmation_stops_if_an_external_id_becomes_conflicting_after_review(): void
    {
        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['LATE-01', 'LATE-MAT-01', 'Marca candidata'],
        ]);
        LocArma::query()->create([
            'matricula' => 'OTHER-MAT',
            'id_armamento' => 'LATE-01',
            'marca' => 'Marca existente',
        ]);

        try {
            app(LocWeaponsXlsImporter::class)->commit($batch, 1);
            $this->fail('No debe confirmarse una referencia externa que ahora apunta a otra matrícula.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ID_ARMAMENTO del lote ya existe en el inventario', $exception->getMessage());
        }

        $this->assertSame('en_revision', $batch->fresh()->estado);
        $this->assertDatabaseCount('loc_armas', 1);
    }

    public function test_armamento_routes_require_administration_access_and_preview_filter_paginates(): void
    {
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'armamento_loc',
            'nombre_archivo' => 'ARMAS-sintetico.xls',
            'estado' => 'en_revision',
        ]);
        $this->get(route('gestion.armamento.importaciones.preview', $batch))->assertRedirect(route('login'));
        $regularUser = User::factory()->create();
        $this->actingAs($regularUser)
            ->get(route('gestion.armamento.importaciones.preview', $batch))
            ->assertForbidden();
        $this->actingAs($regularUser)
            ->post(route('gestion.armamento.importaciones.subir'), [
                'archivo_armas' => UploadedFile::fake()->create('ARMAS.xls', 5, 'application/vnd.ms-excel'),
                'reanalyze' => '1',
                'reanalysis_request_key' => (string) Str::uuid(),
            ])
            ->assertForbidden();
        $this->assertDatabaseCount('loc_import_batches', 1);

        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();
        $source = [['ID_ARMAMENTO', 'MATRICULA', 'MARCA']];
        for ($index = 0; $index < 26; $index++) {
            $source[] = ['EXT-'.$index, '', 'Marca'];
        }
        $batch = $this->stageWorkbook($source);
        $response = $this->actingAs($administrator)
            ->get(route('gestion.armamento.importaciones.preview', $batch).'?solo_errores=1');

        $response->assertOk()
            ->assertSee('26 fila(s) rechazadas')
            ->assertSee('solo_errores=1', false);
        $secondPage = $this->get(route('gestion.armamento.importaciones.preview', $batch).'?solo_errores=1&page=2');
        $secondPage->assertOk()
            ->assertSee('solo_errores=1', false)
            ->assertSee('EXT-25');
        $this->assertDatabaseCount('loc_armas', 0);
    }

    public function test_error_filter_keeps_global_counts_and_excludes_valid_rows(): void
    {
        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();
        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['VALID-01', 'VALID-MAT-01', 'Marca válida'],
            ['REJECT-01', '', 'Marca rechazada'],
        ]);

        $response = $this->actingAs($administrator)
            ->get(route('gestion.armamento.importaciones.preview', $batch).'?solo_errores=1');
        $response->assertOk()
            ->assertSee('1 fila(s) rechazadas o en conflicto.')
            ->assertSee('Marca rechazada')
            ->assertDontSee('VALID-MAT-01');

        $responseAjax = $this->actingAs($administrator)
            ->getJson(route('gestion.armamento.importaciones.preview', [$batch, 'solo_errores' => '1']));
        $responseAjax->assertOk()
            ->assertJsonStructure(['table_html', 'chips_html', 'total_coincidentes'])
            ->assertJsonPath('total_coincidentes', 1);
        $this->assertStringContainsString('Marca rechazada', (string) $responseAjax->json('table_html'));
        $this->assertStringNotContainsString('VALID-MAT-01', (string) $responseAjax->json('table_html'));
        $this->assertSame(2, $batch->fresh()->total_leidas);
        $this->assertSame(1, $batch->fresh()->total_aceptadas);
        $this->assertSame(1, $batch->fresh()->total_rechazadas);
        $this->assertDatabaseCount('loc_armas', 0);
    }

    public function test_unique_matricula_constraint_is_case_sensitive_but_rejects_exact_duplicates(): void
    {
        LocArma::query()->create(['matricula' => 'Case-01']);
        LocArma::query()->create(['matricula' => 'case-01']);
        $this->assertDatabaseCount('loc_armas', 2);

        $this->expectException(QueryException::class);
        LocArma::query()->create(['matricula' => 'Case-01']);
    }

    public function test_armamento_migration_adds_external_reference_origin_and_unique_matricula_schema(): void
    {
        $this->assertTrue(Schema::hasColumns('loc_armas', ['matricula', 'id_armamento', 'datos_origen']));
        $this->assertTrue(Schema::hasColumns('loc_import_staging_rows', ['matricula', 'id_armamento']));

        $matriculaIndex = collect(Schema::getIndexes('loc_armas'))
            ->firstWhere('name', 'loc_armas_matricula_unique');
        $this->assertNotNull($matriculaIndex);
        $this->assertTrue($matriculaIndex['unique']);
        $this->assertSame(['matricula'], $matriculaIndex['columns']);
    }

    public function test_partial_worker_failure_preserves_staging_and_cannot_be_confirmed(): void
    {
        Storage::fake('local');
        $storedPath = 'loc-imports/partial-armas.xls';
        Storage::disk('local')->put($storedPath, 'synthetic workbook');
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'armamento_loc',
            'nombre_archivo' => 'ARMAS-parcial.xls',
            'estado' => 'pendiente',
        ]);
        $failure = new \RuntimeException('Fallo sintético después de una escritura parcial.');
        $importer = $this->mock(LocWeaponsXlsImporter::class);
        $importer->shouldReceive('stage')
            ->once()
            ->andReturnUsing(function () use ($batch, $failure): void {
                $timestamp = now();
                DB::table('loc_import_staging_rows')->insert([
                    'import_batch_id' => $batch->id,
                    'numero_fila' => 2,
                    'cuip' => null,
                    'matricula' => 'PARTIAL-001',
                    'id_armamento' => 'EXTERNAL-001',
                    'datos' => json_encode([
                        'campos' => ['matricula' => 'PARTIAL-001', 'id_armamento' => 'EXTERNAL-001'],
                        'origen' => ['MATRICULA' => 'PARTIAL-001', 'ID_ARMAMENTO' => 'EXTERNAL-001'],
                    ], JSON_THROW_ON_ERROR),
                    'errores' => json_encode([], JSON_THROW_ON_ERROR),
                    'accion' => 'nuevo',
                    'estado_aplicacion' => 'pendiente',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                throw $failure;
            });
        $job = new ProcessLocWeaponsImportBatch($batch->id, $storedPath);

        try {
            $job->handle($importer);
            $this->fail('El procesamiento parcial debe propagarse como error del trabajo.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure->getMessage(), $exception->getMessage());
        }

        $job->failed($failure);

        $this->assertSame('fallido', $batch->fresh()->estado);
        $this->assertDatabaseCount('loc_import_staging_rows', 1);
        $this->assertDatabaseCount('loc_armas', 0);

        try {
            (new LocWeaponsXlsImporter)->commit($batch->fresh(), 1);
            $this->fail('Un lote parcial fallido no debe poder confirmarse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no está disponible para confirmación', $exception->getMessage());
        }
    }

    public function test_block_failure_persists_exact_progress_location_without_losing_staging(): void
    {
        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'armamento_loc',
            'nombre_archivo' => 'ARMAS-fallo-sintetico.xls',
            'estado' => 'procesando',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'loc-armas-fallo-');
        if ($path === false) {
            $this->fail('No se pudo crear un archivo temporal para la prueba de progreso.');
        }
        $failure = new \RuntimeException('Fallo sintético durante la escritura de staging.');
        $failOnStagingInsert = true;
        DB::listen(function (QueryExecuted $query) use (&$failOnStagingInsert, $failure): void {
            if ($failOnStagingInsert && str_starts_with(strtolower($query->sql), 'insert into "loc_import_staging_rows"')) {
                $failOnStagingInsert = false;
                throw $failure;
            }
        });

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray([
                ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
                ['EXT-FAIL-01', 'MAT-FAIL-01', 'Marca sintética'],
                ['EXT-FAIL-02', 'MAT-FAIL-02', 'Marca sintética'],
            ], null, 'A1', true);
            (new Xls($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();

            try {
                app(LocWeaponsXlsImporter::class)->stage($path, $batch);
                $this->fail('El error sintético de staging debe propagarse.');
            } catch (\RuntimeException $exception) {
                $this->assertSame($failure->getMessage(), $exception->getMessage());
            }
        } finally {
            @unlink($path);
        }

        $summary = json_decode((string) $batch->fresh()->resumen_errores, true);
        $this->assertSame(2, $summary['progreso']['filas_leidas']);
        $this->assertSame(0, $summary['progreso']['bloques_completados']);
        $this->assertSame('fallido_clasificacion_existentes', $summary['diagnostico']['etapa_actual']);
        $this->assertSame([
            'stage' => 'fallido_clasificacion_existentes',
            'block' => 1,
            'row_start' => 2,
            'row_end' => 3,
        ], $summary['diagnostico']['location']);
        $this->assertSame(2, $batch->fresh()->total_leidas);
        $this->assertSame('procesando', $batch->fresh()->estado);
        $this->assertDatabaseCount('loc_import_staging_rows', 2);
        $this->assertDatabaseCount('loc_armas', 0);

        try {
            app(LocWeaponsXlsImporter::class)->commit($batch->fresh(), 1);
            $this->fail('Un lote con análisis parcial no debe poder confirmarse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no está disponible para confirmación', $exception->getMessage());
        }
    }

    public function test_migration_refuses_rollback_when_armamento_batches_exist(): void
    {
        LocImportBatch::query()->create([
            'tipo_fuente' => 'armamento_loc',
            'nombre_archivo' => 'ARMAS-sintetico.xls',
            'estado' => 'fallido',
        ]);
        $migration = require database_path('migrations/2026_10_22_000001_add_armamento_import_support.php');

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_existing_weapons_with_external_id_classify_unchanged_or_conflict_and_prevent_overwrite(): void
    {
        $existingOriginOne = [
            'ID_ARMAMENTO' => 'EXT-EXACT-01',
            'MATRICULA' => 'MAT-EXACT-01',
            'MARCA' => 'Glock',
            'CALIBRE' => '9 MM',
        ];
        $existingOriginTwo = [
            'ID_ARMAMENTO' => 'EXT-TAKEN-02',
            'MATRICULA' => 'MAT-ORIGINAL-02',
            'MARCA' => 'Beretta',
            'CALIBRE' => '9 MM',
        ];
        $existingOriginThree = [
            'ID_ARMAMENTO' => 'EXT-DIFF-03',
            'MATRICULA' => 'MAT-DIFF-03',
            'MARCA' => 'Colt',
            'CALIBRE' => '.38',
        ];

        $weaponOne = LocArma::query()->create([
            'matricula' => 'MAT-EXACT-01',
            'id_armamento' => 'EXT-EXACT-01',
            'marca' => 'Glock',
            'calibre' => '9 MM',
            'datos_origen' => $existingOriginOne,
        ]);
        $weaponTwo = LocArma::query()->create([
            'matricula' => 'MAT-ORIGINAL-02',
            'id_armamento' => 'EXT-TAKEN-02',
            'marca' => 'Beretta',
            'calibre' => '9 MM',
            'datos_origen' => $existingOriginTwo,
        ]);
        $weaponThree = LocArma::query()->create([
            'matricula' => 'MAT-DIFF-03',
            'id_armamento' => 'EXT-DIFF-03',
            'marca' => 'Colt',
            'calibre' => '.38',
            'datos_origen' => $existingOriginThree,
        ]);

        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA', 'CALIBRE'],
            ['EXT-EXACT-01', 'MAT-EXACT-01', 'Glock', '9 MM'],
            ['EXT-DIFF-03', 'MAT-DIFF-03', 'Smith & Wesson', '.38'],
            ['EXT-TAKEN-02', 'MAT-OTHER-99', 'Beretta', '9 MM'],
        ]);

        $rows = LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->orderBy('numero_fila')
            ->get();

        $this->assertCount(3, $rows);
        $this->assertSame('sin_cambios', $rows[0]->accion);
        $this->assertSame([], $rows[0]->errores);

        $this->assertSame('conflicto', $rows[1]->accion);
        $this->assertContains('La matrícula ya existe con información distinta. No se actualizará automáticamente.', $rows[1]->errores);

        $this->assertSame('conflicto', $rows[2]->accion);
        $this->assertContains('El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.', $rows[2]->errores);

        try {
            app(LocWeaponsXlsImporter::class)->commit($batch, 1);
            $this->fail('No debe confirmarse un lote que contenga conflictos.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('contiene filas rechazadas o en conflicto', $exception->getMessage());
        }

        $this->assertSame('Glock', $weaponOne->fresh()->marca);
        $this->assertSame('Beretta', $weaponTwo->fresh()->marca);
        $this->assertSame('Colt', $weaponThree->fresh()->marca);
        $this->assertDatabaseCount('loc_armas', 3);

        $cleanBatch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA', 'CALIBRE'],
            ['EXT-EXACT-01', 'MAT-EXACT-01', 'Glock', '9 MM'],
        ]);

        $cleanRow = LocImportStagingRow::query()->where('import_batch_id', $cleanBatch->id)->sole();
        $this->assertSame('sin_cambios', $cleanRow->accion);

        $result = app(LocWeaponsXlsImporter::class)->commit($cleanBatch, 1);
        $this->assertSame(['inserted' => 0, 'unchanged' => 1, 'rejected' => 0], $result);
        $this->assertSame('completado', $cleanBatch->fresh()->estado);

        $freshWeaponOne = $weaponOne->fresh();
        $this->assertSame('MAT-EXACT-01', $freshWeaponOne->matricula);
        $this->assertSame('EXT-EXACT-01', $freshWeaponOne->id_armamento);
        $this->assertSame('Glock', $freshWeaponOne->marca);
        $this->assertSame('9 MM', $freshWeaponOne->calibre);
        $this->assertSame($existingOriginOne, $freshWeaponOne->datos_origen);
        $this->assertDatabaseCount('loc_armas', 3);
    }

    public function test_ole_reader_does_not_leak_or_mix_data_across_consecutive_different_files(): void
    {
        config(['loc_import.weapons_reuse_ole' => true]);

        $rowsFileA = [
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['EXT-AAA-01', 'MAT-AAA-01', 'Marca Alfa'],
            ['EXT-AAA-02', 'MAT-AAA-02', 'Marca Alfa'],
        ];

        $rowsFileB = [
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['EXT-BBB-01', 'MAT-BBB-01', 'Marca Beta'],
            ['EXT-BBB-02', 'MAT-BBB-02', 'Marca Beta'],
        ];

        $batchA = $this->stageWorkbook($rowsFileA);
        $batchB = $this->stageWorkbook($rowsFileB);

        $this->assertFalse(LocWeaponsOleReuseReader::hasActiveCache());
        $this->assertNull(LocWeaponsOleReuseReader::activeScope());

        $stagingMatriculasA = LocImportStagingRow::query()
            ->where('import_batch_id', $batchA->id)
            ->pluck('matricula')
            ->all();

        $stagingMatriculasB = LocImportStagingRow::query()
            ->where('import_batch_id', $batchB->id)
            ->pluck('matricula')
            ->all();

        $this->assertSame(['MAT-AAA-01', 'MAT-AAA-02'], $stagingMatriculasA);
        $this->assertSame(['MAT-BBB-01', 'MAT-BBB-02'], $stagingMatriculasB);
    }

    public function test_ole_reader_clears_cache_when_exception_occurs_during_staging(): void
    {
        config(['loc_import.weapons_reuse_ole' => true]);

        $batch = LocImportBatch::query()->create([
            'tipo_fuente' => 'armamento_loc',
            'nombre_archivo' => 'ARMAS-invalido.xls',
            'estado' => 'procesando',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'loc-invalid-');
        file_put_contents($path, 'no-es-un-xls-valido');

        try {
            app(LocWeaponsXlsImporter::class)->stage($path, $batch);
            $this->fail('Se esperaba una excepción al leer un archivo XLS no válido.');
        } catch (\Throwable $exception) {
            $this->assertFalse(LocWeaponsOleReuseReader::hasActiveCache());
            $this->assertNull(LocWeaponsOleReuseReader::activeScope());
        } finally {
            @unlink($path);
        }
    }

    public function test_ole_reader_produces_identical_staging_rows_as_standard_reader(): void
    {
        $rows = [
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA', 'CALIBRE'],
            ['ID-STD-01', 'MAT-STD-01', 'Beretta', '9 MM'],
            ['ID-STD-02', 'MAT-STD-02', 'Glock', '.40'],
            ['ID-STD-03', 'MAT-STD-03', 'Colt', '.38 SPL'],
        ];

        config(['loc_import.weapons_reuse_ole' => false]);
        $batchStandard = $this->stageWorkbook($rows);

        config(['loc_import.weapons_reuse_ole' => true]);
        $batchReusable = $this->stageWorkbook($rows);

        $stagingStandard = LocImportStagingRow::query()
            ->where('import_batch_id', $batchStandard->id)
            ->orderBy('numero_fila')
            ->get(['numero_fila', 'matricula', 'id_armamento', 'accion', 'errores', 'datos'])
            ->toArray();

        $stagingReusable = LocImportStagingRow::query()
            ->where('import_batch_id', $batchReusable->id)
            ->orderBy('numero_fila')
            ->get(['numero_fila', 'matricula', 'id_armamento', 'accion', 'errores', 'datos'])
            ->toArray();

        $this->assertCount(3, $stagingStandard);
        $this->assertSame($stagingStandard, $stagingReusable);
        $this->assertSame($batchStandard->total_aceptadas, $batchReusable->total_aceptadas);
        $this->assertSame($batchStandard->total_rechazadas, $batchReusable->total_rechazadas);
    }

    private function stageWorkbook(array $rows, ?LocImportBatch $batch = null): LocImportBatch
    {
        $batch ??= LocImportBatch::query()->create([
            'tipo_fuente' => 'armamento_loc',
            'nombre_archivo' => 'ARMAS-sintetico.xls',
            'estado' => 'procesando',
            'archivo_sha256' => hash('sha256', serialize($rows)),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'loc-armas-');
        if ($path === false) {
            $this->fail('No se pudo crear un archivo temporal para la prueba.');
        }

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1', true);
            (new Xls($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();
            app(LocWeaponsXlsImporter::class)->stage($path, $batch);
        } finally {
            @unlink($path);
        }

        return $batch->fresh();
    }

    private function syntheticWorkbookContents(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'loc-weapons-upload-');
        if ($path === false) {
            $this->fail('No se pudo crear el archivo temporal para la prueba de reanálisis.');
        }

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getActiveSheet()->fromArray([
                ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
                ['TEST-1', 'TEST-MAT-1', 'Marca sintética'],
            ], null, 'A1', true);
            (new Xls($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();
            $contents = file_get_contents($path);
            if ($contents === false) {
                $this->fail('No se pudo leer el XLS sintético de la prueba de reanálisis.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    public function test_sm_existing_weapon_unique_external_id_and_equal_attributes_classifies_sin_cambios(): void
    {
        LocArma::query()->create([
            'matricula' => null,
            'id_armamento' => 'EXT-SM-01',
            'marca' => 'Higgins',
            'modelo' => '20',
            'calibre' => '12 GA',
            'tipo_arma' => 'LARGA',
            'clase_arma' => 'ESCOPETA',
            'situacion' => 'ACTIVO',
            'datos_origen' => ['ID_ARMAMENTO' => 'EXT-SM-01', 'MATRICULA' => 'S/M', 'MARCA' => 'Higgins'],
        ]);

        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'TIPO DE ARMA', 'CLASE', 'SITUACION'],
            ['EXT-SM-01', 'S/M', 'Higgins', '20', '12 GA', 'LARGA', 'ESCOPETA', 'ACTIVO'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertNull($row->matricula);
        $this->assertSame('EXT-SM-01', $row->id_armamento);
        $this->assertSame('sin_cambios', $row->accion);
        $this->assertSame([], $row->errores);

        $result = app(LocWeaponsXlsImporter::class)->commit($batch, 1);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame('completado', $batch->fresh()->estado);
        $this->assertDatabaseCount('loc_armas', 1);
    }

    public function test_sm_existing_weapon_unique_external_id_and_different_admin_fields_classifies_actualizar(): void
    {
        $existing = LocArma::query()->create([
            'matricula' => null,
            'id_armamento' => 'EXT-SM-02',
            'marca' => 'Smith & Wesson',
            'modelo' => '60',
            'calibre' => '.38 SPL',
            'situacion' => 'REVISION',
            'observaciones' => 'En taller de mantenimiento',
            'datos_origen' => ['ID_ARMAMENTO' => 'EXT-SM-02', 'MATRICULA' => 'S/M', 'SITUACION' => 'REVISION'],
        ]);

        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION', 'OBSERVACIONES'],
            ['EXT-SM-02', 'S/M', 'Smith & Wesson', '60', '.38 SPL', 'DEVOLUCION', 'Devuelto de taller'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame('actualizar', $row->accion);
        $this->assertSame([], $row->errores);
        $this->assertArrayHasKey('situacion', $row->datos['diferencias']);
        $this->assertSame('REVISION', $row->datos['diferencias']['situacion']['anterior']);
        $this->assertSame('DEVOLUCION', $row->datos['diferencias']['situacion']['nuevo']);

        $result = app(LocWeaponsXlsImporter::class)->commit($batch, 1);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(0, $result['unchanged']);

        $fresh = $existing->fresh();
        $this->assertSame('DEVOLUCION', $fresh->situacion);
        $this->assertSame('Devuelto de taller', $fresh->observaciones);
        $this->assertArrayHasKey('_historial_actualizaciones', $fresh->datos_origen);
        $this->assertSame('REVISION', $fresh->datos_origen['_historial_actualizaciones'][0]['valores_anteriores']['situacion']);
    }

    public function test_sm_weapon_claiming_external_id_of_real_matricula_weapon_flags_conflict(): void
    {
        LocArma::query()->create([
            'matricula' => 'REAL-MAT-01',
            'id_armamento' => 'EXT-SHARED-01',
            'marca' => 'Glock',
        ]);

        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['EXT-SHARED-01', 'S/M', 'Glock'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame('conflicto', $row->accion);
        $this->assertContains('El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.', $row->errores);
    }

    public function test_ambiguous_or_duplicate_external_id_flags_conflict(): void
    {
        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['EXT-DUP-01', 'MAT-ONE-01', 'Glock'],
            ['EXT-DUP-01', 'MAT-TWO-02', 'Glock'],
        ]);

        $rows = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->orderBy('numero_fila')->get();
        $this->assertSame(['conflicto', 'conflicto'], $rows->pluck('accion')->all());
        $this->assertContains('El ID_ARMAMENTO se repite dentro del lote; requiere revisión.', $rows[0]->errores);
        $this->assertContains('El ID_ARMAMENTO se repite dentro del lote; requiere revisión.', $rows[1]->errores);
    }

    public function test_identical_canonical_fields_with_different_datos_origen_does_not_flag_conflict(): void
    {
        LocArma::query()->create([
            'matricula' => 'CANON-01',
            'id_armamento' => 'EXT-CANON-01',
            'marca' => 'Glock',
            'modelo' => '17',
            'calibre' => '9 MM',
            'situacion' => 'ACTIVO',
            'datos_origen' => [
                'ID_ARMAMENTO' => 'EXT-CANON-01',
                'MATRICULA' => 'CANON-01',
                'MARCA' => 'Glock',
                'PERTENECE A' => 'Corporacion A',
                'FECHA DE MOVIMIENTO' => '01/01/2025',
            ],
        ]);

        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION', 'PERTENECE A', 'FECHA DE MOVIMIENTO'],
            ['EXT-CANON-01', 'CANON-01', 'Glock', '17', '9 MM', 'ACTIVO', 'Corporacion B', '29/10/2026'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame('sin_cambios', $row->accion);
        $this->assertSame([], $row->errores);
        $this->assertSame(0, $batch->total_rechazadas);
        $this->assertSame(1, $batch->total_sin_cambios);
    }

    public function test_difference_in_situacion_classifies_as_actualizar_when_identity_is_reliable(): void
    {
        LocArma::query()->create([
            'matricula' => 'SIT-01',
            'marca' => 'Beretta',
            'modelo' => '92 FS',
            'calibre' => '9 MM',
            'situacion' => 'REVISION',
        ]);

        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION'],
            ['SIT-01', 'Beretta', '92 FS', '9 MM', 'DEVOLUCION'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame('actualizar', $row->accion);
        $this->assertSame([], $row->errores);
        $this->assertArrayHasKey('situacion', $row->datos['diferencias']);
    }

    public function test_difference_in_estatus_classifies_as_actualizar_when_identity_is_reliable(): void
    {
        LocArma::query()->create([
            'matricula' => 'STAT-01',
            'marca' => 'Beretta',
            'modelo' => '92 FS',
            'calibre' => '9 MM',
            'estatus' => 'A DISP',
        ]);

        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'STATUS'],
            ['STAT-01', 'Beretta', '92 FS', '9 MM', 'S/A'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame('actualizar', $row->accion);
        $this->assertSame([], $row->errores);
        $this->assertArrayHasKey('estatus', $row->datos['diferencias']);
        $this->assertSame('A DISP', $row->datos['diferencias']['estatus']['anterior']);
        $this->assertSame('S/A', $row->datos['diferencias']['estatus']['nuevo']);
    }

    public function test_differences_in_admin_fields_update_authorized_fields_and_preserve_history(): void
    {
        $arma = LocArma::query()->create([
            'matricula' => 'ADM-01',
            'marca' => 'Pietro Beretta',
            'modelo' => '92 FS',
            'calibre' => '9 MM',
            'agrupamiento' => 'Zona Norte',
            'region' => 'Region I',
            'municipio' => 'Toluca',
            'observaciones' => 'Nota previa de resguardo',
            'datos_origen' => ['MATRICULA' => 'ADM-01', 'OBSERVACIONES' => 'Nota previa de resguardo'],
        ]);

        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'AGRUPAMIENTO', 'REGION', 'MUNICIPIO', 'OBSERVACIONES'],
            ['ADM-01', 'Pietro Beretta', '92 FS', '9 MM', 'Zona Sur', 'Region II', 'Metepec', 'Nota actualizada oficial'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame('actualizar', $row->accion);

        $result = app(LocWeaponsXlsImporter::class)->commit($batch, 1);
        $this->assertSame(1, $result['updated']);

        $updatedArma = $arma->fresh();
        $this->assertSame('Zona Sur', $updatedArma->agrupamiento);
        $this->assertSame('Region II', $updatedArma->region);
        $this->assertSame('Metepec', $updatedArma->municipio);
        $this->assertSame('Nota actualizada oficial', $updatedArma->observaciones);

        $history = $updatedArma->datos_origen['_historial_actualizaciones'];
        $this->assertCount(1, $history);
        $this->assertSame('Nota previa de resguardo', $history[0]['valores_anteriores']['observaciones']);
        $this->assertSame('Zona Norte', $history[0]['valores_anteriores']['agrupamiento']);
        $this->assertContains('observaciones', $history[0]['diferencias']);
        $this->assertContains('agrupamiento', $history[0]['diferencias']);
    }

    public function test_difference_in_technical_identity_fields_flags_conflict_and_is_not_treated_as_admin_update(): void
    {
        LocArma::query()->create([
            'matricula' => 'TECH-01',
            'marca' => 'Beretta',
            'modelo' => '92 FS',
            'calibre' => '9 MM',
            'tipo_arma' => 'CORTA',
            'clase_arma' => 'PISTOLA',
        ]);

        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'TIPO DE ARMA', 'CLASE'],
            ['TECH-01', 'Colt', 'King Cobra', '.38 SPL', 'CORTA', 'REVOLVER'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame('conflicto', $row->accion);
        $this->assertContains('La matrícula ya existe con información distinta. No se actualizará automáticamente.', $row->errores);
    }

    public function test_changed_matricula_or_external_id_detects_collision_requiring_review(): void
    {
        LocArma::query()->create([
            'matricula' => 'MAT-AAA',
            'id_armamento' => 'ID-AAA',
            'marca' => 'Glock',
        ]);
        LocArma::query()->create([
            'matricula' => 'MAT-BBB',
            'id_armamento' => 'ID-BBB',
            'marca' => 'Beretta',
        ]);

        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA'],
            ['ID-BBB', 'MAT-AAA', 'Glock'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame('conflicto', $row->accion);
        $this->assertContains('El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.', $row->errores);
    }

    public function test_revalidation_at_confirmation_detects_concurrent_modification_after_preview(): void
    {
        $arma = LocArma::query()->create([
            'matricula' => 'CONC-01',
            'marca' => 'Glock',
            'modelo' => '17',
            'calibre' => '9 MM',
            'situacion' => 'REVISION',
        ]);

        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION'],
            ['CONC-01', 'Glock', '17', '9 MM', 'DEVOLUCION'],
        ]);

        $this->assertSame('actualizar', LocImportStagingRow::query()->where('import_batch_id', $batch->id)->value('accion'));

        $arma->update(['situacion' => 'EXTRAVIO']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fue modificada concurrentemente después de la revisión');
        app(LocWeaponsXlsImporter::class)->commit($batch, 1);
    }

    public function test_confirming_a_batch_with_new_and_updated_weapons_persists_both_correctly(): void
    {
        $existing = LocArma::query()->create([
            'matricula' => 'EXIST-UPD-01',
            'marca' => 'Glock',
            'modelo' => '19',
            'calibre' => '9 MM',
            'situacion' => 'REVISION',
        ]);

        $batch = $this->stageWorkbook([
            ['ID_ARMAMENTO', 'MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION'],
            ['', 'EXIST-UPD-01', 'Glock', '19', '9 MM', 'ACTIVO'],
            ['NEW-EXT-99', 'NEW-MAT-99', 'Pietro Beretta', '92 FS', '9 MM', 'ACTIVO'],
        ]);

        $counts = LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->pluck('accion', 'matricula')
            ->all();

        $this->assertSame('actualizar', $counts['EXIST-UPD-01']);
        $this->assertSame('nuevo', $counts['NEW-MAT-99']);

        $result = app(LocWeaponsXlsImporter::class)->commit($batch, 1);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('completado', $batch->fresh()->estado);

        $this->assertSame('ACTIVO', $existing->fresh()->situacion);
        $this->assertTrue(LocArma::query()->where('matricula', 'NEW-MAT-99')->exists());

        $this->assertSame('actualizado', LocImportStagingRow::query()->where('matricula', 'EXIST-UPD-01')->value('estado_aplicacion'));
        $this->assertSame('insertado', LocImportStagingRow::query()->where('matricula', 'NEW-MAT-99')->value('estado_aplicacion'));
    }

    public function test_simulated_failure_midway_confirmation_rolls_back_entire_transaction(): void
    {
        $existing = LocArma::query()->create([
            'matricula' => 'ROLL-01',
            'marca' => 'Glock',
            'modelo' => '17',
            'calibre' => '9 MM',
            'situacion' => 'REVISION',
        ]);

        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION'],
            ['ROLL-01', 'Glock', '17', '9 MM', 'ACTIVO'],
            ['NEW-ROLL-02', 'Marca Nueva', 'Mod A', '9 MM', 'ACTIVO'],
        ]);

        LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('matricula', 'NEW-ROLL-02')
            ->update(['datos' => 'invalido-json']);

        try {
            app(LocWeaponsXlsImporter::class)->commit($batch, 1);
            $this->fail('Se esperaba fallo en confirmación.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('revisión del lote cambió', $exception->getMessage());
        }

        $this->assertSame('REVISION', $existing->fresh()->situacion);
        $this->assertFalse(LocArma::query()->where('matricula', 'NEW-ROLL-02')->exists());
        $this->assertSame('en_revision', $batch->fresh()->estado);
    }

    public function test_retry_confirmation_does_not_duplicate_weapons_nor_reapply_updates(): void
    {
        LocArma::query()->create([
            'matricula' => 'RETRY-01',
            'marca' => 'Glock',
            'modelo' => '17',
            'calibre' => '9 MM',
            'situacion' => 'REVISION',
        ]);

        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION'],
            ['RETRY-01', 'Glock', '17', '9 MM', 'ACTIVO'],
        ]);

        $result = app(LocWeaponsXlsImporter::class)->commit($batch, 1);
        $this->assertSame(1, $result['updated']);
        $this->assertSame('completado', $batch->fresh()->estado);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no está disponible para confirmación');
        app(LocWeaponsXlsImporter::class)->commit($batch->fresh(), 1);
    }

    public function test_review_fingerprint_invalidation_when_staging_row_is_altered(): void
    {
        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA'],
            ['FINGER-01', 'Glock'],
        ]);

        $row = LocImportStagingRow::query()->where('import_batch_id', $batch->id)->sole();
        $row->update(['accion' => 'conflicto']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('La revisión del lote cambió después del análisis');
        app(LocWeaponsXlsImporter::class)->commit($batch, 1);
    }

    public function test_formal_omission_records_audit_trail_and_does_not_modify_inventory_for_that_row(): void
    {
        LocArma::query()->create([
            'matricula' => 'OMIT-EX-01',
            'marca' => 'Colt',
        ]);

        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA'],
            ['OMIT-EX-01', 'Smith & Wesson'],
            ['NEW-CLEAN-01', 'Beretta'],
        ]);

        $this->assertSame(1, $batch->total_rechazadas);
        $conflictRow = LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('matricula', 'OMIT-EX-01')
            ->sole();

        $importer = app(LocWeaponsXlsImporter::class);
        $importer->omitStagingRow($conflictRow, 'Exclusión formal autorizada en auditoría', 99);

        $refreshedBatch = $batch->fresh();
        $this->assertSame(0, $refreshedBatch->total_rechazadas);
        $this->assertSame(1, $refreshedBatch->total_nuevas);

        $refreshedRow = $conflictRow->fresh();
        $this->assertSame('omitido', $refreshedRow->accion);
        $this->assertSame('Exclusión formal autorizada en auditoría', $refreshedRow->datos['exclusion']['motivo']);
        $this->assertSame(99, $refreshedRow->datos['exclusion']['usuario_id']);

        $summary = json_decode((string) $refreshedBatch->resumen_errores, true);
        $this->assertCount(1, $summary['filas_omitidas']);
        $this->assertSame('Exclusión formal autorizada en auditoría', $summary['filas_omitidas'][0]['motivo']);

        $result = $importer->commit($refreshedBatch, 99);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame('completado', $refreshedBatch->fresh()->estado);

        $this->assertSame('Colt', LocArma::query()->where('matricula', 'OMIT-EX-01')->value('marca'));
        $this->assertSame('omitido', $refreshedRow->fresh()->estado_aplicacion);
    }

    public function test_omitted_rows_and_conflicts_are_not_counted_as_inserted_or_updated(): void
    {
        LocArma::query()->create(['matricula' => 'EXIST-TECH', 'marca' => 'Colt']);

        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA'],
            ['EXIST-TECH', 'Smith'],
            ['NEW-VAL-01', 'Beretta'],
        ]);

        $row = LocImportStagingRow::query()->where('matricula', 'EXIST-TECH')->sole();
        app(LocWeaponsXlsImporter::class)->omitStagingRow($row, 'Omitido formalmente por discrepancia técnica', 1);

        $result = app(LocWeaponsXlsImporter::class)->commit($batch->fresh(), 1);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(0, $result['updated'] ?? 0);
        $this->assertSame(0, $result['unchanged']);
        $this->assertSame(0, $result['rejected']);

        $this->assertSame(1, $batch->fresh()->total_insertadas);
        $this->assertSame(0, $batch->fresh()->total_actualizadas);
    }

    public function test_second_execution_on_already_applied_data_is_idempotent(): void
    {
        LocArma::query()->create([
            'matricula' => 'IDEMP-01',
            'marca' => 'Glock',
            'modelo' => '17',
            'calibre' => '9 MM',
            'situacion' => 'REVISION',
        ]);

        $batchOne = $this->stageWorkbook([
            ['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION'],
            ['IDEMP-01', 'Glock', '17', '9 MM', 'ACTIVO'],
        ]);

        $resultOne = app(LocWeaponsXlsImporter::class)->commit($batchOne, 1);
        $this->assertSame(1, $resultOne['updated']);

        $batchTwo = $this->stageWorkbook([
            ['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION'],
            ['IDEMP-01', 'Glock', '17', '9 MM', 'ACTIVO'],
        ]);

        $this->assertSame('sin_cambios', LocImportStagingRow::query()->where('import_batch_id', $batchTwo->id)->value('accion'));
        $this->assertSame(1, $batchTwo->total_sin_cambios);
        $this->assertSame(0, $batchTwo->total_actualizadas);
        $this->assertSame(0, $batchTwo->total_nuevas);
        $this->assertSame(0, $batchTwo->total_rechazadas);

        $resultTwo = app(LocWeaponsXlsImporter::class)->commit($batchTwo, 1);
        $this->assertSame(0, $resultTwo['inserted']);
        $this->assertSame(0, $resultTwo['updated'] ?? 0);
        $this->assertSame(1, $resultTwo['unchanged']);
        $this->assertSame('ACTIVO', LocArma::query()->where('matricula', 'IDEMP-01')->value('situacion'));
    }

    public function test_weapons_commit_sin_cambios_revalidation_with_chunk_size_1000(): void
    {
        // Crear 15 armas existentes en inventario
        $rows = [['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION']];
        for ($i = 1; $i <= 15; $i++) {
            $mat = sprintf('OPT-REVAL-%04d', $i);
            LocArma::query()->create([
                'matricula' => $mat,
                'marca' => 'Glock',
                'modelo' => '17',
                'calibre' => '9 MM',
                'situacion' => 'OPERATIVA',
                'datos_origen' => ['EXISTING_ORIGIN_DATA' => 'HEAVY_PAYLOAD'],
            ]);
            $rows[] = [$mat, 'Glock', '17', '9 MM', 'OPERATIVA'];
        }

        $batch = $this->stageWorkbook($rows);
        $this->assertSame(15, $batch->total_sin_cambios);
        $this->assertSame(0, $batch->total_actualizadas);

        $result = app(LocWeaponsXlsImporter::class)->commit($batch, 1);
        $this->assertSame(15, $result['unchanged']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame('completado', $batch->fresh()->estado);

        // Verificar que las staging rows están marcadas como sin_cambios
        $stagingApplied = LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('estado_aplicacion', 'sin_cambios')
            ->count();
        $this->assertSame(15, $stagingApplied);
    }

    public function test_weapons_revalidation_detects_concurrent_physical_change_without_datos_origen(): void
    {
        LocArma::query()->create([
            'matricula' => 'CONCURR-PHYS-01',
            'marca' => 'Beretta',
            'modelo' => '92FS',
            'calibre' => '9 MM',
            'situacion' => 'OPERATIVA',
        ]);

        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION'],
            ['CONCURR-PHYS-01', 'Beretta', '92FS', '9 MM', 'OPERATIVA'],
        ]);

        $this->assertSame(1, $batch->total_sin_cambios);

        // Modificar concurrentemente la marca en base de datos antes de confirmar
        LocArma::query()->where('matricula', 'CONCURR-PHYS-01')->update(['marca' => 'Taurus']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('clasificado sin cambios fue modificado o eliminado después de la revisión');
        app(LocWeaponsXlsImporter::class)->commit($batch, 1);
    }

    public function test_weapons_revalidation_detects_concurrent_admin_change_without_datos_origen(): void
    {
        LocArma::query()->create([
            'matricula' => 'CONCURR-ADMIN-01',
            'marca' => 'Beretta',
            'modelo' => '92FS',
            'calibre' => '9 MM',
            'situacion' => 'OPERATIVA',
        ]);

        $batch = $this->stageWorkbook([
            ['MATRICULA', 'MARCA', 'MODELO', 'CALIBRE', 'SITUACION'],
            ['CONCURR-ADMIN-01', 'Beretta', '92FS', '9 MM', 'OPERATIVA'],
        ]);

        $this->assertSame(1, $batch->total_sin_cambios);

        // Modificar concurrentemente la situación en base de datos antes de confirmar
        LocArma::query()->where('matricula', 'CONCURR-ADMIN-01')->update(['situacion' => 'EN TALLER']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('clasificado sin cambios fue modificado o eliminado después de la revisión');
        app(LocWeaponsXlsImporter::class)->commit($batch, 1);
    }
}
