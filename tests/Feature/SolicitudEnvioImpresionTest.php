<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitudEnvioImpresionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'superadmin']);
    }

    private function solicitud(string $estado = 'evaluada', array $resultados = ['Apto', 'Descartado'], string $sufijo = '001'): SolicitudCredencial
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TJ-ENVIO-'.$sufijo,
            'consecutivo_tarjeta' => (int) $sufijo,
            'numero_oficio' => 'OF-ENVIO-'.$sufijo,
            'fecha_recepcion' => now(),
            'estado_general' => $estado,
            'total_elementos' => count($resultados),
            'total_aptos' => count(array_filter($resultados, fn ($r) => $r === 'Apto')),
            'fecha_prefiltro' => $estado === 'recibida' ? null : now(),
        ]);

        foreach ($resultados as $i => $resultado) {
            SolicitudElemento::create([
                'solicitud_id' => $solicitud->id,
                'numero_fila' => $i + 1,
                'cuip' => sprintf('CUIPENVIO%s%05d', $sufijo, $i),
                'resultado_prefiltro' => $resultado,
                'estado_tramite' => $resultado === 'Apto' ? 'apto' : 'descartado',
            ]);
        }

        return $solicitud;
    }

    public function test_envio_autoriza_solicitud_evaluada_con_aptos(): void
    {
        $solicitud = $this->solicitud();

        $this->actingAs($this->user)
            ->post(route('credencializacion.enviar-impresion', $solicitud->id))
            ->assertRedirect(route('credencializacion.detalle', $solicitud->id))
            ->assertSessionHas('status');

        $this->assertSame('enviada_impresion', $solicitud->fresh()->estado_general);
    }

    public function test_envio_rechaza_solicitud_sin_aptos(): void
    {
        $solicitud = $this->solicitud('evaluada', ['Descartado', 'Descartado']);

        $this->actingAs($this->user)
            ->post(route('credencializacion.enviar-impresion', $solicitud->id))
            ->assertSessionHasErrors('envio');

        $this->assertSame('evaluada', $solicitud->fresh()->estado_general);
    }

    public function test_envio_rechaza_solicitud_sin_prefiltro(): void
    {
        $solicitud = $this->solicitud('recibida', ['Apto']);

        $this->actingAs($this->user)
            ->post(route('credencializacion.enviar-impresion', $solicitud->id))
            ->assertSessionHasErrors('envio');

        $this->assertSame('recibida', $solicitud->fresh()->estado_general);
    }

    public function test_envio_duplicado_no_altera_la_solicitud(): void
    {
        $solicitud = $this->solicitud('enviada_impresion');
        $antes = $solicitud->fresh()->updated_at;

        $this->actingAs($this->user)
            ->post(route('credencializacion.enviar-impresion', $solicitud->id))
            ->assertSessionHasErrors('envio');

        $this->assertSame('enviada_impresion', $solicitud->fresh()->estado_general);
        $this->assertEquals($antes, $solicitud->fresh()->updated_at);
    }

    public function test_envio_requiere_permiso(): void
    {
        $solicitud = $this->solicitud();
        $otro = User::factory()->create(['role' => 'operador']);

        $this->actingAs($otro)
            ->post(route('credencializacion.enviar-impresion', $solicitud->id))
            ->assertForbidden();

        $this->assertSame('evaluada', $solicitud->fresh()->estado_general);
    }

    public function test_prefiltro_bloqueado_tras_envio_conserva_estados_de_tramite(): void
    {
        $solicitud = $this->solicitud('enviada_impresion', ['Apto']);
        $solicitud->elementos()->update(['estado_tramite' => 'listo_impresion']);

        $this->actingAs($this->user)
            ->post(route('credencializacion.prefiltro', $solicitud->id))
            ->assertSessionHasErrors('prefiltro');

        // Debe fallar por el bloqueo de envío, no por fuentes faltantes.
        $this->assertStringContainsString('ya fue enviada a impresión', session('errors')->first('prefiltro'));

        $this->assertSame('enviada_impresion', $solicitud->fresh()->estado_general);
        $this->assertSame('listo_impresion', $solicitud->elementos()->first()->estado_tramite);
    }

    public function test_cola_solo_lista_tarjetas_enviadas_a_impresion(): void
    {
        $this->solicitud('evaluada', ['Apto'], '101');
        $this->solicitud('enviada_impresion', ['Apto'], '102');

        $this->actingAs($this->user)
            ->get(route('impresion'))
            ->assertOk()
            ->assertSee('TJ-ENVIO-102')
            ->assertDontSee('TJ-ENVIO-101');
    }

    public function test_cola_rechaza_tarjetas_no_autorizadas(): void
    {
        $solicitud = $this->solicitud('evaluada', ['Apto']);
        $this->actingAs($this->user);

        $this->getJson(route('impresion.tarjeta.elementos', $solicitud))->assertStatus(409);
        $this->postJson(route('impresion.tarjeta.validar-biometria', $solicitud))->assertStatus(409);

        $solicitud->update(['estado_general' => 'enviada_impresion']);
        $this->getJson(route('impresion.tarjeta.elementos', $solicitud))->assertOk();
    }

    public function test_detalle_muestra_acciones_antes_de_la_tabla(): void
    {
        $solicitud = $this->solicitud();

        $html = $this->actingAs($this->user)
            ->get(route('credencializacion.detalle', $solicitud->id))
            ->assertOk()
            ->getContent();

        $posEnviar = strpos($html, 'Enviar a impresión');
        $posPrefiltro = strpos($html, 'Reejecutar Prefiltro');
        $posTabla = strpos($html, 'Prefiltro / Motivos');
        $this->assertNotFalse($posEnviar);
        $this->assertNotFalse($posPrefiltro);
        $this->assertLessThan($posTabla, $posEnviar);
        $this->assertLessThan($posTabla, $posPrefiltro);
        $this->assertStringContainsString(route('credencializacion.enviar-impresion', $solicitud->id), $html);
        $this->assertStringNotContainsString('Fase 2.4', $html);
    }

    public function test_detalle_indica_solicitud_ya_enviada(): void
    {
        $solicitud = $this->solicitud('enviada_impresion');

        $html = $this->actingAs($this->user)
            ->get(route('credencializacion.detalle', $solicitud->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Enviada a impresión', $html);
        $this->assertStringNotContainsString('action="'.route('credencializacion.enviar-impresion', $solicitud->id).'"', $html);
    }
}
