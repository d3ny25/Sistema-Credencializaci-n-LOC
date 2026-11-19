<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BiometricoArchivo;
use App\Models\BiometricoAuditoria;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;

/**
 * Integración del pad Topaz: la firma aceptada en la estación pasa por la ruta, la validación y el
 * servicio de actualización biométrica existentes. Imágenes 100 % sintéticas; no prueba el pad físico.
 */
class FirmaTopazCapturaTest extends TestCase
{
    use PreparaImpresionReal;
    use RefreshDatabase;

    private User $impresor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDiscosEvidencia();
        $this->impresor = User::factory()->create(['role' => 'impresion']);
    }

    /** PNG de 500×100 como el que entrega SigWeb: fondo blanco o transparente y, si se pide, un trazo negro. */
    private function pngFirma(bool $conTrazo = true, bool $transparente = false, bool $relleno = false): UploadedFile
    {
        $imagen = imagecreatetruecolor(500, 100);
        imagesavealpha($imagen, true);
        $fondo = $transparente ? imagecolorallocatealpha($imagen, 0, 0, 0, 127) : imagecolorallocate($imagen, 255, 255, 255);
        imagefill($imagen, 0, 0, $relleno ? imagecolorallocate($imagen, 0, 0, 0) : $fondo);
        if ($conTrazo && ! $relleno) {
            $tinta = imagecolorallocate($imagen, 0, 0, 0);
            imagesetthickness($imagen, 4);
            imageline($imagen, 40, 70, 180, 25, $tinta);
            imageline($imagen, 180, 25, 320, 75, $tinta);
            imageline($imagen, 320, 75, 460, 30, $tinta);
        }
        ob_start();
        imagepng($imagen);
        $bytes = (string) ob_get_clean();
        imagedestroy($imagen);

        return UploadedFile::fake()->createWithContent('firma_topaz.png', $bytes);
    }

    private function enviarFirma(User $usuario, int $elementoId, UploadedFile $archivo, ?int $elementoCaptura = null): TestResponse
    {
        $datos = [
            'tipo_biometrico' => 'firma',
            'archivo' => $archivo,
            'motivo_cambio' => 'Firma capturada en pad Topaz SigLite LCD 1x5 mediante SigWeb.',
        ];
        if ($elementoCaptura !== null) {
            $datos['elemento_captura_id'] = $elementoCaptura;
        }

        return $this->actingAs($usuario)->postJson(route('impresion.elemento.actualizar-biometrico', $elementoId), $datos);
    }

    private function firmaVigente(int $elementoId): ?int
    {
        return SolicitudElemento::findOrFail($elementoId)->expedienteBiometrico?->firma_archivo_id;
    }

    public function test_firma_en_blanco_o_transparente_se_rechaza_sin_tocar_el_expediente(): void
    {
        $elemento = $this->elementoImprimible();
        $firmaAntes = $this->firmaVigente($elemento->id);
        $auditoriasAntes = BiometricoAuditoria::where('evento', 'actualizacion_biometrica')->count();

        foreach ([$this->pngFirma(false), $this->pngFirma(false, true), $this->pngFirma(relleno: true)] as $vacia) {
            $this->enviarFirma($this->impresor, $elemento->id, $vacia, $elemento->id)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('archivo');
        }

        $this->assertSame($firmaAntes, $this->firmaVigente($elemento->id));
        $this->assertSame($auditoriasAntes, BiometricoAuditoria::where('evento', 'actualizacion_biometrica')->count());
    }

    public function test_firma_con_trazo_se_guarda_en_el_expediente_del_elemento_con_version_y_auditoria(): void
    {
        $elemento = $this->elementoImprimible();
        $firmaAntes = BiometricoArchivo::findOrFail($this->firmaVigente($elemento->id));
        $versionAntes = $firmaAntes->version_secuencia;

        $respuesta = $this->enviarFirma($this->impresor, $elemento->id, $this->pngFirma(), $elemento->id)
            ->assertOk()
            ->assertJson(['success' => true, 'categoria' => 'firma']);

        $firma = BiometricoArchivo::findOrFail($this->firmaVigente($elemento->id));
        $this->assertSame($respuesta->json('sha256'), $firma->sha256);
        $this->assertNotSame($firmaAntes->getOriginal('sha256'), $firma->sha256);
        $this->assertSame($elemento->cuip, $firma->cuip_normalizado);
        $this->assertSame($versionAntes + 1, $firma->version_secuencia);
        $this->assertDatabaseHas('biometrico_archivo_versiones', [
            'biometrico_archivo_id' => $firma->id,
            'version_secuencia' => $versionAntes + 1,
            'sha256_nuevo' => $firma->sha256,
        ]);
        $this->assertDatabaseHas('biometrico_auditorias', [
            'evento' => 'actualizacion_biometrica',
            'user_id' => $this->impresor->id,
            'biometrico_archivo_id' => $firma->id,
        ]);
    }

    public function test_firma_capturada_para_otro_elemento_se_rechaza_y_no_se_guarda_en_ninguno(): void
    {
        $destino = $this->elementoImprimible(1);
        $otro = $this->elementoImprimible(2, folio: 'FOL-2026-00002');
        $firmaDestino = $this->firmaVigente($destino->id);
        $firmaOtro = $this->firmaVigente($otro->id);

        $this->enviarFirma($this->impresor, $destino->id, $this->pngFirma(), $otro->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('elemento_captura_id');

        $this->assertSame($firmaDestino, $this->firmaVigente($destino->id));
        $this->assertSame($firmaOtro, $this->firmaVigente($otro->id));
    }

    public function test_usuario_sin_permiso_no_puede_guardar_firma(): void
    {
        $elemento = $this->elementoImprimible();
        $firmaAntes = $this->firmaVigente($elemento->id);

        $this->enviarFirma(User::factory()->create(['role' => 'consulta_general']), $elemento->id, $this->pngFirma(), $elemento->id)
            ->assertForbidden();

        $this->assertSame($firmaAntes, $this->firmaVigente($elemento->id));
    }

    public function test_credencial_emitida_conserva_el_bloqueo_biometrico_para_la_firma(): void
    {
        $elemento = $this->elementoImprimible();
        $this->emitir($this->impresor, $elemento);
        $firmaAntes = $this->firmaVigente($elemento->id);

        $this->enviarFirma($this->impresor, $elemento->id, $this->pngFirma(), $elemento->id)->assertForbidden();

        $this->assertSame($firmaAntes, $this->firmaVigente($elemento->id));
    }

    public function test_guardar_firma_cancela_la_preparacion_activa_con_el_comportamiento_existente(): void
    {
        $elemento = $this->elementoImprimible();
        $preparacion = $this->prepararImpresion($this->impresor, $elemento);
        $firmaSnapshot = $preparacion->biometricos()->where('categoria', 'firma')->value('sha256');

        $this->enviarFirma($this->impresor, $elemento->id, $this->pngFirma(), $elemento->id)->assertOk();

        $preparacion->refresh();
        $this->assertSame('cancelada', $preparacion->estado);
        $this->assertSame('actualizacion_biometrica', $preparacion->motivo_cancelacion);
        $this->assertSame($firmaSnapshot, $preparacion->biometricos()->where('categoria', 'firma')->value('sha256'));
    }

    public function test_sin_elemento_de_captura_se_mantiene_la_carga_de_firma_por_archivo(): void
    {
        $elemento = $this->elementoImprimible();

        $this->enviarFirma($this->impresor, $elemento->id, $this->pngFirma())->assertOk();
    }
}
