<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BiometricoIncidencia;
use App\Models\CredencialEntrega;
use App\Models\CredencialEntregaDetalle;
use App\Models\CredencialImpresion;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;

/**
 * Entrega y Seguimiento, Iteración 1.3: registro de entregas físicas. Datos sintéticos; la emisión, reimpresión,
 * rectificación e incidencias pasan por las rutas reales de impresión.
 */
class EntregaRegistroTest extends TestCase
{
    use PreparaImpresionReal;
    use RefreshDatabase;

    private User $impresor;

    private User $entregador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDiscosEvidencia();
        $this->impresor = User::factory()->create(['role' => 'impresion']);
        $this->entregador = User::factory()->create(['role' => 'superadmin']);
    }

    /** Tarjeta con $cantidad elementos, cada uno con una sola impresión efectiva (procedimiento normal). */
    private function tarjetaConImpresas(int $cantidad, int $base = 20): SolicitudCredencial
    {
        $tarjeta = null;
        foreach (range(1, $cantidad) as $i) {
            $elemento = $this->elementoImprimible($base + $i, folio: sprintf('FOL-2026-%05d', $base + $i));
            $this->emitir($this->impresor, $elemento);
            if ($tarjeta === null) {
                $tarjeta = $elemento->solicitud;

                continue;
            }
            // Fixture: reúne en una sola tarjeta elementos emitidos por el flujo real.
            DB::table('solicitud_elementos')->where('id', $elemento->id)->update(['solicitud_id' => $tarjeta->id, 'numero_fila' => $i]);
            DB::table('credenciales_emitidas')->where('solicitud_elemento_id', $elemento->id)->update(['solicitud_id' => $tarjeta->id, 'numero_tarjeta' => $tarjeta->numero_tarjeta]);
        }

        return $tarjeta->refresh();
    }

    private function impresionDe(SolicitudCredencial $tarjeta, int $fila): CredencialImpresion
    {
        $elemento = SolicitudElemento::where('solicitud_id', $tarjeta->id)->where('numero_fila', $fila)->sole();

        return CredencialImpresion::where('solicitud_elemento_id', $elemento->id)->orderBy('id')->firstOrFail();
    }

    private function entregar(SolicitudCredencial $tarjeta, array $impresiones, string $receptor = 'María Pérez López', array $extra = [], ?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->entregador)->post(route('entregas.entrega.store', $tarjeta), [
            'receptor_nombre' => $receptor, 'impresiones' => $impresiones, 'clave_operacion' => (string) Str::uuid(), ...$extra,
        ]);
    }

    private function fotoEmisionImpresion(): array
    {
        return [
            DB::table('credenciales_emitidas')->orderBy('id')->get()->toArray(),
            DB::table('credencial_impresiones')->orderBy('id')->get()->toArray(),
            DB::table('credencial_impresion_rectificaciones')->orderBy('id')->get()->toArray(),
            DB::table('credencial_preparaciones')->orderBy('id')->get()->toArray(),
        ];
    }

    // ── Registro ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_flujo_completo_registra_una_entrega_con_responsable_y_fecha_del_servidor(): void
    {
        $tarjeta = $this->tarjetaConImpresas(1);
        $impresion = $this->impresionDe($tarjeta, 1);
        $antes = $this->fotoEmisionImpresion();
        $otro = User::factory()->create(['role' => 'superadmin']);
        $this->travelTo(Carbon::parse('2026-11-20 10:15:00'));

        $this->actingAs($this->entregador)->get(route('entregas.entrega.nueva', $tarjeta))->assertOk()
            ->assertSee('data-disponible="'.$impresion->id.'"', false)->assertSee('Nombre completo de quien recibe');
        $confirmacion = $this->actingAs($this->entregador)->post(route('entregas.entrega.confirmar', $tarjeta), ['receptor_nombre' => '  María   Pérez López ', 'impresiones' => [$impresion->id]])
            ->assertOk()->assertSee('Confirmar entrega')->assertSee('María Pérez López')->assertSee('FOL-2026-00021')->assertSee('data-seleccionada="'.$impresion->id.'"', false);
        $this->assertSame(0, CredencialEntrega::count(), 'La confirmación no guarda nada.');
        preg_match('/name="clave_operacion" value="([^"]+)"/', $confirmacion->getContent(), $clave);

        // Responsable y fecha enviados desde el navegador se ignoran.
        $respuesta = $this->actingAs($this->entregador)->post(route('entregas.entrega.store', $tarjeta), [
            'receptor_nombre' => 'María Pérez López', 'impresiones' => [$impresion->id], 'clave_operacion' => $clave[1],
            'entregado_por_user_id' => $otro->id, 'entregada_at' => '2020-01-01 00:00:00',
        ]);

        $entrega = CredencialEntrega::sole();
        $respuesta->assertRedirect(route('entregas.entrega.detalle', [$tarjeta, $entrega]));
        $this->assertSame('María Pérez López', $entrega->receptor_nombre);
        $this->assertSame($this->entregador->id, $entrega->entregado_por_user_id);
        $this->assertTrue($entrega->entregada_at->equalTo(Carbon::parse('2026-11-20 10:15:00')));
        $this->assertSame([$impresion->id], $entrega->detalles->pluck('credencial_impresion_id')->all());
        $this->assertEquals($antes, $this->fotoEmisionImpresion(), 'La entrega no altera emisión, impresión ni preparación.');

        // Doble envío de la misma confirmación: no duplica.
        $this->actingAs($this->entregador)->post(route('entregas.entrega.store', $tarjeta), ['receptor_nombre' => 'María Pérez López', 'impresiones' => [$impresion->id], 'clave_operacion' => $clave[1]])
            ->assertRedirect(route('entregas.entrega.detalle', [$tarjeta, $entrega]))->assertSessionHas('status', 'Esta entrega ya estaba registrada; no se duplicó.');
        $this->assertSame(1, CredencialEntrega::count());
        $this->assertSame(1, CredencialEntregaDetalle::count());

        $this->actingAs($this->entregador)->get(route('entregas.entrega.detalle', [$tarjeta, $entrega]))->assertOk()
            ->assertSee('María Pérez López')->assertSee($this->entregador->name)->assertSee('20/11/2026 10:15')
            ->assertSee('data-incluida="'.$impresion->id.'"', false)->assertDontSee('Editar')->assertDontSee('Eliminar');
    }

    public function test_entregas_parciales_sucesivas_con_varias_credenciales_y_receptores_distintos(): void
    {
        $tarjeta = $this->tarjetaConImpresas(3);
        [$a, $b, $c] = [$this->impresionDe($tarjeta, 1), $this->impresionDe($tarjeta, 2), $this->impresionDe($tarjeta, 3)];

        $this->entregar($tarjeta, [$a->id, $b->id], 'Enlace del área solicitante')->assertSessionHasNoErrors();
        $this->travel(1)->hours();
        $this->entregar($tarjeta, [$c->id], 'Titular de la fila tres')->assertSessionHasNoErrors();

        $operaciones = CredencialEntrega::orderBy('id')->with('detalles')->get();
        $this->assertSame(['Enlace del área solicitante', 'Titular de la fila tres'], $operaciones->pluck('receptor_nombre')->all());
        $this->assertSame([[$a->id, $b->id], [$c->id]], $operaciones->map(fn ($o) => $o->detalles->pluck('credencial_impresion_id')->all())->all());

        $this->actingAs($this->impresor)->get(route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => 'entregas']))->assertOk()
            ->assertViewHas('conteosEntrega', fn (array $c) => $c === ['operaciones' => 2, 'fisicas' => 3])
            ->assertViewHas('resumen', fn (array $r) => $r['entregadas'] === 3 && $r['pendientes_entrega'] === 0 && $r['total'] === 3)
            ->assertSee('Enlace del área solicitante')->assertSee('Titular de la fila tres')
            ->assertSee(route('entregas.entrega.detalle', [$tarjeta, $operaciones[0]]), false)
            // El usuario de impresión consulta, pero no ve la acción de registrar.
            ->assertDontSee(route('entregas.entrega.nueva', $tarjeta), false);
        $this->actingAs($this->entregador)->get(route('entregas.entrega.nueva', $tarjeta))->assertOk()
            ->assertSee('No hay credenciales disponibles para entregar')->assertSee('3 entregada.', false);
    }

    // ── Elegibilidad ───────────────────────────────────────────────────────────────────────────────────────

    public function test_no_se_entregan_rectificadas_fallidas_intentos_fallidos_ni_impresiones_de_otra_tarjeta(): void
    {
        $tarjeta = $this->tarjetaConImpresas(2);
        $rectificada = $this->impresionDe($tarjeta, 2);
        $this->actingAs($this->entregador)->postJson(route('impresion.impresion.rectificar', $rectificada), ['resultado' => 'fallida', 'motivo' => 'Se confirmó por error.'])->assertOk();

        $fallido = $this->elementoImprimible(40, folio: 'FOL-2026-00040');
        $preparacion = $this->prepararImpresion($this->impresor, $fallido);
        $this->verEvidencias($this->impresor, $preparacion);
        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.emision', $fallido), ['preparacion_uuid' => $preparacion->uuid, 'resultado' => 'fallida', 'motivo' => 'Atasco de la tarjeta'])->assertOk();
        $intentoFallido = CredencialImpresion::where('solicitud_elemento_id', $fallido->id)->sole();

        $otra = $this->tarjetaConImpresas(1, base: 50);
        $ajena = $this->impresionDe($otra, 1);

        $this->actingAs($this->entregador)->get(route('entregas.entrega.nueva', $tarjeta))->assertOk()
            ->assertDontSee('data-disponible="'.$rectificada->id.'"', false)
            ->assertSee('rectificada como fallida')
            ->assertDontSee('data-disponible="'.$ajena->id.'"', false);
        $this->actingAs($this->entregador)->get(route('entregas.entrega.nueva', $fallido->solicitud))->assertOk()
            ->assertDontSee('data-disponible="'.$intentoFallido->id.'"', false)->assertSee('Solo tiene intentos de impresión sin resultado efectivo');

        foreach ([[$tarjeta, $rectificada->id, 'rectificada como fallida'], [$tarjeta, $ajena->id, 'no pertenece a esta tarjeta'], [$fallido->solicitud, $intentoFallido->id, 'sin resultado efectivo'], [$tarjeta, 999999, 'no pertenece a esta tarjeta']] as [$t, $id, $motivo]) {
            $this->entregar($t, [$id])->assertRedirect(route('entregas.entrega.nueva', $t))->assertSessionHasErrors('impresiones');
            $this->assertStringContainsString($motivo, session('errors')->first('impresiones'));
        }
        $this->assertSame(0, CredencialEntrega::count());
    }

    public function test_un_detalle_invalido_revierte_toda_la_operacion_y_no_se_entrega_dos_veces(): void
    {
        $tarjeta = $this->tarjetaConImpresas(2);
        [$a, $b] = [$this->impresionDe($tarjeta, 1), $this->impresionDe($tarjeta, 2)];

        // Formulario abierto antes de que otra operación entregue $b (estado obsoleto en el navegador).
        $this->entregar($tarjeta, [$b->id], 'Primer receptor')->assertSessionHasNoErrors();
        $this->entregar($tarjeta, [$a->id, $b->id], 'Segundo receptor')->assertSessionHasErrors('impresiones');
        $this->assertStringContainsString('ya fue entregada', session('errors')->first('impresiones'));

        $this->assertSame(1, CredencialEntrega::count(), 'La operación con un detalle inválido no deja cabecera ni detalles.');
        $this->assertSame([$b->id], CredencialEntregaDetalle::pluck('credencial_impresion_id')->all());
        $this->assertNull($a->refresh()->entregaDetalle);
    }

    public function test_varias_impresiones_efectivas_son_anomalia_sin_seleccion_automatica_y_se_conserva_el_historial(): void
    {
        $elemento = $this->elementoImprimible(60, folio: 'FOL-2026-00060');
        $this->emitir($this->impresor, $elemento);
        $reimpresion = $this->prepararImpresion($this->impresor, $elemento, 'reimpresion');
        $this->verEvidencias($this->impresor, $reimpresion);
        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.reimpresion', $elemento), ['preparacion_uuid' => $reimpresion->uuid, 'resultado' => 'impresa', 'motivo' => 'Tarjeta dañada al laminar'])->assertOk();
        $impresiones = CredencialImpresion::where('solicitud_elemento_id', $elemento->id)->orderBy('id')->get();
        $this->assertCount(2, $impresiones);

        $this->actingAs($this->entregador)->get(route('entregas.entrega.nueva', $elemento->solicitud))->assertOk()
            ->assertDontSee('data-disponible=', false)
            ->assertSee('data-estado="anomalia_multiple"', false)
            ->assertSee('Requiere revisión institucional');
        foreach ($impresiones as $impresion) {
            $this->entregar($elemento->solicitud, [$impresion->id])->assertSessionHasErrors('impresiones');
        }
        $this->assertSame(0, CredencialEntrega::count());
        $this->assertSame($impresiones->pluck('id')->all(), CredencialImpresion::where('solicitud_elemento_id', $elemento->id)->orderBy('id')->pluck('id')->all());
    }

    // ── POSIBLE_TARJETA_DUPLICADA ──────────────────────────────────────────────────────────────────────────

    public function test_posible_tarjeta_duplicada_coincide_con_la_anomalia_y_tras_rectificar_solo_advierte(): void
    {
        // Mismo escenario que CredencialPreparacionTest: confirmación extemporánea de una emisión ya emitida.
        $elemento = $this->elementoImprimible(70, folio: 'FOL-2026-00070');
        $primera = $this->prepararImpresion($this->impresor, $elemento);
        $this->verEvidencias($this->impresor, $primera);
        $this->actingAs($this->impresor)->postJson(route('impresion.preparacion.cancelar', $primera), ['motivo' => 'Prueba de cancelación'])->assertOk();
        $segunda = $this->prepararImpresion($this->impresor, $elemento);
        $this->verEvidencias($this->impresor, $segunda);
        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.emision', $elemento), ['preparacion_uuid' => $segunda->uuid, 'resultado' => 'impresa'])->assertOk();
        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.emision', $elemento), ['preparacion_uuid' => $primera->uuid, 'resultado' => 'impresa', 'extemporanea' => true, 'justificacion_extemporanea' => 'Se encontró una tarjeta impresa con esta preparación.'])
            ->assertOk()->assertJson(['posible_duplicado' => true]);

        // Origen: la incidencia acompaña a una segunda impresión efectiva de la misma credencial.
        $incidencia = BiometricoIncidencia::where('codigo', 'POSIBLE_TARJETA_DUPLICADA')->sole();
        $this->assertSame($primera->id, $incidencia->credencial_preparacion_id);
        $nueva = route('entregas.entrega.nueva', $elemento->solicitud);
        $this->actingAs($this->entregador)->get($nueva)->assertSee('data-estado="anomalia_multiple"', false)->assertDontSee('data-disponible=', false);

        // El supervisor rectifica la extemporánea a fallida: queda una sola tarjeta efectiva y la incidencia abierta.
        $extemporanea = CredencialImpresion::where('credencial_preparacion_id', $primera->id)->sole();
        $this->actingAs($this->entregador)->postJson(route('impresion.impresion.rectificar', $extemporanea), ['resultado' => 'fallida', 'motivo' => 'La tarjeta encontrada se destruyó.'])->assertOk();
        $vigente = CredencialImpresion::where('credencial_preparacion_id', $segunda->id)->sole();

        // Sin regla institucional que bloquee: se advierte y la entrega ordinaria procede.
        $this->actingAs($this->entregador)->get($nueva)->assertSee('data-disponible="'.$vigente->id.'"', false)
            ->assertSee('data-advertencia-duplicado="'.$vigente->id.'"', false);
        $this->entregar($elemento->solicitud, [$vigente->id])->assertSessionHasNoErrors();
        $this->assertNull($incidencia->refresh()->resuelta_at, 'La entrega no resuelve ni modifica la incidencia.');
    }

    // ── Integridad ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_la_base_rechaza_duplicados_asociaciones_ajenas_no_efectivas_y_cambios_a_entregas(): void
    {
        $tarjeta = $this->tarjetaConImpresas(2);
        [$a, $b] = [$this->impresionDe($tarjeta, 1), $this->impresionDe($tarjeta, 2)];
        $otra = $this->tarjetaConImpresas(1, base: 80);
        $this->entregar($tarjeta, [$a->id])->assertSessionHasNoErrors();
        $entrega = CredencialEntrega::sole();
        $insertar = fn (int $impresion) => DB::table('credencial_entrega_detalles')->insert(['credencial_entrega_id' => $entrega->id, 'credencial_impresion_id' => $impresion, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([
            'misma impresión dos veces' => fn () => $insertar($a->id),
            'impresión de otra tarjeta' => fn () => $insertar($this->impresionDe($otra, 1)->id),
            'impresión inexistente' => fn () => $insertar(999999),
            'actualizar cabecera' => fn () => DB::table('credencial_entregas')->where('id', $entrega->id)->update(['receptor_nombre' => 'Otro']),
            'borrar cabecera' => fn () => DB::table('credencial_entregas')->where('id', $entrega->id)->delete(),
            'borrar detalle' => fn () => DB::table('credencial_entrega_detalles')->delete(),
            'borrar impresión entregada' => fn () => DB::table('credencial_impresiones')->where('id', $a->id)->delete(),
        ] as $caso => $operacion) {
            try {
                $operacion();
                $this->fail("La base aceptó: {$caso}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // Impresión rectificada a fallida: el trigger de validez la rechaza aunque se omita el servicio.
        $this->actingAs($this->entregador)->postJson(route('impresion.impresion.rectificar', $b), ['resultado' => 'fallida', 'motivo' => 'Se confirmó por error.'])->assertOk();
        $this->expectException(QueryException::class);
        $insertar($b->id);
    }

    public function test_el_modelo_impide_editar_o_borrar_entregas(): void
    {
        $tarjeta = $this->tarjetaConImpresas(1);
        $this->entregar($tarjeta, [$this->impresionDe($tarjeta, 1)->id])->assertSessionHasNoErrors();
        $entrega = CredencialEntrega::sole();

        foreach ([fn () => $entrega->update(['receptor_nombre' => 'Otro']), fn () => $entrega->delete(), fn () => $entrega->detalles->first()->delete()] as $operacion) {
            try {
                $operacion();
                $this->fail('El modelo permitió modificar una entrega.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('María Pérez López', $entrega->fresh()->receptor_nombre);
    }

    // ── Autorización ───────────────────────────────────────────────────────────────────────────────────────

    public function test_solo_quien_tiene_permiso_de_entrega_registra_y_el_control_es_del_servidor(): void
    {
        $tarjeta = $this->tarjetaConImpresas(1);
        $impresion = $this->impresionDe($tarjeta, 1);

        foreach (['impresion', 'credencializador', 'operador', 'consulta_general'] as $rol) {
            $usuario = User::factory()->create(['role' => $rol]);
            $this->actingAs($usuario)->get(route('entregas.entrega.nueva', $tarjeta))->assertForbidden();
            $this->actingAs($usuario)->post(route('entregas.entrega.confirmar', $tarjeta), ['receptor_nombre' => 'X Y Z', 'impresiones' => [$impresion->id]])->assertForbidden();
            $this->entregar($tarjeta, [$impresion->id], usuario: $usuario)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->post(route('entregas.entrega.store', $tarjeta), ['receptor_nombre' => 'X Y Z', 'impresiones' => [$impresion->id], 'clave_operacion' => (string) Str::uuid()])->assertRedirect(route('login'));
        $this->assertSame(0, CredencialEntrega::count());

        // Consulta de una entrega desde otra tarjeta: 404, sin revelar la operación.
        $this->entregar($tarjeta, [$impresion->id])->assertSessionHasNoErrors();
        $otra = $this->tarjetaConImpresas(1, base: 90);
        $this->actingAs($this->impresor)->get(route('entregas.entrega.detalle', [$otra, CredencialEntrega::sole()]))->assertNotFound();
    }

    public function test_validacion_del_receptor_y_de_la_seleccion(): void
    {
        $tarjeta = $this->tarjetaConImpresas(1);
        $id = $this->impresionDe($tarjeta, 1)->id;
        $confirmar = fn (array $datos) => $this->actingAs($this->entregador)->post(route('entregas.entrega.confirmar', $tarjeta), $datos);

        $confirmar(['receptor_nombre' => '   ', 'impresiones' => [$id]])->assertRedirect(route('entregas.entrega.nueva', $tarjeta))->assertSessionHasErrors('receptor_nombre');
        $confirmar(['receptor_nombre' => 'Ana Ruiz'])->assertSessionHasErrors('impresiones');
        $confirmar(['receptor_nombre' => 'Ana Ruiz', 'impresiones' => [$id, $id]])->assertSessionHasErrors('impresiones.0');
        $confirmar(['receptor_nombre' => 'Ana Ruiz', 'impresiones' => [$id], 'clave_operacion' => (string) Str::uuid()])->assertSessionHasErrors('clave_operacion');
        $this->entregar($tarjeta, [$id], extra: ['clave_operacion' => 'no-es-uuid'])->assertSessionHasErrors('clave_operacion');
        $this->assertSame(0, CredencialEntrega::count());
    }
}
