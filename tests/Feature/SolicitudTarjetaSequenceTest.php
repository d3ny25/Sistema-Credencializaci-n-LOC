<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\SequenceNotInitializedException;
use App\Models\SolicitudCredencial;
use App\Models\User;
use App\Services\SolicitudTarjetaSequenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SolicitudTarjetaSequenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * A. Generación inicial: con la secuencia en 50000, la primera solicitud recibe 50001.
     */
    public function test_a_primera_solicitud_recibe_consecutivo_50001_y_formato_institucional(): void
    {
        $this->actingAs($this->user);

        // La migración ya inicializa solicitud_secuencias en 50000
        $this->assertSame(50000, (int) DB::table('solicitud_secuencias')->where('clave', 'tarjeta_credencial')->value('ultimo_consecutivo'));

        Carbon::setTestNow('2026-10-30 12:00:00');

        $response = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-TEST-0001',
            'fecha_recepcion' => '2026-10-30',
            'area_solicitante' => 'Coordinación Operativa Toluca',
            'observaciones' => 'Primera solicitud generada automáticamente',
        ]);

        $solicitud = SolicitudCredencial::where('numero_oficio', 'OF-TEST-0001')->firstOrFail();

        $this->assertSame('CE/TA25-27/50001/2026', $solicitud->numero_tarjeta);
        $this->assertSame(50001, $solicitud->consecutivo_tarjeta);
        $this->assertSame(50001, (int) DB::table('solicitud_secuencias')->where('clave', 'tarjeta_credencial')->value('ultimo_consecutivo'));

        $response->assertRedirect(route('credencializacion.detalle', $solicitud->id));
        $response->assertSessionHas('status');
    }

    /**
     * B. Incremento monótono continuo: solicitudes subsecuentes reciben 50002, 50003...
     */
    public function test_b_incremento_consecutivo_monotono_continuo(): void
    {
        $this->actingAs($this->user);
        Carbon::setTestNow('2026-10-30 12:00:00');

        // Solicitud 1 -> 50001
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-INCR-01',
            'fecha_recepcion' => '2026-10-30',
        ]);

        // Solicitud 2 -> 50002
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-INCR-02',
            'fecha_recepcion' => '2026-10-30',
        ]);

        // Solicitud 3 -> 50003
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-INCR-03',
            'fecha_recepcion' => '2026-10-30',
        ]);

        $sol1 = SolicitudCredencial::where('numero_oficio', 'OF-INCR-01')->firstOrFail();
        $sol2 = SolicitudCredencial::where('numero_oficio', 'OF-INCR-02')->firstOrFail();
        $sol3 = SolicitudCredencial::where('numero_oficio', 'OF-INCR-03')->firstOrFail();

        $this->assertSame('CE/TA25-27/50001/2026', $sol1->numero_tarjeta);
        $this->assertSame(50001, $sol1->consecutivo_tarjeta);

        $this->assertSame('CE/TA25-27/50002/2026', $sol2->numero_tarjeta);
        $this->assertSame(50002, $sol2->consecutivo_tarjeta);

        $this->assertSame('CE/TA25-27/50003/2026', $sol3->numero_tarjeta);
        $this->assertSame(50003, $sol3->consecutivo_tarjeta);

        $this->assertSame(50003, (int) DB::table('solicitud_secuencias')->where('clave', 'tarjeta_credencial')->value('ultimo_consecutivo'));
    }

    /**
     * C. Cambio de periodo y año: la secuencia numérica continúa ininterrumpida sin reiniciar.
     */
    public function test_c_cambio_de_periodo_y_anio_continua_secuencia_sin_reiniciar(): void
    {
        $this->actingAs($this->user);

        // Primera solicitud en 2026 con vigencia 25-27
        Carbon::setTestNow('2026-12-31 23:59:00');
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-ANIO-2026',
            'fecha_recepcion' => '2026-12-31',
        ]);
        $sol2026 = SolicitudCredencial::where('numero_oficio', 'OF-ANIO-2026')->firstOrFail();
        $this->assertSame('CE/TA25-27/50001/2026', $sol2026->numero_tarjeta);
        $this->assertSame(50001, $sol2026->consecutivo_tarjeta);

        // Simular cambio de año a 2027 (vigencia 25-27 se mantiene)
        Carbon::setTestNow('2027-01-01 08:00:00');
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-ANIO-2027',
            'fecha_recepcion' => '2027-01-01',
        ]);
        $sol2027 = SolicitudCredencial::where('numero_oficio', 'OF-ANIO-2027')->firstOrFail();
        $this->assertSame('CE/TA25-27/50002/2027', $sol2027->numero_tarjeta);
        $this->assertSame(50002, $sol2027->consecutivo_tarjeta);

        // Simular cambio de trienio institucional a '27-29'
        config(['credencializacion.tarjeta_periodo' => '27-29']);
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-TRIENIO-2729',
            'fecha_recepcion' => '2027-01-02',
        ]);
        $solTrienio = SolicitudCredencial::where('numero_oficio', 'OF-TRIENIO-2729')->firstOrFail();
        $this->assertSame('CE/TA27-29/50003/2027', $solTrienio->numero_tarjeta);
        $this->assertSame(50003, $solTrienio->consecutivo_tarjeta);

        // El registro previo histórico de 2026 nunca fue mutado
        $this->assertSame('CE/TA25-27/50001/2026', $sol2026->fresh()->numero_tarjeta);
    }

    /**
     * D. Rollback transaccional: un fallo no consume el consecutivo ni deja expedientes corruptos.
     */
    public function test_d_rollback_ante_fallo_no_consume_consecutivo(): void
    {
        $this->actingAs($this->user);
        Carbon::setTestNow('2026-10-30 12:00:00');

        // Primera solicitud exitosa -> 50001
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-PREVIO-01',
            'fecha_recepcion' => '2026-10-30',
        ]);
        $this->assertSame(50001, (int) DB::table('solicitud_secuencias')->where('clave', 'tarjeta_credencial')->value('ultimo_consecutivo'));

        // Provocar fallo por colisión de oficio único
        $responseFallo = $this->from(route('credencializacion'))->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-PREVIO-01', // Oficio duplicado que lanza QueryException 23000
            'fecha_recepcion' => '2026-10-30',
        ]);

        $responseFallo->assertSessionHasErrors(['numero_oficio']);

        // El contador en base de datos NO debe haberse incrementado a 50002
        $this->assertSame(50001, (int) DB::table('solicitud_secuencias')->where('clave', 'tarjeta_credencial')->value('ultimo_consecutivo'));
        $this->assertSame(1, SolicitudCredencial::count());

        // La siguiente solicitud exitosa debe recibir limpiamente el 50002
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-SIGUIENTE-02',
            'fecha_recepcion' => '2026-10-30',
        ]);
        $sol2 = SolicitudCredencial::where('numero_oficio', 'OF-SIGUIENTE-02')->firstOrFail();
        $this->assertSame('CE/TA25-27/50002/2026', $sol2->numero_tarjeta);
        $this->assertSame(50002, $sol2->consecutivo_tarjeta);
    }

    /**
     * E. Cancelación: cancelar una solicitud no libera ni permite reutilizar su consecutivo.
     */
    public function test_e_cancelacion_no_libera_ni_reutiliza_el_numero(): void
    {
        $this->actingAs($this->user);
        Carbon::setTestNow('2026-10-30 12:00:00');

        // Solicitud que será cancelada
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-PARA-CANCELAR',
            'fecha_recepcion' => '2026-10-30',
        ]);
        $solicitudCancelada = SolicitudCredencial::where('numero_oficio', 'OF-PARA-CANCELAR')->firstOrFail();
        $this->assertSame('CE/TA25-27/50001/2026', $solicitudCancelada->numero_tarjeta);
        $this->assertSame(50001, $solicitudCancelada->consecutivo_tarjeta);

        // Cancelar formalmente la solicitud
        $solicitudCancelada->update(['estado_general' => 'cancelada']);

        // Crear una nueva solicitud: debe recibir 50002, NUNCA 50001
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-NUEVO-SUBSECUENTE',
            'fecha_recepcion' => '2026-10-30',
        ]);

        $solicitudNueva = SolicitudCredencial::where('numero_oficio', 'OF-NUEVO-SUBSECUENTE')->firstOrFail();
        $this->assertSame('CE/TA25-27/50002/2026', $solicitudNueva->numero_tarjeta);
        $this->assertSame(50002, $solicitudNueva->consecutivo_tarjeta);

        // La solicitud cancelada conserva permanentemente su número asignado
        $this->assertSame('CE/TA25-27/50001/2026', $solicitudCancelada->fresh()->numero_tarjeta);
        $this->assertSame('cancelada', $solicitudCancelada->fresh()->estado_general);
    }

    /**
     * F. Concurrencia e integridad de secuencia atómica.
     */
    public function test_f_generacion_secuencial_sin_colision_con_bloqueo(): void
    {
        $service = app(SolicitudTarjetaSequenceService::class);

        // Generar 20 números consecutivos mediante el servicio en transacciones consecutivas
        $generados = [];
        for ($i = 0; $i < 20; $i++) {
            $res = DB::transaction(fn () => $service->generateNextNumber());
            $generados[] = $res;
        }

        $consecutivos = array_column($generados, 'consecutivo_tarjeta');
        $tarjetas = array_column($generados, 'numero_tarjeta');

        // Deben ser exactamente 20 únicos
        $this->assertCount(20, array_unique($consecutivos));
        $this->assertCount(20, array_unique($tarjetas));

        // El primero debe ser 50001 y el último 50020
        $this->assertSame(50001, $consecutivos[0]);
        $this->assertSame(50020, $consecutivos[19]);
    }

    /**
     * G. Validación del cliente: petición manipulada que envíe numero_tarjeta es rechazada.
     */
    public function test_g_rechazo_de_peticiones_que_intentan_enviar_numero_tarjeta_manual(): void
    {
        $this->actingAs($this->user);

        // 1. Envío manual de numero_tarjeta
        $responseTarjeta = $this->post(route('credencializacion.store'), [
            'numero_tarjeta' => 'CE/TA25-27/99999/2026',
            'numero_oficio' => 'OF-MANIP-01',
            'fecha_recepcion' => '2026-10-30',
        ]);
        $responseTarjeta->assertSessionHasErrors(['numero_tarjeta']);

        // 2. Envío manual de consecutivo_tarjeta
        $responseConsecutivo = $this->post(route('credencializacion.store'), [
            'consecutivo_tarjeta' => 99999,
            'numero_oficio' => 'OF-MANIP-02',
            'fecha_recepcion' => '2026-10-30',
        ]);
        $responseConsecutivo->assertSessionHasErrors(['consecutivo_tarjeta']);

        $this->assertSame(0, SolicitudCredencial::count());
    }

    /**
     * H. Regresión y compatibilidad: registros históricos y campos complementarios.
     */
    public function test_h_regresion_persistencia_de_campos_y_compatibilidad_historica(): void
    {
        $this->actingAs($this->user);

        // 1. Registro histórico previo con consecutivo NULL
        $historica = SolicitudCredencial::create([
            'numero_tarjeta' => 'TJ-2026-HISTORICA-001',
            'consecutivo_tarjeta' => null,
            'numero_oficio' => 'OF-HIST-001',
            'fecha_recepcion' => '2026-01-15',
            'area_solicitante' => 'Área Antigua',
            'observaciones' => 'Registro previo a la implementación',
        ]);

        $this->assertNull($historica->consecutivo_tarjeta);
        $this->assertSame('TJ-2026-HISTORICA-001', $historica->numero_tarjeta);

        // 2. Registro nuevo mediante el flujo oficial
        $response = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-NUEVO-001',
            'fecha_recepcion' => '2026-10-30',
            'area_solicitante' => 'Dirección General de Seguridad Pública',
            'observaciones' => 'Notas institucionales completas',
        ]);

        $nueva = SolicitudCredencial::where('numero_oficio', 'OF-NUEVO-001')->firstOrFail();
        $this->assertSame('CE/TA25-27/50001/2026', $nueva->numero_tarjeta);
        $this->assertSame(50001, $nueva->consecutivo_tarjeta);
        $this->assertSame('Dirección General de Seguridad Pública', $nueva->area_solicitante);
        $this->assertSame('Notas institucionales completas', $nueva->observaciones);
        $this->assertSame('recibida', $nueva->estado_general);

        // Ambas solicitudes coexisten sin conflicto de unicidad
        $this->assertSame(2, SolicitudCredencial::count());
    }

    /**
     * I. Excepción controlada si la secuencia no fue inicializada.
     */
    public function test_i_excepcion_tipada_si_secuencia_no_esta_inicializada(): void
    {
        $this->actingAs($this->user);

        // Eliminar deliberadamente la fila de secuencia para probar fallback defensivo
        DB::table('solicitud_secuencias')->where('clave', 'tarjeta_credencial')->delete();

        $this->expectException(SequenceNotInitializedException::class);
        $this->expectExceptionMessage("La secuencia institucional 'tarjeta_credencial' no está inicializada");

        app(SolicitudTarjetaSequenceService::class)->generateNextNumber();
    }
}

