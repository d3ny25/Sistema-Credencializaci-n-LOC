<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoIndexRunEstado;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoIncidencia;
use App\Models\BiometricoIndexRun;
use App\Services\BiometricIndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiometricHierarchicalResumptionTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;
    private BiometricIndexingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BiometricIndexingService();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bio_hier_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->eliminarDirectorioRecursivo($this->tempDir);
        parent::tearDown();
    }

    private function eliminarDirectorioRecursivo(string $dir): void
    {
        if (! file_exists($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path) && ! is_link($path)) {
                $this->eliminarDirectorioRecursivo($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function test_reanudacion_jerarquica_cubre_100_por_ciento_de_archivos_sin_omisiones_ni_duplicados(): void
    {
        // Estructura de árbol con archivos en diferentes subcarpetas y niveles
        $fotosRaiz = $this->tempDir . '/FOTOS';
        $lote1Dir = $this->tempDir . '/01_LOTE/FOTOS';
        $lote2Dir = $this->tempDir . '/02_LOTE/FOTOS';
        $fotosSubDir = $this->tempDir . '/FOTOS/PROFUNDA';
        $zExtrasDir = $this->tempDir . '/Z_EXTRAS/FOTOS';

        mkdir($fotosRaiz, 0777, true);
        mkdir($lote1Dir, 0777, true);
        mkdir($lote2Dir, 0777, true);
        mkdir($fotosSubDir, 0777, true);
        mkdir($zExtrasDir, 0777, true);

        // Archivos con nombres CUIP válidos y categorías inferibles para indexar
        file_put_contents($fotosRaiz . '/AAAA690916H15136329.JPG', 'raiz_a');
        file_put_contents($lote1Dir . '/BBBB700101H12345678.JPG', 'lote1_b');
        file_put_contents($lote2Dir . '/CCCC800202M87654321.JPG', 'lote2_c');
        file_put_contents($fotosSubDir . '/DDDD850303H11223344.JPG', 'sub_d');
        file_put_contents($zExtrasDir . '/EEEE900404M99887766.JPG', 'extras_e');

        // 1. Obtener orden canónico de escaneo mediante el scanner
        $canonicalRoot = $this->service->getScanner()->validarRutaRaiz($this->tempDir);
        $todasLasEntradas = [];
        foreach ($this->service->getScanner()->scan($canonicalRoot) as $entry) {
            $todasLasEntradas[] = $entry->rutaRelativa;
        }

        $this->assertCount(5, $todasLasEntradas);

        // 2. Ejecutar corrida 1 con interrupción tras procesar los primeros 2 archivos
        $archivosCorrida1 = [];
        $service1 = new BiometricIndexingService();
        $run1 = $service1->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir,
            chunkSize: 1,
            progressCallback: function (int $procesados, int $detectados, BiometricoIndexRun $run) use ($service1, &$archivosCorrida1) {
                $archivosCorrida1[] = $run->ultima_ruta_procesada;
                if ($procesados >= 2) {
                    $service1->abortar();
                }
            }
        );

        $this->assertSame(BiometricoIndexRunEstado::INTERRUMPIDA, $run1->estado);
        $this->assertSame(2, $run1->total_archivos_procesados);
        $this->assertNotNull($run1->ultima_ruta_procesada);

        // 3. Ejecutar corrida 2 con resume: true
        $archivosCorrida2 = [];
        $service2 = new BiometricIndexingService();
        $run2 = $service2->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir,
            chunkSize: 1,
            resume: true,
            progressCallback: function (int $procesados, int $detectados, BiometricoIndexRun $run) use (&$archivosCorrida2) {
                $archivosCorrida2[] = $run->ultima_ruta_procesada;
            }
        );

        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $run2->estado);
        $this->assertSame(3, $run2->total_archivos_procesados);

        // 4. Verificaciones de rigor pericial:
        // A) Ni un solo archivo se omitió: la suma total es 5
        $totalProcesados = count($archivosCorrida1) + count($archivosCorrida2);
        $this->assertSame(5, $totalProcesados);

        // B) No hay duplicados entre la corrida 1 y la corrida 2
        $interseccion = array_intersect($archivosCorrida1, $archivosCorrida2);
        $this->assertEmpty($interseccion, 'Hubo archivos procesados en ambas corridas.');

        // C) La secuencia combinada coincide exactamente con el orden canónico global monótono
        $combinados = array_merge($archivosCorrida1, $archivosCorrida2);
        $this->assertSame($todasLasEntradas, $combinados);

        // D) Todos los archivos quedaron registrados en la base de datos
        $this->assertSame(5, BiometricoArchivo::count());
    }
}
