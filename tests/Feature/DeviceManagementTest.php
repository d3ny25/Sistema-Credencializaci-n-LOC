<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_routes_require_authentication_and_an_explicit_view_permission(): void
    {
        $this->get(route('administracion.dispositivos'))
            ->assertRedirect(route('login'));

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('administracion.dispositivos'))
            ->assertForbidden();
    }

    public function test_manager_can_register_a_catalog_device_and_invalid_configuration_is_rejected(): void
    {
        $user = User::factory()->create();
        config(['devices.permissions.manager_user_ids' => [(int) $user->getKey()]]);

        $response = $this->actingAs($user)->post(route('administracion.dispositivos.guardar'), [
            'name' => 'Zebra de estación 01',
            'type' => 'zebra_zxp_series_8',
            'identifier' => 'ZXP-001',
            'location' => 'Estación 01',
            'connection_method' => 'windows_print_dialog',
            'configuration' => [
                'offset_x_mm' => '0',
                'offset_y_mm' => '1.5',
                'password' => 'not-allowed',
            ],
        ]);

        $response->assertSessionHasErrors('configuration');
        $this->assertDatabaseCount('devices', 0);

        $response = $this->post(route('administracion.dispositivos.guardar'), [
            'name' => 'Zebra de estación 01',
            'type' => 'zebra_zxp_series_8',
            'identifier' => 'ZXP-001',
            'location' => 'Estación 01',
            'connection_method' => 'windows_print_dialog',
            'configuration' => [
                'offset_x_mm' => '0',
                'offset_y_mm' => '1.5',
            ],
        ]);

        $device = Device::query()->firstOrFail();
        $response->assertRedirect(route('administracion.dispositivos.detalle', $device));
        $this->assertSame('Zebra', $device->manufacturer);
        $this->assertSame('ZXP Series 8', $device->model);
        $this->assertSame('pending', $device->administrative_status);
        $this->assertSame('unknown', $device->connection_status);
        $this->assertNull($device->last_test_result);
        $this->assertSame('0', $device->configuration['offset_x_mm']);
        $this->assertDatabaseHas('device_events', [
            'device_id' => $device->getKey(),
            'event_type' => 'registered',
        ]);
    }

    public function test_manager_can_register_a_device_without_another_types_hidden_configuration(): void
    {
        $user = User::factory()->create();
        config(['devices.permissions.manager_user_ids' => [(int) $user->getKey()]]);

        $response = $this->actingAs($user)->post(route('administracion.dispositivos.guardar'), [
            'name' => 'Cámara de estación 02',
            'type' => 'canon_eos_rebel_t6',
            'identifier' => 'CANON-002',
            'location' => 'Estación 02',
            'connection_method' => 'eos_utility_folder',
        ]);

        $device = Device::query()->firstOrFail();
        $response->assertRedirect(route('administracion.dispositivos.detalle', $device));
        $this->assertSame([], $device->configuration);
    }

    public function test_administrator_can_change_administrative_state_but_manager_cannot(): void
    {
        $manager = User::factory()->create();
        $administrator = User::factory()->create();
        config([
            'devices.permissions.manager_user_ids' => [(int) $manager->getKey()],
            'devices.permissions.administrator_user_ids' => [(int) $administrator->getKey()],
        ]);
        $device = $this->makeDevice($manager);

        $this->actingAs($manager)
            ->patch(route('administracion.dispositivos.estado', $device), ['status' => 'enabled'])
            ->assertForbidden();

        $this->actingAs($administrator)
            ->patch(route('administracion.dispositivos.estado', $device), ['status' => 'enabled'])
            ->assertRedirect(route('administracion.dispositivos.detalle', $device));

        $this->assertSame('enabled', $device->fresh()->administrative_status);
        $this->assertDatabaseHas('device_events', [
            'device_id' => $device->getKey(),
            'event_type' => 'status_changed',
        ]);
    }

    public function test_manager_can_render_registration_edit_and_detail_screens(): void
    {
        $manager = User::factory()->create();
        config(['devices.permissions.manager_user_ids' => [(int) $manager->getKey()]]);
        $this->actingAs($manager);

        $this->get(route('administracion.dispositivos.crear'))
            ->assertOk()
            ->assertSee('Nombre identificativo')
            ->assertSee('Canon EOS Rebel T6')
            ->assertSee('Diálogo de impresión')
            ->assertSee('field.disabled = !isVisible;', false);

        $device = $this->makeDevice($manager);

        $this->get(route('administracion.dispositivos.editar', $device))
            ->assertOk()
            ->assertSee('Editar configuración registrada')
            ->assertSee('Zebra de prueba');

        $this->get(route('administracion.dispositivos.detalle', $device))
            ->assertOk()
            ->assertSee('No verificada por Laravel')
            ->assertSee('Historial de cambios y comprobaciones')
            ->assertSee('Zebra de prueba');
    }

    public function test_manager_can_update_a_device_and_the_change_is_persisted_in_history(): void
    {
        $manager = User::factory()->create();
        config(['devices.permissions.manager_user_ids' => [(int) $manager->getKey()]]);
        $device = $this->makeDevice($manager);

        $this->actingAs($manager)
            ->put(route('administracion.dispositivos.actualizar', $device), [
                'type' => 'zebra_zxp_series_8',
                'name' => 'Zebra estación norte',
                'identifier' => 'ZXP-N-01',
                'location' => 'Estación norte',
                'connection_method' => 'windows_print_dialog',
                'configuration' => [
                    'offset_x_mm' => '-0.5',
                    'offset_y_mm' => '1',
                ],
            ])
            ->assertRedirect(route('administracion.dispositivos.detalle', $device));

        $device->refresh();
        $this->assertSame('Zebra estación norte', $device->name);
        $this->assertSame('ZXP-N-01', $device->identifier);
        $this->assertSame('Estación norte', $device->location);
        $this->assertSame('-0.5', $device->configuration['offset_x_mm']);
        $this->assertDatabaseHas('device_events', [
            'device_id' => $device->getKey(),
            'event_type' => 'updated',
        ]);
    }

    public function test_viewer_cannot_manage_test_or_change_device_status(): void
    {
        $viewer = User::factory()->create();
        config(['devices.permissions.viewer_user_ids' => [(int) $viewer->getKey()]]);
        $device = $this->makeDevice($viewer);

        $this->actingAs($viewer)
            ->get(route('administracion.dispositivos.editar', $device))
            ->assertForbidden();

        $this->post(route('administracion.dispositivos.diagnostico', $device))
            ->assertForbidden();

        $this->patch(route('administracion.dispositivos.estado', $device), ['status' => 'disabled'])
            ->assertForbidden();

        $this->get(route('administracion'))
            ->assertForbidden();
    }

    public function test_device_administrator_can_access_management_from_administration(): void
    {
        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();
        $device = $this->makeDevice($administrator);

        $this->actingAs($administrator)
            ->get(route('administracion'))
            ->assertOk()
            ->assertSee('Gestión de dispositivos')
            ->assertSee(route('administracion.dispositivos'), false);

        $this->get(route('administracion.dispositivos'))
            ->assertOk()
            ->assertSee('Registrar dispositivo')
            ->assertSee('Zebra de prueba');

        $this->get(route('administracion.dispositivos.crear'))
            ->assertOk()
            ->assertSee('Nombre identificativo');

        $this->get(route('administracion.dispositivos.editar', $device))
            ->assertOk()
            ->assertSee('Editar configuración registrada');

        $this->patch(route('administracion.dispositivos.estado', $device), ['status' => 'enabled'])
            ->assertRedirect(route('administracion.dispositivos.detalle', $device));
        $this->assertSame('enabled', $device->fresh()->administrative_status);

        $this->post(route('administracion.dispositivos.diagnostico', $device))
            ->assertRedirect(route('administracion.dispositivos.detalle', $device));
    }

    public function test_diagnostic_is_persisted_as_unavailable_without_claiming_physical_connectivity(): void
    {
        $user = User::factory()->create();
        config(['devices.permissions.tester_user_ids' => [(int) $user->getKey()]]);
        $device = $this->makeDevice($user, 'canon_eos_rebel_t6', 'eos_utility_folder');

        $this->actingAs($user)
            ->post(route('administracion.dispositivos.diagnostico', $device))
            ->assertRedirect(route('administracion.dispositivos.detalle', $device))
            ->assertSessionHas('status', 'Diagnóstico registrado: no disponible desde el servidor. No se ejecutó una prueba física.');

        $device->refresh();
        $this->assertSame(DeviceEvent::RESULT_UNAVAILABLE, $device->last_test_result);
        $this->assertSame('unknown', $device->connection_status);
        $this->assertNotNull($device->last_checked_at);
        $this->assertStringContainsString('carpeta local', $device->last_test_message);
        $this->assertDatabaseHas('device_events', [
            'device_id' => $device->getKey(),
            'event_type' => 'diagnostic',
            'operation' => 'server_hardware_availability',
            'result' => 'unavailable',
            'duration_ms' => null,
        ]);
    }

    public function test_inventory_uses_database_counts_and_search_filters(): void
    {
        $viewer = User::factory()->create();
        config(['devices.permissions.viewer_user_ids' => [(int) $viewer->getKey()]]);
        $zebra = $this->makeDevice($viewer);
        $zebra->update(['administrative_status' => 'enabled']);
        $this->makeDevice($viewer, 'topaz_siglite_lcd_1x5', 'sigweb_local_service', 'Topaz de otra estación');

        $response = $this->actingAs($viewer)
            ->get(route('administracion.dispositivos', [
                'search' => 'ZXP',
                'status' => 'enabled',
                'type' => 'zebra_zxp_series_8',
            ]));

        $response->assertOk()
            ->assertSee('Dispositivos registrados')
            ->assertSee('Diseño de credencial')
            ->assertSee('Gestión de dispositivos')
            ->assertSee('Zebra de prueba')
            ->assertDontSee('Topaz de otra estación');
    }

    private function makeDevice(User $user, string $type = 'zebra_zxp_series_8', string $method = 'windows_print_dialog', string $name = 'Zebra de prueba'): Device
    {
        return Device::query()->create([
            'name' => $name,
            'type' => $type,
            'manufacturer' => config('devices.types.'.$type.'.manufacturer'),
            'model' => config('devices.types.'.$type.'.model'),
            'identifier' => null,
            'location' => null,
            'connection_method' => $method,
            'configuration' => [],
            'administrative_status' => 'pending',
            'connection_status' => 'unknown',
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);
    }
}
