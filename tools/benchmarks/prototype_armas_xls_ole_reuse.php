<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xls;

require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

final class PrototypeArmasReadFilter implements IReadFilter
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

final class OleReuseXlsReader extends Xls
{
    private static ?array $oleStreams = null;

    private static float $oleLoadSeconds = 0.0;

    private static int $oleLoadCalls = 0;

    protected function loadOLE(string $filename): void
    {
        if (self::$oleStreams !== null) {
            $this->data = self::$oleStreams['data'];
            $this->summaryInformation = self::$oleStreams['summary'];
            $this->documentSummaryInformation = self::$oleStreams['document_summary'];

            return;
        }

        $startedAt = microtime(true);
        parent::loadOLE($filename);
        self::$oleLoadSeconds += microtime(true) - $startedAt;
        self::$oleLoadCalls++;
        self::$oleStreams = [
            'data' => $this->data,
            'summary' => $this->summaryInformation,
            'document_summary' => $this->documentSummaryInformation,
        ];
    }

    public static function metrics(): array
    {
        return [
            'ole_load_seconds' => self::$oleLoadSeconds,
            'ole_load_calls' => self::$oleLoadCalls,
            'cached_stream_bytes' => self::$oleStreams === null
                ? 0
                : strlen(self::$oleStreams['data'])
                    + strlen(self::$oleStreams['summary'])
                    + strlen(self::$oleStreams['document_summary']),
        ];
    }

    public static function clearCachedStreams(): void
    {
        self::$oleStreams = null;
        self::$oleLoadSeconds = 0.0;
        self::$oleLoadCalls = 0;
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

function sourceRowIsEmpty(array $cells): bool
{
    foreach ($cells as $cell) {
        if (trim((string) ($cell ?? '')) !== '') {
            return false;
        }
    }

    return true;
}

function memoryInMiB(float $bytes): float
{
    return round($bytes / 1048576, 1);
}

function runReaderMode(
    string $mode,
    string $path,
    string $worksheetName,
    string $lastColumn,
    int $startRow,
    int $endRow,
    int $chunkSize,
    int $maxPeakMiB,
): array {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $initialUsedBytes = memory_get_usage(false);
    $initialAllocatedBytes = memory_get_usage(true);
    $peakUsedBytes = memory_get_peak_usage(false);
    $peakAllocatedBytes = memory_get_peak_usage(true);
    $rowsReturned = 0;
    $nonEmptyRows = 0;
    $emptyRows = 0;
    $blocksCompleted = 0;
    $readerSetupSeconds = 0.0;
    $loadSeconds = 0.0;
    $cellReadSeconds = 0.0;
    $fingerprint = hash_init('sha256');
    $rawFingerprint = hash_init('sha256');
    $runStartedAt = microtime(true);

    for ($blockStart = $startRow; $blockStart <= $endRow; $blockStart += $chunkSize) {
        $blockEnd = min($blockStart + $chunkSize - 1, $endRow);
        $setupStartedAt = microtime(true);
        $reader = $mode === 'reuse_ole' ? new OleReuseXlsReader : IOFactory::createReader('Xls');
        $reader->setReadFilter(new PrototypeArmasReadFilter($blockStart, $blockEnd));
        $reader->setLoadSheetsOnly([$worksheetName]);
        $readerSetupSeconds += microtime(true) - $setupStartedAt;

        $workbook = null;
        $sheet = null;
        $matrix = [];
        $rawMatrix = [];
        try {
            $loadStartedAt = microtime(true);
            $workbook = $reader->load($path);
            $loadSeconds += microtime(true) - $loadStartedAt;
            $sheet = $workbook->getSheetByName($worksheetName);
            if (! $sheet) {
                throw new RuntimeException("No se pudo abrir la hoja {$worksheetName}.");
            }

            $cellReadStartedAt = microtime(true);
            $matrix = $sheet->rangeToArray("A{$blockStart}:{$lastColumn}{$blockEnd}", null, true, true);
            $rawMatrix = $sheet->rangeToArray("A{$blockStart}:{$lastColumn}{$blockEnd}", null, false, false);
            $cellReadSeconds += microtime(true) - $cellReadStartedAt;
            $expectedInBlock = $blockEnd - $blockStart + 1;
            if (count($matrix) !== $expectedInBlock || count($rawMatrix) !== $expectedInBlock) {
                throw new RuntimeException("El bloque {$blocksCompleted} devolvió ".count($matrix).' filas formateadas y '.count($rawMatrix)." sin formato; se esperaban {$expectedInBlock}.");
            }

            foreach ($matrix as $offset => $cells) {
                $rowNumber = $blockStart + $offset;
                hash_update($fingerprint, serialize([$rowNumber, $cells]));
                hash_update($rawFingerprint, serialize([$rowNumber, $rawMatrix[$offset]]));
                $rowsReturned++;
                if (sourceRowIsEmpty($cells)) {
                    $emptyRows++;
                } else {
                    $nonEmptyRows++;
                }
            }
        } finally {
            if ($workbook !== null) {
                $workbook->disconnectWorksheets();
            }
            unset($matrix, $rawMatrix, $sheet, $workbook, $reader);
            gc_collect_cycles();
        }

        $blocksCompleted++;
        $peakUsedBytes = max($peakUsedBytes, memory_get_peak_usage(false));
        $peakAllocatedBytes = max($peakAllocatedBytes, memory_get_peak_usage(true));

        if ($peakAllocatedBytes > $maxPeakMiB * 1048576) {
            throw new RuntimeException("Modo {$mode}: se superó el umbral preventivo de {$maxPeakMiB} MiB asignados.");
        }
    }

    $elapsedSeconds = microtime(true) - $runStartedAt;
    $oleMetrics = $mode === 'reuse_ole'
        ? OleReuseXlsReader::metrics()
        : ['ole_load_seconds' => null, 'ole_load_calls' => $blocksCompleted];
    $oleLoadSeconds = $oleMetrics['ole_load_seconds'] ?? 0.0;
    $oleLoadCalls = $oleMetrics['ole_load_calls'];
    if ($mode === 'reuse_ole') {
        OleReuseXlsReader::clearCachedStreams();
    }
    gc_collect_cycles();

    return [
        'mode' => $mode,
        'blocks_and_load_calls' => $blocksCompleted,
        'ole_stream_load_calls' => $oleLoadCalls,
        'rows_expected' => $endRow - $startRow + 1,
        'rows_returned' => $rowsReturned,
        'empty_rows' => $emptyRows,
        'non_empty_rows' => $nonEmptyRows,
        'reader_setup_seconds' => round($readerSetupSeconds, 6),
        'ole_stream_load_seconds' => $oleLoadSeconds === null ? null : round($oleLoadSeconds, 3),
        'reader_load_seconds' => round($loadSeconds, 3),
        'cell_read_seconds' => round($cellReadSeconds, 3),
        'total_seconds' => round($elapsedSeconds, 3),
        'php_peak_used_mb' => memoryInMiB($peakUsedBytes),
        'php_peak_allocated_mb' => memoryInMiB($peakAllocatedBytes),
        'php_initial_used_mb' => memoryInMiB($initialUsedBytes),
        'php_initial_allocated_mb' => memoryInMiB($initialAllocatedBytes),
        'ole_stream_cache_mb' => round(($oleMetrics['cached_stream_bytes'] ?? 0) / 1048576, 1),
        'cell_fingerprint_sha256' => hash_final($fingerprint),
        'raw_typed_cell_fingerprint_sha256' => hash_final($rawFingerprint),
        'errors' => [],
        'warnings' => $GLOBALS['benchmark_warnings'] ?? [],
    ];
}

$results = [];
$activeMode = null;
$GLOBALS['benchmark_warnings'] = [];
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    $GLOBALS['benchmark_warnings'][] = "{$message} at {$file}:{$line}";

    return true;
});

try {
    $arguments = array_slice($argv, 1);
    $path = optionValue($arguments, 'file', '');
    $expectedHash = strtoupper(optionValue($arguments, 'expected-sha256', ''));
    $sampleRows = filter_var(optionValue($arguments, 'rows', '2000'), FILTER_VALIDATE_INT);
    $chunkSize = filter_var(optionValue($arguments, 'chunk', '500'), FILTER_VALIDATE_INT);
    $maxPeakMiB = filter_var(optionValue($arguments, 'max-peak-mb', '400'), FILTER_VALIDATE_INT);
    $mode = optionValue($arguments, 'mode', 'standard');

    if ($path === '' || ! is_file($path) || $expectedHash === '' || $sampleRows === false || $sampleRows < 1 || $chunkSize === false || $chunkSize < 1 || $maxPeakMiB === false || $maxPeakMiB < 1 || ! in_array($mode, ['standard', 'reuse_ole'], true)) {
        throw new InvalidArgumentException('Uso: php tools/benchmarks/prototype_armas_xls_ole_reuse.php --file=RUTA --expected-sha256=SHA256 [--mode=standard|reuse_ole] [--rows=2000] [--chunk=500] [--max-peak-mb=400]');
    }

    $path = realpath($path) ?: $path;
    $fileSize = filesize($path);
    $fileHash = strtoupper(hash_file('sha256', $path));
    if (! hash_equals($expectedHash, $fileHash)) {
        throw new RuntimeException('El SHA-256 no coincide; no se leyó el libro.');
    }

    $metadataStartedAt = microtime(true);
    $metadataReader = IOFactory::createReader('Xls');
    $worksheetInfo = $metadataReader->listWorksheetInfo($path)[0] ?? null;
    $metadataSeconds = microtime(true) - $metadataStartedAt;
    unset($metadataReader);
    gc_collect_cycles();
    if (! $worksheetInfo || (int) $worksheetInfo['totalRows'] < 2) {
        throw new RuntimeException('No se encontró hoja de datos en el XLS.');
    }

    $totalDataRows = (int) $worksheetInfo['totalRows'] - 1;
    if ($sampleRows > $totalDataRows) {
        throw new InvalidArgumentException("La muestra solicita {$sampleRows} filas, pero el libro tiene {$totalDataRows} filas de datos.");
    }
    $startRow = 2;
    $endRow = $startRow + $sampleRows - 1;
    $worksheetName = (string) $worksheetInfo['worksheetName'];
    $lastColumn = (string) $worksheetInfo['lastColumnLetter'];
    $activeMode = $mode;
    $result = runReaderMode(
        $mode,
        $path,
        $worksheetName,
        $lastColumn,
        $startRow,
        $endRow,
        $chunkSize,
        $maxPeakMiB,
    );
    if ($result['rows_returned'] !== $sampleRows
        || $sampleRows !== $result['empty_rows'] + $result['non_empty_rows']) {
        throw new RuntimeException("El modo {$mode} no devolvió el conteo exacto de la muestra.");
    }
    $results[] = $result;
    $activeMode = null;

    if ($GLOBALS['benchmark_warnings'] !== []) {
        throw new RuntimeException('Se registraron advertencias durante la lectura; el resultado no se considera válido.');
    }

    if (strtoupper(hash_file('sha256', $path)) !== $fileHash || filesize($path) !== $fileSize) {
        throw new RuntimeException('El archivo fuente cambió durante el benchmark.');
    }

    echo json_encode([
        'result' => 'ok',
        'mode' => $sampleRows === $totalDataRows ? 'full' : 'sample',
        'file' => ['path' => $path, 'bytes' => $fileSize, 'sha256' => $fileHash],
        'worksheet' => $worksheetName,
        'worksheet_rows_including_header' => (int) $worksheetInfo['totalRows'],
        'header_counted_as_data' => false,
        'sample_range' => "{$startRow}-{$endRow}",
        'sample_rows' => $sampleRows,
        'chunk_size' => $chunkSize,
        'reader_mode' => $mode,
        'php_version' => PHP_VERSION,
        'php_memory_limit' => ini_get('memory_limit'),
        'memory_abort_threshold_mb' => $maxPeakMiB,
        'list_worksheet_info_seconds' => round($metadataSeconds, 3),
        'configurations' => $results,
        'interpretation' => 'reuse_ole conserva los streams OLE descomprimidos en memoria y los inyecta en instancias Reader nuevas; el workbook BIFF se sigue parseando al cargar cada rango.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'result' => 'error',
        'mode' => $activeMode,
        'type' => $exception::class,
        'message' => $exception->getMessage(),
        'completed_configurations' => $results,
        'warnings' => $GLOBALS['benchmark_warnings'],
        'source_hash_after_run' => isset($path) && is_file($path) ? strtoupper(hash_file('sha256', $path)) : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    if ($activeMode === 'reuse_ole') {
        OleReuseXlsReader::clearCachedStreams();
    }
    restore_error_handler();
    exit(1);
}

restore_error_handler();
