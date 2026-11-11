<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

class StreamingHashCalculator
{
    /**
     * Tamaño de bloque por defecto: 64 KB (65,536 bytes).
     */
    public const DEFAULT_CHUNK_SIZE = 65536;

    /**
     * Hash SHA-256 canónico para contenido de 0 bytes (cadena vacía).
     */
    public const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    protected int $chunkSize;

    public function __construct(int $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
        if ($chunkSize <= 0) {
            throw new InvalidArgumentException("El tamaño de bloque debe ser un entero positivo mayor a cero: {$chunkSize}");
        }

        $this->chunkSize = $chunkSize;
    }

    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * Calcula la suma criptográfica SHA-256 de un archivo físico mediante streaming por bloques.
     *
     * @param  string  $filePath Ruta absoluta o relativa al archivo físico
     * @return StreamingHashResult
     *
     * @throws InvalidArgumentException Si el archivo no existe o no es un archivo regular
     * @throws RuntimeException Si el archivo no es legible, no puede abrirse o falla durante la lectura
     */
    public function calculate(string $filePath): StreamingHashResult
    {
        if (! file_exists($filePath)) {
            throw new InvalidArgumentException("El archivo físico especificado no existe: {$filePath}");
        }

        if (! is_file($filePath)) {
            throw new InvalidArgumentException("La ruta especificada no corresponde a un archivo regular: {$filePath}");
        }

        if (! is_readable($filePath)) {
            throw new RuntimeException("El archivo existe pero no cuenta con permisos de lectura para el proceso: {$filePath}");
        }

        $startTime = microtime(true);
        $expectedSize = @filesize($filePath);
        if ($expectedSize === false) {
            throw new RuntimeException("No fue posible determinar el tamaño del archivo: {$filePath}");
        }

        // Política para archivos de 0 bytes (vacíos)
        if ($expectedSize === 0) {
            $durationMs = (microtime(true) - $startTime) * 1000.0;

            return new StreamingHashResult(
                hash: self::EMPTY_SHA256,
                tamanoBytes: 0,
                esVacio: true,
                duracionMs: round($durationMs, 4),
                bloquesLeidos: 0,
                ruta: $filePath
            );
        }

        // Apertura en modo de solo lectura binaria ('rb') sin alterar atributos de archivo
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Fallo al abrir el descriptor de archivo de solo lectura: {$filePath}");
        }

        try {
            return $this->calculateFromResource($handle, $expectedSize, $startTime, $filePath);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Calcula la suma criptográfica SHA-256 a partir de un recurso de flujo (stream) ya abierto.
     *
     * @param  resource  $stream Recurso de flujo abierto en modo lectura
     * @param  int|null  $expectedSize Tamaño esperado en bytes (opcional para control de lectura completa)
     * @param  float|null  $startTime Marca de tiempo inicial para cálculo de duración (opcional)
     * @param  string|null  $ruta Referencia de la ruta (opcional)
     * @return StreamingHashResult
     *
     * @throws RuntimeException Si ocurre un error de E/S o lectura incompleta
     */
    public function calculateFromResource($stream, ?int $expectedSize = null, ?float $startTime = null, ?string $ruta = null): StreamingHashResult
    {
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('El parámetro proporcionado no es un recurso de flujo válido.');
        }

        $start = $startTime ?? microtime(true);
        $ctx = hash_init('sha256');
        $bytesReadTotal = 0;
        $chunksCount = 0;

        while (! feof($stream)) {
            $buffer = fread($stream, $this->chunkSize);
            if ($buffer === false) {
                throw new RuntimeException("Error de E/S durante la lectura por bloques del flujo: {$ruta}");
            }

            $len = strlen($buffer);
            if ($len > 0) {
                hash_update($ctx, $buffer);
                $bytesReadTotal += $len;
                $chunksCount++;
            }
        }

        // Detección de lectura incompleta o archivo truncado en caliente durante el streaming
        if ($expectedSize !== null && $bytesReadTotal !== $expectedSize) {
            throw new RuntimeException(
                "Lectura incompleta o archivo modificado durante el streaming en '{$ruta}': se esperaban {$expectedSize} bytes pero se leyeron {$bytesReadTotal} bytes."
            );
        }

        $hash = hash_final($ctx);
        $durationMs = (microtime(true) - $start) * 1000.0;

        return new StreamingHashResult(
            hash: strtolower($hash),
            tamanoBytes: $bytesReadTotal,
            esVacio: ($bytesReadTotal === 0),
            duracionMs: round($durationMs, 4),
            bloquesLeidos: $chunksCount,
            ruta: $ruta
        );
    }
}

