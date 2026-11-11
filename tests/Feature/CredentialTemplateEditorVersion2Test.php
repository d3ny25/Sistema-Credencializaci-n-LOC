<?php

namespace Tests\Feature;

use App\Http\Controllers\CredentialTemplateController;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoExpediente;
use App\Models\CredentialTemplate;
use App\Models\LocArma;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialTemplateEditorVersion2Test extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'administrador',
        ]);
        config([
            'credential-templates.editor_user_ids' => [(int) $this->adminUser->getKey()],
            'credential-templates.approver_user_ids' => [(int) $this->adminUser->getKey()],
        ]);
    }

    public function test_01_query_and_edit_version_1(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get(route('impresion.plantilla', ['template_version' => 1]));

        $response->assertOk()
            ->assertViewHas('selectedTemplateVersion', 1)
            ->assertSee('data-template-version="1"', false)
            ->assertSee('v1 Base');

        $v1Config = config('credential-templates.default');
        $v1Config['name'] = 'Plantilla Base Modificada Test';

        $saveResponse = $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v1Config,
                'template_version' => 1,
                'change_summary' => 'Ajuste borrador v1',
            ]);

        $saveResponse->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('template_version', 1);

        $savedV1 = CredentialTemplate::where('slug', CredentialTemplateController::SLUG_V1)->first();
        $this->assertNotNull($savedV1);
        $this->assertSame('Plantilla Base Modificada Test', $savedV1->name);
        $this->assertSame(1, $savedV1->current_version);
    }

    public function test_02_query_and_edit_version_2(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->get(route('impresion.plantilla', ['template_version' => 2]));

        $response->assertOk()
            ->assertViewHas('selectedTemplateVersion', 2)
            ->assertSee('data-template-version="2"', false)
            ->assertSee('v2 Oficial Real');

        $v2Config = config('credential-templates.version_2');
        $v2Config['name'] = 'Plantilla Oficial Real v2 Modificada';

        $saveResponse = $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v2Config,
                'template_version' => 2,
                'change_summary' => 'Ajuste borrador v2',
            ]);

        $saveResponse->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('template_version', 2);

        $savedV2 = CredentialTemplate::where('slug', CredentialTemplateController::SLUG_V2)->first();
        $this->assertNotNull($savedV2);
        $this->assertSame('Plantilla Oficial Real v2 Modificada', $savedV2->name);
        $this->assertSame(1, $savedV2->current_version);
    }

    public function test_03_saving_version_2_does_not_alter_version_1(): void
    {
        // First, create and save Version 1
        $v1Config = config('credential-templates.default');
        $v1Config['name'] = 'Plantilla V1 Intacta';
        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v1Config,
                'template_version' => 1,
            ])->assertOk();

        // Now save a modified Version 2
        $v2Config = config('credential-templates.version_2');
        $v2Config['name'] = 'Plantilla V2 Separada';
        $v2Config['faces']['front']['elements'][0]['color'] = '#123456';
        $v2Config['faces']['front']['elements'][0]['border'] = '1px solid #111827';

        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v2Config,
                'template_version' => 2,
            ])->assertOk();

        // Verify Version 1 is intact
        $savedV1 = CredentialTemplate::where('slug', CredentialTemplateController::SLUG_V1)->first();
        $this->assertNotNull($savedV1);
        $this->assertSame('Plantilla V1 Intacta', $savedV1->name);
        $this->assertSame(1, $savedV1->current_version);
        $this->assertNotSame('#123456', $savedV1->template_config['faces']['front']['elements'][0]['color']);

        // Verify Version 2 has its own record
        $savedV2 = CredentialTemplate::where('slug', CredentialTemplateController::SLUG_V2)->first();
        $this->assertNotNull($savedV2);
        $this->assertSame('Plantilla V2 Separada', $savedV2->name);
        $this->assertSame('#123456', $savedV2->template_config['faces']['front']['elements'][0]['color']);
        $this->assertSame('1px solid #111827', $savedV2->template_config['faces']['front']['elements'][0]['border']);
    }

    public function test_04_changes_persist_across_subsequent_http_requests(): void
    {
        $v2Config = config('credential-templates.version_2');
        $v2Config['name'] = 'Persistencia HTTP v2';
        $v2Config['faces']['front']['elements'][0]['x'] = 12.5;

        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v2Config,
                'template_version' => 2,
            ])->assertOk();

        $response = $this->actingAs($this->adminUser)
            ->get(route('impresion.plantilla', ['template_version' => 2]));

        $response->assertOk();
        $responseConfig = $response->viewData('templateConfig');
        $this->assertSame('Persistencia HTTP v2', $responseConfig['name']);
        $this->assertEquals(12.5, $responseConfig['faces']['front']['elements'][0]['x']);
    }

    public function test_05_saved_changes_reflect_in_modal_preview_payload(): void
    {
        $v2Config = config('credential-templates.version_2');
        $v2Config['faces']['front']['elements'][0]['border'] = '2px solid #000000';
        $v2Config['faces']['front']['elements'][0]['x'] = 14.0;

        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v2Config,
                'template_version' => 2,
            ])->assertOk();

        $elemento = $this->crearElementoCompleto();

        $previewResponse = $this->actingAs($this->adminUser)
            ->getJson("/impresion/elementos/{$elemento->id}/previsualizacion?template_version=2");

        $previewResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('template.version', 1) // current_version of draft
            ->assertJsonPath('template.config.faces.front.elements.0.border', '2px solid #000000')
            ->assertJsonPath('template.config.faces.front.elements.0.x', 14);
    }

    public function test_06_saved_changes_reflect_in_physical_print_preview(): void
    {
        $v2Config = config('credential-templates.version_2');
        $v2Config['faces']['front']['elements'][0]['border'] = '1.5px solid #d9251d';

        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v2Config,
                'template_version' => 2,
            ])->assertOk();

        $elemento = $this->crearElementoCompleto();

        $printResponse = $this->actingAs($this->adminUser)
            ->get("/impresion/plantilla/imprimir?elemento_id={$elemento->id}&template_version=2");

        $printResponse->assertOk()
            ->assertViewIs('modules.credential-template-print')
            ->assertSee('border: 1.5px solid #d9251d', false);
    }

    public function test_07_unauthorized_users_cannot_save_modifications(): void
    {
        $v2Config = config('credential-templates.version_2');

        // Unauthenticated
        $this->postJson(route('impresion.plantilla.guardar'), [
            'config' => $v2Config,
            'template_version' => 2,
        ])->assertUnauthorized();

        // Authenticated without manage permission
        $regularUser = User::factory()->create(['role' => 'capturista']);
        $this->actingAs($regularUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v2Config,
                'template_version' => 2,
            ])->assertForbidden();
    }

    public function test_08_invalid_attributes_are_rejected(): void
    {
        $v2Config = config('credential-templates.version_2');

        // Out-of-bounds geometry
        $invalidGeometry = $v2Config;
        $invalidGeometry['faces']['front']['elements'][0]['x'] = 95.0;
        $invalidGeometry['faces']['front']['elements'][0]['width'] = 20.0; // 95 + 20 > 100

        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $invalidGeometry,
                'template_version' => 2,
            ])->assertUnprocessable();

        // Illegal parameter attribute
        $illegalParam = $v2Config;
        $illegalParam['faces']['front']['elements'][0]['unknown_param'] = 'malicious';

        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $illegalParam,
                'template_version' => 2,
            ])->assertUnprocessable();
    }

    public function test_09_discarding_and_restoring_recovers_last_saved_configuration(): void
    {
        $v2Config = config('credential-templates.version_2');
        $v2Config['name'] = 'Versión 2 Inicial';

        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v2Config,
                'template_version' => 2,
            ])->assertOk();

        $v2Saved = CredentialTemplate::where('slug', CredentialTemplateController::SLUG_V2)->firstOrFail();
        $version1 = $v2Saved->versions()->where('version', 1)->firstOrFail();

        // Save a second version
        $v2Config['name'] = 'Versión 2 Modificada Que Se Descartará';
        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v2Config,
                'template_version' => 2,
            ])->assertOk();

        $v2Saved->refresh();
        $this->assertSame(2, $v2Saved->current_version);

        // Restore version 1
        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.restaurar', $version1))
            ->assertOk()
            ->assertJsonPath('version', 3);

        $v2Saved->refresh();
        $this->assertSame('Versión 2 Inicial', $v2Saved->template_config['name']);
        $this->assertSame(3, $v2Saved->current_version);
    }

    public function test_10_version_1_remains_default_when_no_version_parameter_provided(): void
    {
        $elemento = $this->crearElementoCompleto();

        // Editor default
        $editorResponse = $this->actingAs($this->adminUser)
            ->get(route('impresion.plantilla'));

        $editorResponse->assertOk()
            ->assertViewHas('selectedTemplateVersion', 1);

        // Preview default
        $previewResponse = $this->actingAs($this->adminUser)
            ->getJson("/impresion/elementos/{$elemento->id}/previsualizacion");

        $previewResponse->assertOk()
            ->assertJsonPath('template.version', 1);

        // Print default
        $printResponse = $this->actingAs($this->adminUser)
            ->get(route('impresion.plantilla.imprimir', ['elemento_id' => $elemento->id]));

        $printResponse->assertOk()
            ->assertViewHas('templateVersion', 1);
    }

    public function test_11_reproduction_flow_and_precedence_between_draft_and_published_config(): void
    {
        // 1. Cargar V2
        $response = $this->actingAs($this->adminUser)
            ->get(route('impresion.plantilla', ['template_version' => 2]));

        $response->assertOk()
            ->assertViewHas('selectedTemplateVersion', 2)
            ->assertSee('v2 Oficial Real');

        // 2. Modificar la posición de un elemento y su color
        $v2Config = config('credential-templates.version_2');
        $v2Config['faces']['front']['elements'][0]['x'] = 19.5;
        $v2Config['faces']['front']['elements'][0]['color'] = '#e11d48';

        // 3. Guardar el borrador
        $saveResponse = $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v2Config,
                'template_version' => 2,
                'change_summary' => 'Ajuste inicial V2 de prueba',
            ]);

        $saveResponse->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('template_version', 2);

        // 4. Verificar los cambios directamente en la base de datos
        $templateV2 = CredentialTemplate::where('slug', CredentialTemplateController::SLUG_V2)->firstOrFail();
        $this->assertEquals(19.5, $templateV2->template_config['faces']['front']['elements'][0]['x']);
        $this->assertSame('#e11d48', $templateV2->template_config['faces']['front']['elements'][0]['color']);
        $this->assertSame(1, $templateV2->current_version);

        // 5. Realizar una nueva petición GET al editor de V2 (recarga)
        $reloadEditor = $this->actingAs($this->adminUser)
            ->get(route('impresion.plantilla', ['template_version' => 2]));

        // 6. Comprobar que la respuesta contiene los valores guardados
        $reloadEditor->assertOk();
        $editorConfig = $reloadEditor->viewData('templateConfig');
        $this->assertEquals(19.5, $editorConfig['faces']['front']['elements'][0]['x']);
        $this->assertSame('#e11d48', $editorConfig['faces']['front']['elements'][0]['color']);

        // 7. Verificar que la vista previa recupera exactamente los mismos valores
        $elemento = $this->crearElementoCompleto();
        $previewResponse = $this->actingAs($this->adminUser)
            ->getJson("/impresion/elementos/{$elemento->id}/previsualizacion?template_version=2");

        $previewResponse->assertOk()
            ->assertJsonPath('template.config.faces.front.elements.0.x', 19.5)
            ->assertJsonPath('template.config.faces.front.elements.0.color', '#e11d48');

        // 8. Abrir V1 y comprobar que permanece intacta
        $v1Response = $this->actingAs($this->adminUser)
            ->get(route('impresion.plantilla', ['template_version' => 1]));

        $v1Response->assertOk()->assertViewHas('selectedTemplateVersion', 1);
        $v1Config = $v1Response->viewData('templateConfig');
        $this->assertNotSame('#e11d48', $v1Config['faces']['front']['elements'][0]['color'] ?? null);

        // --- CASO DE PRECEDENCIA: published_config tiene datos y se guarda un borrador posterior ---

        // A. Aprobar la versión 1 existente
        $version1 = $templateV2->versions()->where('version', 1)->firstOrFail();
        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.aprobar', $version1))
            ->assertOk();

        $templateV2->refresh();
        $this->assertSame(1, $templateV2->published_version);
        $this->assertEquals(19.5, $templateV2->published_config['faces']['front']['elements'][0]['x']);
        $this->assertSame('#e11d48', $templateV2->published_config['faces']['front']['elements'][0]['color']);

        // B. Guardar un nuevo borrador diferente (versión 2)
        $v2DraftConfig = $v2Config;
        $v2DraftConfig['faces']['front']['elements'][0]['x'] = 33.3;
        $v2DraftConfig['faces']['front']['elements'][0]['color'] = '#10b981';

        $this->actingAs($this->adminUser)
            ->postJson(route('impresion.plantilla.guardar'), [
                'config' => $v2DraftConfig,
                'template_version' => 2,
                'change_summary' => 'Nuevo borrador posterior a aprobación',
            ])->assertOk()
            ->assertJsonPath('version', 2);

        $templateV2->refresh();
        $this->assertSame(2, $templateV2->current_version);
        $this->assertSame(1, $templateV2->published_version);

        // C. Precedencia en el Editor: debe cargar el nuevo borrador (template_config), no la publicada
        $editorAfterDraft = $this->actingAs($this->adminUser)
            ->get(route('impresion.plantilla', ['template_version' => 2]));

        $editorAfterDraft->assertOk();
        $editorAfterDraftConfig = $editorAfterDraft->viewData('templateConfig');
        $this->assertEquals(33.3, $editorAfterDraftConfig['faces']['front']['elements'][0]['x']);
        $this->assertSame('#10b981', $editorAfterDraftConfig['faces']['front']['elements'][0]['color']);

        // D. Precedencia en Vista Previa de calibración: debe cargar el nuevo borrador guardado de V2
        $previewAfterDraft = $this->actingAs($this->adminUser)
            ->getJson("/impresion/elementos/{$elemento->id}/previsualizacion?template_version=2");

        $previewAfterDraft->assertOk()
            ->assertJsonPath('template.version', 2)
            ->assertJsonPath('template.config.faces.front.elements.0.x', 33.3)
            ->assertJsonPath('template.config.faces.front.elements.0.color', '#10b981');

        // E. Precedencia en BD: published_config permanece inalterada con la versión aprobada previa
        $this->assertEquals(19.5, $templateV2->published_config['faces']['front']['elements'][0]['x']);
        $this->assertSame('#e11d48', $templateV2->published_config['faces']['front']['elements'][0]['color']);
    }

    private function crearElementoCompleto(): SolicitudElemento
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

        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'persona_id' => $persona->id,
            'arma_corta_id' => $armaCorta->id,
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
