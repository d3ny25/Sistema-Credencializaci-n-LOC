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
        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSee('Personas en la LOC')
            ->assertSee('Avance del proceso')
            ->assertSee('Solicitudes recientes')
            // El menú solo ofrece módulos que la ruta permite: sin rol no hay Gestión.
            ->assertDontSee('>Gestión de información</span>', false);
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
        // 3 sin evaluar, 4 aptos (1 pendiente de biometría, 2 listos para impresión), 1 descartado.
        $elementos = [
            [null, 'pendiente'], [null, 'pendiente'], [null, 'pendiente'],
            ['Apto', 'pendiente_biometrico'], ['Apto', 'listo_impresion'], ['Apto', 'listo_impresion'], ['Apto', 'apto'],
            ['Descartado', 'descartado'],
        ];
        foreach ($elementos as [$resultado, $estado]) {
            SolicitudElemento::query()->create([
                'solicitud_id' => $request->id,
                'resultado_prefiltro' => $resultado,
                'estado_tramite' => $estado,
            ]);
        }

        foreach (range(1, 5) as $index) {
            CredencialEmitida::query()->create([
                'solicitud_id' => $request->id,
                'folio' => 'DASHBOARD-FOLIO-'.$index,
            ]);
        }

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder([
                'Solicitudes recibidas', '3', 'Solicitudes persistidas',
                'Elementos en validación', '3', 'Sin resultado de prefiltro',
                'Aptos para impresión', '4', 'Resultado de prefiltro apto',
                'Descartados', '1', 'Resultado de prefiltro descartado',
                'Pendientes de biometría', '1',
                'Listos para impresión', '2',
                'Credenciales registradas', '5', 'Incluye cualquier estado de emisión',
                'Personas en la LOC', '2', 'Registros persistidos',
                // Barras: evaluados 5/8, aptos 4/5, listos 2/4, credenciales 5/4 (tope 100).
                'Evaluados en prefiltro', '63%',
                'Aptos sobre evaluados', '80%',
                'Listos para impresión', '50%',
                'Credenciales registradas', '100%',
            ]);
    }

    public function test_dashboard_progress_bars_are_zero_without_records(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Evaluados en prefiltro', '0%', 'Aptos sobre evaluados', '0%']);
    }

    public function test_root_redirects_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_module_screens_render_with_the_shared_institutional_layout(): void
    {
        // Credencialización e impresión exigen sesión desde la Fase 0.
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));

        $routes = [
            ['credencializacion', []],
            ['credencializacion.detalle', ['solicitud' => 'referencia']],
            ['impresion', []],
            ['impresion.biometricos', []],
            ['consultas', []],
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
