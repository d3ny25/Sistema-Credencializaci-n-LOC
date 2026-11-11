<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BiometricoAmbito;
use App\Support\BiometricFilenameParser;
use App\Services\BiometricIndexingService;
use App\Services\BiometricScannerService;
use App\Support\StreamingHashCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BiometricIndexingServiceTest extends TestCase
{
    private BiometricIndexingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BiometricIndexingService();
    }

    public function test_rechaza_chunk_size_menor_o_igual_a_cero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El tamaño de lote debe ser un entero positivo');
        $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: sys_get_temp_dir(),
            chunkSize: 0
        );
    }

    public function test_rechaza_ruta_raiz_vacia(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: '   '
        );
    }

    public function test_rechaza_ruta_raiz_inexistente(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inexistente_' . uniqid()
        );
    }
}
