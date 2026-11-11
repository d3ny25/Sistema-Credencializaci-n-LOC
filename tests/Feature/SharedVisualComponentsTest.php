<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class SharedVisualComponentsTest extends TestCase
{
    use RefreshDatabase;
    /* =========================================================================
     * Componente: x-button
     * ========================================================================= */

    public function test_button_renders_primary_variant_by_default_with_slot_content(): void
    {
        $view = $this->blade('<x-button>Guardar cambios</x-button>');

        $view->assertSee('Guardar cambios');
        $view->assertSee('bg-inst-700');
        $view->assertSee('text-white');
        $view->assertSee('type="button"', false);
    }

    public function test_button_renders_secondary_and_danger_variants(): void
    {
        $secondary = $this->blade('<x-button variant="secondary">Cancelar</x-button>');
        $secondary->assertSee('Cancelar');
        $secondary->assertSee('border-slate-300');
        $secondary->assertSee('bg-white');

        $danger = $this->blade('<x-button variant="danger">Eliminar registro</x-button>');
        $danger->assertSee('Eliminar registro');
        $danger->assertSee('bg-estado-error');
        $danger->assertSee('hover:bg-red-800');

        $success = $this->blade('<x-button variant="success">Aprobar solicitud</x-button>');
        $success->assertSee('Aprobar solicitud');
        $success->assertSee('bg-estado-exito');

        $warning = $this->blade('<x-button variant="warning">Revisar advertencia</x-button>');
        $warning->assertSee('Revisar advertencia');
        $warning->assertSee('bg-estado-adv');
    }

    public function test_button_renders_different_sizes(): void
    {
        $sm = $this->blade('<x-button size="sm">Pequeño</x-button>');
        $sm->assertSee('text-xs');
        $sm->assertSee('px-3 py-1.5');

        $lg = $this->blade('<x-button size="lg">Grande</x-button>');
        $lg->assertSee('text-base');
        $lg->assertSee('px-5 py-2.5');
    }

    public function test_button_preserves_html_attributes_and_supports_submit_type(): void
    {
        $view = $this->blade('<x-button type="submit" id="btn-submit" name="accion" value="confirmar" data-custom="123">Enviar</x-button>');

        $view->assertSee('type="submit"', false);
        $view->assertSee('id="btn-submit"', false);
        $view->assertSee('name="accion"', false);
        $view->assertSee('value="confirmar"', false);
        $view->assertSee('data-custom="123"', false);
    }

    public function test_button_renders_disabled_state_when_explicitly_set(): void
    {
        $view = $this->blade('<x-button :disabled="true">Inactivo</x-button>');

        $view->assertSee('disabled', false);
        $view->assertSee('disabled:cursor-not-allowed');
    }

    public function test_button_renders_as_anchor_link_when_href_provided(): void
    {
        $view = $this->blade('<x-button href="https://example.com/test" variant="secondary">Ir al sitio</x-button>');

        $view->assertSee('<a href="https://example.com/test"', false);
        $view->assertSee('role="button"', false);
        $view->assertSee('Ir al sitio');
        $view->assertDontSee('<button');

        // Enlace deshabilitado no debe permitir interacción engañosa
        $disabledLink = $this->blade('<x-button href="https://example.com/test" variant="secondary" :disabled="true">Enlace inactivo</x-button>');
        $disabledLink->assertSee('pointer-events-none');
        $disabledLink->assertSee('aria-disabled="true"', false);
        $disabledLink->assertSee('onclick="return false;"', false);
    }

    public function test_button_renders_loading_state_with_spinner_and_custom_text(): void
    {
        $view = $this->blade('<x-button :loading="true" loading-text="Procesando lote...">Guardar</x-button>');

        $view->assertSee('Procesando lote...');
        $view->assertSee('animate-spin');
        $view->assertSee('aria-busy="true"', false);
        $view->assertSee('disabled', false);
        $view->assertDontSee('Guardar');
    }

    public function test_button_renders_icon_when_specified(): void
    {
        $view = $this->blade('<x-button icon="plus">Crear nuevo</x-button>');

        $view->assertSee('Crear nuevo');
        $view->assertSee('<svg', false);
    }

    /* =========================================================================
     * Componente: x-reason-badge
     * ========================================================================= */

    public function test_reason_badge_renders_empty_safely_when_reasons_is_empty(): void
    {
        $view = $this->blade('<x-reason-badge :reasons="[]" />');
        $view->assertSee('Descartado');

        $viewEmpty = $this->blade('<x-reason-badge :reasons="null" empty-text="Sin observaciones" />');
        $viewEmpty->assertSee('Sin observaciones');
    }

    public function test_reason_badge_renders_single_reason_with_singular_counter(): void
    {
        $reasons = ['CUIP no localizado en el padrón oficial'];
        $view = $this->blade('<x-reason-badge :reasons="$reasons" />', ['reasons' => $reasons]);

        $view->assertSee('Descartado (1 motivo)');
        $view->assertSee('CUIP no localizado en el padrón oficial');
        $view->assertSee('<details', false);
        $view->assertSee('<summary', false);
    }

    public function test_reason_badge_renders_multiple_reasons_with_plural_counter_and_preserves_all_texts(): void
    {
        $reasons = [
            'CUIP no localizado en el padrón oficial',
            'No cuenta con arma corta asignada',
            'Excede el límite máximo de 3 credenciales vigentes',
        ];

        $view = $this->blade('<x-reason-badge :reasons="$reasons" />', ['reasons' => $reasons]);

        $view->assertSee('Descartado (3 motivos)');
        $view->assertSee('CUIP no localizado en el padrón oficial');
        $view->assertSee('No cuenta con arma corta asignada');
        $view->assertSee('Excede el límite máximo de 3 credenciales vigentes');
        $view->assertSee('Motivos registrados (3)');
    }

    public function test_reason_badge_supports_inline_mode(): void
    {
        $reasons = ['Error A', 'Error B'];
        $view = $this->blade('<x-reason-badge :reasons="$reasons" mode="inline" />', ['reasons' => $reasons]);

        $view->assertSee('Descartado (2 motivos)');
        $view->assertSee('Error A');
        $view->assertSee('Error B');
        $view->assertDontSee('<details');
    }

    public function test_reason_badge_supports_different_tones(): void
    {
        $reasons = ['Advertencia de prueba'];
        $view = $this->blade('<x-reason-badge :reasons="$reasons" tone="warning" label="Observación" />', ['reasons' => $reasons]);

        $view->assertSee('Observación (1 motivo)');
        $view->assertSee('bg-amber-50');
        $view->assertSee('text-estado-adv');
    }

    public function test_reason_badge_escapes_html_in_reasons_to_prevent_xss(): void
    {
        $reasons = ['<script>alert("xss")</script>'];
        $view = $this->blade('<x-reason-badge :reasons="$reasons" />', ['reasons' => $reasons]);

        $view->assertDontSee('<script>', false);
        $view->assertSee('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', false);
    }

    /* =========================================================================
     * Componente: x-file-dropzone
     * ========================================================================= */

    public function test_file_dropzone_renders_default_elements_and_labels(): void
    {
        $view = $this->blade('<x-file-dropzone />');

        $view->assertSee('Selecciona o arrastra un archivo');
        $view->assertSee('Formatos admitidos: .xls');
        $view->assertSee('50.0 MB');
        $view->assertSee('Seleccionar archivo');
        $view->assertSee('type="file"', false);
        $view->assertSee('name="archivo"', false);
        $view->assertSee('id="archivo"', false);
        $view->assertSee('role="region"', false);
        $view->assertSee('x-data="{', false);
    }

    public function test_file_dropzone_customizes_name_id_accept_and_extensions(): void
    {
        $view = $this->blade('<x-file-dropzone name="solicitud_excel" id="drop-solicitud" accept=".xls,.xlsx" :allowed-extensions="[\'xls\', \'xlsx\']" :max-kilobytes="10240" label="Cargar archivo Excel" button-text="Examinar equipo" />');

        $view->assertSee('name="solicitud_excel"', false);
        $view->assertSee('id="drop-solicitud"', false);
        $view->assertSee('accept=".xls,.xlsx"', false);
        $view->assertSee('Cargar archivo Excel');
        $view->assertSee('Examinar equipo');
        $view->assertSee('10.0 MB');
        $view->assertSee('&quot;xls&quot;,&quot;xlsx&quot;', false);
    }

    public function test_file_dropzone_renders_disabled_state_when_configured(): void
    {
        $view = $this->blade('<x-file-dropzone :disabled="true" />');

        $view->assertSee('disabled', false);
        $view->assertSee('cursor-not-allowed');
    }

    public function test_file_dropzone_renders_server_error_properly(): void
    {
        $view = $this->withViewErrors(['archivo_doc' => 'El archivo es obligatorio en el servidor.'])
            ->blade('<x-file-dropzone name="archivo_doc" />');

        $view->assertSee('El archivo es obligatorio en el servidor.');
        $view->assertSee('role="alert"', false);
    }

    public function test_file_dropzone_renders_server_error_via_prop(): void
    {
        $view = $this->blade('<x-file-dropzone server-error="Error específico provisto manualmente." />');

        $view->assertSee('Error específico provisto manualmente.');
        $view->assertSee('role="alert"', false);
    }

    /* =========================================================================
     * Componente: x-status-badge
     * ========================================================================= */

    public function test_status_badge_renders_semantic_aliases_and_accessible_cues(): void
    {
        $completado = $this->blade('<x-status-badge label="Finalizado" status="completado" />');
        $completado->assertSee('Finalizado');
        $completado->assertSee('bg-emerald-50');
        $completado->assertSee('bg-emerald-600'); // dot accesible

        $pendiente = $this->blade('<x-status-badge label="En espera" status="pendiente" />');
        $pendiente->assertSee('En espera');
        $pendiente->assertSee('bg-amber-50');
        $pendiente->assertSee('bg-amber-600');

        $proceso = $this->blade('<x-status-badge label="Analizando" status="en_proceso" />');
        $proceso->assertSee('Analizando');
        $proceso->assertSee('bg-blue-50');
        $proceso->assertSee('bg-blue-600');

        $error = $this->blade('<x-status-badge label="Rechazado" status="error" />');
        $error->assertSee('Rechazado');
        $error->assertSee('bg-red-50');
        $error->assertSee('bg-red-600');

        $cancelado = $this->blade('<x-status-badge label="Anulado" status="cancelado" />');
        $cancelado->assertSee('Anulado');
        $cancelado->assertSee('bg-slate-100');

        $info = $this->blade('<x-status-badge label="Informativo" status="info" />');
        $info->assertSee('Informativo');
        $info->assertSee('bg-inst-50');

        // Estado desconocido debe caer en neutral de forma segura sin romper
        $desconocido = $this->blade('<x-status-badge label="Estado Raro" status="desconocido_no_mapeado" />');
        $desconocido->assertSee('Estado Raro');
        $desconocido->assertSee('bg-slate-100');
    }

    public function test_status_badge_renders_with_explicit_icon_and_slot_content(): void
    {
        $badge = $this->blade('<x-status-badge tone="success" icon="check">Verificado</x-status-badge>');
        $badge->assertSee('Verificado');
        $badge->assertSee('<svg', false);
    }

    /* =========================================================================
     * Vistas Representativas
     * ========================================================================= */

    public function test_representative_view_perfil_renders_status_badge_and_buttons(): void
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->get(route('perfil'));

        $response->assertOk();
        $response->assertSee('Estado de autenticación:');
        $response->assertSee('Autenticado');
        $response->assertSee('Cambiar fotografía · no disponible');
        $response->assertSee('Guardar perfil · no disponible');
        $response->assertSee('Cambiar contraseña · no disponible');
    }

    public function test_representative_view_gestion_credenciales_upload_card_renders_dropzone_and_button(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'superadmin']);

        $response = $this->actingAs($admin)->get(route('gestion'));

        $response->assertOk();
        $response->assertSee('Selecciona o arrastra el padrón de vigencias');
        $response->assertSee('Analizar vigencias');
        $response->assertSee('type="file"', false);
        $response->assertSee('name="archivo_vigencias"', false);
    }

    /* =========================================================================
     * Auxiliar: x-icon
     * ========================================================================= */

    public function test_icon_renders_newly_registered_svg_icons(): void
    {
        $upload = $this->blade('<x-icon name="upload" class="h-6 w-6" />');
        $upload->assertSee('<svg', false);
        $upload->assertSee('h-6 w-6');

        $check = $this->blade('<x-icon name="check" class="h-4 w-4" />');
        $check->assertSee('<polyline points="20 6 9 17 4 12"', false);

        $alert = $this->blade('<x-icon name="alert" class="h-4 w-4" />');
        $alert->assertSee('m21.73 18-8-14a2 2 0 0 0-3.48 0', false);

        $chevron = $this->blade('<x-icon name="chevronDown" class="h-3 w-3" />');
        $chevron->assertSee('m6 9 6 6 6-6', false);

        $file = $this->blade('<x-icon name="file" class="h-5 w-5" />');
        $file->assertSee('M14 2H6a2 2 0 0 0-2 2v16', false);
    }

    /* =========================================================================
     * Componente: x-import-progress-monitor
     * ========================================================================= */

    public function test_import_progress_monitor_renders_pending_state_with_queued_indicator(): void
    {
        $batch = new \App\Models\LocImportBatch([
            'id' => 991,
            'estado' => 'pendiente',
            'total_leidas' => 0,
        ]);

        $view = $this->blade(
            '<x-import-progress-monitor :batch="$batch" progress-url="/test/progreso" preview-url="/test/preview" pending-title="Análisis en cola de espera" />',
            ['batch' => $batch]
        );

        $view->assertSee('Análisis en cola de espera');
        $view->assertSee('role="status"', false);
        $view->assertSee('aria-live="polite"', false);
        $view->assertSee('data-progress-url="/test/progreso"', false);
        $view->assertSee('data-preview-url="/test/preview"', false);
        $view->assertSee('El trabajo espera un trabajador de cola');
        $view->assertSee('Consultar estado');
    }

    public function test_import_progress_monitor_renders_processing_state_with_metrics_and_stage(): void
    {
        $batch = new \App\Models\LocImportBatch([
            'id' => 992,
            'estado' => 'procesando',
            'total_leidas' => 1250,
        ]);

        $view = $this->blade(
            '<x-import-progress-monitor :batch="$batch" progress-url="/test/progreso" preview-url="/test/preview" processing-title="Analizando archivo..." :initial-estimated-rows="5000" initial-stage="validacion_estructuras" worker-command="php artisan queue:work" />',
            ['batch' => $batch]
        );

        $view->assertSee('Analizando archivo...');
        $view->assertSee('Filas procesadas: 1,250 de 5,000 estimadas');
        $view->assertSee('Etapa del servidor: validacion estructuras');
        $view->assertSee('data-worker-command="php artisan queue:work"', false);
    }

    public function test_gestion_screen_renders_all_four_unified_upload_cards(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'superadmin']);

        $response = $this->actingAs($admin)->get(route('gestion'));

        $response->assertOk();
        // 1. Personal
        $response->assertSee('Personal autorizado LOC');
        $response->assertSee('name="archivo_loc"', false);
        $response->assertSee('Analizar archivo');

        // 2. Armamento
        $response->assertSee('LOC · Armamento');
        $response->assertSee('name="archivo_armas"', false);
        $response->assertSee('Analizar armamento');

        // 3. Vigencias
        $response->assertSee('Base de credenciales');
        $response->assertSee('name="archivo_vigencias"', false);
        $response->assertSee('Analizar vigencias');

        // 4. Catálogo
        $response->assertSee('Catálogo organizacional');
        $response->assertSee('name="archivo_catalogo"', false);
        $response->assertSee('Analizar catálogo');
    }

    /* =========================================================================
     * Integración: Modularización de Credencialización
     * ========================================================================= */

    public function test_credencializacion_list_view_renders_module_tabs_metrics_and_modal(): void
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->get(route('credencializacion'));

        $response->assertOk();
        // Tabs del módulo
        $response->assertSee('Expedientes de solicitud');
        $response->assertSee('aria-current="page"', false);

        // Métricas superiores
        $response->assertSee('Solicitudes registradas');
        $response->assertSee('En proceso / Carga');
        $response->assertSee('Elementos aptos acumulados');
        $response->assertSee('Elementos descartados');

        // Formulario de filtro y botón nueva solicitud
        $response->assertSee('Oficio, tarjeta o área');
        $response->assertSee('Nueva solicitud');
        $response->assertSee('id="btn-nueva-solicitud"', false);

        // Modal presente
        $response->assertSee('id="modal-nueva-solicitud"', false);
        $response->assertSee('Registrar nueva solicitud de credencialización');
        $response->assertSee('name="numero_tarjeta"', false);
        $response->assertSee('name="numero_oficio"', false);
    }

    public function test_credencializacion_detail_view_renders_module_tabs_and_file_dropzone(): void
    {
        $user = \App\Models\User::factory()->create();
        $solicitud = \App\Models\SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-MOD-001',
            'numero_oficio' => 'OF-MOD-001',
            'estado_general' => 'recibida',
            'total_elementos' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('credencializacion.detalle', $solicitud->id));

        $response->assertOk();
        // Tabs con pestaña activa del expediente
        $response->assertSee('Expedientes de solicitud');
        $response->assertSee('Expediente: TAR-MOD-001');

        // Resumen
        $response->assertSee('TAR-MOD-001');
        $response->assertSee('OF-MOD-001');

        // Dropzone para carga de elementos
        $response->assertSee('Carga de elementos (Archivo Excel)');
        $response->assertSee('name="archivo"', false);
        $response->assertSee('accept=".xlsx,.xls', false);
        $response->assertSee('Cargar e importar elementos');
    }

    public function test_credencializacion_detail_view_renders_reason_badge_for_discarded_elements(): void
    {
        $user = \App\Models\User::factory()->create();
        $solicitud = \App\Models\SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-MOD-002',
            'numero_oficio' => 'OF-MOD-002',
            'estado_general' => 'evaluada',
            'total_elementos' => 2,
            'total_aptos' => 1,
            'total_descartados' => 1,
        ]);

        \App\Models\SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPAPTO01',
            'resultado_prefiltro' => 'Apto',
            'datos_solicitados' => ['nombre' => 'JUAN PEREZ'],
        ]);

        \App\Models\SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPDESC01',
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => [
                'CUIP no localizado en padrón oficial LOC.',
                'Arma corta sin registro activo.',
            ],
            'datos_solicitados' => ['nombre' => 'MARIA LOPEZ'],
        ]);

        $response = $this->actingAs($user)->get(route('credencializacion.detalle', $solicitud->id));

        $response->assertOk();
        $response->assertSee('Apto');
        // Badge de descarte con contador y lista de motivos accesible
        $response->assertSee('Descartado (2 motivos)');
        $response->assertSee('CUIP no localizado en padrón oficial LOC.');
        $response->assertSee('Arma corta sin registro activo.');
        $response->assertSee('<details', false);
        $response->assertSee('<summary', false);
    }
}
