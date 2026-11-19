<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EstadoEmision;
use App\Http\Controllers\EntregaSeguimientoController;
use App\Models\BiometricoAuditoria;
use App\Models\CredencialEmitida;
use App\Models\CredencialImpresion;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;

/**
 * Entrega y Seguimiento: listado y panel (1.1), bandejas de credenciales recibidas y pendientes (1.2). Solo lectura.
 * Datos sintéticos; la emisión, reimpresión y rectificación pasan por las rutas reales de impresión.
 */
class EntregaSeguimientoTest extends TestCase
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

    /**
     * Tarjeta TJ-EMISION-1 con seis elementos: uno emitido y reimpreso (dos impresiones vigentes), uno pendiente de
     * biométricos, uno descartado, uno sin prefiltro, uno apto sin validación biométrica y uno pendiente de impresión.
     */
    private function tarjetaMixta(): SolicitudCredencial
    {
        $impreso = $this->elementoImprimible(1);
        $this->emitir($this->impresor, $impreso);
        $reimpresion = $this->prepararImpresion($this->impresor, $impreso, 'reimpresion');
        $this->verEvidencias($this->impresor, $reimpresion);
        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.reimpresion', $impreso), [
            'preparacion_uuid' => $reimpresion->uuid, 'resultado' => 'impresa', 'motivo' => 'Tarjeta dañada al laminar',
        ])->assertOk();

        $solicitud = $impreso->solicitud;
        $nuevo = fn (int $fila, ?string $prefiltro, string $tramite) => SolicitudElemento::create([
            'solicitud_id' => $solicitud->id, 'numero_fila' => $fila, 'cuip' => sprintf('CUIPSEGUIM%08d', $fila),
            'resultado_prefiltro' => $prefiltro, 'estado_tramite' => $tramite,
        ]);
        $nuevo(2, 'Apto', 'pendiente_biometrico');
        $nuevo(3, 'Descartado', 'descartado');
        $nuevo(4, null, 'recibido');
        $nuevo(5, 'Apto', 'apto');
        $pendienteImpresion = $nuevo(6, 'Apto', 'listo_impresion');
        CredencialEmitida::create([
            'solicitud_id' => $solicitud->id, 'solicitud_elemento_id' => $pendienteImpresion->id, 'folio' => 'FOL-2026-00006',
            'numero_tarjeta' => $solicitud->numero_tarjeta, 'estado_emision' => EstadoEmision::PENDIENTE->value,
        ]);

        return $solicitud->refresh();
    }

    private function panel(SolicitudCredencial $tarjeta, string $seccion = 'panel', array $query = [])
    {
        return $this->actingAs($this->impresor)->get(route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => $seccion, ...$query]));
    }

    public function test_el_modulo_exige_autenticacion(): void
    {
        $tarjeta = $this->elementoImprimible()->solicitud;

        $this->get(route('entregas'))->assertRedirect(route('login'));
        $this->get(route('entregas.tarjeta', $tarjeta->id))->assertRedirect(route('login'));
    }

    public function test_solo_quien_consulta_tarjetas_en_credencializacion_o_impresion_accede(): void
    {
        $tarjeta = $this->elementoImprimible()->solicitud;
        $sinPermiso = User::factory()->create(['role' => 'consulta_general']);

        $this->actingAs($sinPermiso)->get(route('entregas'))->assertForbidden();
        $this->actingAs($sinPermiso)->get(route('entregas.tarjeta', $tarjeta->id))->assertForbidden();
        // La URL directa a una sección tampoco evade el gate, y el acceso de demostración queda fuera.
        $this->actingAs($sinPermiso)->get(route('entregas.tarjeta', [$tarjeta->id, 'historial']))->assertForbidden();
        $demo = (new User)->forceFill(['id' => 0, 'name' => 'Demo', 'role' => 'local_demo', 'local_demo_access' => true]);
        $this->actingAs($demo)->get(route('entregas.tarjeta', $tarjeta->id))->assertForbidden();

        foreach (['impresion', 'credencializador', 'operador', 'superadmin'] as $rol) {
            $this->actingAs(User::factory()->create(['role' => $rol]))->get(route('entregas'))->assertOk()->assertSee('Números de tarjeta');
        }
    }

    public function test_el_menu_solo_muestra_el_modulo_a_usuarios_autorizados(): void
    {
        $this->actingAs($this->impresor)->get(route('entregas'))->assertSee('Entrega y seguimiento');
        $this->actingAs(User::factory()->create(['role' => 'credencializador']))
            ->get(route('credencializacion'))->assertSee(route('entregas'), false);
        $this->assertFalse(User::factory()->create(['role' => 'consulta_general'])->can('access-entregas'));
    }

    public function test_el_listado_agrupa_por_tarjeta_con_totales_por_elemento_y_no_por_intento(): void
    {
        $mixta = $this->tarjetaMixta();
        $otra = $this->elementoImprimible(2, folio: 'FOL-2026-00002')->solicitud;

        $respuesta = $this->actingAs($this->impresor)->get(route('entregas'))->assertOk();

        $respuesta->assertViewHas('tarjetas', function ($tarjetas) use ($mixta, $otra): bool {
            $fila = $tarjetas->firstWhere('id', $mixta->id);
            $sola = $tarjetas->firstWhere('id', $otra->id);

            return $tarjetas->total() === 2
                && $fila->resumen['total'] === 6
                && $fila->resumen['impresas'] === 1
                && $fila->resumen['pendientes_biometrico'] === 1
                && $sola->resumen['total'] === 1
                && $sola->resumen['impresas'] === 0;
        });
        $this->assertSame(2, CredencialImpresion::count(), 'El elemento impreso tiene dos intentos vigentes.');
        $respuesta->assertSee('TJ-EMISION-1')->assertSee('TJ-EMISION-2');
    }

    public function test_la_busqueda_por_numero_de_tarjeta_no_mezcla_expedientes(): void
    {
        $this->tarjetaMixta();
        $otra = $this->elementoImprimible(2, folio: 'FOL-2026-00002')->solicitud;

        $respuesta = $this->actingAs($this->impresor)->get(route('entregas', ['q' => 'EMISION-2']))->assertOk();

        $respuesta->assertViewHas('tarjetas', fn ($t) => $t->total() === 1 && $t->first()->id === $otra->id && $t->first()->resumen['total'] === 1);
        $respuesta->assertDontSee('TJ-EMISION-1');

        $this->actingAs($this->impresor)->get(route('entregas', ['q' => 'NO-EXISTE']))->assertOk()->assertSee('Ningún número de tarjeta coincide');
        $this->actingAs($this->impresor)->from(route('entregas'))->get(route('entregas', ['estado' => 'inventado']))->assertSessionHasErrors('estado');
    }

    public function test_el_panel_cuenta_cada_elemento_una_vez_y_no_inventa_entregas(): void
    {
        $tarjeta = $this->tarjetaMixta();

        $this->panel($tarjeta)->assertOk()->assertViewHas('resumen', fn (array $r): bool => $r['total'] === 6
            && $r['aptos'] === 4
            && $r['impresas'] === 1
            && $r['pendientes_biometrico'] === 1
            && $r['pendientes_impresion'] === 1
            && $r['sin_validacion_biometrica'] === 1
            && $r['descartados'] === 1
            && $r['sin_prefiltro'] === 1
            // El elemento impreso tiene emisión y reimpresión efectivas: anomalía, no disponible para entrega.
            && $r['pendientes_entrega'] === 0
            && $r['anomalias_entrega'] === 1
            && $r['entregadas'] === 0
            && $r['retenidas'] === null)
            ->assertSee('No disponible');
    }

    public function test_una_impresion_rectificada_a_fallida_no_cuenta_como_impresa(): void
    {
        $elemento = $this->elementoImprimible(3, folio: 'FOL-2026-00003');
        $preparacion = $this->emitir($this->impresor, $elemento);
        $impresion = CredencialImpresion::where('credencial_preparacion_id', $preparacion->id)->sole();
        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->postJson(route('impresion.impresion.rectificar', $impresion), ['resultado' => 'fallida', 'motivo' => 'Se confirmó por error.'])
            ->assertOk();
        $this->assertSame('emitida', $elemento->credencialEmitida->refresh()->estado_emision);
        // Misma regla con las rectificaciones cargadas (listados) y consultadas al momento.
        $this->assertSame('fallida', $impresion->fresh()->resultadoVigente());
        $this->assertSame('fallida', $impresion->fresh()->load('rectificaciones')->resultadoVigente());

        $this->panel($elemento->solicitud)->assertOk()
            ->assertViewHas('resumen', fn (array $r): bool => $r['impresas'] === 0 && $r['emitidas_sin_impresion_vigente'] === 1 && $r['pendientes_impresion'] === 1)
            ->assertSee('rectificada como fallida');
    }

    public function test_con_varias_rectificaciones_rige_la_mas_reciente_con_o_sin_relacion_cargada(): void
    {
        $elemento = $this->elementoImprimible(3, folio: 'FOL-2026-00003');
        $preparacion = $this->emitir($this->impresor, $elemento);
        $impresion = CredencialImpresion::where('credencial_preparacion_id', $preparacion->id)->sole();
        $supervisor = User::factory()->create(['role' => 'superadmin']);
        foreach (['fallida', 'impresa'] as $resultado) {
            $this->actingAs($supervisor)
                ->postJson(route('impresion.impresion.rectificar', $impresion), ['resultado' => $resultado, 'motivo' => 'Corrección documentada.'])
                ->assertOk();
        }

        $this->assertSame('impresa', $impresion->fresh()->resultadoVigente());
        $this->assertSame('impresa', $impresion->fresh()->load('rectificaciones')->resultadoVigente());
        $this->panel($elemento->solicitud)->assertOk()
            ->assertViewHas('resumen', fn (array $r): bool => $r['impresas'] === 1 && $r['emitidas_sin_impresion_vigente'] === 0);
    }

    public function test_los_pendientes_de_biometricos_siguen_en_el_seguimiento_de_la_tarjeta(): void
    {
        $tarjeta = $this->tarjetaMixta();

        $this->panel($tarjeta, query: ['etapa' => 'pendiente_biometrico'])->assertOk()
            ->assertViewHas('elementos', fn ($e) => $e->total() === 1 && $e->first()['elemento']->cuip === 'CUIPSEGUIM00000002')
            ->assertSee('CUIPSEGUIM00000002')
            ->assertSee('Pendiente de biométricos');

        $this->panel($tarjeta)->assertViewHas('elementos', fn ($e) => $e->total() === 6);
    }

    public function test_la_cronologia_usa_fechas_reales_y_distingue_lo_no_registrado_de_lo_no_implementado(): void
    {
        $tarjeta = $this->tarjetaMixta();

        $this->panel($tarjeta)->assertOk()->assertViewHas('cronologia', function (array $c) use ($tarjeta): bool {
            $porClave = collect($c)->keyBy('clave');
            $impresion = CredencialImpresion::orderBy('id')->first();

            return $porClave['registro']['estado'] === 'completada'
                && $porClave['registro']['desde']->equalTo($tarjeta->created_at)
                && $porClave['prefiltro']['estado'] === 'completada'
                && $porClave['autorizacion']['estado'] === 'sin_registro'
                && $porClave['autorizacion']['desde'] === null
                && $porClave['impresion']['estado'] === 'en_curso'
                && $porClave['impresion']['desde']->equalTo($impresion->created_at)
                && $porClave['seguimiento']['estado'] === 'no_implementada'
                && $porClave['loc']['estado'] === 'no_implementada'
                && $porClave['entregas']['estado'] === 'pendiente'
                && $porClave['entregas']['desde'] === null;
        })->assertSee('Sin registro histórico')->assertSee('No implementada');
    }

    public function test_la_navegacion_conserva_el_contexto_y_las_secciones_pendientes_no_operan(): void
    {
        $tarjeta = $this->tarjetaMixta();

        $panel = $this->panel($tarjeta)->assertOk()->assertSee('Tarjeta TJ-EMISION-1')->assertSee(route('entregas'), false);
        foreach (['credenciales-recibidas', 'pendientes', 'entregas', 'retenidas', 'expediente-documental', 'conciliacion-loc', 'historial'] as $seccion) {
            $panel->assertSee(route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => $seccion]), false);

            $pagina = $this->panel($tarjeta, $seccion)->assertOk()
                ->assertSee('Tarjeta TJ-EMISION-1')
                // Las pestañas siguen apuntando a la misma tarjeta desde cualquier sección.
                ->assertSee(route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => 'panel']), false);
            if ($seccion === 'entregas') {
                $pagina->assertSee('Operaciones de entrega')->assertDontSee('data-seccion-pendiente', false);
            } elseif ($seccion === 'expediente-documental') {
                $pagina->assertSee('data-proposito="expediente-documental"', false)->assertDontSee('data-seccion-pendiente', false);
            } elseif (in_array($seccion, ['credenciales-recibidas', 'pendientes'], true)) {
                $pagina->assertSee('data-proposito="'.$seccion.'"', false)->assertDontSee('data-seccion-pendiente', false);
            } else {
                $pagina->assertSee('data-seccion-pendiente="'.$seccion.'"', false)->assertSee('Esta sección aún no está disponible.');
            }
            // El único formulario POST de la página es el de cerrar sesión del layout: la sección no opera nada.
            preg_match_all('/<form\b[^>]*method="POST"[^>]*>/i', $pagina->getContent(), $formularios);
            foreach ($formularios[0] as $formulario) {
                $this->assertStringContainsString('action="'.route('logout').'"', $formulario);
            }
        }

        $this->panel($tarjeta, 'confirmaciones')->assertNotFound();
        $this->actingAs($this->impresor)->get('/entregas/tarjetas/999999')->assertNotFound();
    }

    public function test_consultar_el_modulo_no_modifica_emision_impresion_ni_auditoria(): void
    {
        $tarjeta = $this->tarjetaMixta();
        $foto = fn () => [
            DB::table('credenciales_emitidas')->orderBy('id')->get()->toArray(),
            DB::table('credencial_impresiones')->orderBy('id')->get()->toArray(),
            DB::table('credencial_preparaciones')->orderBy('id')->get()->toArray(),
            DB::table('credencial_impresion_rectificaciones')->orderBy('id')->get()->toArray(),
            DB::table('biometrico_incidencias')->orderBy('id')->get()->toArray(),
            DB::table('solicitud_elementos')->orderBy('id')->get()->toArray(),
            DB::table('solicitudes')->orderBy('id')->get()->toArray(),
            BiometricoAuditoria::count(),
        ];
        $antes = $foto();

        $this->travel(5)->minutes();
        $this->actingAs($this->impresor)->get(route('entregas'))->assertOk();
        $this->actingAs($this->impresor)->get(route('entregas', ['q' => 'TJ']))->assertOk();
        foreach (array_keys(EntregaSeguimientoController::SECCIONES) as $seccion) {
            $this->panel($tarjeta, $seccion)->assertOk();
        }
        $this->panel($tarjeta, 'credenciales-recibidas', ['q' => 'CUIP', 'filtro' => 'reimpresion'])->assertOk();
        foreach (['biometricos', 'impresion', 'loc'] as $categoria) {
            $this->panel($tarjeta, 'pendientes', ['filtro' => $categoria])->assertOk();
        }

        $this->assertEquals($antes, $foto());
    }

    // ── Iteración 1.2: Credenciales recibidas ──────────────────────────────────────────────────────────────

    public function test_credenciales_recibidas_lista_solo_elementos_con_impresion_efectiva_una_vez_con_sus_intentos(): void
    {
        $tarjeta = $this->tarjetaMixta();
        $impresiones = CredencialImpresion::orderBy('id')->pluck('id');

        $this->panel($tarjeta, 'credenciales-recibidas')->assertOk()
            ->assertViewHas('filas', fn ($f) => $f->total() === 1 && $f->first()['elemento']->cuip === 'CUIPEMISION0000001'
                && $f->first()['efectivas'] === 2 && $f->first()['con_reimpresion'] === true)
            ->assertViewHas('conteos', fn (array $c) => $c === ['elementos' => 1, 'impresiones_fisicas' => 2, 'intentos' => 2, 'multiples' => 1])
            // Cada tarjeta física conserva su identificador de registro.
            ->assertSee('data-impresion="'.$impresiones[0].'"', false)
            ->assertSee('data-impresion="'.$impresiones[1].'"', false)
            ->assertSee('Reimpresión histórica')
            ->assertSee('Varias impresiones efectivas')
            ->assertDontSee('CUIPSEGUIM00000002')
            ->assertDontSee('FOL-2026-00006')
            ->assertSee('no significa que la credencial se haya entregado', false);
    }

    public function test_una_impresion_rectificada_a_fallida_sale_de_recibidas_y_queda_pendiente_de_impresion(): void
    {
        $elemento = $this->elementoImprimible(3, folio: 'FOL-2026-00003');
        $preparacion = $this->emitir($this->impresor, $elemento);
        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->postJson(route('impresion.impresion.rectificar', CredencialImpresion::where('credencial_preparacion_id', $preparacion->id)->sole()), ['resultado' => 'fallida', 'motivo' => 'Se confirmó por error.'])
            ->assertOk();

        $this->panel($elemento->solicitud, 'credenciales-recibidas')->assertOk()
            ->assertViewHas('filas', fn ($f) => $f->total() === 0)
            ->assertSee('aún no tiene credenciales con impresión efectiva');
        $this->panel($elemento->solicitud, 'pendientes')->assertOk()
            ->assertViewHas('filas', fn ($f) => $f->total() === 1 && $f->first()['categoria'] === 'impresion')
            ->assertSee('rectificada como fallida');
    }

    public function test_las_bandejas_se_limitan_a_la_tarjeta_y_su_busqueda_no_alcanza_otros_expedientes(): void
    {
        $tarjeta = $this->tarjetaMixta();
        $otra = $this->elementoImprimible(2, folio: 'FOL-2026-00002');
        $this->emitir($this->impresor, $otra);

        $this->panel($tarjeta, 'credenciales-recibidas')->assertViewHas('filas', fn ($f) => $f->total() === 1)->assertDontSee('CUIPEMISION0000002');
        $this->panel($tarjeta, 'pendientes')->assertDontSee('CUIPEMISION0000002');
        $this->panel($tarjeta, 'credenciales-recibidas', ['q' => 'CUIPEMISION0000002'])
            ->assertViewHas('filas', fn ($f) => $f->total() === 0)->assertSee('Ninguna credencial impresa de esta tarjeta coincide');
        $this->panel($otra->solicitud, 'credenciales-recibidas', ['q' => 'cuipemision0000002'])
            ->assertViewHas('filas', fn ($f) => $f->total() === 1);
        $this->panel($tarjeta, 'credenciales-recibidas', ['filtro' => 'no-existe'])->assertSessionHasErrors('filtro');
    }

    // ── Iteración 1.2: Pendientes ──────────────────────────────────────────────────────────────────────────

    public function test_pendientes_clasifica_por_categoria_sin_descartados_ni_conteo_duplicado(): void
    {
        $tarjeta = $this->tarjetaMixta();

        $respuesta = $this->panel($tarjeta, 'pendientes')->assertOk()
            ->assertViewHas('conteos', fn (array $c) => $c == ['loc' => 1, 'biometricos' => 2, 'impresion' => 1]);
        $filas = $respuesta->viewData('filas');
        $porCuip = collect($filas->items())->mapWithKeys(fn (array $f) => [$f['elemento']->cuip => $f['categoria']]);

        $this->assertSame([
            'CUIPEMISION0000001' => 'loc',            // impreso: también aparece en Credenciales recibidas
            'CUIPSEGUIM00000002' => 'biometricos',    // validación 4/4 incompleta
            'CUIPSEGUIM00000005' => 'biometricos',    // apto sin validación ejecutada
            'CUIPSEGUIM00000006' => 'impresion',      // folio sin impresión efectiva
        ], $porCuip->all());
        // Cada elemento una sola vez; descartado (3) y sin prefiltro (4) no se listan.
        $this->assertSame($filas->total(), $porCuip->count());
        $respuesta->assertDontSee('CUIPSEGUIM00000003')->assertDontSee('CUIPSEGUIM00000004')
            ->assertSee('No se listan 1 elemento(s) sin prefiltro ni 1 descartado(s)');
    }

    public function test_la_categoria_loc_no_presenta_validaciones_ni_retenciones_inventadas(): void
    {
        $tarjeta = $this->tarjetaMixta();

        $this->panel($tarjeta, 'pendientes', ['filtro' => 'loc'])->assertOk()
            ->assertViewHas('filas', fn ($f) => $f->total() === 1 && $f->first()['fecha_etiqueta'] === 'Impresión confirmada')
            ->assertSee('la conciliación LOC aún no está implementada')
            ->assertSee('No indica discrepancia, retención ni revisión del archivo LOC')
            ->assertDontSee('Conciliada')
            ->assertDontSee('Validado');

        // Sin impresiones, la categoría LOC explica por qué está vacía.
        $this->panel($this->elementoImprimible(7, folio: 'FOL-2026-00007')->solicitud, 'pendientes', ['filtro' => 'loc'])
            ->assertViewHas('filas', fn ($f) => $f->total() === 0)
            ->assertSee('la categoría LOC solo aplica a credenciales impresas');
    }

    public function test_pendientes_pagina_dentro_de_la_tarjeta_y_presenta_estados_vacios(): void
    {
        $tarjeta = $this->elementoImprimible(8, estadoTramite: 'pendiente_biometrico', folio: null, conBiometria: false)->solicitud;
        foreach (range(2, 30) as $fila) {
            SolicitudElemento::create(['solicitud_id' => $tarjeta->id, 'numero_fila' => $fila, 'cuip' => sprintf('CUIPPAGINA%08d', $fila), 'resultado_prefiltro' => 'Apto', 'estado_tramite' => 'pendiente_biometrico']);
        }

        $this->panel($tarjeta, 'pendientes')->assertViewHas('filas', fn ($f) => $f->total() === 30 && $f->count() === 25)
            ->assertSee('pagina=2', false);
        $this->panel($tarjeta, 'pendientes', ['pagina' => 2])->assertViewHas('filas', fn ($f) => $f->count() === 5 && $f->first()['elemento']->numero_fila === 26);

        $descartado = $this->elementoImprimible(9, estadoSolicitud: 'evaluada', estadoTramite: 'descartado', folio: null, conBiometria: false);
        $descartado->forceFill(['resultado_prefiltro' => 'Descartado'])->save();
        $vacia = $descartado->solicitud;
        $this->panel($vacia, 'pendientes')->assertOk()->assertSee('no tiene elementos pendientes de biométricos, de impresión ni de conciliación LOC');
        $this->panel($vacia, 'credenciales-recibidas')->assertOk()->assertSee('aún no tiene credenciales con impresión efectiva');
    }

    public function test_las_bandejas_siguen_protegidas(): void
    {
        $tarjeta = $this->elementoImprimible()->solicitud;
        foreach (['credenciales-recibidas', 'pendientes'] as $seccion) {
            $url = route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => $seccion]);
            $this->app['auth']->forgetGuards();
            $this->get($url)->assertRedirect(route('login'));
            $this->actingAs(User::factory()->create(['role' => 'consulta_general']))->get($url)->assertForbidden();
        }
    }
}
