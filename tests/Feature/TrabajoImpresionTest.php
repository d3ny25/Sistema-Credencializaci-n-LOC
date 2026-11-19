<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BiometricoAuditoria;
use App\Models\CredencialImpresion;
use App\Models\CredencialPreparacion;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Services\TrabajoImpresionService;
use App\Support\CapturaTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;

/**
 * Envío de una preparación a la impresora Zebra y registro del estado de la cola de Windows informado por la
 * estación. No se imprime ni se consulta ninguna cola real: se prueba la autorización, la correlación y que
 * ningún estado de la cola confirme la impresión ni cambie la credencial.
 */
class TrabajoImpresionTest extends TestCase
{
    use PreparaImpresionReal;
    use RefreshDatabase;

    private const COLA = 'Zebra ZXP Series 7 USB Card Printer';

    private User $impresor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDiscosEvidencia();
        config(['devices.agente_estacion.secreto' => 'secreto-de-prueba-de-la-estacion-0123456789']);
        $this->impresor = User::factory()->create(['role' => 'impresion']);
    }

    /** @return array{0: SolicitudElemento, 1: CredencialPreparacion} */
    private function preparacionLista(): array
    {
        $elemento = $this->elementoImprimible();
        $preparacion = $this->prepararImpresion($this->impresor, $elemento);
        $this->verEvidencias($this->impresor, $preparacion);

        return [$elemento, $preparacion];
    }

    private function autorizar(CredencialPreparacion $preparacion, array $datos = [], ?User $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->impresor)->postJson(route('impresion.preparacion.trabajo', $preparacion), $datos);
    }

    private function informar(CredencialPreparacion $preparacion, string $trabajo, array $datos): TestResponse
    {
        return $this->actingAs($this->impresor)->postJson(route('impresion.preparacion.trabajo.estado', $preparacion), ['trabajo' => $trabajo, ...$datos]);
    }

    public function test_solo_quien_puede_imprimir_autoriza_un_envio(): void
    {
        [, $preparacion] = $this->preparacionLista();

        $this->autorizar($preparacion, [], User::factory()->create(['role' => 'consulta_general']))->assertForbidden();
        $this->assertSame(0, BiometricoAuditoria::where('evento', TrabajoImpresionService::EVENTO_AUTORIZADO)->count());
    }

    public function test_sin_las_cuatro_evidencias_servidas_no_se_autoriza_el_envio(): void
    {
        $elemento = $this->elementoImprimible();
        $preparacion = $this->prepararImpresion($this->impresor, $elemento);
        $this->verEvidencias($this->impresor, $preparacion, ['foto', 'firma']);

        $this->autorizar($preparacion)->assertStatus(409)->assertJson(['success' => false]);
    }

    public function test_autorizar_devuelve_documento_y_ticket_correlacionados_y_no_cambia_la_preparacion_ni_la_credencial(): void
    {
        [$elemento, $preparacion] = $this->preparacionLista();

        $respuesta = $this->autorizar($preparacion)->assertOk()->assertJson(['success' => true, 'cola' => self::COLA, 'envio' => 1]);

        $trabajo = $respuesta->json('trabajo');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $trabajo);
        $this->assertSame('LOC139-'.$trabajo, $respuesta->json('documento'));
        $this->assertSame($trabajo, CapturaTicket::nonce($respuesta->json('ticket')));
        $this->assertStringNotContainsString($elemento->cuip, $respuesta->json('documento'));

        $registro = BiometricoAuditoria::where('evento', TrabajoImpresionService::EVENTO_AUTORIZADO)->sole();
        $this->assertSame($preparacion->uuid, $registro->detalles['preparacion_uuid']);
        $this->assertSame('agente_estacion', $registro->detalles['seguimiento']);

        $this->assertSame('preparada', $preparacion->refresh()->estado);
        $this->assertSame('pendiente', $elemento->credencialEmitida->refresh()->estado_emision);
        $this->assertSame(0, CredencialImpresion::count());
    }

    public function test_sin_agente_configurado_se_autoriza_sin_ticket_ni_seguimiento(): void
    {
        config(['devices.agente_estacion.secreto' => null]);
        [, $preparacion] = $this->preparacionLista();

        $respuesta = $this->autorizar($preparacion)->assertOk();

        $this->assertNull($respuesta->json('ticket'));
        $this->assertSame('sin_agente', BiometricoAuditoria::where('evento', TrabajoImpresionService::EVENTO_AUTORIZADO)->sole()->detalles['seguimiento']);
    }

    public function test_un_reenvio_exige_motivo_para_evitar_una_tarjeta_duplicada(): void
    {
        [, $preparacion] = $this->preparacionLista();
        $primero = $this->autorizar($preparacion)->assertOk()->json('trabajo');

        $this->autorizar($preparacion)->assertStatus(409)->assertJson(['codigo' => 'trabajo_previo', 'envios_previos' => 1]);
        $this->autorizar($preparacion, ['motivo_reenvio' => 'corta'])->assertUnprocessable();

        $segundo = $this->autorizar($preparacion, ['motivo_reenvio' => 'La impresora se quedó sin cinta a media tarjeta.'])->assertOk()->assertJson(['envio' => 2]);
        $this->assertNotSame($primero, $segundo->json('trabajo'));
        $this->assertSame(
            'La impresora se quedó sin cinta a media tarjeta.',
            BiometricoAuditoria::where('evento', TrabajoImpresionService::EVENTO_AUTORIZADO)->latest('id')->first()->detalles['motivo_reenvio'],
        );
    }

    public function test_una_preparacion_cancelada_o_ya_confirmada_no_se_envia(): void
    {
        [, $cancelada] = $this->preparacionLista();
        $this->actingAs($this->impresor)->postJson(route('impresion.preparacion.cancelar', $cancelada), ['motivo' => 'Prueba de cancelación'])->assertOk();
        $this->autorizar($cancelada)->assertStatus(409);

        $otro = $this->elementoImprimible(2, folio: 'FOL-2026-00002');
        $confirmada = $this->emitir($this->impresor, $otro);
        $this->autorizar($confirmada)->assertStatus(409);
    }

    public function test_el_estado_de_la_cola_se_audita_sin_duplicados_y_nunca_confirma_la_impresion(): void
    {
        [$elemento, $preparacion] = $this->preparacionLista();
        $trabajo = $this->autorizar($preparacion)->json('trabajo');

        $this->informar($preparacion, $trabajo, ['estado' => 'en_cola', 'job_id' => 12, 'cola' => self::COLA])->assertOk()->assertJson(['registrado' => true]);
        $this->informar($preparacion, $trabajo, ['estado' => 'en_cola', 'job_id' => 12, 'cola' => self::COLA])->assertOk()->assertJson(['registrado' => false]);
        $this->informar($preparacion, $trabajo, ['estado' => 'completado_en_cola', 'job_id' => 12, 'cola' => self::COLA])->assertOk()->assertJson(['registrado' => true]);

        $estados = BiometricoAuditoria::where('evento', TrabajoImpresionService::EVENTO_ESTADO)->orderBy('id')->get();
        $this->assertSame(['en_cola', 'completado_en_cola'], $estados->map(fn ($a) => $a->detalles['estado'])->all());
        $this->assertTrue($estados->last()->detalles['cola_esperada']);
        $this->assertStringContainsString('no confirma', $estados->last()->detalles['nota']);

        // "Terminado en la cola" no es la tarjeta física: nada cambia hasta que el operador confirma.
        $this->assertSame('preparada', $preparacion->refresh()->estado);
        $this->assertSame('pendiente', $elemento->credencialEmitida->refresh()->estado_emision);
        $this->assertSame(0, CredencialImpresion::count());
    }

    public function test_un_trabajo_ajeno_o_un_estado_desconocido_se_rechaza(): void
    {
        [, $preparacion] = $this->preparacionLista();
        $otro = $this->elementoImprimible(2, folio: 'FOL-2026-00002');
        $otraPreparacion = $this->prepararImpresion($this->impresor, $otro);
        $this->verEvidencias($this->impresor, $otraPreparacion);
        $trabajoAjeno = $this->autorizar($otraPreparacion)->json('trabajo');
        $trabajo = $this->autorizar($preparacion)->json('trabajo');

        $this->informar($preparacion, $trabajoAjeno, ['estado' => 'en_cola'])->assertUnprocessable();
        $this->informar($preparacion, str_repeat('0', 32), ['estado' => 'en_cola'])->assertUnprocessable();
        $this->informar($preparacion, $trabajo, ['estado' => 'impresa'])->assertUnprocessable();
        $this->informar($preparacion, $trabajo, ['estado' => 'en_cola', 'alertas' => ['<script>']])->assertUnprocessable();
        $this->assertSame(0, BiometricoAuditoria::where('evento', TrabajoImpresionService::EVENTO_ESTADO)->count());
    }

    public function test_un_trabajo_en_otra_cola_queda_marcado_como_cola_inesperada(): void
    {
        [, $preparacion] = $this->preparacionLista();
        $trabajo = $this->autorizar($preparacion)->json('trabajo');

        $this->informar($preparacion, $trabajo, ['estado' => 'en_otra_cola', 'job_id' => 3, 'cola' => 'Microsoft Print to PDF'])->assertOk();

        $registro = BiometricoAuditoria::where('evento', TrabajoImpresionService::EVENTO_ESTADO)->sole();
        $this->assertFalse($registro->detalles['cola_esperada']);
    }

    public function test_la_confirmacion_del_operador_no_cambia_y_documenta_la_cola_observada(): void
    {
        [$elemento, $preparacion] = $this->preparacionLista();
        $this->autorizar($preparacion)->assertOk();

        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.emision', $elemento), [
            'preparacion_uuid' => $preparacion->uuid,
            'resultado' => 'impresa',
            'impresora' => self::COLA,
        ])->assertOk();

        $impresion = CredencialImpresion::sole();
        $this->assertSame(self::COLA, $impresion->impresora);
        $this->assertSame('emitida', $elemento->credencialEmitida->refresh()->estado_emision);
        // Tras la confirmación ya no se autorizan más envíos de esa preparación.
        $this->autorizar($preparacion, ['motivo_reenvio' => 'Intento posterior a la confirmación del operador.'])->assertStatus(409);
    }
}
