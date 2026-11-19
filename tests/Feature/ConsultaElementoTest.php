<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BiometricoExpediente;
use App\Models\CredencialEmitida;
use App\Models\CredencialImpresion;
use App\Models\LocArma;
use App\Models\LocPersona;
use App\Models\LocVigenciaCredencial;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Services\ConsultaExpedienteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** Consultas C2: expediente de elemento con datos sintéticos (base de pruebas aislada). */
class ConsultaElementoTest extends TestCase
{
    use RefreshDatabase;

    private const PARCIAL = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private User $admin;

    private LocPersona $persona;

    private LocPersona $otra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'superadmin']);
        $this->persona = LocPersona::forceCreate(['cuip' => 'CUIPELEM000000000001', 'nombre' => 'ELEMENTO SINTETICO', 'curp' => 'SIEL800101HDFNTR01', 'rfc' => null, 'personal_id' => '321',
            'region' => 'REGION SINT', 'municipio' => 'MUNICIPIO SINT', 'categoria' => 'POLICIA', 'estatus' => 'INCLUIDO EN LOC', 'adscripcion' => null, 'activo' => true,
            'datos_loc' => ['MOTIVO DE BAJA' => 'CRUDO-NO-VISIBLE']]);
        $this->otra = LocPersona::forceCreate(['cuip' => 'CUIPELEM000000000002', 'nombre' => 'OTRA PERSONA SINTETICA']);
    }

    private function vigencia(array $a): LocVigenciaCredencial
    {
        return LocVigenciaCredencial::forceCreate(['cuip' => $this->persona->cuip, 'persona_id' => $this->persona->id, 'es_referencia_loc' => true, 'tipo_credencial' => 'CREDENCIAL DE VIGENCIA', 'periodo_credencial' => 'VIGENCIA 2025-2027', ...$a]);
    }

    private function seccion(string $seccion = 'resumen', ?LocPersona $persona = null, ?User $usuario = null, array $cabeceras = [])
    {
        return $this->actingAs($usuario ?? $this->admin)->get(route('consultas.elemento', ['persona' => ($persona ?? $this->persona)->id, 'seccion' => $seccion]), $cabeceras);
    }

    // ── Acceso y rutas ─────────────────────────────────────────────────────────────────────────────────────

    public function test_acceso_rutas_y_navegacion_dinamica(): void
    {
        $url = route('consultas.elemento', $this->persona->id);
        $this->get($url)->assertRedirect(route('login'));
        $this->get($url, self::PARCIAL)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'impresion']))->get($url)->assertForbidden();
        $this->actingAs($this->admin)->get('/consultas/elementos/999999')->assertNotFound();
        $this->actingAs($this->admin)->get('/consultas/elementos/'.$this->persona->id.'/historial')->assertNotFound();
        $this->actingAs($this->admin)->get('/consultas/elementos/'.$this->persona->cuip)->assertNotFound();

        foreach (['resumen', 'vigencias', 'armamento-vinculado', 'tramites'] as $s) {
            $this->seccion($s)->assertOk()->assertSee('Navegación principal')->assertSee('data-expediente="elemento"', false)
                ->assertSee(route('consultas.elemento', ['persona' => $this->persona->id, 'seccion' => 'tramites']), false);
            $parcial = $this->seccion($s, cabeceras: self::PARCIAL)->assertOk()->assertJsonStructure(['html', 'avisos', 'url']);
            $this->assertStringNotContainsString('Navegación principal', $parcial->json('html'));
            $this->assertStringContainsString('/consultas/elementos/'.$this->persona->id.'/'.$s, $parcial->json('url'));
        }
        // C1 enlaza al expediente desde los resultados de elementos.
        $this->actingAs($this->admin)->get(route('consultas', ['q' => $this->persona->cuip]))->assertSee(route('consultas.elemento', $this->persona->id), false);
    }

    // ── Resumen ────────────────────────────────────────────────────────────────────────────────────────────

    public function test_resumen_muestra_solo_datos_disponibles_y_la_situacion_canonica(): void
    {
        $this->vigencia(['numero_credencial' => 'CRED-A', 'estatus_credencial' => 'ALTA', 'fecha_solicitud' => '2025-01-10']);
        $this->vigencia(['numero_credencial' => 'CRED-B', 'estatus_credencial' => 'BAJA', 'fecha_solicitud' => '2024-03-01', 'fecha_baja' => '2025-06-01', 'numero_oficio_baja' => 'OF-BAJA-SINT', 'motivo_situacion_especial' => 'EL ARMA CON MATRICULA SECRETA HA SIDO ROBADA']);
        BiometricoExpediente::forceCreate(['persona_id' => $this->persona->id, 'cuip' => $this->persona->cuip, 'ambito' => 'ESTATALES']);

        $r = $this->seccion()->assertOk()
            ->assertSee('SIEL800101HDFNTR01')->assertSee('321')->assertSee('POLICIA')
            ->assertDontSee('>RFC<', false)->assertDontSee('Adscripción')->assertDontSee('Activo')
            ->assertSee('Último estatus administrativo registrado en LOC')
            // La BAJA de 2025-06 es posterior a la ALTA de 2025-01 según la política (fecha de baja primero).
            ->assertSee('data-situacion="determinada"', false)->assertSee('BAJA')->assertSee('01/06/2025')->assertSee('OF-BAJA-SINT')
            ->assertDontSee('SECRETA')->assertSee('situación especial registrada', false)
            ->assertDontSee('Vence')->assertDontSee('Vigente hoy')->assertDontSee('caducidad')
            ->assertDontSee('CRUDO-NO-VISIBLE')->assertSee('Existe');
        $this->assertSame('CRED-B', $r->viewData('datos')['ultima']->numero_credencial);
    }

    public function test_situacion_ambigua_o_sin_registros_no_se_presenta_como_determinada(): void
    {
        $this->seccion()->assertSee('data-situacion="sin-registros"', false);
        // Dos registros sin fecha comparable: la política devolvería el último insertado; Consultas no lo presenta como situación.
        $this->vigencia(['numero_credencial' => 'CRED-X', 'estatus_credencial' => 'ALTA']);
        $this->vigencia(['numero_credencial' => 'CRED-Y', 'estatus_credencial' => 'BAJA']);
        $this->seccion()->assertSee('Situación administrativa no determinable con los registros disponibles')->assertDontSee('data-situacion="determinada"', false);
        $this->seccion('vigencias')->assertSee('Situación administrativa no determinable')->assertSee('Registros con vínculo verificado')
            ->assertSee('CRED-X')->assertSee('CRED-Y');
    }

    // ── Vigencias ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_vigencias_ordenadas_por_fecha_y_sin_vinculo_separadas(): void
    {
        // Insertadas fuera de orden cronológico: el orden de captura no decide.
        $this->vigencia(['numero_credencial' => 'CRED-2024', 'estatus_credencial' => 'BAJA', 'fecha_solicitud' => '2023-01-01', 'fecha_baja' => '2024-02-01']);
        $this->vigencia(['numero_credencial' => 'CRED-2025', 'estatus_credencial' => 'ALTA', 'fecha_solicitud' => '2025-05-01']);
        $this->vigencia(['numero_credencial' => 'CRED-2022', 'estatus_credencial' => 'BAJA', 'fecha_solicitud' => '2021-01-01', 'fecha_baja' => '2022-01-01']);
        LocVigenciaCredencial::forceCreate(['cuip' => $this->persona->cuip, 'persona_id' => null, 'es_referencia_loc' => false, 'numero_credencial' => 'CRED-NOCONC', 'estatus_credencial' => 'ALTA']);
        LocVigenciaCredencial::forceCreate(['cuip' => $this->otra->cuip, 'persona_id' => $this->otra->id, 'es_referencia_loc' => true, 'numero_credencial' => 'CRED-OTRA']);

        $r = $this->seccion('vigencias')->assertOk()->assertDontSee('CRED-OTRA');
        $d = $r->viewData('datos');
        $this->assertSame('CRED-2025', $d['ultima']->numero_credencial);
        $this->assertSame(['CRED-2024', 'CRED-2022'], $d['anteriores']->pluck('numero_credencial')->all());
        $this->assertSame(['CRED-NOCONC'], $d['sin_vinculo']->pluck('numero_credencial')->all());
        $r->assertSeeInOrder(['data-ultima-vigencia', 'CRED-2025', 'data-vigencias-anteriores', 'CRED-2024', 'CRED-2022', 'data-vigencias-sin-vinculo', 'CRED-NOCONC'], false)
            ->assertSee('No registrada')->assertSee('no se confirma que pertenezcan');
        $this->assertSame(4, substr_count($r->getContent(), 'data-vigencia="'), 'cada vigencia una sola vez');
    }

    // ── Armamento ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_armamento_documental_con_fuentes_sin_duplicar_ni_asignar(): void
    {
        $arma = LocArma::forceCreate(['matricula' => 'MAT-SINT-1', 'id_armamento' => '777', 'tipo_arma' => 'CORTA', 'marca' => 'MARCA', 'estatus' => 'FISICA', 'situacion' => 'MINISTRACION', 'datos_origen' => ['FECHA EXTRAVIO' => 'CRUDO-ARMA']]);
        $otraArma = LocArma::forceCreate(['matricula' => 'MAT-AJENA', 'id_armamento' => '778']);
        $this->vigencia(['numero_credencial' => 'CRED-ARM', 'arma_corta_id' => $arma->id, 'matricula_larga' => 'MAT-SIN-REGISTRO']);
        LocVigenciaCredencial::forceCreate(['cuip' => $this->persona->cuip, 'persona_id' => null, 'es_referencia_loc' => false, 'numero_credencial' => 'CRED-NOCONC', 'arma_corta_id' => $otraArma->id]);
        $tarjeta = SolicitudCredencial::create(['numero_tarjeta' => 'CE/TA25-27/60001/2026', 'consecutivo_tarjeta' => 60001, 'numero_oficio' => 'OF-ARM', 'fecha_recepcion' => now(), 'estado_general' => 'evaluada']);
        SolicitudElemento::create(['solicitud_id' => $tarjeta->id, 'numero_fila' => 1, 'cuip' => $this->persona->cuip, 'persona_id' => $this->persona->id, 'arma_corta_id' => $arma->id]);

        $r = $this->seccion('armamento-vinculado')->assertOk();
        $this->assertSame(1, substr_count($r->getContent(), 'data-arma>'), 'la misma arma por dos fuentes es una sola ficha');
        $r->assertSee('MAT-SINT-1')->assertSee('Vigencia LOC CRED-ARM')->assertSee('Trámite de credencial CE/TA25-27/60001/2026')
            ->assertSee('MAT-SIN-REGISTRO')->assertSee('sin registro de armamento')
            ->assertDontSee('MAT-AJENA')->assertDontSee('CRUDO-ARMA')->assertDontSee('asignada')->assertDontSee('resguardo');
    }

    // ── Trámites ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_tramites_verificables_sin_duplicar_y_con_enlace_autorizado(): void
    {
        $nueva = fn (int $n, array $a) => SolicitudElemento::create(['solicitud_id' => SolicitudCredencial::create(['numero_tarjeta' => "CE/TA25-27/7000{$n}/2026", 'consecutivo_tarjeta' => 70000 + $n, 'numero_oficio' => "OF-TRAM-{$n}", 'fecha_recepcion' => now(), 'estado_general' => 'enviada_impresion', 'area_solicitante' => "Área {$n}"])->id, 'numero_fila' => 1, 'resultado_prefiltro' => 'Apto', 'estado_tramite' => 'listo_impresion', ...$a]);
        $porPadron = $nueva(1, ['cuip' => $this->persona->cuip, 'persona_id' => $this->persona->id]);   // persona_id y CUIP: una sola vez
        $nueva(2, ['cuip' => $this->persona->cuip, 'persona_id' => null]);                              // CUIP exacto sin vínculo
        $nueva(3, ['cuip' => $this->persona->cuip, 'persona_id' => $this->otra->id]);                  // discrepancia: no se une
        $nueva(4, ['cuip' => 'CUIPOTROXXXXXXXXXXXX', 'persona_id' => null, 'datos_solicitados' => ['nombre' => 'ELEMENTO SINTETICO']]); // solo nombre
        $credencial = CredencialEmitida::create(['solicitud_id' => $porPadron->solicitud_id, 'solicitud_elemento_id' => $porPadron->id, 'folio' => 'OM2527E09999', 'numero_tarjeta' => 'x', 'estado_emision' => 'emitida']);
        CredencialImpresion::create(['credencial_emitida_id' => $credencial->id, 'solicitud_elemento_id' => $porPadron->id, 'resultado' => 'impresa', 'numero_intento' => 1]);
        $antes = [DB::table('credenciales_emitidas')->get()->toArray(), DB::table('credencial_impresiones')->get()->toArray(), DB::table('solicitudes')->get()->toArray()];

        $r = $this->seccion('tramites')->assertOk();
        $this->assertSame(2, substr_count($r->getContent(), 'data-tramite="'));
        $r->assertSee('OF-TRAM-1')->assertSee('OF-TRAM-2')->assertDontSee('OF-TRAM-3')->assertDontSee('OF-TRAM-4')
            ->assertSee('Coincidencia de CUIP sin vínculo de padrón')->assertSee('OM2527E09999')->assertSee('Disponible para entrega')->assertSee('Impresa')
            ->assertSee('data-enlace-entregas', false);
        $this->assertEquals($antes, [DB::table('credenciales_emitidas')->get()->toArray(), DB::table('credencial_impresiones')->get()->toArray(), DB::table('solicitudes')->get()->toArray()]);
        $this->assertSame(2, $this->seccion()->viewData('datos')['tramites']);

        // Consultas no implica Entrega y Seguimiento: sin ese gate no se ofrece el enlace.
        Gate::define('access-entregas', fn () => false);
        $this->seccion('tramites')->assertOk()->assertDontSee('data-enlace-entregas', false);
    }

    // ── Integridad ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_ninguna_seccion_modifica_registros(): void
    {
        $this->vigencia(['numero_credencial' => 'CRED-I', 'estatus_credencial' => 'ALTA', 'fecha_solicitud' => '2025-01-01']);
        $tablas = ['loc_personas', 'loc_vigencias_credenciales', 'loc_armas', 'solicitudes', 'solicitud_elementos', 'credenciales_emitidas', 'credencial_impresiones', 'credencial_entregas', 'biometrico_expedientes'];
        $foto = fn () => array_map(fn ($t) => DB::table($t)->get()->toArray(), $tablas);
        $antes = $foto();
        $this->travel(5)->minutes();
        foreach (array_keys(ConsultaExpedienteService::SECCIONES) as $s) {
            $this->seccion($s)->assertOk();
            $this->seccion($s, cabeceras: self::PARCIAL)->assertOk();
        }
        $this->assertEquals($antes, $foto());
    }
}
