<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\EvidenciaBiometricaException;
use App\Models\BiometricoEvidencia;
use App\Services\BiometricEvidenceStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;

/**
 * Fase 1B.2 — Almacén de evidencia: publicación sin sobrescritura (link), verificación de hash,
 * cuarentena, fallos de lectura/escritura y conciliación segura. Contenido 100 % sintético.
 */
class BiometricEvidenceStoreTest extends TestCase
{
    use PreparaImpresionReal;
    use RefreshDatabase;

    private BiometricEvidenceStore $almacen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDiscosEvidencia();
        $this->almacen = app(BiometricEvidenceStore::class);
    }

    /** @return resource */
    private function flujo(string $bytes)
    {
        $flujo = fopen('php://memory', 'w+b');
        fwrite($flujo, $bytes);
        rewind($flujo);

        return $flujo;
    }

    private function publicar(string $bytes, string $etiqueta = '00000000-0000-4000-8000-000000000001-foto', ?string $sha = null, ?int $tamano = null): array
    {
        return $this->almacen->publicar($this->flujo($bytes), $sha ?? hash('sha256', $bytes), $tamano ?? strlen($bytes), $etiqueta);
    }

    private function codigoDe(callable $operacion): string
    {
        try {
            $operacion();
        } catch (EvidenciaBiometricaException $e) {
            return $e->codigo;
        }
        $this->fail('Se esperaba una EvidenciaBiometricaException.');
    }

    public function test_publicacion_inicial_por_hash_solo_lectura_y_sin_temporal(): void
    {
        $bytes = $this->pngSintetico(1);
        $publicacion = $this->publicar($bytes);
        $sha = hash('sha256', $bytes);
        $ruta = $this->almacen->raiz().'/'.$publicacion['ruta_relativa'];

        $this->assertSame(sprintf('sha256/%s/%s/%s.png', substr($sha, 0, 2), substr($sha, 2, 2), $sha), $publicacion['ruta_relativa']);
        $this->assertFalse($publicacion['duplicado']);
        $this->assertSame('image/png', $publicacion['mime_real']);
        $this->assertSame([64, 64], [$publicacion['ancho_px'], $publicacion['alto_px']]);
        $this->assertSame($sha, hash_file('sha256', $ruta));
        $this->assertFalse(is_writable($ruta), 'El archivo publicado debe quedar de solo lectura.');
        $this->assertSame([], glob($this->almacen->raiz().'/_tmp/*.part'));

        // Registrar es idempotente: una sola fila por contenido.
        $this->almacen->registrar($publicacion);
        $this->almacen->registrar($publicacion);
        $this->assertSame(1, BiometricoEvidencia::where('sha256', $sha)->count());
    }

    public function test_destino_existente_con_el_mismo_hash_es_un_duplicado_legitimo(): void
    {
        $bytes = $this->pngSintetico(2);
        $this->publicar($bytes);
        $segunda = $this->publicar($bytes, '00000000-0000-4000-8000-000000000002-foto');

        $this->assertTrue($segunda['duplicado']);
        $this->assertSame([], glob($this->almacen->raiz().'/_tmp/*.part'));
    }

    public function test_destino_existente_alterado_no_se_sobrescribe_y_va_a_cuarentena(): void
    {
        $bytes = $this->pngSintetico(3);
        $publicacion = $this->publicar($bytes);
        $ruta = $this->almacen->raiz().'/'.$publicacion['ruta_relativa'];
        chmod($ruta, 0666);
        file_put_contents($ruta, 'CONTENIDO ALTERADO');

        $codigo = $this->codigoDe(fn () => $this->publicar($bytes, '00000000-0000-4000-8000-000000000003-foto'));

        $this->assertSame('EVIDENCIA_EXISTENTE_ALTERADA', $codigo);
        $this->assertSame('CONTENIDO ALTERADO', file_get_contents($ruta), 'La copia existente no se sobrescribe.');
        $cuarentena = glob($this->almacen->raiz().'/_cuarentena/*.bin');
        $this->assertCount(1, $cuarentena);
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $cuarentena[0]));
        $this->assertSame([], glob($this->almacen->raiz().'/_tmp/*.part'));
    }

    public function test_lectura_incompleta_o_contenido_distinto_no_publica(): void
    {
        $bytes = $this->pngSintetico(4);
        // Origen truncado: el tamaño y el hash de la copia no coinciden con lo registrado.
        $codigo = $this->codigoDe(fn () => $this->almacen->publicar($this->flujo(substr($bytes, 0, 50)), hash('sha256', $bytes), strlen($bytes), '00000000-0000-4000-8000-000000000004-foto'));

        $this->assertSame('EVIDENCIA_ORIGEN_DISCREPANTE', $codigo);
        $this->assertSame([], glob($this->almacen->raiz().'/sha256/*/*/*'));
        $this->assertSame([], glob($this->almacen->raiz().'/_tmp/*.part'));
    }

    public function test_contenido_que_no_es_imagen_no_se_publica(): void
    {
        $bytes = str_repeat('NO ES IMAGEN ', 20);
        $this->assertSame('EVIDENCIA_FORMATO_INVALIDO', $this->codigoDe(fn () => $this->publicar($bytes)));
        $this->assertSame([], glob($this->almacen->raiz().'/sha256/*/*/*'));
    }

    public function test_fallo_de_escritura_del_temporal_no_publica(): void
    {
        $etiqueta = '00000000-0000-4000-8000-000000000005-foto';
        @mkdir($this->almacen->raiz().'/_tmp', 0775, true);
        file_put_contents($this->almacen->raiz()."/_tmp/{$etiqueta}.part", 'TEMPORAL DE OTRA OPERACION');

        $this->assertSame('EVIDENCIA_TEMPORAL_NO_CREADO', $this->codigoDe(fn () => $this->publicar($this->pngSintetico(5), $etiqueta)));
        // El temporal ajeno no se toca.
        $this->assertSame('TEMPORAL DE OTRA OPERACION', file_get_contents($this->almacen->raiz()."/_tmp/{$etiqueta}.part"));
    }

    public function test_recuperacion_verifica_el_hash(): void
    {
        $bytes = $this->pngSintetico(6);
        $evidencia = $this->almacen->registrar($this->publicar($bytes));
        $this->assertSame($bytes, $this->almacen->leerVerificado($evidencia));

        $ruta = $this->almacen->raiz().'/'.$evidencia->ruta_relativa;
        chmod($ruta, 0666);
        file_put_contents($ruta, 'ALTERADO');
        $this->assertSame('EVIDENCIA_HASH_DISCREPANTE', $this->codigoDe(fn () => $this->almacen->leerVerificado($evidencia)));

        unlink($ruta);
        $this->assertSame('EVIDENCIA_FALTANTE', $this->codigoDe(fn () => $this->almacen->leerVerificado($evidencia)));
    }

    public function test_conciliacion_solo_borra_temporales_abandonados_y_nunca_publicados(): void
    {
        $raiz = $this->almacen->raiz();
        @mkdir($raiz.'/_tmp', 0775, true);
        $viejo = $raiz.'/_tmp/11111111-1111-4111-8111-111111111111-foto.part';
        $reciente = $raiz.'/_tmp/22222222-2222-4222-8222-222222222222-foto.part';
        file_put_contents($viejo, 'x');
        file_put_contents($reciente, 'y');
        touch($viejo, time() - 25 * 3600);

        // Huérfano: publicado sin fila en la base de datos.
        $huerfano = $this->publicar($this->pngSintetico(7), '33333333-3333-4333-8333-333333333333-foto');

        $informe = $this->almacen->conciliar(false);
        $this->assertSame([basename($viejo)], $informe['temporales_abandonados']);
        $this->assertSame(0, $informe['temporales_eliminados']);
        $this->assertFileExists($viejo, 'Sin --aplicar no se borra nada.');
        $this->assertSame([$huerfano['ruta_relativa']], $informe['publicados_sin_registro']);

        $this->assertSame(0, Artisan::call('credenciales:conciliar-evidencias', ['--aplicar' => true]));
        $this->assertFileDoesNotExist($viejo);
        $this->assertFileExists($reciente, 'Un temporal reciente puede pertenecer a una operación en curso.');
        $this->assertFileExists($raiz.'/'.$huerfano['ruta_relativa'], 'Los archivos publicados nunca se borran.');
    }

    /** Altera el contenido conservando exactamente el mismo tamaño (solo un hash lo detecta). */
    private function alterarMismoTamano(string $ruta): void
    {
        $bytes = (string) file_get_contents($ruta);
        $bytes[strlen($bytes) - 1] = $bytes[strlen($bytes) - 1] === 'X' ? 'Y' : 'X';
        chmod($ruta, 0666);
        file_put_contents($ruta, $bytes);
    }

    public function test_modo_normal_no_calcula_hashes_y_el_integral_si(): void
    {
        $evidencia = $this->almacen->registrar($this->publicar($this->pngSintetico(8)));
        $this->alterarMismoTamano($this->almacen->raiz().'/'.$evidencia->ruta_relativa);

        // Normal: estructural; un cambio de contenido con el mismo tamaño no se detecta (no lee contenido).
        $normal = $this->almacen->conciliar(false);
        $this->assertFalse($normal['integridad_verificada']);
        $this->assertSame([], $normal['registros_con_hash_discrepante']);
        $this->assertSame([], $normal['registros_tamano_discrepante']);
        $this->assertSame(0, Artisan::call('credenciales:conciliar-evidencias'));

        // Integral: recalcula SHA-256 y detecta la alteración.
        $integral = $this->almacen->conciliar(false, 24, true);
        $this->assertTrue($integral['integridad_verificada']);
        $this->assertSame([$evidencia->id], $integral['registros_con_hash_discrepante']);
        $this->assertSame(1, Artisan::call('credenciales:conciliar-evidencias', ['--integridad' => true]));
    }

    public function test_modo_normal_detecta_archivo_inexistente_y_tamano_inconsistente(): void
    {
        $faltante = $this->almacen->registrar($this->publicar($this->pngSintetico(9)));
        $recortada = $this->almacen->registrar($this->publicar($this->pngSintetico(10), '00000000-0000-4000-8000-000000000010-foto'));
        $rutaFaltante = $this->almacen->raiz().'/'.$faltante->ruta_relativa;
        chmod($rutaFaltante, 0666);
        unlink($rutaFaltante);
        $rutaRecortada = $this->almacen->raiz().'/'.$recortada->ruta_relativa;
        chmod($rutaRecortada, 0666);
        file_put_contents($rutaRecortada, 'MAS CORTO');

        $informe = $this->almacen->conciliar(false);
        $this->assertSame([$faltante->id], $informe['registros_sin_archivo']);
        $this->assertSame([$recortada->id], $informe['registros_tamano_discrepante']);
        $this->assertSame(1, Artisan::call('credenciales:conciliar-evidencias'));
    }

    public function test_conciliacion_no_modifica_archivos_ni_registros(): void
    {
        $evidencia = $this->almacen->registrar($this->publicar($this->pngSintetico(11)));
        $ruta = $this->almacen->raiz().'/'.$evidencia->ruta_relativa;
        $antes = [hash_file('sha256', $ruta), filemtime($ruta), is_writable($ruta), BiometricoEvidencia::count(), $evidencia->refresh()->toArray()];

        Artisan::call('credenciales:conciliar-evidencias', ['--integridad' => true]);
        Artisan::call('credenciales:conciliar-evidencias', ['--aplicar' => true, '--integridad' => true]);

        clearstatcache();
        $this->assertSame($antes, [hash_file('sha256', $ruta), filemtime($ruta), is_writable($ruta), BiometricoEvidencia::count(), $evidencia->refresh()->toArray()]);
    }
}
