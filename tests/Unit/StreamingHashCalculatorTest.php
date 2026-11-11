<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\StreamingHashCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StreamingHashCalculatorTest extends TestCase
{
    protected string $tempDir;
    protected StreamingHashCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_streaming_hash_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->calculator = new StreamingHashCalculator(65536); // 64 KB
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = scandir($this->tempDir) ?: [];
            foreach ($files as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($this->tempDir . DIRECTORY_SEPARATOR . $f);
                }
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_calcula_hash_correcto_para_archivo_pequeno(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'pequeno.dat';
        $content = 'CONTENIDO_BIOMETRICO_DE_PRUEBA_LOC139';
        file_put_contents($file, $content);

        $res = $this->calculator->calculate($file);

        $expectedHash = hash('sha256', $content);
        $this->assertSame($expectedHash, $res->hash);
        $this->assertSame(strlen($content), $res->tamanoBytes);
        $this->assertFalse($res->esVacio);
        $this->assertSame(1, $res->bloquesLeidos);
        $this->assertGreaterThanOrEqual(0.0, $res->duracionMs);
    }

    public function test_calcula_hash_canonico_para_archivo_vacio_sin_error(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'vacio.dat';
        file_put_contents($file, '');

        $res = $this->calculator->calculate($file);

        $this->assertSame(StreamingHashCalculator::EMPTY_SHA256, $res->hash);
        $this->assertSame(0, $res->tamanoBytes);
        $this->assertTrue($res->esVacio);
        $this->assertSame(0, $res->bloquesLeidos);
    }

    public function test_calcula_hash_por_bloques_multiples_sin_saturar_memoria(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'grande.dat';
        // Generar 160 KB (más de dos bloques de 64 KB = 2 bloques completos + 1 parcial)
        $blockSize = 65536;
        $totalBytes = 160 * 1024;
        $handle = fopen($file, 'wb');
        for ($i = 0; $i < 160; $i++) {
            fwrite($handle, str_repeat(chr(65 + ($i % 26)), 1024));
        }
        fclose($handle);

        $res = $this->calculator->calculate($file);

        $expectedHash = hash_file('sha256', $file);
        $this->assertSame($expectedHash, $res->hash);
        $this->assertSame($totalBytes, $res->tamanoBytes);
        $this->assertFalse($res->esVacio);
        $this->assertSame(3, $res->bloquesLeidos); // ceil(163840 / 65536) = 3 bloques
    }

    public function test_calcula_hash_directamente_desde_recurso_de_flujo(): void
    {
        $stream = fopen('php://memory', 'r+');
        $data = 'PRUEBA_DE_STREAMING_DESDE_RECURSO';
        fwrite($stream, $data);
        rewind($stream);

        $res = $this->calculator->calculateFromResource($stream, strlen($data));
        fclose($stream);

        $this->assertSame(hash('sha256', $data), $res->hash);
        $this->assertSame(strlen($data), $res->tamanoBytes);
    }

    public function test_rechaza_archivo_inexistente(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no existe');

        $this->calculator->calculate($this->tempDir . DIRECTORY_SEPARATOR . 'no_existe.dat');
    }

    public function test_rechaza_directorio_como_archivo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no corresponde a un archivo regular');

        $this->calculator->calculate($this->tempDir);
    }

    public function test_rechaza_tamano_de_bloque_menor_o_igual_a_cero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('debe ser un entero positivo');

        new StreamingHashCalculator(0);
    }

    public function test_preserva_intacto_el_archivo_y_sus_atributos(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'preservar.dat';
        file_put_contents($file, 'INTEGRIDAD_PRESERVADA');
        $initialMtime = filemtime($file);
        $initialContent = file_get_contents($file);

        $this->calculator->calculate($file);

        $this->assertSame($initialContent, file_get_contents($file));
        $this->assertSame($initialMtime, filemtime($file));
    }
}

