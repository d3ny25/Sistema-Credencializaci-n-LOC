<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SolicitudElemento;
use App\Models\User;
use App\Support\CapturaTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;

/**
 * Contrato del agente de estación compilado (agente-estacion/bin/AgenteEstacion.exe) ejecutado en MODO SIMULACIÓN
 * o sin SDK. Comprueba seguridad y protocolo con tickets reales de Laravel; NO prueba ninguna cámara física.
 * Compile antes con agente-estacion\compilar.cmd; si el ejecutable no existe, las pruebas se omiten.
 */
class AgenteEstacionSimuladoTest extends TestCase
{
    use PreparaImpresionReal;
    use RefreshDatabase;

    private const PUERTO = 47398;
    private const ORIGEN = 'http://localhost:8000';
    private const SECRETO = 'secreto-de-prueba-del-agente-0123456789abcdef';

    private ?Process $agente = null;
    private string $configuracion;

    protected function setUp(): void
    {
        parent::setUp();
        $exe = base_path('agente-estacion/bin/AgenteEstacion.exe');
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file($exe)) {
            $this->markTestSkipped('Agente de estación no compilado o sistema distinto de Windows.');
        }

        $this->fakeDiscosEvidencia();
        config(['devices.agente_estacion.secreto' => self::SECRETO]);
        $this->configuracion = tempnam(sys_get_temp_dir(), 'agente').'.json';
        file_put_contents($this->configuracion, json_encode(['puerto' => self::PUERTO, 'origenPermitido' => self::ORIGEN, 'secretoEstacion' => self::SECRETO]));
    }

    protected function tearDown(): void
    {
        $this->agente?->stop(1);
        if (isset($this->configuracion) && is_file($this->configuracion)) {
            unlink($this->configuracion);
        }
        parent::tearDown();
    }

    /** @param  list<string>  $extra  banderas adicionales (p. ej. --simulado-lector=en-uso) */
    private function iniciar(?string $simulado, array $extra = [], array $configExtra = []): void
    {
        if ($configExtra !== []) {
            file_put_contents($this->configuracion, json_encode(['puerto' => self::PUERTO, 'origenPermitido' => self::ORIGEN, 'secretoEstacion' => self::SECRETO, ...$configExtra]));
        }
        $args = [base_path('agente-estacion/bin/AgenteEstacion.exe'), '--config', $this->configuracion, ...$extra];
        if ($simulado !== null) {
            $args[] = '--simulado='.$simulado;
        }
        $this->agente = new Process($args);
        $this->agente->start();

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::PUERTO, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        $this->fail('El agente no abrió el puerto: '.$this->agente->getErrorOutput());
    }

    private function llamar(string $metodo, string $ruta, array $cabeceras = []): Response
    {
        return Http::timeout(5)->withHeaders(['Origin' => self::ORIGEN, ...$cabeceras])->send($metodo, 'http://127.0.0.1:'.self::PUERTO.$ruta);
    }

    private ?SolicitudElemento $elemento = null;

    private ?User $impresor = null;

    private function ticketValido(string $categoria = 'foto'): string
    {
        $this->elemento ??= $this->elementoImprimible();
        $this->impresor ??= User::factory()->create(['role' => 'impresion']);

        return CapturaTicket::emitir($this->elemento, $this->impresor, $categoria);
    }

    private function abrirSesionLector(string $categoria = 'pulgar_derecho'): Response
    {
        return $this->llamar('POST', '/v1/huella/sesiones', ['Authorization' => 'Bearer '.$this->ticketValido($categoria)]);
    }

    // ---- Impresora Zebra: monitor de cola de solo lectura ------------------------------------

    private function abrirSeguimiento(string $ticket): Response
    {
        return $this->llamar('POST', '/v1/impresion/sesiones', ['Authorization' => 'Bearer '.$ticket]);
    }

    public function test_seguimiento_simulado_sigue_el_trabajo_por_su_documento_hasta_que_sale_de_la_cola(): void
    {
        $this->iniciar(null, ['--simulado-impresora']);
        $estado = $this->llamar('GET', '/v1/impresion/estado');
        $this->assertSame('lista', $estado->json('estado'));
        $this->assertTrue($estado->json('simulacion'));

        $ticket = $this->ticketValido('impresion');
        $sesion = $this->abrirSeguimiento($ticket);
        $this->assertSame(201, $sesion->status());
        $this->assertSame('LOC139-'.CapturaTicket::nonce($ticket), $sesion->json('documento'));

        $estados = [];
        for ($i = 0; $i < 4; $i++) {
            $trabajo = $this->llamar('GET', '/v1/impresion/trabajo', ['X-Sesion' => $sesion->json('sesion')]);
            $estados[] = $trabajo->json('estado');
            $this->assertStringContainsString('no confirma', $trabajo->json('nota'));
        }
        $this->assertSame(['en_cola', 'imprimiendo', 'completado_en_cola', 'salio_de_cola'], $estados);
    }

    public function test_los_tickets_de_impresion_y_de_captura_no_son_intercambiables(): void
    {
        $this->iniciar('conectada', ['--simulado-lector', '--simulado-impresora']);

        $this->assertSame('ticket_invalido', $this->abrirSeguimiento($this->ticketValido('foto'))->json('codigo'));
        $this->assertSame('ticket_invalido', $this->abrirSeguimiento($this->ticketValido('pulgar_derecho'))->json('codigo'));
        $this->assertSame('ticket_invalido', $this->llamar('POST', '/v1/sesiones', ['Authorization' => 'Bearer '.$this->ticketValido('impresion')])->json('codigo'));
        $this->assertSame('ticket_invalido', $this->llamar('POST', '/v1/huella/sesiones', ['Authorization' => 'Bearer '.$this->ticketValido('impresion')])->json('codigo'));

        $ticket = $this->ticketValido('impresion');
        $this->assertSame(201, $this->abrirSeguimiento($ticket)->status());
        $this->assertSame('ticket_usado', $this->abrirSeguimiento($ticket)->json('codigo'));
        $this->assertSame('sesion_invalida', $this->llamar('GET', '/v1/impresion/trabajo', ['X-Sesion' => 'otra'])->json('codigo'));
    }

    public function test_impresora_desconectada_con_error_o_trabajo_en_otra_cola_se_distinguen(): void
    {
        $casos = [
            'desconectada' => ['impresora_desconectada', 'no_encontrado'],
            'error' => ['error', 'error'],
            'otra-cola' => ['lista', 'en_otra_cola'],
        ];
        foreach ($casos as $modo => [$estadoCola, $estadoTrabajo]) {
            $this->iniciar(null, ['--simulado-impresora='.$modo]);
            $this->assertSame($estadoCola, $this->llamar('GET', '/v1/impresion/estado')->json('estado'), $modo);
            $sesion = $this->abrirSeguimiento($this->ticketValido('impresion'))->json('sesion');
            $ultimo = null;
            for ($i = 0; $i < 2; $i++) {
                $ultimo = $this->llamar('GET', '/v1/impresion/trabajo', ['X-Sesion' => $sesion]);
            }
            $this->assertSame($estadoTrabajo, $ultimo->json('estado'), $modo);
            if ($modo === 'otra-cola') {
                $this->assertStringContainsString('Microsoft Print to PDF', (string) $ultimo->json('cola'));
            }
            $this->agente->stop(1);
        }
    }

    /**
     * Spooler REAL de este equipo, solo lectura: no se envía ningún trabajo. Si la cola Zebra existe, debe
     * reconocerse; un documento que nunca se imprimió debe salir "no_encontrado".
     */
    public function test_spooler_real_se_consulta_sin_enviar_trabajos(): void
    {
        $this->iniciar(null);

        $estado = Http::timeout(30)->withHeaders(['Origin' => self::ORIGEN])->get('http://127.0.0.1:'.self::PUERTO.'/v1/impresion/estado');
        $this->assertSame(200, $estado->status(), $estado->body());
        $this->assertSame('windows_spooler', $estado->json('adaptador'));
        $this->assertFalse($estado->json('simulacion'));
        $this->assertContains($estado->json('estado'), ['lista', 'cola_no_encontrada', 'impresora_desconectada', 'fuera_de_linea', 'pausada', 'error', 'requiere_atencion']);
        if ($estado->json('estado') !== 'cola_no_encontrada') {
            $this->assertSame('Zebra ZXP Series 7 USB Card Printer', $estado->json('cola'));
            $this->assertSame('Zebra ZXP Series 7 USB Card Printer', $estado->json('controlador'));
        }

        $sesion = $this->abrirSeguimiento($this->ticketValido('impresion'))->json('sesion');
        $trabajo = Http::timeout(30)->withHeaders(['Origin' => self::ORIGEN, 'X-Sesion' => $sesion])->get('http://127.0.0.1:'.self::PUERTO.'/v1/impresion/trabajo');
        $this->assertSame('no_encontrado', $trabajo->json('estado'));
    }

    // ---- Lector de huellas Cross Match ------------------------------------------------------

    public function test_sin_sdk_configurado_el_lector_informa_sdk_no_instalado(): void
    {
        $this->iniciar(null);

        $this->assertSame('sdk_no_instalado', $this->llamar('GET', '/v1/estado')->json('lector.estado'));
        $estado = $this->llamar('GET', '/v1/huella/estado');
        $this->assertSame('sdk_no_instalado', $estado->json('estado'));
        $this->assertSame('ninguno', $estado->json('adaptador'));
        $sesion = $this->abrirSesionLector();
        $this->assertSame(503, $sesion->status());
        $this->assertSame('sdk_no_instalado', $sesion->json('codigo'));
    }

    public function test_una_dll_que_no_es_el_sdk_se_informa_como_sdk_incompatible(): void
    {
        $carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sdk-falso-'.bin2hex(random_bytes(4));
        mkdir($carpeta);
        file_put_contents($carpeta.DIRECTORY_SEPARATOR.'USB4XX.dll', 'esto no es una biblioteca de Windows');
        try {
            $this->iniciar(null, [], ['rutaSdkCrossMatch' => $carpeta]);
            $estado = $this->llamar('GET', '/v1/huella/estado');
            $this->assertSame('sdk_incompatible', $estado->json('estado'));
            $this->assertStringContainsString('No se pudo cargar USB4XX.dll', (string) $estado->json('detalle'));
        } finally {
            $this->agente?->stop(1);
            @unlink($carpeta.DIRECTORY_SEPARATOR.'USB4XX.dll');
            @rmdir($carpeta);
        }
    }

    public function test_el_lector_solo_acepta_tickets_de_pulgar_y_la_camara_solo_de_foto(): void
    {
        $this->iniciar('conectada', ['--simulado-lector']);

        $this->assertSame('ticket_invalido', $this->llamar('POST', '/v1/huella/sesiones', ['Authorization' => 'Bearer '.$this->ticketValido('foto')])->json('codigo'));
        $this->assertSame('ticket_invalido', $this->llamar('POST', '/v1/sesiones', ['Authorization' => 'Bearer '.$this->ticketValido('pulgar_derecho')])->json('codigo'));

        $lector = $this->abrirSesionLector('pulgar_izquierdo');
        $this->assertSame(201, $lector->status());
        $this->assertSame('pulgar_izquierdo', $lector->json('categoria'));
        $this->assertSame($this->elemento->id, $lector->json('elemento_id'));

        // Sesiones independientes: abrir la de la cámara no cierra la del lector.
        $camara = $this->abrirSesion();
        $this->assertSame(200, $this->llamar('POST', '/v1/huella/vista-previa', ['X-Sesion' => $lector->json('sesion')])->status());
        $this->assertSame(200, $this->llamar('POST', '/v1/vista-previa', ['X-Sesion' => $camara])->status());
        $this->assertSame('sesion_invalida', $this->llamar('POST', '/v1/huella/vista-previa', ['X-Sesion' => $camara])->json('codigo'));
    }

    public function test_flujo_simulado_del_lector_entrega_png_marcado_que_laravel_rechaza(): void
    {
        $this->iniciar(null, ['--simulado-lector']);
        $estado = $this->llamar('GET', '/v1/huella/estado');
        $this->assertSame('conectado', $estado->json('estado'));
        $this->assertTrue($estado->json('simulacion'));

        $ticket = $this->ticketValido('pulgar_derecho');
        $sesion = $this->llamar('POST', '/v1/huella/sesiones', ['Authorization' => 'Bearer '.$ticket])->json('sesion');
        $this->assertSame(409, $this->llamar('GET', '/v1/huella/fotograma', ['X-Sesion' => $sesion])->status());
        $this->assertSame(200, $this->llamar('POST', '/v1/huella/vista-previa', ['X-Sesion' => $sesion])->status());

        $fotograma = $this->llamar('GET', '/v1/huella/fotograma', ['X-Sesion' => $sesion]);
        $this->assertSame('image/png', $fotograma->header('Content-Type'));
        $this->assertSame([400, 500], array_slice((array) getimagesizefromstring($fotograma->body()), 0, 2));

        $captura = $this->llamar('POST', '/v1/huella/captura', ['X-Sesion' => $sesion]);
        $this->assertSame('1', $captura->header('X-Simulacion'));
        $this->assertStringContainsString(CapturaTicket::MARCADOR_SIMULACION, $captura->body());
        $this->assertNotFalse(imagecreatefromstring($captura->body()), 'El PNG convertido desde el DIB debe decodificarse.');

        // De extremo a extremo: el servidor no acepta la huella simulada aunque el ticket sea válido.
        $antes = SolicitudElemento::findOrFail($this->elemento->id)->expedienteBiometrico?->pulgar_derecho_archivo_id;
        $this->actingAs($this->impresor)->postJson(route('impresion.elemento.actualizar-biometrico', $this->elemento), [
            'tipo_biometrico' => 'pulgar_derecho',
            'archivo' => \Illuminate\Http\UploadedFile::fake()->createWithContent('crossmatch.png', $captura->body()),
            'motivo_cambio' => 'Prueba de contrato con el agente simulado.',
            'captura_ticket' => $ticket,
        ])->assertUnprocessable()->assertJsonValidationErrors(['archivo' => 'modo simulación']);
        $this->assertSame($antes, SolicitudElemento::findOrFail($this->elemento->id)->expedienteBiometrico?->pulgar_derecho_archivo_id);

        $this->assertSame(200, $this->llamar('DELETE', '/v1/huella/sesiones', ['X-Sesion' => $sesion])->status());
        $this->assertSame('sesion_invalida', $this->llamar('GET', '/v1/huella/fotograma', ['X-Sesion' => $sesion])->json('codigo'));
    }

    public function test_lector_desconectado_en_uso_o_con_falla_del_sdk_se_informa_como_error(): void
    {
        foreach (['desconectado' => [503, 'desconectado'], 'en-uso' => [409, 'en_uso']] as $modo => [$http, $codigo]) {
            $this->iniciar(null, ['--simulado-lector='.$modo]);
            $sesion = $this->abrirSesionLector();
            $this->assertSame($http, $sesion->status());
            $this->assertSame($codigo, $sesion->json('codigo'));
            $this->agente->stop(1);
        }

        $this->iniciar(null, ['--simulado-lector=falla-captura']);
        $sesion = $this->abrirSesionLector()->json('sesion');
        $this->llamar('POST', '/v1/huella/vista-previa', ['X-Sesion' => $sesion]);
        $captura = $this->llamar('POST', '/v1/huella/captura', ['X-Sesion' => $sesion]);
        $this->assertSame(502, $captura->status());
        $this->assertSame('error_sdk', $captura->json('codigo'));
    }

    /**
     * Opcional: con CROSSMATCH_SDK_DIR apuntando a la carpeta de USB4XX.dll comprueba la carga real del SDK.
     * Sin el lector conectado, el SDK real debe informar "desconectado"; NO es una prueba física de captura.
     */
    public function test_sdk_real_carga_y_sin_lector_informa_desconectado(): void
    {
        $carpeta = getenv('CROSSMATCH_SDK_DIR');
        if (! $carpeta || ! is_file($carpeta.DIRECTORY_SEPARATOR.'USB4XX.dll')) {
            $this->markTestSkipped('Defina CROSSMATCH_SDK_DIR para probar la carga real de USB4XX.dll.');
        }
        $this->iniciar(null, [], ['rutaSdkCrossMatch' => $carpeta]);

        $this->assertSame('crossmatch_usb_sdk', $this->llamar('GET', '/v1/estado')->json('lector.adaptador'));
        $estado = Http::timeout(30)->withHeaders(['Origin' => self::ORIGEN])->get('http://127.0.0.1:'.self::PUERTO.'/v1/huella/estado');
        $this->assertContains($estado->json('estado'), ['desconectado', 'conectado', 'en_uso', 'error_inicializacion'], (string) $estado->body());
    }

    private function abrirSesion(): string
    {
        $respuesta = $this->llamar('POST', '/v1/sesiones', ['Authorization' => 'Bearer '.$this->ticketValido()]);
        $this->assertSame(201, $respuesta->status(), $respuesta->body());

        return $respuesta->json('sesion');
    }

    public function test_sin_sdk_el_agente_no_finge_ninguna_camara(): void
    {
        $this->iniciar(null);

        $estado = $this->llamar('GET', '/v1/estado');
        $this->assertSame(200, $estado->status());
        $this->assertSame('ninguno', $estado->json('adaptador'));
        $this->assertFalse($estado->json('simulacion'));
        $this->assertSame('sdk_no_instalado', $estado->json('camara.estado'));

        $vista = $this->llamar('POST', '/v1/vista-previa', ['X-Sesion' => $this->abrirSesion()]);
        $this->assertSame(503, $vista->status());
        $this->assertSame('sdk_no_instalado', $vista->json('codigo'));
    }

    public function test_rechaza_host_u_origen_ajenos_y_responde_al_preflight_de_red_privada(): void
    {
        $this->iniciar('conectada');

        $this->assertSame('host_no_permitido', $this->llamar('GET', '/v1/estado', ['Host' => 'atacante.example:'.self::PUERTO])->json('codigo'));
        $ajeno = $this->llamar('GET', '/v1/estado', ['Origin' => 'https://atacante.example']);
        $this->assertSame(403, $ajeno->status());
        $this->assertNull($ajeno->header('Access-Control-Allow-Origin') ?: null);
        $this->assertSame(403, Http::timeout(5)->get('http://127.0.0.1:'.self::PUERTO.'/v1/estado')->status());

        $preflight = $this->llamar('OPTIONS', '/v1/sesiones', ['Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Private-Network' => 'true']);
        $this->assertSame(204, $preflight->status());
        $this->assertSame(self::ORIGEN, $preflight->header('Access-Control-Allow-Origin'));
        $this->assertSame('true', $preflight->header('Access-Control-Allow-Private-Network'));
    }

    public function test_tickets_firmados_por_laravel_son_de_un_solo_uso_y_se_rechazan_si_no_son_validos(): void
    {
        $this->iniciar('conectada');

        $this->assertSame('ticket_invalido', $this->llamar('POST', '/v1/sesiones')->json('codigo'));
        $ticket = $this->ticketValido();
        [$carga] = explode('.', $ticket);
        $this->assertSame('ticket_invalido', $this->llamar('POST', '/v1/sesiones', ['Authorization' => 'Bearer '.$carga.'.firmaFalsa'])->json('codigo'));

        $abierta = $this->llamar('POST', '/v1/sesiones', ['Authorization' => 'Bearer '.$ticket]);
        $this->assertSame(201, $abierta->status());
        $this->assertTrue($abierta->json('simulacion'));
        $this->assertSame('ticket_usado', $this->llamar('POST', '/v1/sesiones', ['Authorization' => 'Bearer '.$ticket])->json('codigo'));

        $this->travel(-20)->minutes();
        $vencido = $this->ticketValido();
        $this->travelBack();
        $this->assertSame('ticket_expirado', $this->llamar('POST', '/v1/sesiones', ['Authorization' => 'Bearer '.$vencido])->json('codigo'));
    }

    public function test_flujo_simulado_entrega_imagenes_marcadas_y_la_sesion_se_cierra(): void
    {
        $this->iniciar('conectada');
        $sesion = $this->abrirSesion();

        $this->assertSame(409, $this->llamar('GET', '/v1/fotograma', ['X-Sesion' => $sesion])->status());
        $this->assertSame(200, $this->llamar('POST', '/v1/vista-previa', ['X-Sesion' => $sesion])->status());

        $fotograma = $this->llamar('GET', '/v1/fotograma', ['X-Sesion' => $sesion]);
        $this->assertSame('image/jpeg', $fotograma->header('Content-Type'));
        $this->assertSame('1', $fotograma->header('X-Simulacion'));
        $this->assertSame([1056, 704], array_slice((array) getimagesizefromstring($fotograma->body()), 0, 2));

        $captura = $this->llamar('POST', '/v1/captura', ['X-Sesion' => $sesion]);
        $this->assertSame(200, $captura->status());
        $this->assertSame('liveview', $captura->header('X-Captura-Origen'));
        $this->assertSame('1', $captura->header('X-Simulacion'));

        $this->assertSame('sesion_invalida', $this->llamar('GET', '/v1/fotograma', ['X-Sesion' => 'otra'])->json('codigo'));
        $this->assertSame(200, $this->llamar('DELETE', '/v1/sesiones', ['X-Sesion' => $sesion])->status());
        $this->assertSame('sesion_invalida', $this->llamar('GET', '/v1/fotograma', ['X-Sesion' => $sesion])->json('codigo'));
    }

    public function test_camara_desconectada_y_falla_de_captura_se_informan_como_error(): void
    {
        $this->iniciar('desconectada');
        $this->assertSame('desconectada', $this->llamar('GET', '/v1/estado')->json('camara.estado'));
        $vista = $this->llamar('POST', '/v1/vista-previa', ['X-Sesion' => $this->abrirSesion()]);
        $this->assertSame(503, $vista->status());
        $this->assertSame('desconectada', $vista->json('codigo'));
        $this->agente->stop(1);

        $this->iniciar('falla-captura');
        $sesion = $this->abrirSesion();
        $this->llamar('POST', '/v1/vista-previa', ['X-Sesion' => $sesion]);
        $captura = $this->llamar('POST', '/v1/captura', ['X-Sesion' => $sesion]);
        $this->assertSame(502, $captura->status());
        $this->assertSame('error_camara', $captura->json('codigo'));
    }
}
