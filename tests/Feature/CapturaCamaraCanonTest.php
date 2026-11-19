<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BiometricoArchivo;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Support\CapturaTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;

/**
 * Cámara Canon por agente de estación: ticket de un solo uso emitido por Laravel y guardado de la fotografía
 * por el flujo biométrico existente. Imágenes sintéticas; no prueba la cámara ni el agente físicos.
 */
class CapturaCamaraCanonTest extends TestCase
{
    use PreparaImpresionReal;
    use RefreshDatabase;

    private User $impresor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDiscosEvidencia();
        config(['devices.agente_estacion.secreto' => 'secreto-de-prueba-de-la-estacion-0123456789']);
        $this->impresor = User::factory()->create(['role' => 'impresion']);
    }

    /** PNG con rostro esquemático como el recorte 3:4 del Live View (528 × 704); $uniforme simula el objetivo tapado. */
    private function png(int $ancho = 528, int $alto = 704, bool $uniforme = false): string
    {
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 180, 190, 200));
        if (! $uniforme) {
            imagefilledellipse($imagen, intdiv($ancho, 2), intdiv($alto, 2), intdiv($ancho, 2), intdiv($alto, 2), imagecolorallocate($imagen, 120, 90, 70));
            imagefilledrectangle($imagen, 0, $alto - 80, $ancho, $alto, imagecolorallocate($imagen, 30, 40, 60));
        }
        ob_start();
        imagepng($imagen);
        $bytes = (string) ob_get_clean();
        imagedestroy($imagen);

        return $bytes;
    }

    private function ticket(SolicitudElemento $elemento, ?User $usuario = null): string
    {
        return $this->actingAs($usuario ?? $this->impresor)
            ->postJson(route('impresion.elemento.captura-ticket', $elemento))
            ->assertOk()
            ->json('ticket');
    }

    private function guardar(SolicitudElemento $elemento, string $bytes, ?string $ticket, ?User $usuario = null): TestResponse
    {
        $datos = [
            'tipo_biometrico' => 'foto',
            'archivo' => UploadedFile::fake()->createWithContent('canon.png', $bytes),
            'motivo_cambio' => 'Fotografía facial capturada con cámara Canon EOS mediante el agente de estación.',
            'elemento_captura_id' => $elemento->id,
        ];
        if ($ticket !== null) {
            $datos['captura_ticket'] = $ticket;
        }

        return $this->actingAs($usuario ?? $this->impresor)->postJson(route('impresion.elemento.actualizar-biometrico', $elemento), $datos);
    }

    private function fotoVigente(SolicitudElemento $elemento): ?int
    {
        return SolicitudElemento::findOrFail($elemento->id)->expedienteBiometrico?->foto_archivo_id;
    }

    public function test_el_ticket_exige_permiso_de_actualizacion_biometrica(): void
    {
        $elemento = $this->elementoImprimible();

        $this->actingAs(User::factory()->create(['role' => 'consulta_general']))
            ->postJson(route('impresion.elemento.captura-ticket', $elemento))
            ->assertForbidden();
    }

    public function test_sin_secreto_de_estacion_el_servidor_informa_agente_no_configurado(): void
    {
        config(['devices.agente_estacion.secreto' => null]);

        $this->actingAs($this->impresor)
            ->postJson(route('impresion.elemento.captura-ticket', $this->elementoImprimible()))
            ->assertStatus(503)
            ->assertJson(['success' => false, 'codigo' => 'agente_no_configurado']);
    }

    public function test_fotografia_canon_valida_se_guarda_con_version_y_auditoria_y_el_ticket_no_se_reutiliza(): void
    {
        $elemento = $this->elementoImprimible();
        $antes = BiometricoArchivo::findOrFail($this->fotoVigente($elemento));
        $versionAntes = $antes->version_secuencia;
        $ticket = $this->ticket($elemento);

        $respuesta = $this->guardar($elemento, $this->png(), $ticket)->assertOk()->assertJson(['success' => true, 'categoria' => 'foto']);

        $foto = BiometricoArchivo::findOrFail($this->fotoVigente($elemento));
        $this->assertSame($respuesta->json('sha256'), $foto->sha256);
        $this->assertSame($elemento->cuip, $foto->cuip_normalizado);
        $this->assertSame($versionAntes + 1, $foto->version_secuencia);
        $this->assertDatabaseHas('biometrico_archivo_versiones', ['biometrico_archivo_id' => $foto->id, 'version_secuencia' => $versionAntes + 1, 'sha256_nuevo' => $foto->sha256]);
        $this->assertDatabaseHas('biometrico_auditorias', ['evento' => 'actualizacion_biometrica', 'user_id' => $this->impresor->id, 'biometrico_archivo_id' => $foto->id]);

        $this->guardar($elemento, $this->png(560, 746), $ticket)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captura_ticket' => 'ya se utilizó']);
        $this->assertSame($versionAntes + 1, $foto->refresh()->version_secuencia);
    }

    public function test_ticket_emitido_para_otro_elemento_o_usuario_se_rechaza_sin_guardar(): void
    {
        $destino = $this->elementoImprimible(1);
        $otro = $this->elementoImprimible(2, folio: 'FOL-2026-00002');
        $fotoDestino = $this->fotoVigente($destino);

        $this->guardar($destino, $this->png(), $this->ticket($otro))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captura_ticket' => 'otro elemento']);

        $otroImpresor = User::factory()->create(['role' => 'impresion']);
        $this->guardar($destino, $this->png(), $this->ticket($destino, $otroImpresor))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('captura_ticket');

        $this->assertSame($fotoDestino, $this->fotoVigente($destino));
    }

    public function test_ticket_alterado_o_expirado_se_rechaza(): void
    {
        $elemento = $this->elementoImprimible();
        [$carga, $firma] = explode('.', $this->ticket($elemento));

        $alterado = rtrim(strtr(base64_encode((string) json_encode(['v' => 1, 'n' => 'x', 'e' => $elemento->id, 'u' => $this->impresor->id, 'c' => 'foto', 'x' => time() + 9999])), '+/', '-_'), '=');
        $this->guardar($elemento, $this->png(), $alterado.'.'.$firma)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captura_ticket' => 'no es válido']);

        $vigente = $carga.'.'.$firma;
        $this->travel(11)->minutes();
        $this->guardar($elemento, $this->png(), $vigente)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captura_ticket' => 'expiró']);
    }

    public function test_imagen_vacia_uniforme_pequena_o_danada_se_rechaza_y_conserva_el_ticket(): void
    {
        $elemento = $this->elementoImprimible();
        $ticket = $this->ticket($elemento);
        $fotoAntes = $this->fotoVigente($elemento);

        $real = $this->png();
        // Firma PNG + cabecera IHDR (33 bytes): las dimensiones se leen, pero no hay datos de imagen.
        $danada = substr($real, 0, 33);

        foreach ([$this->png(uniforme: true), $this->png(200, 266), $danada] as $invalida) {
            $this->guardar($elemento, $invalida, $ticket)->assertUnprocessable()->assertJsonValidationErrors('archivo');
        }
        $this->guardar($elemento, '', $ticket)->assertUnprocessable()->assertJsonValidationErrors('archivo');
        $this->assertSame($fotoAntes, $this->fotoVigente($elemento));

        // El ticket no se consumió con las imágenes rechazadas.
        $this->guardar($elemento, $real, $ticket)->assertOk();
    }

    public function test_captura_jpeg_que_el_servidor_no_puede_decodificar_se_rechaza(): void
    {
        if ((imagetypes() & IMG_JPG) !== 0) {
            $this->markTestSkipped('El GD de este entorno sí decodifica JPEG.');
        }
        $elemento = $this->elementoImprimible();
        // Cabecera JPEG mínima (SOI + SOF0 de 600 × 800): getimagesize la reconoce, pero GD no puede verificarla.
        $jpeg = "\xFF\xD8\xFF\xC0\x00\x11\x08\x03\x20\x02\x58\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01\xFF\xD9";

        $this->guardar($elemento, $jpeg, $this->ticket($elemento))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['archivo' => 'no puede verificar']);
    }

    public function test_credencial_emitida_bloquea_ticket_y_guardado(): void
    {
        $elemento = $this->elementoImprimible();
        $ticket = $this->ticket($elemento);
        $this->emitir($this->impresor, $elemento);
        $fotoAntes = $this->fotoVigente($elemento);

        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.captura-ticket', $elemento))->assertForbidden();
        $this->guardar($elemento, $this->png(), $ticket)->assertForbidden();
        $this->assertSame($fotoAntes, $this->fotoVigente($elemento));
    }

    public function test_sin_ticket_la_carga_de_fotografia_existente_no_cambia(): void
    {
        $elemento = $this->elementoImprimible();

        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.actualizar-biometrico', $elemento), [
            'tipo_biometrico' => 'foto',
            'archivo' => UploadedFile::fake()->createWithContent('foto.png', $this->pngSintetico(7001)),
            'motivo_cambio' => 'Carga de fotografía facial institucional.',
        ])->assertOk();
    }

    public function test_formato_del_ticket_es_el_que_verifica_el_agente(): void
    {
        $ticket = CapturaTicket::emitir($this->elementoImprimible(), $this->impresor, 'foto');
        [$carga, $firma] = explode('.', $ticket);
        $datos = json_decode(base64_decode(strtr($carga, '-_', '+/')), true);

        $this->assertSame(['v', 'n', 'e', 'u', 'c', 'x'], array_keys($datos));
        $this->assertSame(32, strlen($datos['n']));
        $this->assertSame(
            rtrim(strtr(base64_encode(hash_hmac('sha256', $carga, 'secreto-de-prueba-de-la-estacion-0123456789', true)), '+/', '-_'), '='),
            $firma,
        );
    }
}
