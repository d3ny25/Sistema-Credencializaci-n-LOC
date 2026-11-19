<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\EntregaSeguimientoController;
use App\Models\CredencialDocumento;
use App\Models\CredencialEntrega;
use App\Models\CredencialImpresion;
use App\Models\User;
use App\Services\ExpedienteDocumentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;

/**
 * Navegación dinámica de Entrega y Seguimiento: la misma URL responde la página completa o, ante fetch
 * (X-Requested-With + Accept JSON), solo las secciones renderizadas; seguridad y reglas idénticas en ambos casos.
 */
class EntregaNavegacionDinamicaTest extends TestCase
{
    use PreparaImpresionReal;
    use RefreshDatabase;

    private const PARCIAL = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDiscosEvidencia();
        Storage::fake(ExpedienteDocumentalService::DISCO);
        $this->gestor = User::factory()->create(['role' => 'superadmin']);
    }

    public function test_cada_vista_responde_completa_o_parcial_sin_reconstruir_el_layout(): void
    {
        $tarjeta = $this->elementoImprimible()->solicitud;
        $urls = [route('entregas'), route('entregas', ['q' => 'TJ', 'page' => 1]), route('entregas.entrega.nueva', $tarjeta)];
        foreach (array_keys(EntregaSeguimientoController::SECCIONES) as $seccion) {
            $urls[] = route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => $seccion]);
        }

        foreach ($urls as $url) {
            // Recarga manual / sin JavaScript: página completa con layout y el contenedor dinámico.
            $this->actingAs($this->gestor)->get($url)->assertOk()
                ->assertSee('Navegación principal')->assertSee('data-entrega-navegacion', false);

            $parcial = $this->actingAs($this->gestor)->get($url, self::PARCIAL)->assertOk()
                ->assertJsonStructure(['html', 'titulo', 'encabezado', 'subencabezado', 'avisos', 'url']);
            $this->assertStringNotContainsString('Navegación principal', $parcial->json('html'), $url);
            $this->assertStringNotContainsString('data-entrega-navegacion', $parcial->json('html'), 'el contenedor no se anida');
            $this->assertSame(Request::create($url)->fullUrl(), $parcial->json('url'), 'conserva ruta y filtros (orden normalizado)');
        }

        $panel = $this->actingAs($this->gestor)->get(route('entregas.tarjeta', $tarjeta->id), self::PARCIAL);
        $this->assertSame('Tarjeta TJ-EMISION-1', $panel->json('encabezado'));
        $this->assertSame('Panel · Tarjeta TJ-EMISION-1 · Sistema Estatal LOC 139', $panel->json('titulo'));
        $this->assertStringContainsString('data-etapa="registro"', $panel->json('html'));
    }

    public function test_la_respuesta_parcial_respeta_autenticacion_permisos_validacion_y_pertenencia(): void
    {
        $tarjeta = $this->elementoImprimible()->solicitud;
        $url = route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => 'pendientes']);

        $this->get($url, self::PARCIAL)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'consulta_general']))->get($url, self::PARCIAL)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'impresion']))->get(route('entregas.entrega.nueva', $tarjeta), self::PARCIAL)->assertForbidden();
        $this->actingAs($this->gestor)->get($url.'?filtro=no-existe', self::PARCIAL)->assertUnprocessable()->assertJsonValidationErrors('filtro');
        $this->actingAs($this->gestor)->get('/entregas/tarjetas/999999', self::PARCIAL)->assertNotFound();
    }

    public function test_flujo_de_entrega_dinamico_conserva_confirmacion_idempotencia_y_errores(): void
    {
        $elemento = $this->elementoImprimible();
        $this->emitir(User::factory()->create(['role' => 'impresion']), $elemento);
        $tarjeta = $elemento->solicitud;
        $impresion = CredencialImpresion::where('solicitud_elemento_id', $elemento->id)->sole();

        // Selección incompleta: 422 con los errores; el formulario del navegador no se sustituye.
        $this->actingAs($this->gestor)->post(route('entregas.entrega.confirmar', $tarjeta), ['receptor_nombre' => ''], self::PARCIAL)
            ->assertUnprocessable()->assertJsonValidationErrors(['receptor_nombre', 'impresiones']);

        $confirmacion = $this->actingAs($this->gestor)->post(route('entregas.entrega.confirmar', $tarjeta), ['receptor_nombre' => 'Enlace sintético', 'impresiones' => [$impresion->id]], self::PARCIAL)
            ->assertOk();
        $this->assertStringContainsString('Confirmar entrega', $confirmacion->json('html'));
        $this->assertSame(0, CredencialEntrega::count(), 'la revisión no guarda');
        preg_match('/name="clave_operacion" value="([^"]+)"/', $confirmacion->json('html'), $clave);

        $datos = ['receptor_nombre' => 'Enlace sintético', 'impresiones' => [$impresion->id], 'clave_operacion' => $clave[1]];
        $registro = $this->actingAs($this->gestor)->post(route('entregas.entrega.store', $tarjeta), $datos, self::PARCIAL);
        $entrega = CredencialEntrega::sole();
        $registro->assertRedirect(route('entregas.entrega.detalle', [$tarjeta, $entrega]));
        // fetch sigue la redirección con las mismas cabeceras: el detalle llega parcial con el aviso de éxito.
        $detalle = $this->actingAs($this->gestor)->get(route('entregas.entrega.detalle', [$tarjeta, $entrega]), self::PARCIAL)->assertOk();
        $this->assertStringContainsString('Entrega registrada', $detalle->json('avisos'));
        $this->assertStringContainsString('data-incluida="'.$impresion->id.'"', $detalle->json('html'));

        // Reenvío de la misma respuesta (doble clic / reintento): no duplica.
        $this->actingAs($this->gestor)->post(route('entregas.entrega.store', $tarjeta), $datos, self::PARCIAL)
            ->assertRedirect(route('entregas.entrega.detalle', [$tarjeta, $entrega]));
        $this->assertSame(1, CredencialEntrega::count());

        // Error de negocio: vuelve al formulario con el mensaje, que llega en los avisos de la respuesta parcial.
        $this->actingAs($this->gestor)->post(route('entregas.entrega.store', $tarjeta), [...$datos, 'clave_operacion' => (string) Str::uuid()], self::PARCIAL)
            ->assertRedirect(route('entregas.entrega.nueva', $tarjeta));
        $nueva = $this->actingAs($this->gestor)->get(route('entregas.entrega.nueva', $tarjeta), self::PARCIAL)->assertOk();
        $this->assertStringContainsString('ya fue entregada', $nueva->json('avisos'));
    }

    public function test_carga_documental_dinamica_actualiza_el_listado_y_las_descargas_siguen_siendo_normales(): void
    {
        $tarjeta = $this->elementoImprimible(folio: null, conBiometria: false)->solicitud;
        $pdf = UploadedFile::fake()->createWithContent('oficio.pdf', "%PDF-1.4\n%sintetico\n%%EOF\n");

        $this->actingAs($this->gestor)->post(route('entregas.documento.store', $tarjeta), ['categoria' => 'oficio_solicitud', 'archivo' => $pdf, 'confirmacion' => '1'], self::PARCIAL)
            ->assertRedirect(route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => 'expediente-documental']));
        $seccion = $this->actingAs($this->gestor)->get(route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => 'expediente-documental']), self::PARCIAL)->assertOk();
        $this->assertStringContainsString('Documento incorporado: oficio.pdf', $seccion->json('avisos'));
        $this->assertStringContainsString('data-documento=', $seccion->json('html'));

        // Validación del archivo: 422 JSON, sin documento ni archivo.
        $this->actingAs($this->gestor)->post(route('entregas.documento.store', $tarjeta), ['categoria' => 'oficio_solicitud', 'archivo' => UploadedFile::fake()->createWithContent('x.html', '<html></html>'), 'confirmacion' => '1'], self::PARCIAL)
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
        $this->assertSame(1, CredencialDocumento::count());

        // Aunque llegue con cabeceras de fetch, un archivo se responde como archivo, nunca como HTML insertable.
        $documento = CredencialDocumento::sole();
        $descarga = $this->actingAs($this->gestor)->get(route('entregas.documento.archivo', [$tarjeta, $documento, 'descargar']), self::PARCIAL)->assertOk();
        $this->assertSame('application/pdf', $descarga->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $descarga->headers->get('Content-Disposition'));
    }
}
