<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EstadoEmision;
use App\Models\CredencialEmitida;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Support\Code128;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;

class CredencialEmisionTest extends TestCase
{
    use PreparaImpresionReal;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDiscosEvidencia();
    }

    // Fase 1B.2: un elemento imprimible incluye sus cuatro biométricos (PNG sintéticos) y se imprime desde una preparación.
    private function elementoListo(string $estadoSolicitud = 'enviada_impresion', string $estadoTramite = 'listo_impresion', ?string $folio = 'FOL-2026-00001', int $n = 1): SolicitudElemento
    {
        return $this->elementoImprimible($n, $estadoSolicitud, $estadoTramite, $folio);
    }

    private function usuario(?string $rol): User
    {
        return User::factory()->create(['role' => $rol]);
    }

    // ---- Rutas protegidas -------------------------------------------------

    public function test_rutas_de_impresion_y_credencializacion_exigen_sesion(): void
    {
        $elemento = $this->elementoListo();

        foreach ([
            '/impresion',
            '/impresion/cola',
            '/impresion/biometricos',
            '/impresion/plantilla',
            '/impresion/plantilla/imprimir?elemento_id='.$elemento->id,
            '/credencializacion/solicitudes',
            '/credencializacion/solicitudes/'.$elemento->solicitud_id,
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_dashboard_exige_sesion(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_credencializacion_exige_rol_autorizado(): void
    {
        $elemento = $this->elementoListo();

        foreach (['impresion', null] as $rol) {
            $this->actingAs($this->usuario($rol))->get('/credencializacion/solicitudes')->assertForbidden();
            $this->actingAs($this->usuario($rol))->get('/credencializacion/solicitudes/'.$elemento->solicitud_id)->assertForbidden();
        }

        foreach (['superadmin', 'credencializador', 'operador'] as $rol) {
            $this->actingAs($this->usuario($rol))->get('/credencializacion/solicitudes')->assertOk();
        }
    }

    public function test_responsable_del_expediente_sin_rol_de_cola_no_asigna_folios(): void
    {
        $elemento = $this->elementoListo();
        $responsable = $this->usuario('credencializador');
        $elemento->solicitud->update(['usuario_responsable_id' => $responsable->id]);

        $this->actingAs($responsable)
            ->postJson(route('impresion.tarjeta.validar-biometria', $elemento->solicitud_id))
            ->assertForbidden();
    }

    public function test_credencial_emitida_solo_ofrece_reimpresion_registrada(): void
    {
        $elemento = $this->elementoListo();
        $usuario = $this->usuario('impresion');
        $url = route('impresion.plantilla.imprimir', ['elemento_id' => $elemento->id]);

        $html = $this->actingAs($usuario)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('name="tipo" value="emision"', $html);
        $this->assertStringContainsString('Preparar impresión', $html);
        $this->assertStringNotContainsString('value="reimpresion"', $html);

        $this->emitir($usuario, $elemento);

        $html = $this->actingAs($usuario)->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('name="tipo" value="emision"', $html);
        $this->assertStringContainsString('name="tipo" value="reimpresion"', $html);

        // La vista de la reimpresión pide el motivo al confirmar.
        $reimpresion = $this->prepararImpresion($usuario, $elemento, 'reimpresion');
        $html = $this->actingAs($usuario)->get(route('impresion.preparacion', $reimpresion))->assertOk()->getContent();
        $this->assertStringContainsString('<button type="button" data-print-button', $html);
        $this->assertStringContainsString('data-registrar="impresa"', $html);
        $this->assertStringContainsString('minlength="10"', $html);
    }

    public function test_cola_rechaza_roles_sin_permiso(): void
    {
        $elemento = $this->elementoListo();

        $this->actingAs($this->usuario('credencializador'))
            ->getJson(route('impresion.tarjeta.elementos', $elemento->solicitud_id))
            ->assertForbidden();

        $this->actingAs($this->usuario('operador'))
            ->getJson(route('impresion.tarjeta.elementos', $elemento->solicitud_id))
            ->assertOk();
    }

    public function test_validar_biometria_no_concede_acceso_por_entorno(): void
    {
        $elemento = $this->elementoListo();

        $this->actingAs($this->usuario(null))
            ->postJson(route('impresion.tarjeta.validar-biometria', $elemento->solicitud_id))
            ->assertForbidden();
    }

    // ---- Emisión ----------------------------------------------------------

    public function test_emision_correcta_marca_emitida_y_registra_intento(): void
    {
        $elemento = $this->elementoListo();
        $usuario = $this->usuario('impresion');
        $preparacion = $this->prepararImpresion($usuario, $elemento);
        $this->verEvidencias($usuario, $preparacion);

        $this->actingAs($usuario)
            ->postJson(route('impresion.elemento.emision', $elemento), ['preparacion_uuid' => $preparacion->uuid, 'resultado' => 'impresa', 'impresora' => 'Zebra ZXP Series 8'])
            ->assertOk()
            ->assertJson(['success' => true, 'estado_emision' => 'emitida', 'numero_intento' => 1, 'modalidad' => 'normal']);
        $this->assertSame('confirmada', $preparacion->refresh()->estado);

        $credencial = $elemento->credencialEmitida()->first();
        $this->assertSame('emitida', $credencial->estado_emision);
        $this->assertNotNull($credencial->fecha_emision);
        $this->assertSame($usuario->id, (int) $credencial->emitida_por_user_id);
        $this->assertDatabaseHas('credencial_impresiones', [
            'credencial_emitida_id' => $credencial->id,
            'solicitud_elemento_id' => $elemento->id,
            'usuario_id' => $usuario->id,
            'resultado' => 'impresa',
            'impresora' => 'Zebra ZXP Series 8',
            'numero_intento' => 1,
            'credencial_preparacion_id' => $preparacion->id,
        ]);
    }

    public function test_emision_fallida_registra_intento_sin_emitir(): void
    {
        $elemento = $this->elementoListo();
        $usuario = $this->usuario('impresion');
        $preparacion = $this->prepararImpresion($usuario, $elemento);

        $this->actingAs($usuario)
            ->postJson(route('impresion.elemento.emision', $elemento), ['preparacion_uuid' => $preparacion->uuid, 'resultado' => 'fallida', 'motivo' => 'Tarjeta atascada'])
            ->assertOk()
            ->assertJson(['estado_emision' => 'pendiente', 'numero_intento' => 1]);

        $this->assertSame('pendiente', $elemento->credencialEmitida()->first()->estado_emision);
        $this->assertDatabaseHas('credencial_impresiones', ['resultado' => 'fallida', 'motivo' => 'Tarjeta atascada']);
    }

    public function test_emision_rechaza_roles_sin_permiso(): void
    {
        $elemento = $this->elementoListo();

        foreach (['operador', 'credencializador', null] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->postJson(route('impresion.elemento.emision', $elemento), ['resultado' => 'impresa'])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('credencial_impresiones', 0);
    }

    public function test_emision_exige_solicitud_enviada_elemento_listo_y_folio(): void
    {
        $usuario = $this->usuario('impresion');

        foreach ([
            ['evaluada', 'listo_impresion', 'FOL-2026-00011', 11],
            ['enviada_impresion', 'pendiente_biometrico', 'FOL-2026-00012', 12],
            ['enviada_impresion', 'listo_impresion', null, 13],
        ] as [$estadoSolicitud, $estadoTramite, $folio, $n]) {
            $elemento = $this->elementoListo($estadoSolicitud, $estadoTramite, $folio, $n);

            $this->actingAs($usuario)
                ->postJson(route('impresion.elemento.preparar', $elemento), ['tipo' => 'emision'])
                ->assertStatus(409);
            $this->actingAs($usuario)
                ->postJson(route('impresion.elemento.emision', $elemento), ['preparacion_uuid' => (string) Str::uuid(), 'resultado' => 'impresa'])
                ->assertStatus(409);
        }

        $this->assertDatabaseCount('credencial_impresiones', 0);
        $this->assertDatabaseCount('credencial_preparaciones', 0);
    }

    public function test_emision_duplicada_se_rechaza_y_dirige_a_reimpresion(): void
    {
        $elemento = $this->elementoListo();
        $usuario = $this->usuario('impresion');
        $preparacion = $this->emitir($usuario, $elemento);

        // Repetir la misma confirmación es idempotente; una nueva emisión se rechaza y dirige a reimpresión.
        $this->actingAs($usuario)
            ->postJson(route('impresion.elemento.emision', $elemento), ['preparacion_uuid' => $preparacion->uuid, 'resultado' => 'impresa'])
            ->assertOk()->assertJson(['idempotente' => true, 'numero_intento' => 1]);
        $this->actingAs($usuario)
            ->postJson(route('impresion.elemento.preparar', $elemento), ['tipo' => 'emision'])
            ->assertStatus(409);

        $this->assertDatabaseCount('credencial_impresiones', 1);
    }

    // ---- Reimpresión ------------------------------------------------------

    public function test_reimpresion_exige_motivo_y_conserva_folio(): void
    {
        $elemento = $this->elementoListo();
        $usuario = $this->usuario('impresion');
        $this->emitir($usuario, $elemento);
        $reimpresion = $this->prepararImpresion($usuario, $elemento, 'reimpresion');
        $this->verEvidencias($usuario, $reimpresion);

        $this->actingAs($usuario)
            ->postJson(route('impresion.elemento.reimpresion', $elemento), ['preparacion_uuid' => $reimpresion->uuid, 'resultado' => 'impresa', 'motivo' => 'corto'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo');

        $this->actingAs($usuario)
            ->postJson(route('impresion.elemento.reimpresion', $elemento), ['preparacion_uuid' => $reimpresion->uuid, 'resultado' => 'impresa', 'motivo' => 'Tarjeta dañada al laminar'])
            ->assertOk()
            ->assertJson(['numero_intento' => 2, 'folio' => 'FOL-2026-00001']);

        $this->assertSame('FOL-2026-00001', $elemento->credencialEmitida()->first()->folio);
        $this->assertDatabaseHas('credencial_impresiones', ['numero_intento' => 2, 'motivo' => 'Tarjeta dañada al laminar']);
    }

    public function test_reimpresion_solo_aplica_a_credenciales_emitidas(): void
    {
        $elemento = $this->elementoListo();
        $usuario = $this->usuario('impresion');

        $this->actingAs($usuario)
            ->postJson(route('impresion.elemento.preparar', $elemento), ['tipo' => 'reimpresion'])
            ->assertStatus(409);

        // Una preparación de emisión no sirve para registrar una reimpresión.
        $emision = $this->prepararImpresion($usuario, $elemento);
        $this->actingAs($usuario)
            ->postJson(route('impresion.elemento.reimpresion', $elemento), ['preparacion_uuid' => $emision->uuid, 'resultado' => 'impresa', 'motivo' => 'Motivo suficientemente largo'])
            ->assertStatus(409);
    }

    // ---- Inmutabilidad ----------------------------------------------------

    public function test_emision_activa_candado_de_inmutabilidad_biometrica(): void
    {
        $elemento = $this->elementoListo();
        $usuario = $this->usuario('impresion');
        $this->emitir($usuario, $elemento);

        // Fase 1B.2 no retira el candado post-emisión.
        $this->actingAs($usuario)
            ->postJson(route('impresion.elemento.actualizar-biometrico', $elemento), [
                'tipo_biometrico' => 'foto',
                'archivo' => UploadedFile::fake()->create('foto.jpg', 10, 'image/jpeg'), // la autorización se evalúa antes que la validación
                'motivo_cambio' => 'Intento posterior a la emisión',
            ])
            ->assertForbidden();
    }

    // ---- Vista de impresión ----------------------------------------------

    public function test_vista_de_impresion_real_exige_autorizacion_y_estado(): void
    {
        $elemento = $this->elementoListo();
        $url = route('impresion.plantilla.imprimir', ['elemento_id' => $elemento->id]);

        $this->actingAs($this->usuario('local_demo'))->get($url)->assertForbidden();
        // Ver biométricos no basta: imprimir una credencial real exige el permiso de registro de impresión.
        $this->actingAs($this->usuario('credencializador'))->get($url)->assertForbidden();
        $this->actingAs($this->usuario('operador'))->get($url)->assertForbidden();

        $impresor = $this->usuario('impresion');
        $html = $this->actingAs($impresor)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('CUIPEMISION0000001', $html);
        $this->assertStringNotContainsString('demostración', mb_strtolower($html));
        $this->assertStringContainsString(route('impresion.elemento.preparar', $elemento), $html);
        // El lanzador no renderiza la tarjeta con datos vigentes.
        $this->assertStringNotContainsString('data-code128', $html);
        $this->assertDatabaseCount('credencial_preparaciones', 0);

        // La tarjeta real se renderiza solo desde el snapshot de una preparación, con las mismas autorizaciones.
        $preparacion = $this->prepararImpresion($impresor, $elemento);
        $vista = route('impresion.preparacion', $preparacion);
        $this->actingAs($this->usuario('credencializador'))->get($vista)->assertForbidden();
        $html = $this->actingAs($impresor)->get($vista)->assertOk()->getContent();
        $this->assertStringContainsString('CUIPEMISION0000001', $html);
        $this->assertStringContainsString(route('impresion.elemento.emision', $elemento), $html);
        $this->assertStringContainsString('data-code128', $html);
        $this->assertStringContainsString(route('impresion.preparacion.biometrico', ['preparacion' => $preparacion, 'categoria' => 'foto']), $html);

        $elemento->update(['estado_tramite' => 'pendiente_biometrico']);
        $this->actingAs($impresor)->get($url)->assertStatus(409);
    }

    public function test_texto_rotado_usa_la_proporcion_fisica_de_la_tarjeta(): void
    {
        $elemento = $this->elementoListo();
        $config = config('credential-templates.version_2');
        $folio = collect($config['faces']['front']['elements'])->firstWhere('id', 'folio_value');
        $this->assertSame(90, (int) $folio['rotation']);

        // Largo del texto = alto físico de la caja (h% · 86 mm) expresado como % de su ancho físico (w% · 54 mm).
        $aspecto = $config['height_mm'] / $config['width_mm'];
        $ancho = number_format(($folio['height'] / $folio['width']) * $aspecto * 100, 2, '.', '').'%';
        $alto = number_format(($folio['width'] / $folio['height']) / $aspecto * 100, 2, '.', '').'%';

        $impresor = $this->usuario('impresion');
        $preparacion = $this->prepararImpresion($impresor, $elemento, 'emision', ['template_version' => '2']);
        $html = $this->actingAs($impresor)
            ->get(route('impresion.preparacion', $preparacion))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("width: {$ancho}; height: {$alto}; transform: translate(-50%, -50%) rotate(90deg)", $html);
        $this->assertMatchesRegularExpression('/white-space: nowrap;">\s*FOL-2026-00001/', $html);
    }

    public function test_vista_de_impresion_sin_elemento_sigue_siendo_demostracion(): void
    {
        $this->actingAs($this->usuario('impresion'))
            ->get(route('impresion.plantilla.imprimir'))
            ->assertOk()
            ->assertSee('SOLO DEMOSTRACIÓN');
    }

    // ---- Code 128 ---------------------------------------------------------

    public function test_code128_tabla_y_codificacion_son_consistentes(): void
    {
        $patrones = Code128::PATRONES;
        $this->assertCount(107, $patrones);
        $this->assertCount(107, array_unique($patrones));

        foreach ($patrones as $valor => $patron) {
            $anchos = array_map('intval', str_split($patron));
            $this->assertSame($valor === 106 ? 13 : 11, array_sum($anchos), "Ancho total del símbolo {$valor}");
            // En Code 128 las barras de cada símbolo suman un número par de módulos.
            $barras = $anchos[0] + $anchos[2] + $anchos[4] + ($anchos[6] ?? 0);
            $this->assertSame(0, $barras % 2, "Paridad de barras del símbolo {$valor}");
        }

        // Start B (104) + 'A'(33) -> checksum (104 + 33) % 103 = 34
        $this->assertSame([104, 33, 34, 106], Code128::valores('A'));
        $svg = Code128::svg('CUIPEMISION0000001');
        $this->assertStringContainsString('<svg', $svg);
        // Start + 18 datos + checksum = 20 símbolos de 11 módulos, stop de 13, zonas quietas 2 x 10.
        $this->assertSame((18 + 2) * 11 + 13 + 20, Code128::modulos('CUIPEMISION0000001'));
        $this->assertNull(Code128::valoresSeguros('ÑANDÚ'));
    }
}
