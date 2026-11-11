<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BiometricScannerService;
use App\Support\BiometricScanEntry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BiometricScannerServiceTest extends TestCase
{
    private string $tempDir;
    private BiometricScannerService $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new BiometricScannerService();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bio_scan_test_' . uniqid();
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

    public function test_valida_ruta_raiz_con_exito(): void
    {
        $canonical = $this->scanner->validarRutaRaiz($this->tempDir);
        $this->assertNotEmpty($canonical);
        $this->assertStringNotContainsString('\\', $canonical);
        $this->assertSame(rtrim(str_replace('\\', '/', realpath($this->tempDir)), '/'), $canonical);
    }

    public function test_rechaza_ruta_raiz_vacia(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La ruta raíz del repositorio biométrico no puede estar vacía.');
        $this->scanner->validarRutaRaiz('   ');
    }

    public function test_rechaza_ruta_raiz_con_bytes_nulos(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La ruta raíz contiene caracteres nulos no permitidos.');
        $this->scanner->validarRutaRaiz($this->tempDir . "\0/inyeccion");
    }

    public function test_rechaza_ruta_raiz_con_segmentos_relativos(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("La ruta raíz no puede contener segmentos relativos ('.' o '..')");
        $this->scanner->validarRutaRaiz($this->tempDir . '/../otro');
    }

    public function test_rechaza_ruta_raiz_inexistente(): void
    {
        $nonExistent = $this->tempDir . DIRECTORY_SEPARATOR . 'no_existe_' . uniqid();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La ruta raíz especificada no existe');
        $this->scanner->validarRutaRaiz($nonExistent);
    }

    public function test_rechaza_ruta_raiz_que_es_archivo_y_no_directorio(): void
    {
        $filePath = $this->tempDir . DIRECTORY_SEPARATOR . 'archivo.txt';
        file_put_contents($filePath, 'contenido');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La ruta raíz especificada no es un directorio');
        $this->scanner->validarRutaRaiz($filePath);
    }

    public function test_escanea_directorio_vacio_sin_producir_entradas(): void
    {
        $entries = iterator_to_array($this->scanner->scan($this->tempDir));
        $this->assertEmpty($entries);
    }

    public function test_escanea_archivos_en_raiz_y_subdirectorios_anidados(): void
    {
        // Crear estructura:
        // tempDir/
        // ├── FOTOS/
        // │   └── CUIP123456HDFR01.JPG
        // ├── FIRMAS/
        // │   └── CUIP123456HDFR01.PNG
        // └── raiz.jpg
        mkdir($this->tempDir . '/FOTOS', 0777, true);
        mkdir($this->tempDir . '/FIRMAS', 0777, true);

        file_put_contents($this->tempDir . '/FOTOS/CUIP123456HDFR01.JPG', 'foto_bytes');
        file_put_contents($this->tempDir . '/FIRMAS/CUIP123456HDFR01.PNG', 'firma_bytes');
        file_put_contents($this->tempDir . '/raiz.jpg', 'raiz_bytes');

        $entries = iterator_to_array($this->scanner->scan($this->tempDir));

        $this->assertCount(3, $entries);

        $relPaths = array_map(fn(BiometricScanEntry $e) => $e->rutaRelativa, $entries);
        $this->assertContains('raiz.jpg', $relPaths);
        $this->assertContains('FOTOS/CUIP123456HDFR01.JPG', $relPaths);
        $this->assertContains('FIRMAS/CUIP123456HDFR01.PNG', $relPaths);

        foreach ($entries as $entry) {
            $this->assertTrue($entry->esValido);
            $this->assertTrue($entry->esLegible);
            $this->assertTrue($entry->esArchivoRegular);
            $this->assertFalse($entry->esDirectorio);
            $this->assertGreaterThan(0, $entry->tamanoBytes);
        }
    }

    public function test_escanea_archivos_vacios_de_cero_bytes(): void
    {
        $zeroFile = $this->tempDir . '/vacio.jpg';
        file_put_contents($zeroFile, '');

        $entries = iterator_to_array($this->scanner->scan($this->tempDir));
        $this->assertCount(1, $entries);

        $entry = $entries[0];
        $this->assertSame('vacio.jpg', $entry->rutaRelativa);
        $this->assertSame(0, $entry->tamanoBytes);
        $this->assertTrue($entry->esLegible);
        $this->assertTrue($entry->esValido);
    }

    public function test_identifica_archivos_ocultos_y_archivos_de_sistema(): void
    {
        file_put_contents($this->tempDir . '/desktop.ini', '[.ShellClassInfo]');
        file_put_contents($this->tempDir . '/.oculto.jpg', 'imagen oculta');
        file_put_contents($this->tempDir . '/normal.jpg', 'imagen normal');
        file_put_contents($this->tempDir . '/notas.txt', 'texto');

        $entries = iterator_to_array($this->scanner->scan($this->tempDir));
        $this->assertCount(4, $entries);

        $byName = [];
        foreach ($entries as $e) {
            $byName[$e->nombreArchivo] = $e;
        }

        // desktop.ini es archivo de sistema
        $this->assertTrue($byName['desktop.ini']->esSistema);

        // .oculto.jpg es dot-file
        $this->assertTrue($byName['.oculto.jpg']->esOculto);

        // notas.txt es extensión de sistema/texto
        $this->assertTrue($byName['notas.txt']->esSistema);

        // normal.jpg es un archivo regular estándar
        $this->assertFalse($byName['normal.jpg']->esSistema);
        $this->assertFalse($byName['normal.jpg']->esOculto);
        $this->assertTrue($byName['normal.jpg']->esCandidatoProcesamiento());
    }

    public function test_soporta_directorios_vacios_sin_fallar(): void
    {
        mkdir($this->tempDir . '/DIR_VACIO_1', 0777, true);
        mkdir($this->tempDir . '/DIR_VACIO_2/ANIDADO_VACIO', 0777, true);
        mkdir($this->tempDir . '/DIR_CON_ARCHIVO', 0777, true);
        file_put_contents($this->tempDir . '/DIR_CON_ARCHIVO/archivo.png', 'datos');

        $entries = iterator_to_array($this->scanner->scan($this->tempDir));
        $this->assertCount(1, $entries);
        $this->assertSame('DIR_CON_ARCHIVO/archivo.png', $entries[0]->rutaRelativa);
    }

    public function test_soporta_nombres_especiales_con_espacios_y_acentos(): void
    {
        $dirEspecial = $this->tempDir . '/PULGAR DERECHO';
        mkdir($dirEspecial, 0777, true);
        file_put_contents($dirEspecial . '/CUIP CON ESPACIO 123.JPG', 'bytes');

        $entries = iterator_to_array($this->scanner->scan($this->tempDir));
        $this->assertCount(1, $entries);
        $this->assertSame('PULGAR DERECHO/CUIP CON ESPACIO 123.JPG', $entries[0]->rutaRelativa);
    }

    public function test_reanudacion_con_start_after_omite_entradas_previas(): void
    {
        file_put_contents($this->tempDir . '/01_foto.jpg', '1');
        file_put_contents($this->tempDir . '/02_foto.jpg', '2');
        file_put_contents($this->tempDir . '/03_foto.jpg', '3');
        file_put_contents($this->tempDir . '/04_foto.jpg', '4');

        // Reanudar después de '02_foto.jpg'
        $entries = iterator_to_array($this->scanner->scan($this->tempDir, '02_foto.jpg'));

        $this->assertCount(2, $entries);
        $relPaths = array_map(fn(BiometricScanEntry $e) => $e->rutaRelativa, $entries);
        $this->assertSame(['03_foto.jpg', '04_foto.jpg'], $relPaths);
    }

    public function test_comparador_uniforme_de_rutas_maneja_mayusculas_y_natural_order(): void
    {
        $rutas = ['c_3.jpg', 'a_1.jpg', 'B_2.jpg', 'Fotos/a_1.jpg', 'fotos/b_2.jpg'];
        usort($rutas, [BiometricScannerService::class, 'compararRutas']);

        // Debe ordenar a_1, B_2, c_3 independientemente del casing
        $this->assertSame('a_1.jpg', $rutas[0]);
        $this->assertSame('B_2.jpg', $rutas[1]);
        $this->assertSame('c_3.jpg', $rutas[2]);
    }

    public function test_reanudacion_con_start_after_soporta_nombres_mixtos_sin_invertir_orden(): void
    {
        file_put_contents($this->tempDir . '/a_1.jpg', '1');
        file_put_contents($this->tempDir . '/B_2.jpg', '2');
        file_put_contents($this->tempDir . '/c_3.jpg', '3');

        $entries = iterator_to_array($this->scanner->scan($this->tempDir, 'B_2.jpg'));
        $this->assertCount(1, $entries);
        $this->assertSame('c_3.jpg', $entries[0]->rutaRelativa);
        $this->assertFalse($this->scanner->huboErroresDeRecorrido());
    }
}

