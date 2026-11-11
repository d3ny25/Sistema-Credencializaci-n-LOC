<?php

namespace Tests\Feature;

use App\Models\BiometricoArchivo;
use App\Models\BiometricoExpediente;
use App\Models\LocArma;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialTemplateVersion2Test extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'administrador',
        ]);
    }

    public function test_previsualizacion_returns_version_2_when_requested(): void
    {
        $elemento = $this->crearElementoCompleto();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/impresion/elementos/{$elemento->id}/previsualizacion?template_version=2");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('template.version', 2)
            ->assertJsonPath('template.width_mm', 54)
            ->assertJsonPath('template.height_mm', 86);

        $data = $response->json();

        // Check front elements
        $frontElements = collect($data['template']['config']['faces']['front']['elements']);
        $this->assertNotEmpty($frontElements->firstWhere('id', 'folio_value'));
        $this->assertNotEmpty($frontElements->firstWhere('id', 'cuip_value'));
        $this->assertNotEmpty($frontElements->firstWhere('id', 'se_acredita'));
        $this->assertNotEmpty($frontElements->firstWhere('id', 'nombre_secretario'));

        // Check Arial font family
        $seAcredita = $frontElements->firstWhere('id', 'se_acredita');
        $this->assertStringContainsString('Arial', $seAcredita['font_family']);

        // Check rear elements
        $rearElements = collect($data['template']['config']['faces']['rear']['elements']);
        $this->assertNotEmpty($rearElements->firstWhere('id', 'leyenda_licencia'));
        $this->assertNotEmpty($rearElements->firstWhere('id', 'huella_izquierda'));
        $this->assertNotEmpty($rearElements->firstWhere('id', 'huella_derecha'));
        $this->assertNotEmpty($rearElements->firstWhere('id', 'leyenda_validez'));
        $this->assertNotEmpty($rearElements->firstWhere('id', 'clase_arma'));
        $this->assertNotEmpty($rearElements->firstWhere('id', 'calibre'));
        $this->assertNotEmpty($rearElements->firstWhere('id', 'marca'));
        $this->assertNotEmpty($rearElements->firstWhere('id', 'matricula'));
        $this->assertNotEmpty($rearElements->firstWhere('id', 'modelo'));
        $this->assertNotEmpty($rearElements->firstWhere('id', 'codigo_barras'));
        $this->assertNotEmpty($rearElements->firstWhere('id', 'numero_tarjeta'));
        $this->assertNotEmpty($rearElements->firstWhere('id', 'nombre_titular'));

        // Check institutional signatures mapped
        $this->assertArrayHasKey('firma_secretario', $data['template']['recursos']);
        $this->assertArrayHasKey('firma_oficial_mayor', $data['template']['recursos']);
    }

    public function test_previsualizacion_defaults_to_version_1_when_no_parameter_passed(): void
    {
        $elemento = $this->crearElementoCompleto();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/impresion/elementos/{$elemento->id}/previsualizacion");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('template.version', 1);
    }

    public function test_previsualizacion_combines_dual_weapons_with_newlines(): void
    {
        $elemento = $this->crearElementoCompleto(conArmaLarga: true);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/impresion/elementos/{$elemento->id}/previsualizacion?template_version=2");

        $response->assertOk();
        $campos = $response->json('campos_valores');

        $this->assertStringContainsString("\n", $campos['clase_arma']);
        $this->assertStringContainsString("\n", $campos['calibre']);
        $this->assertStringContainsString("\n", $campos['marca']);
        $this->assertStringContainsString("\n", $campos['matricula']);
        $this->assertStringContainsString("\n", $campos['modelo']);
    }

    public function test_print_preview_supports_version_2(): void
    {
        $elemento = $this->crearElementoCompleto();

        $response = $this->actingAs($this->adminUser)
            ->get("/impresion/plantilla/imprimir?elemento_id={$elemento->id}&template_version=2");

        $response->assertOk()
            ->assertViewIs('modules.credential-template-print')
            ->assertViewHas('templateVersion', 2);
    }

    private function crearElementoCompleto(bool $conArmaLarga = false): SolicitudElemento
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'CE/TA25-27/971/2026',
            'consecutivo_tarjeta' => 1,
            'numero_oficio' => 'OF-139-2026',
            'fecha_recepcion' => now(),
            'estado_general' => 'recibida',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'OIFR970807H154871939',
            'nombre' => 'ORTIZ FERREYRA RODOLFO ADEMAR',
            'categoria' => 'POLICIA DE FUERZA ESPECIAL',
            'subdireccion' => 'GRUPO TACTICO',
            'municipio' => 'COORDINACION GRUPOS TACTICOS',
            'activo' => true,
        ]);

        $armaCorta = LocArma::create([
            'matricula' => 'UGW954',
            'tipo_arma' => 'CORTA',
            'clase_arma' => 'PISTOLA',
            'marca' => 'GLOCK',
            'modelo' => '17',
            'calibre' => '9X19 MM.',
            'rfa' => 'D 26234',
            'resguardo' => '1317780',
        ]);

        $armaLarga = null;
        if ($conArmaLarga) {
            $armaLarga = LocArma::create([
                'matricula' => '45103144',
                'tipo_arma' => 'LARGA',
                'clase_arma' => 'FUSIL',
                'marca' => 'I.W.I.',
                'modelo' => 'GALIL ACE 21 S.A.',
                'calibre' => '5.56X45 MM.',
                'rfa' => 'D 27769',
                'resguardo' => '1459049',
            ]);
        }

        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'persona_id' => $persona->id,
            'arma_corta_id' => $armaCorta->id,
            'arma_larga_id' => $armaLarga?->id,
            'numero_fila' => 1,
            'cuip' => 'OIFR970807H154871939',
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'listo_impresion',
            'ambito_biometrico' => \App\Enums\BiometricoAmbito::ESTATALES,
            'estado_clasificacion_ambito' => \App\Enums\BiometricoClasificacionEstado::VALIDADA,
        ]);

        $archivos = [];
        $mapCategorias = [
            'foto' => \App\Enums\BiometricoCategoria::FOTO,
            'firma' => \App\Enums\BiometricoCategoria::FIRMA,
            'pulgar_derecho' => \App\Enums\BiometricoCategoria::PULGAR_DERECHO,
            'pulgar_izquierdo' => \App\Enums\BiometricoCategoria::PULGAR_IZQUIERDO,
        ];

        foreach (['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo'] as $tipo) {
            $archivos[$tipo] = BiometricoArchivo::create([
                'ambito' => \App\Enums\BiometricoAmbito::ESTATALES,
                'categoria' => $mapCategorias[$tipo],
                'ruta_relativa' => "biometricos/{$tipo}.png",
                'nombre_archivo' => "{$tipo}.png",
                'extension' => 'png',
                'formato_real' => 'png',
                'mime_type' => 'image/png',
                'tamano_bytes' => 1024,
                'sha256' => hash('sha256', "demo-content-{$tipo}"),
                'cuip_detectado' => 'OIFR970807H154871939',
                'cuip_normalizado' => 'OIFR970807H154871939',
                'tipo_identificacion' => \App\Enums\BiometricoTipoIdentificacion::ESTANDAR,
                'estado_archivo' => \App\Enums\BiometricoEstadoArchivo::VALIDO,
                'es_vigente' => true,
                'version_secuencia' => 1,
            ]);
        }

        $expediente = BiometricoExpediente::create([
            'cuip' => 'OIFR970807H154871939',
            'persona_id' => $persona->id,
            'ambito' => \App\Enums\BiometricoAmbito::ESTATALES,
            'foto_archivo_id' => $archivos['foto']->id,
            'firma_archivo_id' => $archivos['firma']->id,
            'pulgar_derecho_archivo_id' => $archivos['pulgar_derecho']->id,
            'pulgar_izquierdo_archivo_id' => $archivos['pulgar_izquierdo']->id,
            'total_biometricos' => 4,
            'es_completo' => true,
            'faltantes' => [],
            'estado_revision' => \App\Enums\BiometricoEstadoRevision::APROBADO_TECNICO,
        ]);

        $elemento->biometrico_expediente_id = $expediente->id;
        $elemento->save();

        return $elemento;
    }
}
