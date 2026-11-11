<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Throwable;

class LocWeaponsXlsChunkReadFilter implements IReadFilter
{
    public function __construct(private int $startRow, private int $endRow) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row === 1 || ($row >= $this->startRow && $row <= $this->endRow);
    }
}

class LocWeaponsXlsImporter
{
    public const SOURCE_TYPE = 'armamento_loc';

    public const ACTION_NUEVO = 'nuevo';
    public const ACTION_SIN_CAMBIOS = 'sin_cambios';
    public const ACTION_ACTUALIZAR = 'actualizar';
    public const ACTION_CONFLICTO = 'conflicto';
    public const ACTION_RECHAZADO = 'rechazado';
    public const ACTION_OMITIDO = 'omitido';

    public const STATUS_APLICACION_PENDIENTE = 'pendiente';
    public const STATUS_APLICACION_INSERTADO = 'insertado';
    public const STATUS_APLICACION_ACTUALIZADO = 'actualizado';
    public const STATUS_APLICACION_SIN_CAMBIOS = 'sin_cambios';
    public const STATUS_APLICACION_OMITIDO = 'omitido';

    private const FIELD_MAP = [
        'ID_ARMAMENTO' => 'id_armamento',
        'TIPO DE ARMA' => 'tipo_arma',
        'CLASE' => 'clase_arma',
        'MARCA' => 'marca',
        'CALIBRE' => 'calibre',
        'MODELO' => 'modelo',
        'MATRICULA' => 'matricula',
        'RFA' => 'rfa',
        'RESGUARDO' => 'resguardo',
        'PROPIEDAD' => 'propiedad',
        'AGRUPAMIENTO' => 'agrupamiento',
        'REGION' => 'region',
        'MUNICIPIO' => 'municipio',
        'STATUS' => 'estatus',
        'SITUACION' => 'situacion',
        'OBSERVACIONES' => 'observaciones',
    ];

    /**
     * Atributos que definen la identidad técnica y física del arma.
     * Si difieren respecto al inventario existente, se considera colisión de identidad técnica (conflicto).
     */
    private const IDENTITY_FIELDS = [
        'tipo_arma',
        'clase_arma',
        'marca',
        'calibre',
        'modelo',
    ];

    /**
     * Atributos operativos y administrativos que pueden actualizarse desde la fuente oficial.
     */
    private const UPDATABLE_FIELDS = [
        'rfa',
        'resguardo',
        'propiedad',
        'agrupamiento',
        'region',
        'municipio',
        'estatus',
        'situacion',
        'observaciones',
    ];

    private const COMPARISON_FIELDS = [
        'id_armamento',
        'tipo_arma',
        'clase_arma',
        'marca',
        'calibre',
        'modelo',
        'rfa',
        'resguardo',
        'propiedad',
        'agrupamiento',
        'region',
        'municipio',
        'estatus',
        'situacion',
        'observaciones',
    ];

    private const FIELD_LIMITS = [
        'id_armamento' => 100,
        'tipo_arma' => 80,
        'clase_arma' => 80,
        'marca' => 120,
        'calibre' => 60,
        'modelo' => 120,
        'matricula' => 60,
        'rfa' => 100,
        'resguardo' => 150,
        'propiedad' => 120,
        'agrupamiento' => 150,
        'region' => 150,
        'municipio' => 150,
        'estatus' => 80,
        'situacion' => 120,
    ];

    public function stage(string $path, LocImportBatch $batch): void
    {
        if ($batch->tipo_fuente !== self::SOURCE_TYPE) {
            throw new \RuntimeException('El lote no corresponde a la fuente de armamento LOC.');
        }

        $useOleReuse = (bool) config('loc_import.weapons_reuse_ole', true);
        if ($useOleReuse) {
            $scopeToken = 'batch_'.$batch->id.'_'.($batch->archivo_sha256 ?? (string) Str::uuid());
            LocWeaponsOleReuseReader::beginScope($scopeToken, $batch->archivo_sha256);
        }

        $startedAt = microtime(true);
        $metrics = [
            'blocks' => [],
            'classification_seconds' => 0.0,
            'review_fingerprint_seconds' => 0.0,
        ];
        $book = null;
        $sheet = null;

        try {
            $workbookInfo = $this->createReader()->listWorksheetInfo($path);
            $worksheetInfo = $workbookInfo[0] ?? null;
            if (! $worksheetInfo || (int) $worksheetInfo['totalRows'] < 2) {
                throw new \RuntimeException('El libro de armamento no contiene una hoja con filas de datos.');
            }

            $worksheetName = (string) $worksheetInfo['worksheetName'];
            $lastColumn = (string) $worksheetInfo['lastColumnLetter'];
            $headerReader = $this->createReader();
            $headerReader->setReadFilter(new LocWeaponsXlsChunkReadFilter(1, 1));
            $headerReader->setLoadSheetsOnly([$worksheetName]);
            $book = $headerReader->load($path);
            $sheet = $book->getSheetByName($worksheetName);
            if (! $sheet) {
                throw new \RuntimeException('No fue posible leer la hoja de armamento.');
            }

            $rawHeaders = $sheet->rangeToArray("A1:{$lastColumn}1", null, true, true)[0] ?? [];
            $headers = $this->describeHeaders($rawHeaders);
            $matriculaHeaders = array_filter($headers, static fn (array $header): bool => $header['normalized'] === 'MATRICULA');
            if (count($matriculaHeaders) !== 1) {
                throw new \RuntimeException('El libro debe incluir un único encabezado MATRÍCULA para identificar cada arma.');
            }
            $book->disconnectWorksheets();
            unset($book, $sheet, $headerReader);
            gc_collect_cycles();

            $readChunkSize = max(1, min(5000, (int) config('loc_import.weapons_read_chunk_size', 5000)));
            $writeChunkSize = max(1, min(250, (int) config('loc_import.weapons_write_chunk_size', 100)));
            $totalRows = (int) $worksheetInfo['totalRows'];
            $totalColumns = (int) $worksheetInfo['totalColumns'];
            $totalRead = 0;
            $blocksCompleted = 0;
            $maxPeakBytes = memory_get_peak_usage(true);

            $batch->update([
                'total_leidas' => 0,
                'total_aceptadas' => 0,
                'total_rechazadas' => 0,
                'total_actualizadas' => 0,
                'hoja_procesada' => $worksheetName,
                'total_columnas' => $totalColumns,
            ]);
            $this->persistProgress($batch, $totalRead, $totalRows - 1, $blocksCompleted, $metrics);

            for ($start = 2; $start <= $totalRows; $start += $readChunkSize) {
                memory_reset_peak_usage();
                $end = min($start + $readChunkSize - 1, $totalRows);
                $blockNumber = $blocksCompleted + 1;
                $blockStartedAt = microtime(true);
                $memoryStart = memory_get_usage(true);
                $rowsBeforeBlock = $totalRead;
                $openSeconds = 0.0;
                $readSeconds = 0.0;
                $mappingSeconds = 0.0;
                $comparisonSeconds = 0.0;
                $writeSeconds = 0.0;
                $reader = null;
                $book = null;
                $sheet = null;
                $matrix = [];
                $buffer = [];
                $blockPhase = 'apertura';

                try {
                    $stageStartedAt = microtime(true);
                    $reader = $this->createReader();
                    $reader->setReadFilter(new LocWeaponsXlsChunkReadFilter($start, $end));
                    $reader->setLoadSheetsOnly([$worksheetName]);
                    $book = $reader->load($path);
                    $sheet = $book->getSheetByName($worksheetName);
                    $openSeconds = microtime(true) - $stageStartedAt;
                    if (! $sheet) {
                        throw new \RuntimeException('No fue posible abrir la hoja durante la lectura por bloques.');
                    }

                    $blockPhase = 'lectura';
                    $stageStartedAt = microtime(true);
                    $matrix = $sheet->rangeToArray("A{$start}:{$lastColumn}{$end}", null, true, true);
                    $readSeconds = microtime(true) - $stageStartedAt;

                    $blockPhase = 'mapeo_validacion';
                    $stageStartedAt = microtime(true);
                    foreach ($matrix as $offset => $cells) {
                        if ($this->isEmptyRow($cells)) {
                            continue;
                        }

                        $rowNumber = $start + $offset;
                        $totalRead++;
                        [$fields, $source] = $this->mapRow($headers, $cells);
                        $errors = $this->validateRow($fields);
                        $buffer[] = [
                            'row_number' => $rowNumber,
                            'fields' => $fields,
                            'source' => $source,
                            'errors' => $errors,
                        ];

                        if (count($buffer) >= $writeChunkSize) {
                            $mappingSeconds += microtime(true) - $stageStartedAt;
                            $blockPhase = 'clasificacion_existentes';
                            $flushStartedAt = microtime(true);
                            $flushComparisonSeconds = $this->classifyAndWriteBuffer($buffer, $batch);
                            $comparisonSeconds += $flushComparisonSeconds;
                            $writeSeconds += max(0, microtime(true) - $flushStartedAt - $flushComparisonSeconds);
                            $buffer = [];
                            $blockPhase = 'mapeo_validacion';
                            $stageStartedAt = microtime(true);
                        }
                    }
                    $mappingSeconds += microtime(true) - $stageStartedAt;

                    if ($buffer) {
                        $blockPhase = 'clasificacion_existentes';
                        $flushStartedAt = microtime(true);
                        $flushComparisonSeconds = $this->classifyAndWriteBuffer($buffer, $batch);
                        $comparisonSeconds += $flushComparisonSeconds;
                        $writeSeconds += max(0, microtime(true) - $flushStartedAt - $flushComparisonSeconds);
                    }

                    $blocksCompleted++;
                    $blockSeconds = microtime(true) - $blockStartedAt;
                    $blockPeakBytes = memory_get_peak_usage(true);
                    $maxPeakBytes = max($maxPeakBytes, $blockPeakBytes);
                    $blockMetrics = [
                        'block' => $blockNumber,
                        'row_start' => $start,
                        'row_end' => $end,
                        'rows_processed' => $totalRead,
                        'rows_in_block' => $totalRead - $rowsBeforeBlock,
                        'open_seconds' => round($openSeconds, 3),
                        'read_seconds' => round($readSeconds, 3),
                        'map_validate_seconds' => round($mappingSeconds, 3),
                        'existing_compare_seconds' => round($comparisonSeconds, 3),
                        'staging_write_seconds' => round($writeSeconds, 3),
                        'block_seconds' => round($blockSeconds, 3),
                        'memory_start_mb' => round($memoryStart / 1048576, 1),
                        'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
                        'peak_memory_mb' => round($blockPeakBytes / 1048576, 1),
                    ];
                    $metrics['blocks'][] = $blockMetrics;
                    Log::info('Bloque de importación de armamento LOC completado.', [
                        'batch_id' => $batch->id,
                        ...$blockMetrics,
                    ]);
                    $blockPhase = 'progreso';
                    $this->persistProgress($batch, $totalRead, $totalRows - 1, $blocksCompleted, $metrics, [
                        'stage' => 'bloque_completado',
                        'block' => $blockNumber,
                        'row_start' => $start,
                        'row_end' => $end,
                    ]);
                } catch (Throwable $exception) {
                    Log::error('Falló un bloque del análisis de armamento LOC.', [
                        'batch_id' => $batch->id,
                        'block' => $blockNumber,
                        'row_start' => $start,
                        'row_end' => $end,
                        'block_phase' => $blockPhase,
                        'exception_type' => $exception::class,
                        'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
                        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                    ]);
                    try {
                        $this->persistProgress($batch, $totalRead, $totalRows - 1, $blocksCompleted, $metrics, [
                            'stage' => 'fallido_'.$blockPhase,
                            'block' => $blockNumber,
                            'row_start' => $start,
                            'row_end' => $end,
                        ]);
                    } catch (Throwable $progressException) {
                        Log::error('No fue posible guardar el diagnóstico del bloque fallido de armamento LOC.', [
                            'batch_id' => $batch->id,
                            'block' => $blockNumber,
                            'exception_type' => $progressException::class,
                        ]);
                    }
                    throw $exception;
                } finally {
                    if (isset($book) && $book) {
                        $book->disconnectWorksheets();
                    }
                    unset($matrix, $buffer, $sheet, $book, $reader);
                    gc_collect_cycles();
                }
            }

            $stageStartedAt = microtime(true);
            $this->markDuplicateRows($batch);
            $metrics['classification_seconds'] = round(microtime(true) - $stageStartedAt, 3);

            $counts = $this->rowCounts($batch);
            $rejected = ($counts[self::ACTION_RECHAZADO] ?? 0) + ($counts[self::ACTION_CONFLICTO] ?? 0);
            $accepted = ($counts[self::ACTION_NUEVO] ?? 0)
                + ($counts[self::ACTION_ACTUALIZAR] ?? 0)
                + ($counts[self::ACTION_SIN_CAMBIOS] ?? 0)
                + ($counts[self::ACTION_OMITIDO] ?? 0);

            $metrics['peak_memory_mb'] = round(max($maxPeakBytes, memory_get_peak_usage(true)) / 1048576, 1);
            $metrics['read_chunk_size'] = $readChunkSize;
            $metrics['write_chunk_size'] = $writeChunkSize;

            $batch->forceFill([
                'total_leidas' => $totalRead,
                'total_aceptadas' => $accepted,
                'total_rechazadas' => $rejected,
                'total_nuevas' => (int) ($counts[self::ACTION_NUEVO] ?? 0),
                'total_actualizadas' => (int) ($counts[self::ACTION_ACTUALIZAR] ?? 0),
                'total_sin_cambios' => (int) ($counts[self::ACTION_SIN_CAMBIOS] ?? 0),
                'hoja_procesada' => $worksheetName,
                'total_columnas' => $totalColumns,
            ]);

            $fingerprintStartedAt = microtime(true);
            $fingerprint = $this->reviewFingerprint($batch);
            $metrics['review_fingerprint_seconds'] = round(microtime(true) - $fingerprintStartedAt, 3);
            $elapsed = microtime(true) - $startedAt;
            $metrics['total_seconds'] = round($elapsed, 3);
            $metrics['rows_per_second'] = $elapsed > 0 ? round($totalRead / $elapsed, 2) : 0;
            $summary = [
                'progreso' => [
                    'filas_leidas' => $totalRead,
                    'filas_estimadas' => $totalRows - 1,
                    'bloques_completados' => $blocksCompleted,
                ],
                'clasificacion' => $counts,
                'metricas_revision' => $metrics,
            ];

            DB::transaction(function () use ($batch, $summary, $totalRead, $accepted, $rejected, $counts, $worksheetName, $totalColumns, $fingerprint): void {
                $lockedBatch = LocImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
                $currentSummary = json_decode((string) $lockedBatch->resumen_errores, true) ?: [];
                if (is_array($currentSummary['reanalyze'] ?? null)) {
                    $summary['reanalyze'] = $currentSummary['reanalyze'];
                }
                if (is_array($currentSummary['filas_omitidas'] ?? null)) {
                    $summary['filas_omitidas'] = $currentSummary['filas_omitidas'];
                }

                $lockedBatch->update([
                    'estado' => 'en_revision',
                    'total_leidas' => $totalRead,
                    'total_aceptadas' => $accepted,
                    'total_rechazadas' => $rejected,
                    'total_actualizadas' => (int) ($counts[self::ACTION_ACTUALIZAR] ?? 0),
                    'total_nuevas' => (int) ($counts[self::ACTION_NUEVO] ?? 0),
                    'total_sin_cambios' => (int) ($counts[self::ACTION_SIN_CAMBIOS] ?? 0),
                    'hoja_procesada' => $worksheetName,
                    'total_columnas' => $totalColumns,
                    'revision_sha256' => $fingerprint,
                    'resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            });

            Log::info('Revisión de armamento LOC completada.', [
                'batch_id' => $batch->id,
                'rows_read' => $totalRead,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'updated' => (int) ($counts[self::ACTION_ACTUALIZAR] ?? 0),
                'total_seconds' => $metrics['total_seconds'],
                'rows_per_second' => $metrics['rows_per_second'],
                'peak_memory_mb' => $metrics['peak_memory_mb'],
            ]);
        } finally {
            if (isset($book) && $book) {
                $book->disconnectWorksheets();
            }
            unset($book, $sheet);
            if ($useOleReuse) {
                LocWeaponsOleReuseReader::endScope();
            }
            gc_collect_cycles();
        }
    }

    public function commit(LocImportBatch $batch, int $userId): array
    {
        $startedAt = microtime(true);

        return DB::transaction(function () use ($batch, $userId, $startedAt): array {
            $lockedBatch = LocImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($lockedBatch->tipo_fuente !== self::SOURCE_TYPE || $lockedBatch->estado !== 'en_revision') {
                throw new \RuntimeException('El lote no está disponible para confirmación.');
            }
            if ((int) $lockedBatch->total_rechazadas > 0) {
                throw new \RuntimeException('El lote contiene filas rechazadas o en conflicto. Corrige el archivo y vuelve a analizarlo; no se aplicó ningún cambio.');
            }
            if (! $lockedBatch->revision_sha256
                || ! hash_equals($lockedBatch->revision_sha256, $this->reviewFingerprint($lockedBatch))) {
                throw new \RuntimeException('La revisión del lote cambió después del análisis. Vuelve a analizar el archivo antes de confirmar.');
            }

            $rowCounts = $this->rowCounts($lockedBatch);
            if (($rowCounts[self::ACTION_RECHAZADO] ?? 0) > 0 || ($rowCounts[self::ACTION_CONFLICTO] ?? 0) > 0) {
                throw new \RuntimeException('El lote contiene filas rechazadas o en conflicto. No se aplicó ningún cambio.');
            }

            $writeChunkSize = max(1, min(250, (int) config('loc_import.weapons_write_chunk_size', 100)));
            $revalidationChunkSize = max(1, min(2000, (int) config('loc_import.weapons_revalidation_chunk_size', 1000)));
            $inserted = 0;
            $updated = 0;
            $unchanged = (int) ($rowCounts[self::ACTION_SIN_CAMBIOS] ?? 0);
            $insertStartedAt = microtime(true);

            // 1. Revalidación estricta de filas clasificadas sin cambios
            LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', self::ACTION_SIN_CAMBIOS)
                ->orderBy('id')
                ->chunkById($revalidationChunkSize, function ($rows): void {
                    $matriculas = $rows->pluck('matricula')->filter()->all();
                    $externalIds = $rows->whereNull('matricula')->pluck('id_armamento')->filter()->all();

                    $columns = array_merge(['id', 'matricula', 'id_armamento'], self::COMPARISON_FIELDS);
                    $existing = collect();
                    if ($matriculas || $externalIds) {
                        $existing = DB::table('loc_armas')
                            ->where(function ($q) use ($matriculas, $externalIds): void {
                                if ($matriculas) {
                                    $q->whereIn('matricula', array_values(array_unique($matriculas)));
                                }
                                if ($externalIds) {
                                    $method = $matriculas ? 'orWhereIn' : 'whereIn';
                                    $q->{$method}('id_armamento', array_values(array_unique($externalIds)));
                                }
                            })
                            ->get(array_values(array_unique($columns)));
                    }

                    $existingByMatricula = $existing->whereNotNull('matricula')->keyBy('matricula');
                    $existingByExternalId = $existing->whereNotNull('id_armamento')->groupBy('id_armamento');

                    foreach ($rows as $row) {
                        $data = is_array($row->datos) ? $row->datos : [];
                        $fields = is_array($data['campos'] ?? null) ? $data['campos'] : [];
                        $record = null;

                        if ($row->matricula !== null) {
                            $record = $existingByMatricula->get($row->matricula);
                        } elseif ($row->id_armamento !== null) {
                            $owners = $existingByExternalId->get($row->id_armamento);
                            if ($owners && $owners->count() === 1 && $owners->first()->matricula === null) {
                                $record = $owners->first();
                            }
                        }

                        if (! $record || ! $this->sameRecordState($record, $fields)) {
                            throw new \RuntimeException("El registro de armamento (Fila {$row->numero_fila}) clasificado sin cambios fue modificado o eliminado después de la revisión. Vuelve a analizar el archivo.");
                        }
                    }
                });

            // 2. Revalidación y aplicación de actualizaciones oficiales
            LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', self::ACTION_ACTUALIZAR)
                ->orderBy('id')
                ->chunkById($writeChunkSize, function ($rows) use ($lockedBatch, $userId, &$updated): void {
                    $matriculas = $rows->pluck('matricula')->filter()->all();
                    $externalIds = $rows->whereNull('matricula')->pluck('id_armamento')->filter()->all();

                    $columns = array_merge(['id', 'matricula', 'id_armamento', 'datos_origen'], self::COMPARISON_FIELDS);
                    $existing = collect();
                    if ($matriculas || $externalIds) {
                        $existing = DB::table('loc_armas')
                            ->where(function ($q) use ($matriculas, $externalIds): void {
                                if ($matriculas) {
                                    $q->whereIn('matricula', array_values(array_unique($matriculas)));
                                }
                                if ($externalIds) {
                                    $method = $matriculas ? 'orWhereIn' : 'whereIn';
                                    $q->{$method}('id_armamento', array_values(array_unique($externalIds)));
                                }
                            })
                            ->get(array_values(array_unique($columns)));
                    }

                    $existingByMatricula = $existing->whereNotNull('matricula')->keyBy('matricula');
                    $existingByExternalId = $existing->whereNotNull('id_armamento')->groupBy('id_armamento');

                    $timestamp = now();
                    foreach ($rows as $row) {
                        $data = is_array($row->datos) ? $row->datos : [];
                        $fields = is_array($data['campos'] ?? null) ? $data['campos'] : [];
                        $source = is_array($data['origen'] ?? null) ? $data['origen'] : [];
                        $camposAnteriores = is_array($data['campos_anteriores'] ?? null) ? $data['campos_anteriores'] : [];
                        $record = null;

                        if ($row->matricula !== null) {
                            $record = $existingByMatricula->get($row->matricula);
                        } elseif ($row->id_armamento !== null) {
                            $owners = $existingByExternalId->get($row->id_armamento);
                            if ($owners && $owners->count() === 1 && $owners->first()->matricula === null) {
                                $record = $owners->first();
                            }
                        }

                        if (! $record) {
                            throw new \RuntimeException("El arma a actualizar (Fila {$row->numero_fila}) no se encontró en el inventario. Vuelve a analizar el archivo.");
                        }

                        // Revalidar identidad técnica
                        foreach (self::IDENTITY_FIELDS as $field) {
                            $normDb = $record->{$field} !== null ? trim((string) $record->{$field}) : null;
                            $normStag = ($fields[$field] ?? '') !== '' ? trim((string) $fields[$field]) : null;
                            if ($normDb !== $normStag) {
                                throw new \RuntimeException("La identidad técnica del arma (Fila {$row->numero_fila}) cambió concurrentemente en el inventario. Vuelve a analizar el archivo.");
                            }
                        }

                        // Revalidar que los campos anteriores coincidan con el estado de revisión
                        foreach ($camposAnteriores as $field => $oldVal) {
                            $normDb = $record->{$field} !== null ? trim((string) $record->{$field}) : null;
                            $normOld = $oldVal !== null ? trim((string) $oldVal) : null;
                            if ($normDb !== $normOld) {
                                throw new \RuntimeException("El arma (Fila {$row->numero_fila}) clasificada para actualizar fue modificada concurrentemente después de la revisión. Vuelve a analizar el archivo.");
                            }
                        }

                        $updateData = [];
                        foreach (self::UPDATABLE_FIELDS as $field) {
                            $val = $fields[$field] ?? '';
                            $updateData[$field] = $val === '' ? null : $val;
                        }

                        if ($row->id_armamento !== null && ($record->id_armamento === null || $record->id_armamento === '')) {
                            $updateData['id_armamento'] = $row->id_armamento;
                        }

                        // Trazabilidad histórica de auditoría en datos_origen
                        $existingOrigen = is_array($record->datos_origen)
                            ? $record->datos_origen
                            : (json_decode((string) ($record->datos_origen ?? ''), true) ?: []);

                        $historial = $existingOrigen['_historial_actualizaciones'] ?? [];
                        if (! is_array($historial)) {
                            $historial = [];
                        }

                        $historial[] = [
                            'import_batch_id' => $lockedBatch->id,
                            'usuario_id' => $userId,
                            'fecha' => $timestamp->toIso8601String(),
                            'numero_fila' => $row->numero_fila,
                            'valores_anteriores' => $camposAnteriores,
                            'valores_nuevos' => array_intersect_key($updateData, $data['diferencias'] ?? []),
                            'diferencias' => array_keys($data['diferencias'] ?? []),
                        ];

                        $newOrigen = $source;
                        $newOrigen['_historial_actualizaciones'] = $historial;
                        $updateData['datos_origen'] = json_encode($newOrigen, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        $updateData['import_batch_id'] = $lockedBatch->id;
                        $updateData['updated_at'] = $timestamp;

                        DB::table('loc_armas')->where('id', $record->id)->update($updateData);
                    }

                    DB::table('loc_import_staging_rows')
                        ->whereIn('id', $rows->pluck('id'))
                        ->update([
                            'estado_aplicacion' => self::STATUS_APLICACION_ACTUALIZADO,
                            'updated_at' => $timestamp,
                        ]);

                    $updated += count($rows);
                });

            // 3. Inserción de armas nuevas
            LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', self::ACTION_NUEVO)
                ->orderBy('id')
                ->chunkById($writeChunkSize, function ($rows) use ($lockedBatch, &$inserted): void {
                    $matriculas = $rows->pluck('matricula')
                        ->filter(static fn ($value): bool => $value !== null && $value !== '')
                        ->all();
                    $externalIds = $rows->pluck('id_armamento')
                        ->filter(static fn ($value): bool => $value !== null && $value !== '')
                        ->all();

                    if (DB::table('loc_armas')->whereIn('matricula', $matriculas)->exists()) {
                        throw new \RuntimeException('Una matrícula del lote ya existe en el inventario. No se sobrescribió ningún registro; vuelve a analizar el archivo.');
                    }
                    if ($externalIds) {
                        if (DB::table('loc_armas')->whereIn('id_armamento', $externalIds)->exists()) {
                            throw new \RuntimeException('Un ID_ARMAMENTO del lote ya existe en el inventario. No se sobrescribió ningún registro; vuelve a analizar el archivo.');
                        }
                    }

                    $timestamp = now();
                    $records = [];
                    foreach ($rows as $row) {
                        $data = is_array($row->datos) ? $row->datos : [];
                        $fields = is_array($data['campos'] ?? null) ? $data['campos'] : [];
                        $source = is_array($data['origen'] ?? null) ? $data['origen'] : [];
                        $record = [
                            'import_batch_id' => $lockedBatch->id,
                            'matricula' => $row->matricula,
                            'id_armamento' => $row->id_armamento,
                            'datos_origen' => json_encode($source, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                        foreach (self::FIELD_MAP as $field) {
                            if ($field === 'id_armamento' || $field === 'matricula') {
                                continue;
                            }
                            $record[$field] = ($fields[$field] ?? '') === '' ? null : $fields[$field];
                        }
                        $records[] = $record;
                    }

                    DB::table('loc_armas')->insert($records);
                    DB::table('loc_import_staging_rows')
                        ->whereIn('id', $rows->pluck('id'))
                        ->update(['estado_aplicacion' => self::STATUS_APLICACION_INSERTADO, 'updated_at' => $timestamp]);
                    $inserted += count($records);
                });

            // 4. Marcar filas sin cambios
            LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', self::ACTION_SIN_CAMBIOS)
                ->update(['estado_aplicacion' => self::STATUS_APLICACION_SIN_CAMBIOS, 'updated_at' => now()]);

            // 5. Marcar filas formalmente omitidas
            LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', self::ACTION_OMITIDO)
                ->update(['estado_aplicacion' => self::STATUS_APLICACION_OMITIDO, 'updated_at' => now()]);

            // 6. Validaciones post-confirmación e integridad estricta
            $remainingPending = LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('estado_aplicacion', self::STATUS_APLICACION_PENDIENTE)
                ->count();

            if ($remainingPending > 0) {
                throw new \RuntimeException("La confirmación quedó incompleta: existen {$remainingPending} filas con estado_aplicacion 'pendiente'. No se marcó el lote como completado.");
            }

            // Verificar que ninguna fila formalmente omitida haya sido insertada en loc_armas
            $omittedMatriculas = LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', self::ACTION_OMITIDO)
                ->whereNotNull('matricula')
                ->pluck('matricula')
                ->all();

            if (! empty($omittedMatriculas)) {
                $omittedInDb = DB::table('loc_armas')
                    ->where('import_batch_id', $lockedBatch->id)
                    ->whereIn('matricula', $omittedMatriculas)
                    ->count();

                if ($omittedInDb > 0) {
                    throw new \RuntimeException("Violación de integridad: se detectaron {$omittedInDb} registros omitidos insertados en el inventario.");
                }
            }

            $actualStagingInserted = LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', self::ACTION_NUEVO)
                ->where('estado_aplicacion', self::STATUS_APLICACION_INSERTADO)
                ->count();

            if ($actualStagingInserted !== $inserted) {
                throw new \RuntimeException("Discrepancia en confirmación: staging reporta {$actualStagingInserted} filas insertadas pero se registraron {$inserted}.");
            }

            $actualStagingUpdated = LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', self::ACTION_ACTUALIZAR)
                ->where('estado_aplicacion', self::STATUS_APLICACION_ACTUALIZADO)
                ->count();

            if ($actualStagingUpdated !== $updated) {
                throw new \RuntimeException("Discrepancia en confirmación: staging reporta {$actualStagingUpdated} filas actualizadas pero se registraron {$updated}.");
            }

            $summary = json_decode((string) $lockedBatch->resumen_errores, true) ?: [];
            $confirmationMetrics = [
                'total_seconds' => round(microtime(true) - $startedAt, 3),
                'staging_insert_seconds' => round(microtime(true) - $insertStartedAt, 3),
                'rows_inserted' => $inserted,
                'rows_updated' => $updated,
                'rows_unchanged' => $unchanged,
                'rows_omitted' => (int) ($rowCounts[self::ACTION_OMITIDO] ?? 0),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ];
            $summary['metricas_confirmacion'] = $confirmationMetrics;

            $lockedBatch->update([
                'estado' => 'completado',
                'total_insertadas' => $inserted,
                'total_actualizadas' => $updated,
                'total_sin_cambios' => $unchanged,
                'fecha_confirmacion' => now(),
                'resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);

            Log::info('Confirmación de armamento LOC completada.', [
                'batch_id' => $lockedBatch->id,
                ...$confirmationMetrics,
            ]);

            $result = [
                'inserted' => $inserted,
                'unchanged' => $unchanged,
                'rejected' => 0,
            ];
            if ($updated > 0) {
                $result['updated'] = $updated;
            }

            return $result;
        }, 3);
    }

    public function omitStagingRow(LocImportStagingRow $row, string $motivo, ?int $usuarioId = null): void
    {
        DB::transaction(function () use ($row, $motivo, $usuarioId): void {
            $lockedRow = LocImportStagingRow::query()->where('id', $row->id)->lockForUpdate()->firstOrFail();
            $batch = LocImportBatch::query()->where('id', $lockedRow->import_batch_id)->lockForUpdate()->firstOrFail();

            if ($batch->tipo_fuente !== self::SOURCE_TYPE) {
                throw new \RuntimeException('El lote no corresponde a armamento LOC.');
            }

            if (! in_array($batch->estado, ['en_revision', 'pendiente'], true)) {
                throw new \RuntimeException('Solo se pueden omitir filas de un lote en revisión o pendiente.');
            }

            if ($lockedRow->estado_aplicacion !== self::STATUS_APLICACION_PENDIENTE) {
                throw new \RuntimeException('No se puede omitir una fila que ya fue aplicada.');
            }

            if ($lockedRow->accion === self::ACTION_NUEVO) {
                throw new \RuntimeException('No se permite omitir un alta nueva legítima sin evaluación técnica previa.');
            }

            if (trim($motivo) === '') {
                throw new \RuntimeException('El motivo de omisión es obligatorio y no puede estar vacío.');
            }

            $datos = is_array($lockedRow->datos) ? $lockedRow->datos : json_decode((string) $lockedRow->datos, true);
            $accionPrevia = $lockedRow->accion;

            $datos['exclusion'] = [
                'motivo' => trim($motivo),
                'accion_previa' => $accionPrevia,
                'fecha' => now()->toIso8601String(),
                'usuario_id' => $usuarioId,
            ];

            $lockedRow->update([
                'accion' => self::ACTION_OMITIDO,
                'datos' => $datos,
                'updated_at' => now(),
            ]);

            $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
            $summary['filas_omitidas'] = $summary['filas_omitidas'] ?? [];
            $summary['filas_omitidas'][] = [
                'numero_fila' => $lockedRow->numero_fila,
                'motivo' => trim($motivo),
                'usuario_id' => $usuarioId,
                'fecha' => now()->toIso8601String(),
            ];

            $stagingCounts = LocImportStagingRow::query()
                ->where('import_batch_id', $batch->id)
                ->selectRaw('accion, COUNT(*) as total')
                ->groupBy('accion')
                ->pluck('total', 'accion')
                ->map(static fn ($c): int => (int) $c)
                ->all();

            $batch->total_rechazadas = ($stagingCounts[self::ACTION_CONFLICTO] ?? 0) + ($stagingCounts[self::ACTION_RECHAZADO] ?? 0);
            $batch->total_actualizadas = (int) ($stagingCounts[self::ACTION_ACTUALIZAR] ?? 0);
            $batch->total_nuevas = (int) ($stagingCounts[self::ACTION_NUEVO] ?? 0);
            $batch->total_sin_cambios = (int) ($stagingCounts[self::ACTION_SIN_CAMBIOS] ?? 0);
            $summary['clasificacion'] = $stagingCounts;
            $batch->resumen_errores = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $batch->revision_sha256 = $this->reviewFingerprint($batch);
            $batch->save();
        });
    }

    private function classifyAndWriteBuffer(array $buffer, LocImportBatch $batch): float
    {
        $matriculas = [];
        $externalIds = [];
        foreach ($buffer as $row) {
            if ($row['errors']) {
                continue;
            }
            if ($row['fields']['matricula'] !== null) {
                $matriculas[] = $row['fields']['matricula'];
            }
            if (($row['fields']['id_armamento'] ?? '') !== '') {
                $externalIds[] = $row['fields']['id_armamento'];
            }
        }

        $comparisonStartedAt = microtime(true);
        $existingByMatricula = collect();
        $existingByExternalId = collect();
        if ($matriculas || $externalIds) {
            $columns = array_merge(['id', 'matricula', 'id_armamento', 'datos_origen'], self::COMPARISON_FIELDS);
            $existingQuery = DB::table('loc_armas')
                ->where(function ($query) use ($matriculas, $externalIds): void {
                    if ($matriculas) {
                        $query->whereIn('matricula', array_values(array_unique($matriculas)));
                    }

                    if ($externalIds) {
                        $method = $matriculas ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('id_armamento', array_values(array_unique($externalIds)));
                    }
                });
            $existing = $existingQuery->get(array_values(array_unique($columns)));
            $existingByMatricula = $existing->whereNotNull('matricula')->keyBy('matricula');
            $existingByExternalId = $existing->whereNotNull('id_armamento')->groupBy('id_armamento');
        }
        $comparisonSeconds = microtime(true) - $comparisonStartedAt;

        $timestamp = now();
        $staging = [];
        foreach ($buffer as $row) {
            $fields = $row['fields'];
            $errors = $row['errors'];
            $action = $errors ? self::ACTION_RECHAZADO : self::ACTION_NUEVO;
            $updatableDiffs = [];
            $camposAnteriores = [];
            $matchedExisting = null;

            if (! $errors) {
                $matricula = $fields['matricula'];
                $externalId = (string) ($fields['id_armamento'] ?? '');

                if ($matricula !== null) {
                    $existing = $existingByMatricula->get($matricula);
                    $externalIdConflict = false;

                    if ($externalId !== '') {
                        $externalOwners = $existingByExternalId->get($externalId);
                        if ($externalOwners && $externalOwners->isNotEmpty()) {
                            // Conflicto si este externalId le pertenece a OTRA arma que tiene una matrícula diferente
                            $externalIdConflict = $externalOwners->contains(
                                static fn (object $owner): bool => $owner->matricula !== null && $owner->matricula !== $matricula
                            );
                        }
                    }

                    if ($externalIdConflict) {
                        $action = self::ACTION_CONFLICTO;
                        $errors[] = 'El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.';
                    } elseif ($existing) {
                        $matchedExisting = $existing;
                        // 1. Verificar identidad técnica física
                        $identityDiffs = $this->compareFieldList($existing, $fields, self::IDENTITY_FIELDS);
                        if (! empty($identityDiffs)) {
                            $action = self::ACTION_CONFLICTO;
                            $errors[] = 'La matrícula ya existe con información distinta. No se actualizará automáticamente.';
                        } else {
                            // 2. Verificar coherencia de ID_ARMAMENTO
                            $currentExtId = (string) ($existing->id_armamento ?? '');
                            if ($currentExtId !== '' && $externalId !== '' && $currentExtId !== $externalId) {
                                $action = self::ACTION_CONFLICTO;
                                $errors[] = 'El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.';
                            } else {
                                // 3. Evaluar atributos operativos actualizables
                                $updatableDiffs = $this->compareFieldList($existing, $fields, self::UPDATABLE_FIELDS);
                                if ($currentExtId === '' && $externalId !== '') {
                                    $updatableDiffs['id_armamento'] = [
                                        'anterior' => null,
                                        'nuevo' => $externalId,
                                    ];
                                }

                                if (! empty($updatableDiffs)) {
                                    $action = self::ACTION_ACTUALIZAR;
                                    $camposAnteriores = $this->extractFieldValues($existing, array_keys($updatableDiffs));
                                } else {
                                    $action = self::ACTION_SIN_CAMBIOS;
                                }
                            }
                        }
                    } else {
                        // Matrícula no está en BD. Si el ID_ARMAMENTO ya existe, es conflicto
                        if ($externalId !== '') {
                            $externalOwners = $existingByExternalId->get($externalId);
                            if ($externalOwners && $externalOwners->isNotEmpty()) {
                                $action = self::ACTION_CONFLICTO;
                                $errors[] = 'El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.';
                            }
                        }
                    }
                } else {
                    // Caso matrícula === null (ej. S/M)
                    if ($externalId === '') {
                        $action = self::ACTION_RECHAZADO;
                        $errors[] = 'La matrícula no disponible requiere ID_ARMAMENTO para identificar el arma.';
                    } else {
                        $externalOwners = $existingByExternalId->get($externalId);
                        if (! $externalOwners || $externalOwners->isEmpty()) {
                            $action = self::ACTION_NUEVO;
                        } elseif ($externalOwners->count() > 1) {
                            $action = self::ACTION_CONFLICTO;
                            $errors[] = 'El ID_ARMAMENTO se repite dentro del lote; requiere revisión.';
                        } else {
                            $owner = $externalOwners->first();
                            if ($owner->matricula !== null) {
                                $action = self::ACTION_CONFLICTO;
                                $errors[] = 'El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.';
                            } else {
                                // Matrícula null en ambos y coincidencia exacta de ID_ARMAMENTO
                                $matchedExisting = $owner;
                                $identityDiffs = $this->compareFieldList($owner, $fields, self::IDENTITY_FIELDS);
                                if (! empty($identityDiffs)) {
                                    $action = self::ACTION_CONFLICTO;
                                    $errors[] = 'El ID_ARMAMENTO ya existe en el inventario; requiere revisión. No se aplicará automáticamente.';
                                } else {
                                    $updatableDiffs = $this->compareFieldList($owner, $fields, self::UPDATABLE_FIELDS);
                                    if (! empty($updatableDiffs)) {
                                        $action = self::ACTION_ACTUALIZAR;
                                        $camposAnteriores = $this->extractFieldValues($owner, array_keys($updatableDiffs));
                                    } else {
                                        $action = self::ACTION_SIN_CAMBIOS;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $datosPayload = [
                'campos' => $fields,
                'origen' => $row['source'],
            ];
            if (! empty($updatableDiffs)) {
                $datosPayload['diferencias'] = $updatableDiffs;
                $datosPayload['campos_anteriores'] = $camposAnteriores;
            }
            if ($matchedExisting) {
                $datosPayload['arma_existente_id'] = $matchedExisting->id;
            }

            $staging[] = [
                'import_batch_id' => $batch->id,
                'numero_fila' => $row['row_number'],
                'cuip' => null,
                'matricula' => $fields['matricula'] !== null
                    && $this->fitsColumn($fields['matricula'], 60)
                    && $fields['matricula'] !== ''
                    ? $fields['matricula']
                    : null,
                'id_armamento' => $this->fitsColumn($fields['id_armamento'] ?? '', 100) && $fields['id_armamento'] !== ''
                    ? $fields['id_armamento']
                    : null,
                'datos' => json_encode($datosPayload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'errores' => json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'accion' => $action,
                'estado_aplicacion' => self::STATUS_APLICACION_PENDIENTE,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        DB::table('loc_import_staging_rows')->insert($staging);

        return $comparisonSeconds;
    }

    private function markDuplicateRows(LocImportBatch $batch): void
    {
        $duplicateMatriculas = DB::table('loc_import_staging_rows')
            ->select('matricula')
            ->where('import_batch_id', $batch->id)
            ->whereNotNull('matricula')
            ->groupBy('matricula')
            ->havingRaw('COUNT(*) > 1');
        $matriculaRows = DB::table('loc_import_staging_rows as candidate')
            ->select('candidate.id', 'candidate.errores')
            ->where('candidate.import_batch_id', $batch->id)
            ->whereIn('candidate.matricula', $duplicateMatriculas)
            ->orderBy('candidate.id');
        $this->markRowsAsConflicts($matriculaRows, 'La matrícula se repite dentro del lote; requiere revisión.');

        $duplicateExternalIds = DB::table('loc_import_staging_rows')
            ->select('id_armamento')
            ->where('import_batch_id', $batch->id)
            ->whereNotNull('id_armamento')
            ->groupBy('id_armamento')
            ->havingRaw('COUNT(*) > 1');
        $externalIdRows = DB::table('loc_import_staging_rows as candidate')
            ->select('candidate.id', 'candidate.errores')
            ->where('candidate.import_batch_id', $batch->id)
            ->whereIn('candidate.id_armamento', $duplicateExternalIds)
            ->orderBy('candidate.id');
        $this->markRowsAsConflicts($externalIdRows, 'El ID_ARMAMENTO se repite dentro del lote; requiere revisión.');
    }

    private function markRowsAsConflicts($query, string $reason): void
    {
        $query->chunkById(500, function ($rows) use ($reason): void {
            foreach ($rows as $row) {
                $errors = json_decode((string) $row->errores, true);
                if (! is_array($errors)) {
                    $errors = $row->errores === null ? [] : [$row->errores];
                }
                if (! in_array($reason, $errors, true)) {
                    $errors[] = $reason;
                }

                DB::table('loc_import_staging_rows')
                    ->where('id', $row->id)
                    ->update([
                        'accion' => self::ACTION_CONFLICTO,
                        'errores' => json_encode(array_values($errors), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            }
        }, 'candidate.id', 'id');
    }

    public function sameExistingRecord(object $existing, array $fields, array $source = []): bool
    {
        return $this->sameRecordState($existing, $fields);
    }

    private function sameRecordState(object $record, array $fields): bool
    {
        foreach (self::IDENTITY_FIELDS as $field) {
            $normDb = $record->{$field} !== null ? trim((string) $record->{$field}) : null;
            $normStag = ($fields[$field] ?? '') !== '' ? trim((string) $fields[$field]) : null;
            if ($normDb !== $normStag) {
                return false;
            }
        }

        foreach (self::UPDATABLE_FIELDS as $field) {
            $normDb = $record->{$field} !== null ? trim((string) $record->{$field}) : null;
            $normStag = ($fields[$field] ?? '') !== '' ? trim((string) $fields[$field]) : null;
            if ($normDb !== $normStag) {
                return false;
            }
        }

        return true;
    }

    private function compareFieldList(object $record, array $fields, array $fieldList): array
    {
        $diffs = [];
        foreach ($fieldList as $field) {
            $incoming = $fields[$field] ?? '';
            $incoming = $incoming === '' ? null : $incoming;
            $current = $record->{$field} ?? null;
            $current = $current === '' ? null : $current;

            $normIncoming = $incoming !== null ? trim((string) $incoming) : null;
            $normCurrent = $current !== null ? trim((string) $current) : null;

            if ($normCurrent !== $normIncoming) {
                $diffs[$field] = [
                    'anterior' => $current,
                    'nuevo' => $incoming,
                ];
            }
        }

        return $diffs;
    }

    private function extractFieldValues(object $record, array $fieldList): array
    {
        $values = [];
        foreach ($fieldList as $field) {
            $val = $record->{$field} ?? null;
            $values[$field] = $val === '' ? null : $val;
        }

        return $values;
    }

    private function describeHeaders(array $rawHeaders): array
    {
        $seen = [];
        $headers = [];
        foreach ($rawHeaders as $index => $value) {
            $original = (string) $value;
            $normalized = $this->normalizeHeader($original);
            $seen[$normalized] = ($seen[$normalized] ?? 0) + 1;
            $column = $this->columnName($index + 1);
            $rawKey = trim($original) === ''
                ? "COLUMNA SIN NOMBRE [{$column}]"
                : ($seen[$normalized] > 1 ? "{$original} [columna {$column}]" : $original);
            $headers[] = [
                'original' => $original,
                'normalized' => $normalized,
                'raw_key' => $rawKey,
                'index' => $index,
            ];
        }

        return $headers;
    }

    private function mapRow(array $headers, array $cells): array
    {
        $fields = [];
        $source = [];
        foreach ($headers as $header) {
            $value = $this->stringValue($cells[$header['index']] ?? null);
            $source[$header['raw_key']] = $value;
            $field = self::FIELD_MAP[$header['normalized']] ?? null;
            if ($field && ! array_key_exists($field, $fields)) {
                $mappedValue = trim($value);
                $fields[$field] = $field === 'matricula'
                    && mb_strtoupper($mappedValue, 'UTF-8') === 'S/M'
                    ? null
                    : $mappedValue;
            }
        }

        foreach (self::FIELD_MAP as $field) {
            if (! array_key_exists($field, $fields)) {
                $fields[$field] = '';
            }
        }

        return [$fields, $source];
    }

    private function validateRow(array $fields): array
    {
        $errors = [];
        if ($fields['matricula'] === '') {
            $errors[] = 'Falta la matrícula obligatoria.';
        }
        if ($fields['matricula'] === null && ($fields['id_armamento'] ?? '') === '') {
            $errors[] = 'La matrícula no disponible requiere ID_ARMAMENTO para identificar el arma.';
        }

        foreach (self::FIELD_LIMITS as $field => $limit) {
            if (mb_strlen((string) ($fields[$field] ?? '')) > $limit) {
                $label = $field === 'matricula' ? 'Matrícula' : $field;
                $errors[] = "{$label} excede la capacidad de almacenamiento de {$limit} caracteres.";
            }
        }

        if (strlen((string) ($fields['observaciones'] ?? '')) > 65535) {
            $errors[] = 'Observaciones excede la capacidad de almacenamiento permitida.';
        }

        return $errors;
    }

    private function rowCounts(LocImportBatch $batch): array
    {
        return DB::table('loc_import_staging_rows')
            ->where('import_batch_id', $batch->id)
            ->selectRaw('accion, COUNT(*) as total')
            ->groupBy('accion')
            ->pluck('total', 'accion')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    private function persistProgress(
        LocImportBatch $batch,
        int $rowsRead,
        int $rowsEstimated,
        int $blocksCompleted,
        array $metrics,
        array $location = [],
    ): void {
        DB::transaction(function () use ($batch, $rowsRead, $rowsEstimated, $blocksCompleted, $metrics, $location): void {
            $lockedBatch = LocImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $summary = json_decode((string) $lockedBatch->resumen_errores, true) ?: [];
            $summary['progreso'] = [
                'filas_leidas' => $rowsRead,
                'filas_estimadas' => $rowsEstimated,
                'bloques_completados' => $blocksCompleted,
            ];
            $summary['metricas_revision'] = $metrics;
            $summary['diagnostico'] = array_merge($summary['diagnostico'] ?? [], [
                'etapa_actual' => $location['stage'] ?? 'lectura_mapeo_validacion_escritura',
                'location' => $location,
            ]);
            $lockedBatch->update([
                'total_leidas' => $rowsRead,
                'resumen_errores' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        });
    }

    public function reviewFingerprint(LocImportBatch $batch): string
    {
        $context = hash_init('sha256');
        hash_update($context, json_encode([
            $batch->id,
            $batch->tipo_fuente,
            $batch->archivo_sha256,
            $batch->total_leidas,
            $batch->hoja_procesada,
            $batch->total_columnas,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($context): void {
                foreach ($rows as $row) {
                    hash_update($context, json_encode([
                        $row->id,
                        $row->numero_fila,
                        $row->matricula,
                        $row->id_armamento,
                        $row->datos,
                        $row->errores,
                        $row->accion,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                }
            });

        return hash_final($context);
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

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function fitsColumn(string $value, int $limit): bool
    {
        return mb_strlen($value) <= $limit;
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = $ascii === false ? $value : $ascii;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
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

    private function createReader(): IReader
    {
        if (config('loc_import.weapons_reuse_ole', true)) {
            return new LocWeaponsOleReuseReader();
        }

        return IOFactory::createReader('Xls');
    }
}
