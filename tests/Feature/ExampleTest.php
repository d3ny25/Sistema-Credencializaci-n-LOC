<?php

namespace Tests\Feature;

use App\Models\CredencialEmitida;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_the_institutional_base_screen(): void
    {
        $response = $this->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSee('Personas en la LOC')
            ->assertSee('Gestión de información')
            ->assertSee('Avance del proceso')
            ->assertSee('Sin avisos disponibles');
    }

    public function test_dashboard_indicators_use_persisted_record_counts(): void
    {
        foreach (range(1, 2) as $index) {
            LocPersona::query()->create([
                'cuip' => 'DASHBOARD-CUIP-'.$index,
                'nombre' => 'Persona '.$index,
            ]);
        }

        foreach (range(1, 3) as $index) {
            SolicitudCredencial::query()->create([
                'numero_tarjeta' => 'DASHBOARD-TARJETA-'.$index,
                'numero_oficio' => 'DASHBOARD-OFICIO-'.$index,
            ]);
        }

        $request = SolicitudCredencial::query()->firstOrFail();
        foreach (range(1, 4) as $index) {
            SolicitudElemento::query()->create([
                'solicitud_id' => $request->id,
            ]);
        }

        foreach (range(1, 5) as $index) {
            CredencialEmitida::query()->create([
                'solicitud_id' => $request->id,
                'folio' => 'DASHBOARD-FOLIO-'.$index,
            ]);
        }

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Personas en la LOC',
                '2',
                'Registros persistidos',
                'Solicitudes recibidas',
                '3',
                'Solicitudes persistidas',
                'Elementos en solicitudes',
                '4',
                'Elementos persistidos',
                'Credenciales registradas',
                '5',
                'Incluye cualquier estado de emisión',
            ]);
    }

    public function test_root_redirects_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_module_screens_render_with_the_shared_institutional_layout(): void
    {
        $routes = [
            ['credencializacion', []],
            ['credencializacion.detalle', ['solicitud' => 'referencia']],
            ['impresion', []],
            ['impresion.biometricos', []],
            ['consultas', []],
            ['consultas.expediente', ['elemento' => 'referencia']],
            ['reportes', []],
            ['avisos', []],
            ['perfil', []],
        ];

        foreach ($routes as [$name, $parameters]) {
            $this->get(route($name, $parameters))
                ->assertOk()
                ->assertSee('Sistema Estatal')
                ->assertSee('Navegación principal');
        }

        $sections = array_map(fn (string $section) => ['entregas', ['section' => $section]], [
            'panel', 'credenciales-recibidas', 'pendientes', 'entregas', 'retenidas',
            'confirmaciones', 'conciliacion', 'historial',
        ]);

        foreach ($sections as [$name, $parameters]) {
            $this->get(route($name, $parameters))
                ->assertOk()
                ->assertSee('Sistema Estatal')
                ->assertSee('Navegación principal');
        }

        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();
        $this->actingAs($administrator);

        $this->get(route('gestion'))
            ->assertOk()
            ->assertSee('Personal autorizado LOC')
            ->assertSee('Analizar archivo');
        $this->get(route('gestion.historial'))
            ->assertOk()
            ->assertSee('Historial de importaciones');

        foreach (['usuarios', 'roles', 'catalogos', 'avisos', 'bitacora'] as $section) {
            $this->get(route('administracion', ['section' => $section]))
                ->assertOk()
                ->assertSee('Sistema Estatal')
                ->assertSee('Navegación principal')
                ->assertSee('Gestión de dispositivos');
        }

        $this->get(route('administracion'))
            ->assertOk()
            ->assertSee('Administración')
            ->assertSee('Diseño de credencial')
            ->assertSee(route('impresion.plantilla'), false)
            ->assertSee('Gestión de dispositivos')
            ->assertSee(route('administracion.dispositivos'), false);

        $this->get(route('impresion.plantilla'))
            ->assertOk()
            ->assertSee('Administración')
            ->assertSee('Diseño de credencial')
            ->assertSee('Gestión de dispositivos')
            ->assertSee('Editor de plantilla');
    }

    public function test_unimplemented_module_sections_return_not_found(): void
    {
        $this->get('/entregas/no-existe')->assertNotFound();

        $administrator = User::factory()->create();
        $administrator->forceFill(['role' => 'superadmin'])->save();

        $this->actingAs($administrator)
            ->get('/administracion/no-existe')
            ->assertNotFound();
    }

    public function test_administration_is_reserved_for_authenticated_super_administrators(): void
    {
        $this->get(route('administracion'))
            ->assertRedirect(route('login'));

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('administracion'))
            ->assertForbidden();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('>Administración</span>', false);

        $user->forceFill(['role' => 'superadmin'])->save();
        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'role' => 'superadmin',
        ]);

        $this->get(route('administracion'))
            ->assertOk()
            ->assertSee('Gestión de dispositivos');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('>Administración</span>', false);
        $this->get('/administracion/no-existe')->assertNotFound();
    }

    public function test_login_screen_has_an_active_authentication_form_without_demo_credentials(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('Iniciar sesión')
            ->assertDontSee('admin123')
            ->assertDontSee('usuario123');
    }
}
