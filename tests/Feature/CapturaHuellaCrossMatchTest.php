<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BiometricoArchivo;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;

/**
 * Lector Cross Match por agente de estación: ticket ligado a elemento, usuario y pulgar, y guardado de la huella
 * por el flujo biométrico existente. Imágenes sintéticas; no prueba el lector ni el agente físicos.
 */
class CapturaHuellaCrossMatchTest extends TestCase
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

    /** PNG en escala de grises con crestas concéntricas, como el que entrega el agente (400 × 500). */
    private function huella(int $ancho = 400, int $alto = 500, bool $vacia = false, string $textoPng = ''): string
    {
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 250, 250, 250));
        if (! $vacia) {
            for ($r = 10; $r < min($ancho, $alto) / 2; $r += 7) {
                imageellipse($imagen, intdiv($ancho, 2), intdiv($alto, 2), $r * 2, (int) ($r * 2.4), imagecolorallocate($imagen, 40, 40, 40));
            }
        }
        ob_start();
        imagepng($imagen);
        $png = (string) ob_get_clean();
        imagedestroy($imagen);

        if ($textoPng === '') {
            return $png;
        }
        // Fragmento tEXt tras IHDR, igual que el que añade el agente en modo simulación.
        $datos = 'tEXt'.$textoPng;
        $fragmento = pack('N', strlen($textoPng)).$datos.pack('N', crc32($datos));

        return substr($png, 0, 33).$fragmento.substr($png, 33);
    }

    private function ticket(SolicitudElemento $elemento, string $categoria, ?User $usuario = null): string
    {
        return $this->actingAs($usuario ?? $this->impresor)
            ->postJson(route('impresion.elemento.captura-ticket', $elemento), ['categoria' => $categoria])
            ->assertOk()
            ->assertJson(['categoria' => $categoria])
            ->json('ticket');
    }

    private function guardar(SolicitudElemento $elemento, string $categoria, string $bytes, ?string $ticket, ?User $usuario = null): TestResponse
    {
        $datos = [
            'tipo_biometrico' => $categoria,
            'archivo' => UploadedFile::fake()->createWithContent('crossmatch.png', $bytes),
            'motivo_cambio' => 'Captura dactilar con lector Cross Match Verifier 300 LC 2.0 mediante el agente de estación.',
            'elemento_captura_id' => $elemento->id,
        ];
        if ($ticket !== null) {
            $datos['captura_ticket'] = $ticket;
        }

        return $this->actingAs($usuario ?? $this->impresor)->postJson(route('impresion.elemento.actualizar-biometrico', $elemento), $datos);
    }

    private function vigente(SolicitudElemento $elemento, string $categoria): ?int
    {
        return SolicitudElemento::findOrFail($elemento->id)->expedienteBiometrico?->getAttribute($categoria.'_archivo_id');
    }

    public function test_el_ticket_admite_solo_categorias_del_agente_y_exige_permiso(): void
    {
        $elemento = $this->elementoImprimible();

        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.captura-ticket', $elemento), ['categoria' => 'firma'])->assertStatus(422);
        $this->actingAs(User::factory()->create(['role' => 'consulta_general']))
            ->postJson(route('impresion.elemento.captura-ticket', $elemento), ['categoria' => 'pulgar_derecho'])
            ->assertForbidden();
    }

    public function test_huella_valida_se_guarda_en_su_pulgar_con_version_y_auditoria_y_el_ticket_no_se_reutiliza(): void
    {
        $elemento = $this->elementoImprimible();
        $antes = BiometricoArchivo::findOrFail($this->vigente($elemento, 'pulgar_derecho'));
        $izquierdoAntes = $this->vigente($elemento, 'pulgar_izquierdo');
        $ticket = $this->ticket($elemento, 'pulgar_derecho');

        $respuesta = $this->guardar($elemento, 'pulgar_derecho', $this->huella(), $ticket)->assertOk()->assertJson(['categoria' => 'pulgar_derecho']);

        $huella = BiometricoArchivo::findOrFail($this->vigente($elemento, 'pulgar_derecho'));
        $this->assertSame($respuesta->json('sha256'), $huella->sha256);
        $this->assertSame($elemento->cuip, $huella->cuip_normalizado);
        $this->assertSame($antes->version_secuencia + 1, $huella->version_secuencia);
        $this->assertSame($izquierdoAntes, $this->vigente($elemento, 'pulgar_izquierdo'));
        $this->assertDatabaseHas('biometrico_archivo_versiones', ['biometrico_archivo_id' => $huella->id, 'version_secuencia' => $huella->version_secuencia, 'sha256_nuevo' => $huella->sha256]);
        $this->assertDatabaseHas('biometrico_auditorias', ['evento' => 'actualizacion_biometrica', 'user_id' => $this->impresor->id, 'biometrico_archivo_id' => $huella->id]);

        $this->guardar($elemento, 'pulgar_derecho', $this->huella(420, 520), $ticket)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captura_ticket' => 'ya se utilizó']);
    }

    public function test_si_el_guardado_falla_tras_validar_el_ticket_se_libera_y_la_captura_puede_reintentarse(): void
    {
        // Folio asignado sin expediente: el servicio rechaza la asociación (regla vigente) después de que el
        // validador consumió el ticket. Antes, el reintento respondía "ya se utilizó".
        $elemento = $this->elementoImprimible(conBiometria: false);
        $ticket = $this->ticket($elemento, 'pulgar_derecho');
        $huella = $this->huella();

        $this->withoutExceptionHandling();
        try {
            $this->guardar($elemento, 'pulgar_derecho', $huella, $ticket);
            $this->fail('El guardado debió fallar en el servicio.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('inmutable', $e->getMessage());
        }
        $this->withExceptionHandling();
        $this->assertNull($this->vigente($elemento, 'pulgar_derecho'));

        // Corregida la causa (expediente ligado), la misma captura con el mismo ticket se guarda.
        $expediente = \App\Models\BiometricoExpediente::create(['cuip' => $elemento->cuip, 'ambito' => \App\Enums\BiometricoAmbito::ESTATALES, 'total_biometricos' => 0, 'es_completo' => false, 'faltantes' => ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo']]);
        $elemento->forceFill(['biometrico_expediente_id' => $expediente->id])->save();
        $this->guardar($elemento, 'pulgar_derecho', $huella, $ticket)->assertOk();
        $this->assertNotNull($this->vigente($elemento, 'pulgar_derecho'));

        // Tras un guardado exitoso el ticket queda consumido.
        $this->guardar($elemento, 'pulgar_derecho', $this->huella(420, 520), $ticket)
            ->assertUnprocessable()->assertJsonValidationErrors(['captura_ticket' => 'ya se utilizó']);
    }

    public function test_ticket_de_otro_pulgar_otro_elemento_u_otro_usuario_se_rechaza(): void
    {
        $destino = $this->elementoImprimible(1);
        $otro = $this->elementoImprimible(2, folio: 'FOL-2026-00002');
        $derechoAntes = $this->vigente($destino, 'pulgar_derecho');

        $this->guardar($destino, 'pulgar_derecho', $this->huella(), $this->ticket($destino, 'pulgar_izquierdo'))
            ->assertUnprocessable()->assertJsonValidationErrors(['captura_ticket' => 'componente biométrico']);
        $this->guardar($destino, 'pulgar_derecho', $this->huella(), $this->ticket($otro, 'pulgar_derecho'))
            ->assertUnprocessable()->assertJsonValidationErrors(['captura_ticket' => 'otro elemento']);
        $this->guardar($destino, 'pulgar_derecho', $this->huella(), $this->ticket($destino, 'pulgar_derecho', User::factory()->create(['role' => 'impresion'])))
            ->assertUnprocessable()->assertJsonValidationErrors('captura_ticket');

        $this->assertSame($derechoAntes, $this->vigente($destino, 'pulgar_derecho'));
    }

    public function test_ticket_alterado_o_expirado_se_rechaza(): void
    {
        $elemento = $this->elementoImprimible();
        [$carga, $firma] = explode('.', $this->ticket($elemento, 'pulgar_izquierdo'));

        $this->guardar($elemento, 'pulgar_izquierdo', $this->huella(), $carga.'.'.strrev($firma))
            ->assertUnprocessable()->assertJsonValidationErrors(['captura_ticket' => 'no es válido']);

        $this->travel(11)->minutes();
        $this->guardar($elemento, 'pulgar_izquierdo', $this->huella(), $carga.'.'.$firma)
            ->assertUnprocessable()->assertJsonValidationErrors(['captura_ticket' => 'expiró']);
    }

    public function test_cristal_vacio_imagen_pequena_o_danada_se_rechaza_y_conserva_el_ticket(): void
    {
        $elemento = $this->elementoImprimible();
        $ticket = $this->ticket($elemento, 'pulgar_derecho');
        $antes = $this->vigente($elemento, 'pulgar_derecho');

        foreach ([$this->huella(vacia: true), $this->huella(150, 150), substr($this->huella(), 0, 33)] as $invalida) {
            $this->guardar($elemento, 'pulgar_derecho', $invalida, $ticket)->assertUnprocessable()->assertJsonValidationErrors('archivo');
        }
        $this->assertSame($antes, $this->vigente($elemento, 'pulgar_derecho'));

        $this->guardar($elemento, 'pulgar_derecho', $this->huella(), $ticket)->assertOk();
    }

    public function test_imagen_del_modo_simulacion_nunca_se_guarda_por_ningun_canal(): void
    {
        $elemento = $this->elementoImprimible();
        $simulada = $this->huella(textoPng: "Simulacion\0agente-estacion: imagen simulada, no es una huella real");
        $antes = $this->vigente($elemento, 'pulgar_derecho');

        $this->guardar($elemento, 'pulgar_derecho', $simulada, $this->ticket($elemento, 'pulgar_derecho'))
            ->assertUnprocessable()->assertJsonValidationErrors(['archivo' => 'modo simulación']);
        // Ni siquiera como archivo importado a mano.
        $this->guardar($elemento, 'pulgar_derecho', $simulada, null)
            ->assertUnprocessable()->assertJsonValidationErrors(['archivo' => 'modo simulación']);

        $this->assertSame($antes, $this->vigente($elemento, 'pulgar_derecho'));
    }

    public function test_credencial_emitida_bloquea_ticket_y_guardado_de_huella(): void
    {
        $elemento = $this->elementoImprimible();
        $ticket = $this->ticket($elemento, 'pulgar_izquierdo');
        $this->emitir($this->impresor, $elemento);
        $antes = $this->vigente($elemento, 'pulgar_izquierdo');

        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.captura-ticket', $elemento), ['categoria' => 'pulgar_izquierdo'])->assertForbidden();
        $this->guardar($elemento, 'pulgar_izquierdo', $this->huella(), $ticket)->assertForbidden();
        $this->assertSame($antes, $this->vigente($elemento, 'pulgar_izquierdo'));
    }

    public function test_guardar_huella_cancela_la_preparacion_activa_con_el_comportamiento_existente(): void
    {
        $elemento = $this->elementoImprimible();
        $preparacion = $this->prepararImpresion($this->impresor, $elemento);

        $this->guardar($elemento, 'pulgar_derecho', $this->huella(), $this->ticket($elemento, 'pulgar_derecho'))->assertOk();

        $preparacion->refresh();
        $this->assertSame('cancelada', $preparacion->estado);
        $this->assertSame('actualizacion_biometrica', $preparacion->motivo_cancelacion);
    }
}
