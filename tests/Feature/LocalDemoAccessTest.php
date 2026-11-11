<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class LocalDemoAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_demo_mode_can_browse_protected_read_only_modules_without_creating_a_user(): void
    {
        $this->app['env'] = 'local';
        config([
            'app.env' => 'local',
            'local-demo.enabled' => true,
        ]);
        $this->assertTrue(app()->environment('local'));
        $this->assertTrue(config('local-demo.enabled'));
        $this->get(route('gestion'))
            ->assertOk()
            ->assertSee('Modo demo local de solo lectura')
            ->assertSee('Personal autorizado LOC');

        $this->get(route('administracion'))
            ->assertOk()
            ->assertSee('Modo demo local de solo lectura')
            ->assertSee('Administración');

        $this->get(route('administracion.dispositivos'))
            ->assertOk()
            ->assertSee('Modo demo local de solo lectura');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_demo_mode_refuses_write_actions_without_authenticating_or_persisting(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);
        config([
            'app.env' => 'local',
            'local-demo.enabled' => true,
        ]);

        $this->post(route('gestion.importaciones.subir'))
            ->assertForbidden()
            ->assertSee('solo lectura');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_demo_identity_has_read_permissions_but_no_write_permissions(): void
    {
        $this->app['env'] = 'local';
        config([
            'app.env' => 'local',
            'local-demo.enabled' => true,
        ]);
        $demo = new User;
        $demo->setRawAttributes([
            'id' => 0,
            'role' => 'local_demo',
            'local_demo_access' => true,
        ]);

        $this->assertTrue(Gate::forUser($demo)->allows('access-administration'));
        $this->assertTrue(Gate::forUser($demo)->allows('view-devices'));
        $this->assertTrue(Gate::forUser($demo)->allows('view-credential-template-versions'));
        $this->assertFalse(Gate::forUser($demo)->allows('manage-devices'));
        $this->assertFalse(Gate::forUser($demo)->allows('test-devices'));
        $this->assertFalse(Gate::forUser($demo)->allows('administer-devices'));
        $this->assertFalse(Gate::forUser($demo)->allows('manage-credential-templates'));
        $this->assertFalse(Gate::forUser($demo)->allows('approve-credential-templates'));
    }

    public function test_real_authorized_user_can_write_while_local_demo_is_enabled(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(PreventRequestForgery::class);
        config([
            'app.env' => 'local',
            'local-demo.enabled' => true,
        ]);
        $manager = User::factory()->create(['role' => 'operator']);
        config(['devices.permissions.manager_user_ids' => [(int) $manager->getKey()]]);

        $this->actingAs($manager)
            ->post(route('administracion.dispositivos.guardar'), [
                'name' => 'Dispositivo de prueba',
                'type' => 'zebra_zxp_series_8',
                'identifier' => 'DEMO-REAL-001',
                'location' => 'Laboratorio',
                'connection_method' => 'windows_print_dialog',
                'configuration' => [
                    'offset_x_mm' => '0',
                    'offset_y_mm' => '1.5',
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('devices', [
            'identifier' => 'DEMO-REAL-001',
            'created_by' => $manager->getKey(),
        ]);
    }

    public function test_disabled_local_demo_preserves_normal_authentication_behavior(): void
    {
        $this->app['env'] = 'local';
        config([
            'app.env' => 'local',
            'local-demo.enabled' => false,
        ]);

        $this->get(route('gestion'))
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_demo_flag_cannot_grant_access_outside_local_environment(): void
    {
        $this->app['env'] = 'staging';
        config([
            'app.env' => 'staging',
            'local-demo.enabled' => true,
        ]);

        $this->get(route('gestion'))
            ->assertRedirect(route('login'));

        $this->get(route('administracion'))
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_demo_refuses_to_read_the_operational_database(): void
    {
        $this->app['env'] = 'local';
        $defaultConnection = config('database.default');

        try {
            config([
                'app.env' => 'local',
                'local-demo.enabled' => true,
                'database.default' => 'mysql',
                'database.connections.mysql.database' => 'loc_credencializacion',
            ]);

            $this->get(route('gestion'))
                ->assertForbidden()
                ->assertSee('base operativa');
        } finally {
            config(['database.default' => $defaultConnection]);
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_demo_does_not_override_an_authenticated_users_real_permissions(): void
    {
        $this->app['env'] = 'local';
        config([
            'app.env' => 'local',
            'local-demo.enabled' => true,
        ]);
        $user = User::factory()->create(['role' => 'operador']);

        $this->actingAs($user)
            ->get(route('gestion'))
            ->assertForbidden();
    }
}
