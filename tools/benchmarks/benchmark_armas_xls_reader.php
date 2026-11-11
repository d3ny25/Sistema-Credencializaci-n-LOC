<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

final class BenchmarkArmasReadFilter implements IReadFilter
{
    public function __construct(
        private readonly int $startRow,
        private readonly int $endRow,
    ) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row === 1 || ($row >= $this->startRow && $row <= $this->endRow);
    }
}

function optionValue(array $arguments, string $name, string $default): string
{
    $prefix = '--'.$name.'=';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }

    return $default;
}

function isEmptySourceRow(array $cells): bool
{
    foreach ($cells as $cell) {
        if (trim((string) ($cell ?? '')) !== '') {
            return false;
        }
    }

    return true;
}

function formatBytes(float $bytes): float
{
    return round($bytes / 1048576, 1);
}

$results = [];
$activeConfiguration = null;

try {
    $arguments = array_slice($argv, 1);
    $filePath = optionValue($arguments, 'file', '');
    $expectedHash = strtoupper(optionValue($arguments, 'expected-sha256', ''));
    $sampleRows = filter_var(optionValue($arguments, 'rows', '2000'), FILTER_VALIDATE_INT);
    $chunkValues = array_map(
        static fn (string $value): int|false => filter_var($value, FILTER_VALIDATE_INT),
        explode(',', optionValue($arguments, 'chunks', '500,1000,2000')),
    );
    $maxPeakMb = filter_var(optionValue($arguments, 'max-peak-mb', '400'), FILTER_VALIDATE_INT);

    if ($filePath === '' || ! is_file($filePath) || $expectedHash === '' || $sampleRows === false || $sampleRows < 1 || $maxPeakMb === false || $maxPeakMb < 1) {
        throw new InvalidArgumentException('Uso: php tools/benchmarks/benchmark_armas_xls_reader.php --file=RUTA --expected-sha256=SHA256 [--rows=2000] [--chunks=500,1000,2000] [--max-peak-mb=400]');
    }

    foreach ($chunkValues as $chunkValue) {
        if ($chunkValue === false || $chunkValue < 1) {
            throw new InvalidArgumentException('Los tamaños de bloque deben ser enteros positivos.');
        }
    }

    $chunkSizes = array_values(array_unique($chunkValues));
    if ($chunkSizes !== $chunkValues) {
        throw new InvalidArgumentException('Los tamaños de bloque deben ser únicos.');
    }

    $filePath = realpath($filePath) ?: $filePath;
    $fileSize = filesize($filePath);
    $fileHash = strtoupper(hash_file('sha256', $filePath));
    if ($fileHash !== $expectedHash) {
        throw new RuntimeException('El SHA-256 del archivo no coincide con el esperado; no se leyó el libro.');
    }

    $metadataStartedAt = microtime(true);
    $metadataReader = IOFactory::createReader('Xls');
    $worksheetInfo = $metadataReader->listWorksheetInfo($filePath)[0] ?? null;
    $metadataSeconds = microtime(true) - $metadataStartedAt;
    unset($metadataReader);
    gc_collect_cycles();

    if (! $worksheetInfo || (int) $worksheetInfo['totalRows'] < 2) {
        throw new RuntimeException('El libro no contiene filas de datos.');
    }

    $totalWorksheetRows = (int) $worksheetInfo['totalRows'];
    $sampleRowCount = min($sampleRows, $totalWorksheetRows - 1);
    $expectedRows = $sampleRowCount;
    $sampleStart = 2;
    $sampleEnd = $sampleStart + $sampleRowCount - 1;
    $worksheetName = (string) $worksheetInfo['worksheetName'];
    $lastColumn = (string) $worksheetInfo['lastColumnLetter'];
    $expectedFingerprint = null;

    foreach ($chunkSizes as $chunkSize) {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $peakPhpBytes = memory_get_peak_usage(false);
        $peakAllocatedBytes = memory_get_peak_usage(true);
        $rowsReturned = 0;
        $nonEmptyRows = 0;
        $emptyRows = 0;
        $blocks = 0;
        $readerInitSeconds = 0.0;
        $fileOpenLoadSeconds = 0.0;
        $cellReadSeconds = 0.0;
        $fingerprint = hash_init('sha256');
        $runStartedAt = microtime(true);
        $activeConfiguration = [
            'chunk_rows' => $chunkSize,
            'blocks_completed' => 0,
            'row_range' => "{$sampleStart}-{$sampleEnd}",
            'rows_expected' => $expectedRows,
            'rows_returned' => 0,
            'non_empty_rows' => 0,
            'empty_rows' => 0,
            'reader_init_seconds' => 0.0,
            'file_open_load_seconds' => 0.0,
            'cell_read_seconds' => 0.0,
            'php_peak_used_mb' => formatBytes($peakPhpBytes),
            'php_peak_allocated_mb' => formatBytes($peakAllocatedBytes),
            'cell_fingerprint_sha256' => null,
            'errors' => [],
            'warnings' => [],
        ];

        for ($start = $sampleStart; $start <= $sampleEnd; $start += $chunkSize) {
            $end = min($start + $chunkSize - 1, $sampleEnd);
            $readerStartedAt = microtime(true);
            $reader = IOFactory::createReader('Xls');
            $reader->setReadFilter(new BenchmarkArmasReadFilter($start, $end));
            $reader->setLoadSheetsOnly([$worksheetName]);
            $readerInitSeconds += microtime(true) - $readerStartedAt;

            $openStartedAt = microtime(true);
            $workbook = $reader->load($filePath);
            $fileOpenLoadSeconds += microtime(true) - $openStartedAt;
            $sheet = $workbook->getSheetByName($worksheetName);
            if (! $sheet) {
                throw new RuntimeException("No se pudo abrir la hoja {$worksheetName}.");
            }

            $readStartedAt = microtime(true);
            $matrix = $sheet->rangeToArray("A{$start}:{$lastColumn}{$end}", null, true, true);
            $cellReadSeconds += microtime(true) - $readStartedAt;
            $expectedRowsInBlock = $end - $start + 1;
            if (count($matrix) !== $expectedRowsInBlock) {
                throw new RuntimeException("Rango inesperado de filas en el bloque {$blocks}: ".count($matrix)." recibidas, {$expectedRowsInBlock} esperadas.");
            }

            foreach ($matrix as $cells) {
                hash_update($fingerprint, serialize($cells));
                $rowsReturned++;
                if (isEmptySourceRow($cells)) {
                    $emptyRows++;
                } else {
                    $nonEmptyRows++;
                }
            }

            $workbook->disconnectWorksheets();
            unset($matrix, $sheet, $workbook, $reader);
            gc_collect_cycles();
            $blocks++;
            $peakPhpBytes = max($peakPhpBytes, memory_get_peak_usage(false));
            $peakAllocatedBytes = max($peakAllocatedBytes, memory_get_peak_usage(true));
            $activeConfiguration = [
                'chunk_rows' => $chunkSize,
                'blocks_completed' => $blocks,
                'row_range' => "{$sampleStart}-{$sampleEnd}",
                'rows_expected' => $expectedRows,
                'rows_returned' => $rowsReturned,
                'non_empty_rows' => $nonEmptyRows,
                'empty_rows' => $emptyRows,
                'reader_init_seconds' => round($readerInitSeconds, 6),
                'file_open_load_seconds' => round($fileOpenLoadSeconds, 3),
                'cell_read_seconds' => round($cellReadSeconds, 3),
                'elapsed_seconds' => round(microtime(true) - $runStartedAt, 3),
                'php_peak_used_mb' => formatBytes($peakPhpBytes),
                'php_peak_allocated_mb' => formatBytes($peakAllocatedBytes),
                'cell_fingerprint_sha256' => hash_final(hash_copy($fingerprint)),
                'errors' => [],
                'warnings' => [],
            ];

            if (formatBytes($peakAllocatedBytes) > $maxPeakMb) {
                throw new RuntimeException("Se alcanzó el umbral preventivo de memoria de {$maxPeakMb} MiB; benchmark detenido.");
            }
        }

        $elapsedSeconds = microtime(true) - $runStartedAt;
        $currentFingerprint = hash_final($fingerprint);
        if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $currentFingerprint)) {
            throw new RuntimeException('Las celdas leídas difieren entre tamaños de bloque; benchmark detenido.');
        }
        $expectedFingerprint ??= $currentFingerprint;

        if ($rowsReturned !== $expectedRows || $nonEmptyRows + $emptyRows !== $expectedRows) {
            throw new RuntimeException("Conteo de filas inesperado: {$rowsReturned} leídas de {$expectedRows} esperadas.");
        }

        $completedConfiguration = [
            'chunk_rows' => $chunkSize,
            'blocks' => $blocks,
            'row_range' => "{$sampleStart}-{$sampleEnd}",
            'rows_expected' => $expectedRows,
            'rows_returned' => $rowsReturned,
            'non_empty_rows' => $nonEmptyRows,
            'empty_rows' => $emptyRows,
            'reader_init_seconds' => round($readerInitSeconds, 6),
            'file_open_load_seconds' => round($fileOpenLoadSeconds, 3),
            'cell_read_seconds' => round($cellReadSeconds, 3),
            'total_seconds' => round($elapsedSeconds, 3),
            'php_peak_used_mb' => formatBytes($peakPhpBytes),
            'php_peak_allocated_mb' => formatBytes($peakAllocatedBytes),
            'cell_fingerprint_sha256' => $currentFingerprint,
            'errors' => [],
            'warnings' => [],
        ];
        $results[] = $completedConfiguration;
        $activeConfiguration = null;

        if (strtoupper(hash_file('sha256', $filePath)) !== $fileHash || filesize($filePath) !== $fileSize) {
            throw new RuntimeException('El archivo cambió durante la prueba; benchmark detenido.');
        }
    }

    echo json_encode([
        'result' => 'ok',
        'mode' => $sampleRowCount === $totalWorksheetRows - 1 ? 'full' : 'sample',
        'rows_requested' => $sampleRows,
        'rows_expected' => $expectedRows,
        'row_start' => $sampleStart,
        'row_end' => $sampleEnd,
        'header_counted_as_data' => false,
        'total_worksheet_rows_including_header' => $totalWorksheetRows,
        'worksheet' => $worksheetName,
        'file' => [
            'path' => $filePath,
            'bytes' => $fileSize,
            'sha256' => $fileHash,
        ],
        'php_version' => PHP_VERSION,
        'php_memory_limit' => ini_get('memory_limit'),
        'metadata_seconds_excluded_from_runs' => round($metadataSeconds, 3),
        'memory_abort_threshold_mb' => $maxPeakMb,
        'configurations' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'result' => 'error',
        'type' => $exception::class,
        'message' => $exception->getMessage(),
        'completed_configurations' => $results,
        'partial_configuration' => $activeConfiguration,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
