<?php

namespace Tests\Feature;

use App\Models\CredentialTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CredentialTemplateEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_is_available_as_a_demo_without_existing_credential_records(): void
    {
        $this->get(route('impresion.plantilla'))
            ->assertOk()
            ->assertSee('Vista de demostración')
            ->assertSee('Anverso')
            ->assertSee('Reverso')
            ->assertSee('Fondo institucional inalterable')
            ->assertSee('54 × 86 mm')
            ->assertSee('Dato de la credencial')
            ->assertSee('Tipo de contenido')
            ->assertSee('Etiqueta visible del campo')
            ->assertDontSee('Parámetro Cardpresso')
            ->assertSee('Elementos y capas')
            ->assertSee('Deshacer')
            ->assertSee('Cuadrícula')
            ->assertSee('template-preview-dialog', false)
            ->assertSee('credential-template-demo', false);

        $config = config('credential-templates.default');
        $this->assertSame(54, $config['width_mm']);
        $this->assertSame(86, $config['height_mm']);
        $this->assertFalse($config['faces']['front']['elements'][0]['locked']);
        $this->assertArrayNotHasKey('parameter', $config['faces']['front']['elements'][1]);
        $this->assertSame(13.5, $config['faces']['front']['elements'][6]['font_size']);
        $this->assertSame('FOLIO', $config['faces']['front']['elements'][0]['content']);
        $barcode = collect($config['faces']['rear']['elements'])->firstWhere('id', 'codigo_barras');
        $this->assertSame('barcode', $barcode['kind']);
        $this->assertNull($barcode['asset_path']);
        $this->assertStringContainsString('PENDIENTE', $barcode['content']);
        $this->assertLessThan(40, strlen($barcode['content']));
        $this->assertContains('Esta credencial ampara la portación de armas de fuego, autorizadas en la Licencia Oficial Colectiva No. 139 concedida por la Secretaría de la Defensa Nacional, mediante oficio No. S.L./11378 de fecha 15 de Septiembre de 2025.', array_column($config['faces']['rear']['elements'], 'content'));
        $this->assertContains('Únicamente tiene validez dentro de los límites del Estado de México y exclusivamente durante el desempeño de su servicio salvo con oficio de colaboración.', array_column($config['faces']['rear']['elements'], 'content'));
        $this->assertDatabaseCount('credential_templates', 0);
        $this->assertDatabaseCount('credenciales_emitidas', 0);
    }

    public function test_saving_a_template_requires_an_authenticated_authorized_user(): void
    {
        $this->postJson(route('impresion.plantilla.guardar'), ['config' => $this->templateConfig()])
            ->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)
            ->postJson(route('impresion.plantilla.guardar'), ['config' => $this->templateConfig()])
            ->assertForbidden();

        $this->assertDatabaseCount('credential_templates', 0);
    }

    public function test_superadministrator_can_manage_and_approve_templates_by_role(): void
    {
        $user = User::factory()->create();
        $user->role = 'superadmin';
        $user->save();
        config([
            'credential-templates.editor_user_ids' => [],
            'credential-templates.approver_user_ids' => [],
        ]);
        $this->actingAs($user);

        $this->postJson(route('impresion.plantilla.guardar'), [
            'config' => $this->templateConfig(),
        ])->assertOk()
            ->assertJsonPath('version', 1);

        $version = CredentialTemplate::query()->firstOrFail()->versions()->firstOrFail();
        $this->postJson(route('impresion.plantilla.aprobar', $version))
            ->assertOk()
            ->assertJsonPath('version', 1);
    }

    public function test_template_rejects_obsolete_external_parameters(): void
    {
        $user = User::factory()->create();
        config(['credential-templates.editor_user_ids' => [(int) $user->getKey()]]);
        $this->actingAs($user);

        $config = $this->templateConfig();
        $config['faces']['front']['elements'][1]['parameter'] = 'external_parameter';

        $this->postJson(route('impresion.plantilla.guardar'), ['config' => $config])
            ->assertUnprocessable();

        $this->assertDatabaseCount('credential_templates', 0);
    }

    public function test_authorized_user_can_save_a_version_and_invalid_layout_is_rejected(): void
    {
        $user = User::factory()->create();
        config(['credential-templates.editor_user_ids' => [(int) $user->getKey()]]);
        $this->actingAs($user);

        $config = $this->templateConfig();
        $config['faces']['front']['elements'][14]['content'] = 'NOMBRE INSTITUCIONAL EDITADO';
        $config['faces']['front']['elements'][0]['locked'] = true;
        $this->postJson(route('impresion.plantilla.guardar'), [
            'config' => $config,
            'change_summary' => 'Primera versión de prueba',
        ])->assertOk()
            ->assertJsonPath('version', 1);

        $template = CredentialTemplate::query()->firstOrFail();
        $this->assertSame($config['faces']['front']['elements'][0]['field'], $template->template_config['faces']['front']['elements'][0]['field']);
        $this->assertTrue($template->template_config['faces']['front']['elements'][0]['locked']);
        $this->assertSame('NOMBRE INSTITUCIONAL EDITADO', $template->template_config['faces']['front']['elements'][14]['content']);
        $this->assertDatabaseHas('credential_template_versions', [
            'credential_template_id' => $template->id,
            'version' => 1,
            'change_summary' => 'Primera versión de prueba',
        ]);

        $invalid = $this->templateConfig();
        $invalid['faces']['front']['elements'][0]['x'] = 98;
        $invalid['faces']['front']['elements'][0]['width'] = 10;

        $this->postJson(route('impresion.plantilla.guardar'), ['config' => $invalid])
            ->assertUnprocessable();

        $this->assertDatabaseCount('credential_template_versions', 1);
    }

    public function test_approver_can_publish_and_print_only_the_approved_demo_template(): void
    {
        $user = User::factory()->create();
        config([
            'credential-templates.editor_user_ids' => [(int) $user->getKey()],
            'credential-templates.approver_user_ids' => [(int) $user->getKey()],
        ]);
        $this->actingAs($user);

        $config = $this->templateConfig();
        $config['width_mm'] = 54;
        $config['height_mm'] = 86;

        $this->postJson(route('impresion.plantilla.guardar'), ['config' => $config])
            ->assertOk();

        $version = CredentialTemplate::query()->firstOrFail()->versions()->firstOrFail();
        $this->postJson(route('impresion.plantilla.aprobar', $version))
            ->assertOk()
            ->assertJsonPath('version', 1);

        $template = CredentialTemplate::query()->firstOrFail();
        $this->assertSame(1, $template->published_version);
        $this->assertNotNull($template->approved_at);

        $this->get(route('impresion.plantilla.imprimir'))
            ->assertOk()
            ->assertSee('SOLO DEMOSTRACIÓN')
            ->assertSee('Anverso')
            ->assertSee('Reverso')
            ->assertSee('size: 54mm 86mm', false)
            ->assertSee('PERSONA DE DEMOSTRACIÓN');
    }

    public function test_template_dimensions_must_match_the_configured_physical_format(): void
    {
        $user = User::factory()->create();
        config(['credential-templates.editor_user_ids' => [(int) $user->getKey()]]);
        $this->actingAs($user);

        $config = $this->templateConfig();
        $config['width_mm'] = 55;

        $this->postJson(route('impresion.plantilla.guardar'), ['config' => $config])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.width_mm');
    }

    public function test_physical_dimensions_cannot_deviate_from_the_approved_card_format(): void
    {
        $user = User::factory()->create();
        config(['credential-templates.editor_user_ids' => [(int) $user->getKey()]]);
        $this->actingAs($user);

        $config = $this->templateConfig();
        $config['height_mm'] = 85;

        $this->postJson(route('impresion.plantilla.guardar'), ['config' => $config])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.height_mm');
    }

    public function test_authorized_user_can_upload_an_approved_image_resource_for_a_template_element(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        config(['credential-templates.editor_user_ids' => [(int) $user->getKey()]]);
        $this->actingAs($user);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p8sAAAAASUVORK5CYII=');

        $response = $this->postJson(route('impresion.plantilla.recurso.subir'), [
            'face' => 'front',
            'element_id' => 'firma_secretario',
            'resource' => UploadedFile::fake()->createWithContent('signature.png', $png),
        ]);

        $response->assertOk()
            ->assertJsonPath('image_aspect', 1);
        Storage::disk('local')->assertExists('credential-template-resources/'.$response->json('asset_path'));
        $this->get($response->json('asset_url'))->assertOk();
    }

    public function test_restore_creates_a_new_draft_without_changing_the_published_version(): void
    {
        $user = User::factory()->create();
        config([
            'credential-templates.editor_user_ids' => [(int) $user->getKey()],
            'credential-templates.approver_user_ids' => [(int) $user->getKey()],
        ]);
        $this->actingAs($user);

        $firstConfig = $this->templateConfig();
        $firstConfig['width_mm'] = 54;
        $firstConfig['height_mm'] = 86;
        $this->postJson(route('impresion.plantilla.guardar'), ['config' => $firstConfig])
            ->assertOk();
        $firstVersion = CredentialTemplate::query()->firstOrFail()->versions()->where('version', 1)->firstOrFail();
        $legacyConfig = $firstVersion->template_config;
        unset($legacyConfig['faces']['front']['elements'][0]['locked']);
        $legacyConfig['faces']['front']['elements'][0]['parameter'] = 'legacy_external_key';
        $firstVersion->forceFill(['template_config' => $legacyConfig])->save();
        $this->postJson(route('impresion.plantilla.aprobar', $firstVersion))
            ->assertOk();

        $secondConfig = $firstConfig;
        $secondConfig['name'] = 'Borrador de prueba siguiente';
        $this->postJson(route('impresion.plantilla.guardar'), ['config' => $secondConfig])
            ->assertOk();

        $this->postJson(route('impresion.plantilla.restaurar', $firstVersion))
            ->assertOk()
            ->assertJsonPath('version', 3);

        $template = CredentialTemplate::query()->firstOrFail();
        $this->assertSame($firstConfig['name'], $template->template_config['name']);
        $this->assertSame(1, $template->published_version);
        $this->assertSame($firstConfig['name'], $template->published_config['name']);
        $this->assertFalse($template->template_config['faces']['front']['elements'][0]['locked']);
        $this->assertArrayNotHasKey('parameter', $template->template_config['faces']['front']['elements'][0]);
        $this->assertDatabaseCount('credential_template_versions', 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateConfig(): array
    {
        $config = config('credential-templates.default');

        foreach ($config['faces'] as &$face) {
            foreach ($face['elements'] as &$element) {
                $element['font_family'] = 'Inter';
            }
        }

        return $config;
    }
}
