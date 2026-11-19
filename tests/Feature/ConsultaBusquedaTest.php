<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CredencialEmitida;
use App\Models\LocArma;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\LocPersona;
use App\Models\LocVigenciaCredencial;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Consultas C0 + C1: protección y búsqueda centralizada. Datos sintéticos en la base de pruebas aislada. */
class ConsultaBusquedaTest extends TestCase
{
    use RefreshDatabase;

    private const PARCIAL = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'superadmin']);

        $this->persona('CUIPSINT000000000001', 'JUAN PEREZ LOPEZ', curp: 'PELJ800101HDFRPN09', rfc: 'PELJ800101AB1', personalId: '123');
        $this->persona('CUIPSINT000000000002', 'JUAN MARTINEZ RUIZ', personalId: '456');
        $this->persona('CUIPSINT000000000003', 'MARIA PEREZ SOTO', personalId: '789');
        LocArma::forceCreate(['matricula' => 'ABC1234', 'id_armamento' => '123', 'tipo_arma' => 'CORTA', 'clase_arma' => 'PISTOLA', 'marca' => 'MARCA SINT', 'calibre' => '9MM', 'estatus' => 'FISICA', 'situacion' => 'MINISTRACION']);
        LocArma::forceCreate(['matricula' => 'ABC1299', 'id_armamento' => '555', 'tipo_arma' => 'LARGA']);

        $tarjeta = SolicitudCredencial::create(['numero_tarjeta' => 'CE/TA25-27/50001/2026', 'consecutivo_tarjeta' => 50001, 'numero_oficio' => 'OF-SINT-001', 'fecha_recepcion' => now(), 'estado_general' => 'enviada_impresion', 'area_solicitante' => 'Área sintética']);
        $elemento = SolicitudElemento::create(['solicitud_id' => $tarjeta->id, 'numero_fila' => 1, 'cuip' => 'CUIPSINT000000000001', 'persona_id' => LocPersona::where('cuip', 'CUIPSINT000000000001')->value('id'), 'resultado_prefiltro' => 'Apto', 'estado_tramite' => 'listo_impresion']);
        CredencialEmitida::create(['solicitud_id' => $tarjeta->id, 'solicitud_elemento_id' => $elemento->id, 'folio' => 'OM2527E00001', 'numero_tarjeta' => $tarjeta->numero_tarjeta, 'estado_emision' => 'emitida']);

        LocVigenciaCredencial::forceCreate(['cuip' => 'CUIPSINT000000000001', 'persona_id' => LocPersona::where('cuip', 'CUIPSINT000000000001')->value('id'), 'numero_credencial' => 'CRED-SINT-01', 'estatus_credencial' => 'ALTA', 'periodo_credencial' => 'VIGENCIA 2025-2027', 'es_referencia_loc' => true, 'tipo_credencial' => 'CREDENCIAL DE VIGENCIA']);
        LocVigenciaCredencial::forceCreate(['cuip' => 'CUIPSINT000000000099', 'persona_id' => null, 'numero_credencial' => 'CRED-SINT-02', 'estatus_credencial' => 'BAJA', 'periodo_credencial' => 'VIGENCIA 2025-2027', 'es_referencia_loc' => false, 'tipo_credencial' => 'CREDENCIAL DE VIGENCIA']);
    }

    private function persona(string $cuip, string $nombre, ?string $curp = null, ?string $rfc = null, ?string $personalId = null): void
    {
        LocPersona::forceCreate(['cuip' => $cuip, 'nombre' => $nombre, 'curp' => $curp, 'rfc' => $rfc, 'personal_id' => $personalId, 'region' => 'REGION SINT', 'municipio' => 'MUNICIPIO SINT', 'estatus' => 'INCLUIDO EN LOC', 'datos_loc' => ['MOTIVO DE BAJA' => 'DATO-CRUDO-NO-VISIBLE']]);
    }

    private function buscar(string $q, string $tipo = 'todo', array $extra = [])
    {
        return $this->actingAs($this->admin)->get(route('consultas', ['q' => $q, 'tipo' => $tipo, ...$extra]));
    }

    /** @return array<string, int> grupo => total */
    private function totales($respuesta): array
    {
        return array_map(fn ($p) => $p->total(), $respuesta->viewData('resultados'));
    }

    // ── C0: protección ─────────────────────────────────────────────────────────────────────────────────────

    public function test_solo_el_superadministrador_accede_y_el_menu_lo_respeta(): void
    {
        $this->get(route('consultas'))->assertRedirect(route('login'));
        $this->get(route('consultas', ['q' => 'JUAN']))->assertRedirect(route('login'));
        $this->get(route('consultas', ['q' => 'JUAN']), self::PARCIAL)->assertUnauthorized();

        foreach (['impresion', 'credencializador', 'operador', 'consulta_general'] as $rol) {
            $usuario = User::factory()->create(['role' => $rol]);
            $this->actingAs($usuario)->get(route('consultas', ['q' => 'CUIPSINT000000000001']))->assertForbidden();
            $this->actingAs($usuario)->get(route('dashboard'))->assertDontSee('Consulta general');
        }

        $this->actingAs($this->admin)->get(route('consultas'))->assertOk()->assertSee('Consulta general')->assertSee('data-sin-busqueda', false)
            // Entrada limpia: ningún resultado ni tabla antes de buscar.
            ->assertDontSee('data-grupo=', false)->assertViewHas('resultados', null);
        $this->actingAs($this->admin)->get('/consultas/expedientes/cualquiera')->assertNotFound();
    }

    // ── C1: identificadores ────────────────────────────────────────────────────────────────────────────────

    public function test_cada_identificador_encuentra_su_entidad(): void
    {
        $casos = [
            ['CUIPSINT000000000001', 'elementos', 1],   // CUIP exacta
            ['cuipsint0000000', 'elementos', 3],        // CUIP por prefijo (sin distinguir mayúsculas)
            ['PELJ800101HDFRPN09', 'elementos', 1],     // CURP
            ['PELJ800101AB1', 'elementos', 1],          // RFC exacto
            ['PELJ800101', 'elementos', 1],             // RFC por prefijo (10)
            ['456', 'elementos', 1],                    // ID_PERSONAL
            ['juan perez', 'elementos', 1],             // nombre: todos los términos
            ['perez', 'elementos', 2],
            ['ABC1234', 'armamento', 1],                // matrícula exacta
            ['ABC12', 'armamento', 2],                  // matrícula por prefijo
            ['555', 'armamento', 1],                    // ID_ARMAMENTO
            ['CE/TA25-27/50001/2026', 'tarjetas', 1],   // número de tarjeta
            ['50001', 'tarjetas', 1],
            ['OF-SINT-001', 'tarjetas', 1],             // oficio
            ['OM2527E00001', 'folios', 1],              // folio de credencial
            ['CRED-SINT-01', 'vigencias', 1],           // número de credencial LOC
        ];
        foreach ($casos as [$q, $grupo, $esperados]) {
            $totales = $this->totales($this->buscar($q)->assertOk());
            $this->assertSame($esperados, $totales[$grupo], "«{$q}» en {$grupo}");
        }

        $this->buscar('OM2527E00001')->assertSee('CE/TA25-27/50001/2026')->assertSee('JUAN PEREZ LOPEZ');
    }

    public function test_todo_agrupa_sin_mezclar_y_el_contexto_limita_los_grupos(): void
    {
        // "123" es ID_PERSONAL de una persona e ID_ARMAMENTO de un arma: dos entidades distintas, nunca fusionadas.
        $todo = $this->buscar('123')->assertOk()->assertSee('data-grupo="elementos"', false)->assertSee('data-grupo="armamento"', false)
            ->assertSee('Identificador detectado: ID_PERSONAL o ID_ARMAMENTO');
        $this->assertSame(['elementos' => 1, 'armamento' => 1, 'tarjetas' => 0, 'folios' => 0, 'vigencias' => 0], $this->totales($todo));

        $this->assertSame(['elementos'], array_keys($this->buscar('123', 'elementos')->viewData('resultados')));
        $this->assertSame(['armamento'], array_keys($this->buscar('123', 'armamento')->viewData('resultados')));
        $this->assertSame(['tarjetas', 'folios', 'vigencias'], array_keys($this->buscar('123', 'tarjetas')->viewData('resultados')));
        $this->buscar('123', 'tarjetas')->assertSee('data-sin-resultados', false)->assertDontSee('data-grupo=', false);
        $this->buscar('zzzz-no-existe')->assertSee('No hay coincidencias');
    }

    public function test_validacion_de_la_busqueda(): void
    {
        foreach (['', 'ab', str_repeat('A', 101), "JUAN'; DROP", 'JUAN<script>'] as $q) {
            $this->buscar($q)->assertRedirect(route('consultas'))->assertSessionHasErrors('q');
        }
        $this->buscar('JUAN', 'personas')->assertSessionHasErrors('tipo');
        $this->buscar('  juan    perez  ')->assertOk()->assertViewHas('q', 'juan perez');
        // 2 caracteres solo para identificadores numéricos (hay IDs reales de 2 dígitos), y solo coincidencia exacta.
        LocPersona::forceCreate(['cuip' => 'CUIPSINT000000000010', 'nombre' => 'CORTO', 'personal_id' => '12']);
        $this->assertSame(1, $this->totales($this->buscar('12'))['elementos']);
        $this->buscar('12')->assertViewHas('resultados', fn ($r) => $r['tarjetas']->total() === 0 && $r['armamento']->total() === 0);
        $this->actingAs($this->admin)->get(route('consultas', ['q' => 'JUAN']), self::PARCIAL)->assertOk();
        $this->actingAs($this->admin)->get(route('consultas', ['q' => 'ab']), self::PARCIAL)->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_paginadores_independientes_por_grupo_conservan_la_busqueda(): void
    {
        foreach (range(10, 31) as $i) {
            LocPersona::forceCreate(['cuip' => sprintf('CUIPPAG%013d', $i), 'nombre' => "LOPEZ SINTETICO {$i}"]);
            LocArma::forceCreate(['matricula' => sprintf('LOPEZ%03d', $i)]);
        }
        $r = $this->buscar('LOPEZ');
        $this->assertSame(['elementos' => 23, 'armamento' => 22], array_intersect_key($this->totales($r), ['elementos' => 0, 'armamento' => 0]));
        $r->assertSee('elementos_page=2', false)->assertSee('armamento_page=2', false)->assertSee('q=LOPEZ', false)->assertSee('tipo=todo', false);

        $p = $this->buscar('LOPEZ', extra: ['elementos_page' => 3, 'armamento_page' => 2]);
        $this->assertSame(3, $p->viewData('resultados')['elementos']->count());
        $this->assertSame(10, $p->viewData('resultados')['armamento']->count());
        $this->assertSame(3, $p->viewData('resultados')['elementos']->currentPage());
    }

    // ── Integridad ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_solo_fuentes_confirmadas_sin_datos_crudos_ni_asignaciones_ni_vencimientos(): void
    {
        $lote = LocImportBatch::forceCreate(['tipo_fuente' => 'personal_autorizado_loc', 'nombre_archivo' => 'sintetico.xls', 'estado' => 'fallido']);
        LocImportStagingRow::forceCreate(['import_batch_id' => $lote->id, 'numero_fila' => 1, 'cuip' => 'CUIPSTAGING00000001', 'datos' => ['NOMBRE' => 'SOLO EN STAGING'], 'accion' => 'nuevo']);
        $foto = fn () => [DB::table('loc_personas')->get()->toArray(), DB::table('loc_armas')->get()->toArray(), DB::table('loc_vigencias_credenciales')->get()->toArray(), DB::table('credenciales_emitidas')->get()->toArray()];
        $antes = $foto();

        $this->assertSame(0, array_sum($this->totales($this->buscar('CUIPSTAGING00000001'))));
        $this->buscar('SOLO EN STAGING')->assertSee('No hay coincidencias');

        $this->buscar('CUIPSINT000000000001')->assertDontSee('DATO-CRUDO-NO-VISIBLE')->assertDontSee('MOTIVO DE BAJA');
        $this->buscar('ABC1234')->assertDontSee('asignad')->assertDontSee('Asignad');
        $vig = $this->buscar('CRED-SINT-02')->assertSee('Sin vínculo verificado con el padrón')->assertSee('VIGENCIA 2025-2027')->assertDontSee('Vence');
        $this->buscar('CRED-SINT-01')->assertSee('JUAN PEREZ LOPEZ')->assertDontSee('Sin vínculo verificado');
        $this->assertEquals($antes, $foto(), 'La búsqueda no modifica registros.');
    }

    // ── Navegación dinámica ────────────────────────────────────────────────────────────────────────────────

    public function test_respuesta_parcial_y_pagina_completa_con_la_misma_url(): void
    {
        $url = route('consultas', ['q' => 'JUAN', 'tipo' => 'elementos']);
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('data-consultas-navegacion', false)->assertSee('Navegación principal')
            ->assertSee('value="JUAN"', false)->assertSee('<option value="elementos" selected', false);

        $parcial = $this->actingAs($this->admin)->get($url, self::PARCIAL)->assertOk()->assertJsonStructure(['html', 'avisos', 'url']);
        $this->assertStringNotContainsString('Navegación principal', $parcial->json('html'));
        $this->assertStringNotContainsString('data-consultas-navegacion', $parcial->json('html'));
        $this->assertStringContainsString('data-grupo="elementos"', $parcial->json('html'));
        $this->assertStringContainsString('q=JUAN', $parcial->json('url'));
    }
}
