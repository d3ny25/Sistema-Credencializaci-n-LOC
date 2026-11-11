<?php

namespace App\Services;

use App\Exceptions\LocImportDiagnosticException;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\LocPersona;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class LocXlsChunkReadFilter implements IReadFilter
{
    public function __construct(private int $startRow, private int $endRow) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row === 1 || ($row >= $this->startRow && $row <= $this->endRow);
    }
}

class LocPersonalXlsImporter
{
    private string $diagnosticStage = 'recepcion';

    private array $stageMetrics = [];

    private ?float $activeStageStartedAt = null;

    private int $activeStageMemory = 0;

    private ?LocImportBatch $diagnosticBatch = null;

    private array $diagnosticLocation = [];

    private const FIELD_MAP = [
        'ID_PERSONAL' => 'personal_id',
        'NOMBRE' => 'nombre',
        'RFC' => 'rfc',
        'CUIP' => 'cuip',
        'CURP' => 'curp',
        'CATEGORIA' => 'categoria',
        'SUBDIRECCION' => 'subdireccion',
        'MUNICIPIO' => 'municipio',
        'REGION' => 'region',
        'AGRUPAMIENTO' => 'agrupamiento',
        'CLAVE' => 'clave_seguridad',
        'CUERPO DE SEGURIDAD' => 'cuerpo_seguridad',
        'OBSERVACIONES' => 'observaciones',
        'ESTATUS' => 'estatus',
    ];

    private const HEADER_ALIASES = [
        'ID PERSONAL' => 'ID_PERSONAL',
        'IDENTIFICADOR PERSONAL' => 'ID_PERSONAL',
        'CUERPO SEGURIDAD' => 'CUERPO DE SEGURIDAD',
    ];

    private const REQUIRED_HEADERS = ['CUIP', 'NOMBRE'];

    public function stage(string $path, LocImportBatch $batch): array
    {
        memory_reset_peak_usage();
        $processingStartedAt = microtime(true);
        $this->stageMetrics = [];
        $this->diagnosticBatch = $batch;
        $this->diagnosticLocation = [];
        $this->beginDiagnosticStage('identificacion_libro');

        try {
            $result = $this->stageWorkbook($path, $batch);
            $this->recordTotalPhaseMetrics($batch, 'revision', $processingStartedAt);

            return $result;
        } catch (LocImportDiagnosticException $exception) {
            $this->recordTotalPhaseMetrics($batch, 'revision', $processingStartedAt);

            throw $exception;
        } catch (Throwable $exception) {
            $this->finishDiagnosticStage();
            $this->recordTotalPhaseMetrics($batch, 'revision', $processingStartedAt);
            $incidentId = (string) Str::uuid();
            $errorCode = $this->diagnosticErrorCode($exception, $this->diagnosticStage);
            $userMessage = $this->diagnosticUserMessage($exception, $this->diagnosticStage);
            $technicalMessage = $this->safeTechnicalMessage($exception);
            $diagnostics = [
                'incident_id' => $incidentId,
                'stage' => $this->diagnosticStage,
                'error_code' => $errorCode,
                'exception_type' => $exception::class,
                'technical_message' => $technicalMessage,
                'location' => $this->diagnosticLocation,
                'stage_metrics' => $this->stageMetrics,
            ];
            $diagnostics = array_merge($diagnostics, $this->queryExceptionDiagnostics($exception));

            Log::error('Falló una etapa del análisis de importación LOC.', array_merge([
                'batch_id' => $batch->id,
            ], $diagnostics));

            $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
            try {
                $batch->update([
                    'resumen_errores' => json_encode(array_merge($summary, [
                        'error' => $userMessage,
                        'diagnostico' => $diagnostics,
                    ]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            } catch (Throwable $persistException) {
                Log::error('No se pudo persistir el diagnóstico del análisis LOC.', [
                    'batch_id' => $batch->id,
                    'incident_id' => $incidentId,
                    'exception_type' => $persistException::class,
                ]);
            }

            throw new LocImportDiagnosticException(
                $incidentId,
                $this->diagnosticStage,
                $errorCode,
                $userMessage,
                $technicalMessage,
                $this->diagnosticLocation,
                $this->stageMetrics,
                $exception,
                $this->queryExceptionDiagnostics($exception),
            );
        }
    }

    private function stageWorkbook(string $path, LocImportBatch $batch): array
    {
        if (IOFactory::identify($path) !== 'Xls') {
            throw new \RuntimeException('El contenido del archivo no corresponde a un libro .xls válido.');
        }

        $reader = IOFactory::createReader('Xls');
        $worksheetInfo = $reader->listWorksheetInfo($path);
        $worksheet = $worksheetInfo[0] ?? null;
        if (! $worksheet) {
            throw new \RuntimeException('El archivo no contiene una hoja de cálculo.');
        }
        $lastColumn = $worksheet['lastColumnLetter'];
        $totalRows = (int) $worksheet['totalRows'];
        $worksheetName = $worksheet['worksheetName'];
        $this->beginDiagnosticStage('seleccion_hoja_deteccion_encabezados');
        $reader->setReadFilter(new LocXlsChunkReadFilter(1, 1));
        $reader->setLoadSheetsOnly([$worksheetName]);
        $headerBook = $reader->load($path);
        $sheet = $headerBook->getSheetByName($worksheetName);
        if (! $sheet) {
            throw new \RuntimeException('No fue posible abrir la primera hoja de datos del libro.');
        }
        $rawHeaders = $sheet->rangeToArray("A1:{$lastColumn}1", null, true, true)[0] ?? [];
        $headers = $this->describeHeaders($rawHeaders);
        $this->diagnosticLocation = [
            'sheet' => $worksheetName,
            'header_row' => 1,
            'column_count' => count($headers),
            'headers' => array_map(fn (array $header): array => [
                'column' => $this->columnName($header['index'] + 1),
                'original' => $header['original'],
                'normalized' => $header['normalized'],
            ], $headers),
        ];
        $headerBook->disconnectWorksheets();
        unset($headerBook);

        if (! $headers) {
            throw new \RuntimeException('El archivo no contiene encabezados.');
        }
        if ($totalRows < 2) {
            throw new \RuntimeException('La hoja contiene encabezados, pero no tiene filas de datos.');
        }

        $this->beginDiagnosticStage('limpieza_normalizacion_encabezados');
        $headerGroups = $this->duplicateHeaderGroups($headers);
        $cleaningReport = $this->inspectAndCleanHeaders($headers, $headerGroups);

        $normalizedHeaders = array_column($headers, 'normalized');
        $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, $normalizedHeaders));
        if ($missingHeaders) {
            $cleaningReport['errores'][] = 'Faltan encabezados obligatorios: '.implode(', ', $missingHeaders).'.';
        }

        $estimatedRows = max(0, $totalRows - 1);
        $batch->update([
            'total_leidas' => 0,
            'hoja_procesada' => $worksheetName,
            'total_columnas' => (int) $worksheet['totalColumns'],
        ]);
        $this->persistProgressSummary($batch, 0, $estimatedRows, $cleaningReport);
        if ($missingHeaders) {
            throw new \RuntimeException('Faltan encabezados obligatorios: '.implode(', ', $missingHeaders).'.');
        }

        $readChunkSize = max(1, min(5000, (int) config('loc_import.read_chunk_size', 5000)));
        $insertChunkSize = max(1, min(500, (int) config('loc_import.write_chunk_size', 250)));
        $totalRead = 0;
        $blocksCompleted = 0;
        $totalBlockSeconds = 0.0;
        $differentRowsByGroup = array_fill_keys(array_keys($headerGroups), 0);
        $rowsWithDuplicateDifferences = 0;
        $maxBlockPeakBytes = memory_get_peak_usage(true);
        $maxBlockPeakActualBytes = memory_get_peak_usage(false);
        $this->beginDiagnosticStage('mapeo_validacion_preparacion_staging');

        for ($start = 2; $start <= $totalRows; $start += $readChunkSize) {
            memory_reset_peak_usage();
            $end = min($start + $readChunkSize - 1, $totalRows);
            $blockNumber = (int) floor(($start - 2) / $readChunkSize) + 1;
            $rowsBeforeBlock = $totalRead;
            $blockStartedAt = microtime(true);
            $blockMemoryStart = memory_get_usage(true);
            $openSeconds = 0.0;
            $readSeconds = 0.0;
            $mapSeconds = 0.0;
            $writeSeconds = 0.0;
            $writeStartedAt = 0.0;
            $mapStartedAt = 0.0;
            $blockPhase = 'apertura';
            $book = null;
            $dataSheet = null;
            $reader = null;
            $rows = [];
            $staging = [];
            $this->diagnosticLocation = [
                'sheet' => $worksheetName,
                'block' => $blockNumber,
                'row_start' => $start,
                'row_end' => $end,
            ];
            $this->persistStageStatus();
            Log::info('Inicia bloque de importación LOC.', [
                'batch_id' => $batch->id,
                'block' => $blockNumber,
                'row_start' => $start,
                'row_end' => $end,
                'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
                'memory_usage_actual_mb' => round(memory_get_usage(false) / 1048576, 1),
            ]);
            try {
                $stageStartedAt = microtime(true);
                $reader = IOFactory::createReader('Xls');
                $reader->setReadFilter(new LocXlsChunkReadFilter($start, $end));
                $reader->setLoadSheetsOnly([$worksheetName]);
                $book = $reader->load($path);
                $dataSheet = $book->getSheetByName($worksheetName);
                $openSeconds = microtime(true) - $stageStartedAt;
                if (! $dataSheet) {
                    throw new \RuntimeException('No fue posible abrir la hoja de datos durante la lectura por bloques.');
                }

                $blockPhase = 'lectura';
                $stageStartedAt = microtime(true);
                $rows = $dataSheet->rangeToArray("A{$start}:{$lastColumn}{$end}", null, true, true);
                $readSeconds = microtime(true) - $stageStartedAt;
                $blockPhase = 'mapeo_validacion';
                $mapStartedAt = microtime(true);
                foreach ($rows as $offset => $cells) {
                    if ($this->isEmptyRow($cells)) {
                        continue;
                    }

                    $rowNumber = $start + $offset;
                    $this->diagnosticLocation = [
                        'sheet' => $worksheetName,
                        'block' => $blockNumber,
                        'row' => $rowNumber,
                    ];
                    $totalRead++;
                    [$canonical, $raw] = $this->mapRow($headers, $cells, $dataSheet, $rowNumber);
                    $errors = $this->validateRow($canonical, $raw);
                    $duplicateDifferences = $this->duplicateGroupsForRow($raw, $headerGroups);
                    foreach (array_keys($duplicateDifferences) as $groupKey) {
                        $differentRowsByGroup[$groupKey]++;
                    }
                    if ($duplicateDifferences) {
                        $rowsWithDuplicateDifferences++;
                        $errors = array_merge($errors, $this->duplicateRowErrors($raw, $duplicateDifferences));
                    }
                    $cuip = $this->normalizeCuip((string) ($canonical['cuip'] ?? ''));
                    $action = $errors ? 'rechazado' : 'pendiente';

                    $staging[] = [
                        'import_batch_id' => $batch->id,
                        'numero_fila' => $rowNumber,
                        'cuip' => $cuip === '' || mb_strlen($cuip) > 100 ? null : $cuip,
                        'datos' => json_encode(['campos' => $canonical, 'origen' => $raw], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'errores' => json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'accion' => $action,
                        'estado_aplicacion' => 'pendiente',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (count($staging) >= $insertChunkSize) {
                        $blockPhase = 'escritura_staging';
                        $writeStartedAt = microtime(true);
                        DB::table('loc_import_staging_rows')->insert($staging);
                        $writeSeconds += microtime(true) - $writeStartedAt;
                        $staging = [];
                        $blockPhase = 'mapeo_validacion';
                    }
                }
                $mapSeconds = microtime(true) - $mapStartedAt - $writeSeconds;
                if ($staging) {
                    $blockPhase = 'escritura_staging';
                    $writeStartedAt = microtime(true);
                    DB::table('loc_import_staging_rows')->insert($staging);
                    $writeSeconds += microtime(true) - $writeStartedAt;
                }
            } catch (Throwable $exception) {
                if ($blockPhase === 'apertura' && $openSeconds === 0.0) {
                    $openSeconds = microtime(true) - $stageStartedAt;
                } elseif ($blockPhase === 'lectura' && $readSeconds === 0.0) {
                    $readSeconds = microtime(true) - $stageStartedAt;
                }
                if ($blockPhase === 'escritura_staging') {
                    $writeSeconds += microtime(true) - $writeStartedAt;
                }
                if ($mapStartedAt > 0 && $mapSeconds === 0.0) {
                    $mapSeconds = max(0, microtime(true) - $mapStartedAt - $writeSeconds);
                }
                $queryDiagnostics = $this->queryExceptionDiagnostics($exception);
                Log::error('Falló un bloque del análisis de importación LOC.', array_merge([
                    'batch_id' => $batch->id,
                    'block' => $blockNumber,
                    'row_start' => $start,
                    'row_end' => $end,
                    'rows_processed_before_failure' => $totalRead,
                    'block_phase' => $blockPhase,
                    'stage' => $this->diagnosticStage,
                    'exception_type' => $exception::class,
                    'elapsed_seconds' => round(microtime(true) - $blockStartedAt, 3),
                    'open_seconds' => round($openSeconds, 3),
                    'read_seconds' => round($readSeconds, 3),
                    'map_validate_seconds' => round($mapSeconds, 3),
                    'staging_write_seconds' => round($writeSeconds, 3),
                    'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
                    'memory_usage_actual_mb' => round(memory_get_usage(false) / 1048576, 1),
                    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                    'peak_memory_actual_mb' => round(memory_get_peak_usage(false) / 1048576, 1),
                ], $queryDiagnostics));

                throw $exception;
            } finally {
                if ($book !== null) {
                    $book->disconnectWorksheets();
                }
                unset($book, $dataSheet, $reader, $rows, $staging, $insertRows);
                gc_collect_cycles();
            }

            $blockMetrics = [
                'block' => $blockNumber,
                'row_start' => $start,
                'row_end' => $end,
                'rows_processed' => $totalRead - $rowsBeforeBlock,
                'open_seconds' => round($openSeconds, 3),
                'read_seconds' => round($readSeconds, 3),
                'map_validate_seconds' => round($mapSeconds, 3),
                'staging_write_seconds' => round($writeSeconds, 3),
                'elapsed_seconds' => round(microtime(true) - $blockStartedAt, 3),
                'memory_start_mb' => round($blockMemoryStart / 1048576, 1),
                'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
                'memory_usage_actual_mb' => round(memory_get_usage(false) / 1048576, 1),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'peak_memory_actual_mb' => round(memory_get_peak_usage(false) / 1048576, 1),
            ];
            $blocksCompleted++;
            $totalBlockSeconds += $blockMetrics['elapsed_seconds'];
            $maxBlockPeakBytes = max($maxBlockPeakBytes, memory_get_peak_usage(true));
            $maxBlockPeakActualBytes = max($maxBlockPeakActualBytes, memory_get_peak_usage(false));
            Log::info('Bloque de importación LOC procesado.', [
                'batch_id' => $batch->id,
                ...$blockMetrics,
            ]);

            $batch->update(['total_leidas' => $totalRead]);
            $this->persistProgressSummary($batch, $totalRead, $estimatedRows, $cleaningReport);
            $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
            $summary['diagnostico']['ultimo_bloque'] = $blockMetrics;
            $summary['diagnostico']['resumen_bloques'] = [
                'bloques_completados' => $blocksCompleted,
                'duracion_total_segundos' => round($totalBlockSeconds, 3),
                'pico_maximo_memoria_mb' => round($maxBlockPeakBytes / 1048576, 1),
                'pico_maximo_memoria_real_mb' => round($maxBlockPeakActualBytes / 1048576, 1),
            ];
            $batch->update([
                'resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
        $this->beginDiagnosticStage('comparacion_duplicados_staging');
        $cleaningReport = $this->finalizeDuplicateColumns(
            $batch,
            $headerGroups,
            $cleaningReport,
            $differentRowsByGroup,
            $rowsWithDuplicateDifferences
        );
        $this->beginDiagnosticStage('clasificacion_filas');
        $this->markDuplicateCuips($batch);
        $this->classifyRows($batch);
        $counts = $this->rowCounts($batch);
        $cleaningErrors = $cleaningReport['errores'];
        $rowsWithErrors = ($counts['rechazado'] ?? 0) + ($counts['conflicto'] ?? 0);
        if ($rowsWithErrors > 0) {
            $cleaningErrors[] = "Se detectaron {$rowsWithErrors} filas rechazadas o en conflicto; consulta las observaciones por fila antes de confirmar.";
        }
        $previousSummary = json_decode((string) $batch->resumen_errores, true) ?: [];
        $batch->update([
            'estado' => 'en_revision',
            'total_leidas' => $totalRead,
            'total_aceptadas' => ($counts['nuevo'] ?? 0) + ($counts['actualizar'] ?? 0) + ($counts['sin_cambios'] ?? 0),
            'total_rechazadas' => ($counts['rechazado'] ?? 0) + ($counts['conflicto'] ?? 0),
            'total_actualizadas' => $counts['actualizar'] ?? 0,
            'total_nuevas' => $counts['nuevo'] ?? 0,
            'total_sin_cambios' => $counts['sin_cambios'] ?? 0,
            'total_columnas' => (int) $worksheet['totalColumns'],
            'hoja_procesada' => $worksheet['worksheetName'],
            'resumen_errores' => json_encode([
                'conteos' => $counts,
                'encabezados_duplicados' => array_column($cleaningReport['encabezados_afectados'], 'encabezado'),
                'encabezados' => array_column($headers, 'original'),
                'limpieza' => array_merge($cleaningReport, [
                    'filas_con_errores' => $rowsWithErrors,
                    'errores' => $cleaningErrors,
                ]),
                'hoja' => $worksheet['worksheetName'],
                'columnas' => (int) $worksheet['totalColumns'],
                'diagnostico' => $previousSummary['diagnostico'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $batch->refresh();
        $this->beginDiagnosticStage('huella_revision');
        $batch->update(['revision_sha256' => $this->reviewFingerprint($batch)]);
        $this->finishDiagnosticStage();
        $processPeakMemoryBytes = max($maxBlockPeakBytes, memory_get_peak_usage(true));
        $processPeakMemoryActualBytes = max($maxBlockPeakActualBytes, memory_get_peak_usage(false));
        $summary = json_decode((string) $batch->fresh()->resumen_errores, true) ?: [];
        $summary['diagnostico'] = array_merge($summary['diagnostico'] ?? [], [
            'stage_metrics' => $this->stageMetrics,
            'peak_memory_mb' => round($processPeakMemoryBytes / 1048576, 1),
            'peak_memory_actual_mb' => round($processPeakMemoryActualBytes / 1048576, 1),
            'resumen_bloques' => [
                'bloques_completados' => $blocksCompleted,
                'duracion_total_segundos' => round($totalBlockSeconds, 3),
                'pico_maximo_memoria_mb' => round($maxBlockPeakBytes / 1048576, 1),
                'pico_maximo_memoria_real_mb' => round($maxBlockPeakActualBytes / 1048576, 1),
            ],
        ]);
        $batch->update(['resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

        return $counts;
    }

    public function commit(LocImportBatch $batch, ?int $userId = null): array
    {
        $confirmationStartedAt = microtime(true);
        $fingerprintStartedAt = null;
        $fingerprintSeconds = 0.0;
        $applicationStartedAt = null;
        $applicationSeconds = 0.0;
        $batch = DB::transaction(function () use ($batch, $userId) {
            $locked = LocImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($userId !== null && $locked->usuario_id !== $userId && auth()->user()?->role !== 'superadmin') {
                abort(403);
            }

            if (! in_array($locked->estado, ['en_revision', 'fallido_parcial'], true)) {
                throw new \RuntimeException('Esta importación no está disponible para confirmación o reanudación.');
            }

            $locked->estado = 'procesando';
            $locked->save();

            return $locked;
        });

        try {
            $fingerprintStartedAt = microtime(true);
            if (! hash_equals((string) $batch->revision_sha256, $this->reviewFingerprint($batch))) {
                throw new \RuntimeException('Los datos revisados cambiaron después de generar la vista previa. El lote no se aplicó.');
            }
            $fingerprintSeconds = microtime(true) - $fingerprintStartedAt;

            $applicationStartedAt = microtime(true);
            $this->applyPendingRows($batch);
            $applicationSeconds = microtime(true) - $applicationStartedAt;
            $counts = $this->applicationCounts($batch);
            $rowCounts = $this->rowCounts($batch);
            $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
            $confirmationMetrics = $this->confirmationMetrics(
                $batch,
                $confirmationStartedAt,
                $fingerprintSeconds,
                $applicationSeconds
            );
            $summary['diagnostico']['confirmacion'] = $confirmationMetrics;

            $batch->update([
                'estado' => 'completado',
                'fecha_confirmacion' => now(),
                'total_aceptadas' => $counts['insertado'] + $counts['actualizado'] + $counts['sin_cambios'],
                'total_rechazadas' => ($rowCounts['rechazado'] ?? 0) + ($rowCounts['conflicto'] ?? 0),
                'total_insertadas' => $counts['insertado'],
                'total_actualizadas' => $counts['actualizado'],
                'total_nuevas' => $rowCounts['nuevo'] ?? 0,
                'total_sin_cambios' => $counts['sin_cambios'],
                'resumen_errores' => json_encode(array_merge($summary, [
                    'conteos_revision' => $rowCounts,
                    'resultado' => $counts,
                ]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

            return [
                'inserted' => $counts['insertado'],
                'updated' => $counts['actualizado'],
                'unchanged' => $counts['sin_cambios'],
                'rejected' => ($rowCounts['rechazado'] ?? 0) + ($rowCounts['conflicto'] ?? 0),
            ];
        } catch (Throwable $exception) {
            if ($fingerprintStartedAt !== null && $fingerprintSeconds === 0.0) {
                $fingerprintSeconds = microtime(true) - $fingerprintStartedAt;
            } elseif ($applicationStartedAt !== null && $applicationSeconds === 0.0) {
                $applicationSeconds = microtime(true) - $applicationStartedAt;
            }
            $counts = $this->applicationCounts($batch);
            $rowCounts = $this->rowCounts($batch);
            $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
            $summary['diagnostico']['confirmacion'] = $this->confirmationMetrics(
                $batch,
                $confirmationStartedAt,
                $fingerprintSeconds,
                $applicationSeconds
            );
            $batch->update([
                'estado' => $exception->getMessage() === 'Los datos revisados cambiaron después de generar la vista previa. El lote no se aplicó.'
                    ? 'fallido'
                    : 'fallido_parcial',
                'total_aceptadas' => $counts['insertado'] + $counts['actualizado'] + $counts['sin_cambios'],
                'total_rechazadas' => ($rowCounts['rechazado'] ?? 0) + ($rowCounts['conflicto'] ?? 0),
                'total_insertadas' => $counts['insertado'],
                'total_actualizadas' => $counts['actualizado'],
                'total_sin_cambios' => $counts['sin_cambios'],
                'resumen_errores' => json_encode(array_merge($summary, [
                    'error' => 'La confirmación se detuvo. Revise el lote y los registros aplicados antes de reanudar.',
                    'tipo_error' => class_basename($exception),
                    'conteos_aplicacion' => $counts,
                ]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

            throw $exception;
        }
    }

    public function omitStagingRow(LocImportStagingRow $row, string $motivo, ?int $usuarioId = null): void
    {
        DB::transaction(function () use ($row, $motivo, $usuarioId): void {
            $lockedRow = LocImportStagingRow::query()->where('id', $row->id)->lockForUpdate()->firstOrFail();
            $batch = LocImportBatch::query()->where('id', $lockedRow->import_batch_id)->lockForUpdate()->firstOrFail();

            if (! in_array($batch->estado, ['en_revision', 'pendiente', 'fallido_parcial'], true)) {
                throw new \RuntimeException('Solo se pueden excluir filas de un lote en revisión.');
            }

            $datos = is_array($lockedRow->datos) ? $lockedRow->datos : json_decode((string) $lockedRow->datos, true);
            $accionPrevia = $lockedRow->accion;

            $datos['exclusion'] = [
                'motivo' => trim($motivo),
                'accion_previa' => $accionPrevia,
                'fecha' => now()->toDateTimeString(),
                'usuario_id' => $usuarioId,
            ];

            $lockedRow->update([
                'accion' => 'omitido',
                'estado_aplicacion' => 'omitido',
                'datos' => $datos,
                'updated_at' => now(),
            ]);

            $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
            $summary['filas_omitidas'] = $summary['filas_omitidas'] ?? [];
            $summary['filas_omitidas'][] = [
                'numero_fila' => $lockedRow->numero_fila,
                'motivo' => trim($motivo),
                'usuario_id' => $usuarioId,
                'fecha' => now()->toDateTimeString(),
            ];

            $stagingCounts = LocImportStagingRow::query()
                ->where('import_batch_id', $batch->id)
                ->selectRaw('accion, COUNT(*) as total')
                ->groupBy('accion')
                ->pluck('total', 'accion')
                ->all();

            $batch->total_rechazadas = ($stagingCounts['conflicto'] ?? 0) + ($stagingCounts['rechazado'] ?? 0);
            $batch->resumen_errores = json_encode(array_merge($summary, [
                'conflicto' => $stagingCounts['conflicto'] ?? 0,
                'rechazado' => $stagingCounts['rechazado'] ?? 0,
                'omitido' => $stagingCounts['omitido'] ?? 0,
            ]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $batch->revision_sha256 = $this->reviewFingerprint($batch);
            $batch->save();
        });
    }

    private function applyPendingRows(LocImportBatch $batch): void
    {
        $chunkSize = max(1, min(500, (int) config('loc_import.write_chunk_size', 250)));

        LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('estado_aplicacion', 'pendiente')
            ->whereIn('accion', ['nuevo', 'actualizar', 'sin_cambios'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($batch) {
                DB::transaction(function () use ($rows, $batch) {
                    $newRecords = [];
                    $cuips = $rows->pluck('cuip')
                        ->filter()
                        ->map(fn ($cuip): string => $this->normalizeCuip((string) $cuip))
                        ->filter(static fn (string $cuip): bool => $cuip !== '')
                        ->unique()
                        ->values()
                        ->all();

                    $lookupVariants = $this->cuipLookupVariants($cuips);

                    $existingPeople = $lookupVariants !== []
                        ? LocPersona::query()
                            ->whereIn('cuip', $lookupVariants)
                            ->lockForUpdate()
                            ->get()
                            ->groupBy(fn (LocPersona $person): string => $this->normalizeCuip($person->cuip))
                        : collect();

                    $reconciledPersonIds = $rows->map(function ($r) {
                        $d = is_array($r->datos) ? $r->datos : json_decode((string) $r->datos, true);
                        return $d['conciliacion_identidad']['persona_id'] ?? null;
                    })->filter()->unique()->values();

                    $reconciledPeople = $reconciledPersonIds->isNotEmpty()
                        ? LocPersona::query()->whereIn('id', $reconciledPersonIds)->lockForUpdate()->get()->keyBy('id')
                        : collect();

                    foreach ($rows as $staged) {
                        $row = LocImportStagingRow::query()->lockForUpdate()->findOrFail($staged->id);
                        if ($row->estado_aplicacion !== 'pendiente') {
                            continue;
                        }

                        $data = is_array($row->datos) ? $row->datos : json_decode((string) $row->datos, true);
                        $fields = $data['campos'] ?? [];
                        $raw = $data['origen'] ?? [];
                        $reconciledInfo = $data['conciliacion_identidad'] ?? null;
                        $reconciledPersonId = $reconciledInfo['persona_id'] ?? null;

                        $attributes = $this->personAttributes($fields, $raw);
                        $person = null;

                        if ($reconciledPersonId !== null) {
                            $person = $reconciledPeople->get($reconciledPersonId) ?? LocPersona::query()->lockForUpdate()->find($reconciledPersonId);
                        } else {
                            $matches = $existingPeople->get($this->normalizeCuip((string) $row->cuip), collect());
                            if ($matches->count() > 1) {
                                throw new \RuntimeException("El CUIP '{$row->cuip}' coincide con más de una persona existente; corrige el padrón antes de reanudar.");
                            }
                            $person = $matches->first();
                        }

                        if (! $person) {
                            $attributes['datos_loc'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                            $newRecords[] = [
                                'staged_id' => $row->id,
                                'attributes' => array_merge([
                                    'import_batch_id' => $batch->id,
                                    'cuip' => null,
                                    'loc_id' => null,
                                    'personal_id' => null,
                                    'nombre' => null,
                                    'rfc' => null,
                                    'curp' => null,
                                    'categoria' => null,
                                    'estatus' => null,
                                    'subdireccion' => null,
                                    'municipio' => null,
                                    'region' => null,
                                    'agrupamiento' => null,
                                    'clave_seguridad' => null,
                                    'cuerpo_seguridad' => null,
                                    'adscripcion' => null,
                                    'unidad_adscripcion' => null,
                                    'fecha_ingreso' => null,
                                    'fecha_baja' => null,
                                    'activo' => true,
                                    'observaciones' => null,
                                    'datos_loc' => null,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ], $attributes),
                            ];

                            continue;
                        }

                        if ($reconciledInfo !== null) {
                            $attributes['cuip'] = (string) $row->cuip;
                            $datosLoc = is_array($person->datos_loc) ? $person->datos_loc : (json_decode((string) $person->datos_loc, true) ?: []);
                            $historial = $datosLoc['_historial_identidad'] ?? [];
                            if (! is_array($historial)) {
                                $historial = [];
                            }

                            $alreadyRecorded = false;
                            foreach ($historial as $entry) {
                                if (($entry['cuip_anterior'] ?? null) === $person->cuip
                                    && ($entry['cuip_nuevo'] ?? null) === (string) $row->cuip
                                    && ($entry['import_batch_id'] ?? null) === $batch->id) {
                                    $alreadyRecorded = true;
                                    break;
                                }
                            }

                            if (! $alreadyRecorded) {
                                $historial[] = [
                                    'cuip_anterior' => $person->cuip,
                                    'cuip_nuevo' => (string) $row->cuip,
                                    'fecha_actualizacion' => now()->toIso8601String(),
                                    'criterio' => $reconciledInfo['criterio'] ?? 'conciliacion_secundaria',
                                    'import_batch_id' => $batch->id,
                                    'numero_fila' => $row->numero_fila,
                                ];
                            }

                            $datosLoc = array_replace(
                                $datosLoc,
                                array_filter($raw, static fn ($value): bool => $value !== ''),
                                ['_historial_identidad' => $historial]
                            );
                            $attributes['datos_loc'] = $datosLoc;

                            $person->fill($attributes);
                            $person->import_batch_id = $batch->id;
                            $person->save();
                            $row->update(['estado_aplicacion' => 'actualizado']);

                            continue;
                        }

                        $attributes['datos_loc'] = array_replace(
                            $person->datos_loc ?? [],
                            array_filter($raw, static fn ($value): bool => $value !== '')
                        );

                        if (! $this->personChanged($person, $attributes)) {
                            $row->update(['estado_aplicacion' => 'sin_cambios']);

                            continue;
                        }

                        $person->fill($attributes);
                        $person->import_batch_id = $batch->id;
                        $person->save();
                        $row->update(['estado_aplicacion' => 'actualizado']);
                    }

                    foreach (array_chunk($newRecords, max(1, min(500, (int) config('loc_import.write_chunk_size', 250)))) as $newChunk) {
                        LocPersona::query()->insert(array_column($newChunk, 'attributes'));
                        LocImportStagingRow::query()
                            ->whereIn('id', array_column($newChunk, 'staged_id'))
                            ->update(['estado_aplicacion' => 'insertado', 'updated_at' => now()]);
                    }
                });
            });
    }

    private function personAttributes(array $fields, array $raw): array
    {
        $attributes = [];
        foreach (self::FIELD_MAP as $source => $destination) {
            if (array_key_exists($destination, $fields) && trim((string) $fields[$destination]) !== '') {
                $attributes[$destination] = trim((string) $fields[$destination]);
            }
        }

        $cuip = $this->normalizeCuip((string) ($fields['cuip'] ?? ''));
        if ($cuip !== '') {
            $attributes['cuip'] = $cuip;
        }

        $termination = $this->dateValue($this->sourceValue($raw, 'FECHA DE BAJA'));
        if ($termination !== null) {
            $attributes['fecha_baja'] = $termination;
            $attributes['activo'] = false;
        }

        $entry = $this->dateValue($this->sourceValue($raw, 'FECHA DE ALTA COORPORACION'));
        if ($entry !== null) {
            $attributes['fecha_ingreso'] = $entry;
        }

        $attributes['datos_loc'] = $raw;

        return $attributes;
    }

    private function classifyRows(LocImportBatch $batch): void
    {
        LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('accion', 'pendiente')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                $cuips = $rows->pluck('cuip')
                    ->filter()
                    ->map(fn ($cuip): string => $this->normalizeCuip((string) $cuip))
                    ->filter(static fn (string $cuip): bool => $cuip !== '')
                    ->unique()
                    ->values()
                    ->all();

                $lookupVariants = $this->cuipLookupVariants($cuips);

                $existing = $lookupVariants !== []
                    ? LocPersona::query()
                        ->whereIn('cuip', $lookupVariants)
                        ->get()
                        ->groupBy(fn (LocPersona $person): string => $this->normalizeCuip($person->cuip))
                    : collect();
                $idsByAction = ['nuevo' => [], 'actualizar' => [], 'sin_cambios' => []];

                foreach ($rows as $row) {
                    $fields = $row->datos['campos'] ?? [];
                    $matches = $existing->get($this->normalizeCuip((string) $row->cuip), collect());
                    if ($matches->count() > 1) {
                        $errors = $row->errores ?? [];
                        $errors[] = 'CUIP ambiguo: coincide con más de una persona existente en el padrón.';
                        $row->update(['accion' => 'conflicto', 'errores' => $errors]);

                        continue;
                    }
                    $person = $matches->first();
                    if ($person) {
                        // Regla A: Si el CUIP coincide, verificar si los identificadores secundarios presentan contradicciones fuertes
                        $incomingCurp = strtoupper(trim(preg_replace('/\s+/', '', (string) ($fields['curp'] ?? $row->datos['origen']['CURP'] ?? ''))));
                        $existingCurp = strtoupper(trim(preg_replace('/\s+/', '', (string) ($person->curp ?? ''))));
                        $incomingRfc = strtoupper(trim(preg_replace('/\s+/', '', (string) ($fields['rfc'] ?? $row->datos['origen']['RFC'] ?? ''))));
                        $existingRfc = strtoupper(trim(preg_replace('/\s+/', '', (string) ($person->rfc ?? ''))));

                        $hasCurpConflict = strlen($incomingCurp) === 18 && strlen($existingCurp) === 18 && substr($incomingCurp, 0, 10) !== substr($existingCurp, 0, 10);
                        $hasRfcConflict = strlen($incomingRfc) >= 10 && strlen($existingRfc) >= 10 && substr($incomingRfc, 0, 10) !== substr($existingRfc, 0, 10);

                        if ($hasCurpConflict || $hasRfcConflict) {
                            $errors = $row->errores ?? [];
                            $errors[] = "Contradicción en identidad: el CUIP coincide pero los identificadores secundarios difieren (CURP/RFC). Requiere revisión manual.";
                            $row->update(['accion' => 'conflicto', 'errores' => $errors]);

                            continue;
                        }
                    } else {
                        // Regla B: Búsqueda secundaria cuando no coincide el CUIP
                        $secMatch = $this->reconcilePersonBySecondaryIdentifiers($fields, $row->datos['origen'] ?? [], (string) $row->cuip);

                        if ($secMatch['status'] === 'reconciliado') {
                            $person = $secMatch['person'];
                            $rowDatos = $row->datos;
                            $rowDatos['conciliacion_identidad'] = [
                                'persona_id' => $person->id,
                                'cuip_anterior' => $person->cuip,
                                'cuip_nuevo' => (string) $row->cuip,
                                'criterio' => $secMatch['criterio'],
                            ];
                            $row->update(['datos' => $rowDatos]);

                            $attributes = $this->personAttributes($fields, $row->datos['origen'] ?? []);
                            $attributes['cuip'] = (string) $row->cuip;
                            $attributes['datos_loc'] = array_replace(
                                $person->datos_loc ?? [],
                                array_filter($row->datos['origen'] ?? [], static fn ($value): bool => $value !== '')
                            );
                            $idsByAction['actualizar'][] = $row->id;

                            continue;
                        }

                        if ($secMatch['status'] === 'conflicto') {
                            $errors = $row->errores ?? [];
                            $errors[] = $secMatch['error'];
                            $row->update(['accion' => 'conflicto', 'errores' => $errors]);

                            continue;
                        }

                        $idsByAction['nuevo'][] = $row->id;

                        continue;
                    }

                    $attributes = $this->personAttributes($fields, $row->datos['origen'] ?? []);
                    $attributes['datos_loc'] = array_replace(
                        $person->datos_loc ?? [],
                        array_filter($row->datos['origen'] ?? [], static fn ($value): bool => $value !== '')
                    );
                    $idsByAction[$this->personChanged($person, $attributes) ? 'actualizar' : 'sin_cambios'][] = $row->id;
                }

                foreach ($idsByAction as $action => $ids) {
                    foreach (array_chunk($ids, 500) as $idChunk) {
                        LocImportStagingRow::query()->whereIn('id', $idChunk)->update([
                            'accion' => $action,
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    private function markDuplicateCuips(LocImportBatch $batch): void
    {
        $duplicates = DB::table('loc_import_staging_rows')
            ->select('cuip')
            ->where('import_batch_id', $batch->id)
            ->whereNotNull('cuip')
            ->groupBy('cuip')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('cuip')
            ->all();

        foreach (array_chunk($duplicates, 400) as $cuipChunk) {
            LocImportStagingRow::query()
                ->where('import_batch_id', $batch->id)
                ->whereIn('cuip', $cuipChunk)
                ->update(['accion' => 'conflicto', 'updated_at' => now()]);
        }
    }

    private function personChanged(LocPersona $person, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            if ($key === 'datos_loc') {
                $old = $person->datos_loc ?? [];
                if (json_encode($old, JSON_UNESCAPED_UNICODE) !== json_encode($value, JSON_UNESCAPED_UNICODE)) {
                    return true;
                }

                continue;
            }

            $old = $person->{$key};
            if (in_array($key, ['fecha_baja', 'fecha_ingreso'], true)) {
                $old = $old ? Carbon::parse($old)->format('Y-m-d') : null;
            }

            if ((string) ($old ?? '') !== (string) ($value ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Regla B: Búsqueda secundaria de persona por CURP, RFC y personal_id.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $raw
     * @param  string  $cuip
     * @return array{status: 'reconciliado'|'conflicto'|'sin_coincidencia', person?: LocPersona, criterio?: string, error?: string}
     */
    private function reconcilePersonBySecondaryIdentifiers(array $fields, array $raw, string $cuip): array
    {
        $curp = strtoupper(trim(preg_replace('/\s+/', '', (string) ($fields['curp'] ?? $raw['CURP'] ?? ''))));
        $rfc = strtoupper(trim(preg_replace('/\s+/', '', (string) ($fields['rfc'] ?? $raw['RFC'] ?? ''))));
        $personalIdRaw = trim((string) ($fields['personal_id'] ?? $raw['ID PERSONAL'] ?? $raw['PERSONAL ID'] ?? ''));
        $personalId = is_numeric($personalIdRaw) ? (int) $personalIdRaw : null;
        $nombre = strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($fields['nombre'] ?? ''))));

        $candidateByCurp = null;
        $candidateByRfc = null;

        // 1. Búsqueda por CURP (18 caracteres)
        if (strlen($curp) === 18) {
            $curpMatches = LocPersona::query()->where('curp', $curp)->get();
            if ($curpMatches->count() > 1) {
                return [
                    'status' => 'conflicto',
                    'error' => "CURP '{$curp}' ambigua: coincide con múltiples personas en el padrón.",
                ];
            }
            if ($curpMatches->count() === 1) {
                $candidateByCurp = $curpMatches->first();
            }
        }

        // 2. Búsqueda por RFC (al menos 10 caracteres)
        if (strlen($rfc) >= 10) {
            $rfc10 = substr($rfc, 0, 10);
            $query = LocPersona::query();
            if ($personalId !== null) {
                $query->where('personal_id', $personalId)
                    ->where(function ($q) use ($rfc, $rfc10) {
                        $q->where('rfc', $rfc)
                            ->orWhere('rfc', 'LIKE', $rfc10.'%');
                    });
            } else {
                $query->where(function ($q) use ($rfc, $rfc10) {
                    $q->where('rfc', $rfc)
                        ->orWhere('rfc', 'LIKE', $rfc10.'%');
                });
            }

            $rfcMatches = $query->get();
            if ($rfcMatches->count() > 1) {
                if ($candidateByCurp === null) {
                    return [
                        'status' => 'conflicto',
                        'error' => "RFC '{$rfc}' ambiguo: coincide con múltiples personas en el padrón.",
                    ];
                }
            } elseif ($rfcMatches->count() === 1) {
                $candidateByRfc = $rfcMatches->first();
            }
        }

        // 3. Evaluar contradicción entre CURP y RFC si ambos encontraron personas distintas
        if ($candidateByCurp !== null && $candidateByRfc !== null && $candidateByCurp->id !== $candidateByRfc->id) {
            return [
                'status' => 'conflicto',
                'error' => "Contradicción en identificadores: la CURP '{$curp}' apunta a la persona ID {$candidateByCurp->id} pero el RFC '{$rfc}' apunta a la persona ID {$candidateByRfc->id}.",
            ];
        }

        // 4. Evaluar coincidencia unívoca por CURP
        if ($candidateByCurp !== null) {
            if (strlen($rfc) >= 10 && ! empty($candidateByCurp->rfc)) {
                $candRfc10 = substr(strtoupper(trim((string) $candidateByCurp->rfc)), 0, 10);
                $inRfc10 = substr($rfc, 0, 10);
                if (strlen($candRfc10) === 10 && $candRfc10 !== $inRfc10) {
                    return [
                        'status' => 'conflicto',
                        'error' => "Contradicción entre CURP y RFC para la persona ID {$candidateByCurp->id}.",
                    ];
                }
            }

            return [
                'status' => 'reconciliado',
                'person' => $candidateByCurp,
                'criterio' => 'coincidencia_inequivoca_por_curp',
            ];
        }

        // 5. Evaluar coincidencia por RFC + personal_id o RFC + Nombre
        if ($candidateByRfc !== null) {
            if (strlen($curp) === 18 && ! empty($candidateByRfc->curp)) {
                $candCurp = strtoupper(trim((string) $candidateByRfc->curp));
                if (strlen($candCurp) === 18 && substr($candCurp, 0, 10) !== substr($curp, 0, 10)) {
                    return [
                        'status' => 'conflicto',
                        'error' => "Contradicción entre RFC y CURP para la persona ID {$candidateByRfc->id}.",
                    ];
                }
            }

            if ($personalId !== null && (int) $candidateByRfc->personal_id === $personalId) {
                return [
                    'status' => 'reconciliado',
                    'person' => $candidateByRfc,
                    'criterio' => 'coincidencia_inequivoca_por_rfc_personal_id',
                ];
            }

            $candNombre = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $candidateByRfc->nombre)));
            if (! empty($nombre) && ! empty($candNombre) && ($nombre === $candNombre || str_contains($candNombre, $nombre) || str_contains($nombre, $candNombre))) {
                return [
                    'status' => 'reconciliado',
                    'person' => $candidateByRfc,
                    'criterio' => 'coincidencia_inequivoca_por_rfc_nombre',
                ];
            }

            return [
                'status' => 'sin_coincidencia',
            ];
        }

        return [
            'status' => 'sin_coincidencia',
        ];
    }

    /**
     * @param  list<array{original: string, normalized: string, raw_key: string, index: int}>  $headers
     * @param  array<string, array{header: string, columns: list<array{label: string, index: int, raw_key: string}>}>  $groups
     */
    private function inspectAndCleanHeaders(array $headers, array $groups): array
    {
        $emptyHeaders = array_values(array_filter($headers, static fn (array $header): bool => $header['normalized'] === ''));
        $affectedHeaders = [];
        $warnings = [];
        foreach ($groups as $group) {
            $labels = array_column($group['columns'], 'label');
            $affectedHeaders[] = [
                'encabezado' => $group['header'],
                'columnas' => $labels,
                'redundantes_descartadas' => [],
                'filas_con_discrepancia' => 0,
            ];
        }

        if ($emptyHeaders) {
            $emptyColumns = array_map(
                fn (array $header): string => $this->columnName($header['index'] + 1),
                $emptyHeaders
            );
            $warnings[] = 'Se detectaron '.count($emptyHeaders).' encabezados vacíos en las columnas '.implode(', ', $emptyColumns).'; se conservaron en el origen y no se asignaron a campos del padrón.';
            foreach ($emptyHeaders as $header) {
                $affectedHeaders[] = [
                    'encabezado' => '(vacío)',
                    'columnas' => [$this->columnName($header['index'] + 1)],
                    'redundantes_descartadas' => [],
                    'filas_con_discrepancia' => 0,
                ];
            }
        }

        return [
            'columnas_duplicadas_detectadas' => array_sum(array_map(
                static fn (array $group): int => count($group['columns']) - 1,
                $groups
            )),
            'grupos_duplicados_detectados' => count($groups),
            'columnas_redundantes_descartadas' => 0,
            'encabezados_vacios_detectados' => count($emptyHeaders),
            'encabezados_afectados' => $affectedHeaders,
            'filas_con_discrepancias' => 0,
            'filas_con_errores' => 0,
            'advertencias' => $warnings,
            'errores' => [],
        ];
    }

    /**
     * Remove redundant source keys after duplicate values have been compared during row mapping.
     */
    private function finalizeDuplicateColumns(
        LocImportBatch $batch,
        array $groups,
        array $report,
        array $differentRowsByGroup,
        int $rowsWithDifferences
    ): array {
        if (! $groups) {
            return $report;
        }

        $redundantKeysByGroup = [];
        foreach ($groups as $key => $group) {
            if ($differentRowsByGroup[$key] === 0) {
                $redundantKeysByGroup[$key] = array_column(array_slice($group['columns'], 1), 'raw_key');
            }
        }
        $redundantKeys = array_merge([], ...array_values($redundantKeysByGroup ?: [[]]));
        $this->removeRedundantSourceColumns($batch, $redundantKeys);

        $affectedHeaders = [];
        $warnings = $report['advertencias'];
        $errors = [];
        foreach ($groups as $key => $group) {
            $differenceCount = $differentRowsByGroup[$key];
            $discardedLabels = $differenceCount === 0
                ? array_column(array_slice($group['columns'], 1), 'label')
                : [];
            if ($discardedLabels) {
                $warnings[] = "Se descartaron columnas redundantes del encabezado {$group['header']} porque sus valores coinciden en todas las filas.";
            } else {
                $errors[] = "El encabezado {$group['header']} tiene valores diferentes en {$differenceCount} filas; esas filas requieren revisión y no se aplicarán.";
            }
            $affectedHeaders[] = [
                'encabezado' => $group['header'],
                'columnas' => array_column($group['columns'], 'label'),
                'redundantes_descartadas' => $discardedLabels,
                'filas_con_discrepancia' => $differenceCount,
            ];
        }

        $report['columnas_redundantes_descartadas'] = array_sum(array_map('count', $redundantKeysByGroup));
        $report['encabezados_afectados'] = array_merge($affectedHeaders, array_slice($report['encabezados_afectados'], count($groups)));
        $report['filas_con_discrepancias'] = $rowsWithDifferences;
        $report['filas_con_errores'] = $rowsWithDifferences;
        $report['advertencias'] = array_values(array_unique($warnings));
        $report['errores'] = $errors;

        return $report;
    }

    private function removeRedundantSourceColumns(LocImportBatch $batch, array $rawKeys): void
    {
        if (! $rawKeys) {
            return;
        }

        $jsonPaths = array_map(
            static fn (string $key): string => '$.origen.'.json_encode(
                $key,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            $rawKeys
        );
        $grammar = DB::connection()->getQueryGrammar();
        $table = $grammar->wrapTable('loc_import_staging_rows');
        $dataColumn = $grammar->wrap('datos');
        $updatedAtColumn = $grammar->wrap('updated_at');
        $batchColumn = $grammar->wrap('import_batch_id');
        $pathPlaceholders = implode(', ', array_fill(0, count($jsonPaths), '?'));

        DB::update(
            "UPDATE {$table} SET {$dataColumn} = JSON_REMOVE({$dataColumn}, {$pathPlaceholders}), {$updatedAtColumn} = ? WHERE {$batchColumn} = ?",
            [...$jsonPaths, now(), $batch->id]
        );
    }

    /**
     * @return array<string, array{header: string, columns: list<array{label: string, index: int}>}>
     */
    private function duplicateHeaderGroups(array $headers): array
    {
        $groups = [];
        foreach ($headers as $header) {
            if ($header['normalized'] === '') {
                continue;
            }
            $groups[$header['normalized']]['header'] = $header['normalized'];
            $groups[$header['normalized']]['columns'][] = [
                'label' => $this->columnName($header['index'] + 1),
                'index' => $header['index'],
                'raw_key' => $header['raw_key'],
            ];
        }

        return array_filter($groups, static fn (array $group): bool => count($group['columns']) > 1);
    }

    /**
     * @param  list<array{header: string, columns: list<array{label: string, index: int}>}>  $groups
     * @return list<string>
     */
    private function duplicateRowErrors(array $source, array $groups): array
    {
        $errors = [];
        foreach ($this->duplicateGroupsForRow($source, $groups) as $group) {
            $columns = implode(' y ', array_column($group['columns'], 'label'));
            $errors[] = "El encabezado duplicado {$group['header']} contiene valores diferentes entre las columnas {$columns}; la fila requiere aclaración.";
        }

        return $errors;
    }

    private function duplicateGroupsForRow(array $source, array $groups): array
    {
        $differentGroups = [];
        foreach ($groups as $key => $group) {
            $first = trim((string) ($source[$group['columns'][0]['raw_key']] ?? ''));
            foreach (array_slice($group['columns'], 1) as $column) {
                if (trim((string) ($source[$column['raw_key']] ?? '')) !== $first) {
                    $differentGroups[$key] = $group;
                    break;
                }
            }
        }

        return $differentGroups;
    }

    private function mapRow(array $headers, array $cells, Worksheet $sheet, int $rowNumber): array
    {
        $canonical = [];
        $raw = [];

        foreach ($headers as $index => $header) {
            $cellValue = $cells[$header['index']] ?? null;
            if (in_array($header['normalized'], ['FECHA DE BAJA', 'FECHA DE ALTA COORPORACION'], true)) {
                $cell = $sheet->getCell($this->columnName($header['index'] + 1).$rowNumber);
                $cellValue = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
            }
            $value = $this->stringValue($cellValue);
            $raw[$header['raw_key']] = $value;
            $destination = self::FIELD_MAP[$header['normalized']] ?? null;

            if ($destination && ! array_key_exists($destination, $canonical)) {
                $canonical[$destination] = $value;
            }
        }

        if (isset($canonical['cuip'])) {
            $canonical['cuip'] = $this->normalizeCuip($canonical['cuip']);
        }

        if (isset($canonical['nombre'])) {
            $canonical['nombre'] = trim($canonical['nombre']);
        }

        return [$canonical, $raw];
    }

    private function describeHeaders(array $headers): array
    {
        $seen = [];
        $result = [];

        foreach ($headers as $index => $value) {
            $original = trim((string) $value);
            $normalized = $this->normalizeHeader($original);
            $normalized = self::HEADER_ALIASES[$normalized] ?? $normalized;
            $seen[$normalized] = ($seen[$normalized] ?? 0) + 1;
            $column = $this->columnName($index + 1);
            $rawKey = $seen[$normalized] > 1 || $original === ''
                ? ($original === '' ? "COLUMNA SIN NOMBRE [{$column}]" : "{$original} [columna {$column}]")
                : $original;

            $result[] = [
                'original' => $original,
                'normalized' => $normalized,
                'raw_key' => $rawKey,
                'index' => $index,
            ];
        }

        return $result;
    }

    private function normalizeHeader(string $value): string
    {
        $value = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', trim(mb_strtoupper($value, 'UTF-8')));
        $value = strtr($value, [
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'Ü' => 'U',
            'Ñ' => 'N',
        ]);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $ascii === false ? $value : $ascii;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function normalizeCuip(string $value): string
    {
        return mb_strtoupper(trim($value), 'UTF-8');
    }

    /**
     * Genera variantes de búsqueda indexable para CUIPs (canónica, espacios externos y minúsculas)
     * permitiendo localizar registros históricos no normalizados sin anular el uso del índice B-Tree en MariaDB.
     *
     * @param list<string> $cuips
     * @return list<string>
     */
    private function cuipLookupVariants(array $cuips): array
    {
        $variants = [];
        foreach ($cuips as $cuip) {
            $norm = $this->normalizeCuip((string) $cuip);
            if ($norm === '') {
                continue;
            }
            $variants[] = $norm;
            $variants[] = ' ' . $norm;
            $variants[] = $norm . ' ';
            $variants[] = ' ' . $norm . ' ';
            $lower = mb_strtolower($norm, 'UTF-8');
            if ($lower !== $norm) {
                $variants[] = $lower;
                $variants[] = ' ' . $lower;
                $variants[] = $lower . ' ';
                $variants[] = ' ' . $lower . ' ';
            }
        }

        return array_values(array_unique($variants));
    }

    private function validateRow(array $fields, array $raw): array
    {
        $errors = [];
        $cuip = trim((string) ($fields['cuip'] ?? ''));
        if ($cuip === '') {
            $errors[] = 'CUIP obligatorio: no se puede identificar de forma segura a la persona.';
        } elseif (mb_strlen($cuip) > 100) {
            $errors[] = 'CUIP excede la capacidad de almacenamiento de 100 caracteres.';
        }

        $name = trim((string) ($fields['nombre'] ?? ''));
        if ($name === '') {
            $errors[] = 'Nombre vacío.';
        } elseif (mb_strlen($name) > 255) {
            $errors[] = 'Nombre excede los 255 caracteres permitidos.';
        }

        $limits = [
            'personal_id' => 60,
            'rfc' => 20,
            'curp' => 20,
            'categoria' => 120,
            'estatus' => 80,
            'subdireccion' => 150,
            'municipio' => 150,
            'region' => 150,
            'agrupamiento' => 150,
            'clave_seguridad' => 50,
            'cuerpo_seguridad' => 80,
        ];

        foreach ($limits as $field => $limit) {
            if (mb_strlen((string) ($fields[$field] ?? '')) > $limit) {
                $errors[] = sprintf('%s excede los %d caracteres permitidos.', $field, $limit);
            }
        }

        foreach (['FECHA DE BAJA', 'FECHA DE ALTA COORPORACION'] as $dateHeader) {
            $value = $this->sourceValue($raw, $dateHeader);
            if (trim($value) !== '' && $this->dateValue($value) === null) {
                $errors[] = "{$dateHeader} no contiene una fecha válida.";
            }
        }

        return $errors;
    }

    private function sourceValue(array $raw, string $header): string
    {
        foreach ($raw as $key => $value) {
            if ($this->normalizeHeader($key) === $header) {
                return (string) $value;
            }
        }

        return '';
    }

    private function rowCounts(LocImportBatch $batch): array
    {
        return LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->selectRaw('accion, COUNT(*) as total')
            ->groupBy('accion')
            ->pluck('total', 'accion')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    private function beginDiagnosticStage(string $stage): void
    {
        $this->finishDiagnosticStage();
        $this->diagnosticStage = $stage;
        $this->activeStageStartedAt = microtime(true);
        $this->activeStageMemory = memory_get_usage(true);
        $this->persistStageStatus();
    }

    private function persistProgressSummary(
        LocImportBatch $batch,
        int $rowsRead,
        int $estimatedRows,
        array $cleaningReport
    ): void {
        $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
        $summary['progreso'] = [
            'filas_leidas' => $rowsRead,
            'filas_estimadas' => $estimatedRows,
        ];
        $summary['limpieza'] = $cleaningReport;
        $summary['diagnostico'] = array_merge($summary['diagnostico'] ?? [], [
            'etapa_actual' => $this->diagnosticStage,
            'location' => $this->diagnosticLocation,
            'stage_metrics' => $this->stageMetrics,
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ]);
        $batch->update([
            'resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    private function finishDiagnosticStage(): void
    {
        if ($this->activeStageStartedAt === null) {
            return;
        }

        $this->stageMetrics[$this->diagnosticStage] = [
            'elapsed_seconds' => round(microtime(true) - $this->activeStageStartedAt, 3),
            'memory_delta_mb' => round((memory_get_usage(true) - $this->activeStageMemory) / 1048576, 1),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
        $this->activeStageStartedAt = null;
        $this->persistStageStatus();
    }

    private function recordTotalPhaseMetrics(LocImportBatch $batch, string $phase, float $startedAt): void
    {
        $elapsedSeconds = round(microtime(true) - $startedAt, 3);
        $freshBatch = $batch->fresh();
        $rows = max(0, (int) $freshBatch->total_leidas);
        $summary = json_decode((string) $freshBatch->resumen_errores, true) ?: [];
        $diagnostic = $summary['diagnostico'] ?? [];
        $diagnostic[$phase.'_total_seconds'] = $elapsedSeconds;
        $diagnostic[$phase.'_rows_per_second'] = $elapsedSeconds > 0
            ? round($rows / $elapsedSeconds, 1)
            : null;
        $diagnostic[$phase.'_peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
        $diagnostic[$phase.'_peak_memory_actual_mb'] = round(memory_get_peak_usage(false) / 1048576, 1);
        $summary['diagnostico'] = $diagnostic;

        try {
            $batch->update([
                'resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $exception) {
            Log::warning('No se pudieron persistir las métricas totales de importación LOC.', [
                'batch_id' => $batch->id,
                'phase' => $phase,
                'exception_type' => $exception::class,
            ]);
        }

        Log::info('Finalizó una fase de importación LOC.', [
            'batch_id' => $batch->id,
            'phase' => $phase,
            'elapsed_seconds' => $elapsedSeconds,
            'rows_processed' => $rows,
            'rows_per_second' => $diagnostic[$phase.'_rows_per_second'],
            'peak_memory_mb' => $diagnostic[$phase.'_peak_memory_mb'],
            'peak_memory_actual_mb' => $diagnostic[$phase.'_peak_memory_actual_mb'],
        ]);
    }

    private function confirmationMetrics(
        LocImportBatch $batch,
        float $startedAt,
        float $fingerprintSeconds,
        float $applicationSeconds
    ): array {
        $elapsedSeconds = round(microtime(true) - $startedAt, 3);
        $rows = max(0, (int) $batch->total_aceptadas);

        return [
            'total_seconds' => $elapsedSeconds,
            'fingerprint_seconds' => round($fingerprintSeconds, 3),
            'application_seconds' => round($applicationSeconds, 3),
            'rows_processed' => $rows,
            'rows_per_second' => $elapsedSeconds > 0 ? round($rows / $elapsedSeconds, 1) : null,
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'peak_memory_actual_mb' => round(memory_get_peak_usage(false) / 1048576, 1),
        ];
    }

    private function persistStageStatus(): void
    {
        if (! $this->diagnosticBatch) {
            return;
        }

        $summary = json_decode((string) $this->diagnosticBatch->resumen_errores, true) ?: [];
        $diagnostic = $summary['diagnostico'] ?? [];
        $diagnostic['etapa_actual'] = $this->diagnosticStage;
        $diagnostic['location'] = $this->diagnosticLocation;
        $diagnostic['stage_metrics'] = $this->stageMetrics;
        $diagnostic['peak_memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
        $summary['diagnostico'] = $diagnostic;

        try {
            $this->diagnosticBatch->update([
                'resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable $exception) {
            Log::warning('No se pudo actualizar el estado diagnóstico de la importación LOC.', [
                'batch_id' => $this->diagnosticBatch->id,
                'stage' => $this->diagnosticStage,
                'exception_type' => $exception::class,
            ]);
        }
    }

    private function diagnosticErrorCode(Throwable $exception, string $stage): string
    {
        if ($exception instanceof QueryException && $stage === 'mapeo_validacion_preparacion_staging') {
            return 'STAGING_DATABASE_WRITE_FAILED';
        }

        if ($this->isMemoryLimitFailure($exception)) {
            return 'PHP_MEMORY_LIMIT_EXCEEDED';
        }

        if ($exception instanceof \JsonException && $exception->getCode() === JSON_ERROR_UTF8) {
            return 'INVALID_UTF8_IN_STAGED_DATA';
        }

        if (str_starts_with($exception->getMessage(), 'Faltan encabezados obligatorios:')) {
            return 'MISSING_REQUIRED_HEADERS';
        }

        if (str_contains($exception->getMessage(), 'no corresponde a un libro .xls válido')) {
            return 'INVALID_XLS_FORMAT';
        }

        return strtoupper($stage).'_FAILED';
    }

    private function diagnosticUserMessage(Throwable $exception, string $stage): string
    {
        $message = $exception->getMessage();
        if ($this->isMemoryLimitFailure($exception)) {
            return 'El análisis superó el límite de memoria del worker. El lote incompleto no puede confirmarse; revisa la incidencia antes de volver a cargar el archivo.';
        }
        if (str_starts_with($message, 'Faltan encabezados obligatorios:')) {
            return $message;
        }
        if (str_contains($message, 'no corresponde a un libro .xls válido')) {
            return 'El contenido no es un archivo .xls legible. Verifica el formato y vuelve a cargarlo.';
        }

        return match ($stage) {
            'identificacion_libro', 'seleccion_hoja_deteccion_encabezados' => 'No fue posible leer la estructura del libro Excel.',
            'limpieza_normalizacion_encabezados' => 'No fue posible normalizar y verificar los encabezados del archivo.',
            'mapeo_validacion_preparacion_staging', 'comparacion_duplicados_staging' => 'No fue posible preparar las filas para la vista previa.',
            'clasificacion_filas' => 'No fue posible clasificar las filas del archivo.',
            'huella_revision' => 'No fue posible asegurar la integridad de la vista previa.',
            default => 'No fue posible analizar el archivo .xls.',
        };
    }

    private function safeTechnicalMessage(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            return $this->safeDatabaseDriverMessage($this->databaseDriverMessage($exception));
        }

        if ($exception instanceof \JsonException) {
            return $exception->getMessage();
        }

        $message = $exception->getMessage();
        if (str_starts_with($message, 'Faltan encabezados obligatorios:')
            || str_contains($message, 'no corresponde a un libro .xls válido')
            || str_contains($message, 'No fue posible abrir')
            || str_contains($message, 'No fue posible leer')
            || str_contains($message, 'No fue posible preparar')) {
            return mb_substr($message, 0, 300);
        }

        return 'Excepción durante el procesamiento; el mensaje original se omitió para evitar registrar valores de celdas.';
    }

    private function queryExceptionDiagnostics(Throwable $exception): array
    {
        if (! $exception instanceof QueryException) {
            return [];
        }

        $errorInfo = $exception->errorInfo ?? [];
        $previous = $exception->getPrevious();
        if (! is_array($errorInfo) && $previous instanceof \PDOException) {
            $errorInfo = $previous->errorInfo ?? [];
        }
        if (! is_array($errorInfo)) {
            $errorInfo = [];
        }

        $sqlState = $errorInfo[0] ?? $exception->getCode();
        $driverCode = $errorInfo[1] ?? null;

        return [
            'database_error' => [
                'sqlstate' => is_scalar($sqlState) ? (string) $sqlState : 'unknown',
                'driver_code' => is_numeric($driverCode) ? (int) $driverCode : null,
                'driver_message' => $this->safeDatabaseDriverMessage($this->databaseDriverMessage($exception)),
            ],
        ];
    }

    private function databaseDriverMessage(QueryException $exception): string
    {
        $errorInfo = $exception->errorInfo ?? [];
        if (is_array($errorInfo) && isset($errorInfo[2]) && is_string($errorInfo[2])) {
            return $errorInfo[2];
        }

        $previous = $exception->getPrevious();

        return $previous instanceof \PDOException ? $previous->getMessage() : '';
    }

    private function safeDatabaseDriverMessage(string $message): string
    {
        $columns = 'import_batch_id|numero_fila|cuip|datos|errores|accion|estado_aplicacion|created_at|updated_at';
        if (preg_match('/data too long for column [`\'"]?('.$columns.')/i', $message, $matches)) {
            return 'Data too long for staging column '.$matches[1].'.';
        }

        if (preg_match('/cannot be null.*column [`\'"]?('.$columns.')/i', $message, $matches)) {
            return 'A required value is missing for staging column '.$matches[1].'.';
        }

        if (stripos($message, 'duplicate entry') !== false
            || stripos($message, 'unique constraint failed: loc_import_staging_rows.import_batch_id') !== false) {
            return 'Duplicate staging row conflicts with the unique constraint (import_batch_id, numero_fila).';
        }

        if (stripos($message, 'invalid json') !== false || stripos($message, 'json text') !== false) {
            return 'The database rejected a JSON value in staging.';
        }

        if (stripos($message, 'max_allowed_packet') !== false
            || stripos($message, 'packet too large') !== false
            || stripos($message, 'too much data for one statement') !== false) {
            return 'The staging insert exceeded the database packet limit.';
        }

        if (stripos($message, 'incorrect string value') !== false) {
            return 'The database rejected a string encoding in a staging value.';
        }

        return 'The database driver rejected a staging write; inspect the SQLSTATE and driver code.';
    }

    private function isMemoryLimitFailure(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'allowed memory size')
            || str_contains($message, 'memory limit exceeded')
            || str_contains($message, 'out of memory');
    }

    private function applicationCounts(LocImportBatch $batch): array
    {
        return LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->whereIn('estado_aplicacion', ['insertado', 'actualizado', 'sin_cambios'])
            ->selectRaw('estado_aplicacion, COUNT(*) as total')
            ->groupBy('estado_aplicacion')
            ->pluck('total', 'estado_aplicacion')
            ->map(static fn ($count): int => (int) $count)
            ->all() + ['insertado' => 0, 'actualizado' => 0, 'sin_cambios' => 0];
    }

    public function reviewFingerprint(LocImportBatch $batch): string
    {
        $context = hash_init('sha256');
        hash_update($context, json_encode([
            $batch->id,
            $batch->tipo_fuente,
            $batch->nombre_archivo,
            $batch->archivo_sha256,
            $batch->usuario_id,
            $batch->fecha_procesamiento?->toISOString(),
            $batch->hoja_procesada,
            $batch->total_columnas,
            $batch->total_leidas,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($context) {
                foreach ($rows as $row) {
                    hash_update($context, json_encode([
                        $row->id,
                        $row->numero_fila,
                        $row->cuip,
                        $row->datos,
                        $row->errores,
                        $row->accion,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                }
            });

        return hash_final($context);
    }

    private function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + ($column % 26)).$name;
            $column = intdiv($column, 26);
        }

        return $name;
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function dateValue(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
                // Try the next recognized date format.
            }
        }

        if (is_numeric($value) && (float) $value > 10000) {
            try {
                return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
