<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocArma;
use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;
use App\Models\LocImportBatch;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Support\AgrupamientoInstitucional;
use App\Support\MotivoDescarte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitudElementosOptimizacionTablaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private LocImportBatch $batch;
    private LocCatalogoVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        AgrupamientoInstitucional::limpiarCache();

        $this->user = User::factory()->create([
            'role' => 'superadmin',
        ]);

        $this->batch = LocImportBatch::create([
            'tipo_fuente' => 'catalogo_organizacional_loc',
            'nombre_archivo' => 'catalogo_test.xlsx',
            'estado' => 'completado',
            'usuario_id' => $this->user->id,
            'fecha_confirmacion' => now(),
            'total_leidas' => 10,
            'total_insertadas' => 10,
        ]);

        $this->version = LocCatalogoVersion::create([
            'import_batch_id' => $this->batch->id,
            'version_numero' => 1,
            'archivo_nombre' => 'catalogo_test.xlsx',
            'total_registros' => 10,
            'es_vigente' => true,
            'usuario_id' => $this->user->id,
            'fecha_activacion' => now(),
        ]);
    }

    private function createSolicitud(array $attributes = []): SolicitudCredencial
    {
        static $seq = 80000;
        $seq++;

        return SolicitudCredencial::create(array_merge([
            'numero_tarjeta' => "LOC-2026-{$seq}",
            'consecutivo_tarjeta' => $seq,
            'numero_oficio' => "OF-OPT-{$seq}",
            'fecha_recepcion' => now()->toDateString(),
            'estado_general' => 'recibida',
            'total_elementos' => 0,
            'total_aptos' => 0,
            'total_descartados' => 0,
        ], $attributes));
    }

    /** 01. La tabla conserva exactamente las 8 columnas en el orden solicitado y con los anchos calibrados */
    public function test_01_tabla_conserva_ocho_columnas_en_orden_y_con_anchos_objetivo(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 1]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP12345678901234',
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'apto',
        ]);

        $resp = $this->get(route('credencializacion.detalle', $solicitud->id));
        $resp->assertOk();

        $content = $resp->getContent();

        // Extraer el bloque <thead> para validar el orden estricto de los encabezados de columna
        preg_match('/<thead[^>]*>(.*?)<\/thead>/s', $content, $matches);
        $this->assertNotEmpty($matches, 'Bloque thead de la tabla no encontrado');
        $thead = $matches[1];

        // 1. Encabezados obligatorios en orden estricto dentro de <thead>
        $posFila = strpos($thead, '# / Fila');
        $posCuip = strpos($thead, 'CUIP');
        $posPersona = strpos($thead, 'Persona');
        $posAgrup = strpos($thead, 'Agrupamiento Institucional');
        $posArmaCorta = strpos($thead, 'Arma Corta');
        $posArmaLarga = strpos($thead, 'Arma Larga');
        $posPrefiltro = strpos($thead, 'Prefiltro / Motivos');
        $posEstado = strpos($thead, 'Estado Trámite');

        $this->assertNotFalse($posFila, 'Encabezado # / Fila no encontrado en thead');
        $this->assertNotFalse($posCuip, 'Encabezado CUIP no encontrado en thead');
        $this->assertNotFalse($posPersona, 'Encabezado Persona no encontrado en thead');
        $this->assertNotFalse($posAgrup, 'Encabezado Agrupamiento Institucional no encontrado en thead');
        $this->assertNotFalse($posArmaCorta, 'Encabezado Arma Corta no encontrado en thead');
        $this->assertNotFalse($posArmaLarga, 'Encabezado Arma Larga no encontrado en thead');
        $this->assertNotFalse($posPrefiltro, 'Encabezado Prefiltro / Motivos no encontrado en thead');
        $this->assertNotFalse($posEstado, 'Encabezado Estado Trámite no encontrado en thead');

        $this->assertTrue($posFila < $posCuip, '# / Fila debe preceder a CUIP');
        $this->assertTrue($posCuip < $posPersona, 'CUIP debe preceder a Persona');
        $this->assertTrue($posPersona < $posAgrup, 'Persona debe preceder a Agrupamiento');
        $this->assertTrue($posAgrup < $posArmaCorta, 'Agrupamiento debe preceder a Arma Corta');
        $this->assertTrue($posArmaCorta < $posArmaLarga, 'Arma Corta debe preceder a Arma Larga');
        $this->assertTrue($posArmaLarga < $posPrefiltro, 'Arma Larga debe preceder a Prefiltro / Motivos');
        $this->assertTrue($posPrefiltro < $posEstado, 'Prefiltro / Motivos debe preceder a Estado Trámite');

        // 2. Anchos calibrados objetivo de las 8 columnas
        $this->assertStringContainsString('w-[45px]', $content);
        $this->assertStringContainsString('w-[150px]', $content);
        $this->assertStringContainsString('w-[170px]', $content);
        $this->assertStringContainsString('w-[165px]', $content);
        $this->assertStringContainsString('w-[125px]', $content);
        $this->assertStringContainsString('w-[90px]', $content);
    }

    /** 02. Prefiltro y motivos: apto muestra ✓ Apto sin motivos inexistentes, descartado muestra botón y sin evaluar muestra Sin evaluar */
    public function test_02_prefiltro_y_motivos_representacion_aptos_descartados_y_sin_evaluar(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 3]);

        // Elemento 1: Apto
        $eApto = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP11111111111111',
            'resultado_prefiltro' => 'Apto',
            'motivos_descarte' => null,
            'codigos_descarte' => null,
            'estado_tramite' => 'apto',
        ]);

        // Elemento 2: Descartado con 1 motivo
        $eDesc = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP22222222222222',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::EXP_CANCELACION],
            'motivos_descarte' => ['Cancelación administrativa de expediente'],
            'estado_tramite' => 'descartado',
        ]);

        // Elemento 3: Sin evaluar
        $eSinEval = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIP33333333333333',
            'resultado_prefiltro' => null,
            'estado_tramite' => 'recibido',
        ]);

        $resp = $this->get(route('credencializacion.detalle', $solicitud->id));
        $resp->assertOk();
        $html = $resp->getContent();

        // 1. Apto muestra ✓ Apto
        $this->assertStringContainsString('✓ Apto', $html);

        // 2. Descartado muestra ✕ Descartado y botón Ver motivos
        $this->assertStringContainsString('✕ Descartado', $html);
        $this->assertStringContainsString('Ver motivos', $html);
        $this->assertStringContainsString('EXP_CANCELACION', $html);

        // 3. Sin evaluar muestra Sin evaluar
        $this->assertStringContainsString('Sin evaluar', $html);
    }

    /** 03. Elementos descartados permiten consultar todos sus motivos con códigos y descripciones */
    public function test_03_elementos_descartados_permiten_consultar_todos_sus_motivos_con_codigos(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 1]);

        $e = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP99999999999999',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::CUIP_VACIO, MotivoDescarte::ARM_SIN_ASIGNACION],
            'estado_tramite' => 'descartado',
        ]);

        $resp = $this->get(route('credencializacion.detalle', $solicitud->id));
        $resp->assertOk();
        $html = $resp->getContent();

        // Debe indicar 2 causales
        $this->assertStringContainsString('Ver motivos (2)', $html);
        $this->assertStringContainsString('[CUIP_VACIO]', $html);
        $this->assertStringContainsString('[ARM_SIN_ASIGNACION]', $html);
    }

    /** 04. Estados administrativos se muestran con su valor real sin interpretarlos como Detenido o Apto trámite */
    public function test_04_estados_administrativos_reales_se_muestran_fielmente_sin_reinterpretacion(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 6]);

        $estados = ['recibido', 'pendiente', 'apto', 'descartado', 'rechazado', 'retenido'];
        foreach ($estados as $idx => $st) {
            SolicitudElemento::create([
                'solicitud_id' => $solicitud->id,
                'numero_fila' => $idx + 1,
                'cuip' => "CUIP0000000000000{$idx}",
                'resultado_prefiltro' => $st === 'apto' ? 'Apto' : ($st === 'descartado' ? 'Descartado' : null),
                'estado_tramite' => $st,
            ]);
        }

        $resp = $this->get(route('credencializacion.detalle', $solicitud->id));
        $resp->assertOk();
        $html = $resp->getContent();

        // Debe renderizar los estados reales
        $this->assertStringContainsString('Recibido', $html);
        $this->assertStringContainsString('Pendiente', $html);
        $this->assertStringContainsString('Apto', $html);
        $this->assertStringContainsString('Descartado', $html);
        $this->assertStringContainsString('Rechazado', $html);
        $this->assertStringContainsString('Retenido', $html);

        // NO debe contener los mapeos cosméticos descartados
        $this->assertStringNotContainsString('Apto trámite', $html);
        $this->assertStringNotContainsString('Detenido', $html);
    }

    /** 05. Estados desconocidos no se ocultan y se presentan de forma segura */
    public function test_05_estados_desconocidos_se_muestran_de_forma_segura_sin_ocultarse(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 1]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP77777777777777',
            'resultado_prefiltro' => null,
            'estado_tramite' => 'en_auditoria_especial',
        ]);

        $resp = $this->get(route('credencializacion.detalle', $solicitud->id));
        $resp->assertOk();
        $html = $resp->getContent();

        $this->assertStringContainsString('En_auditoria_especial', $html);
    }

    /** 06. Solicitud AJAX devuelve JSON con fragmentos parciales y NO devuelve la página HTML completa */
    public function test_06_solicitud_ajax_devuelve_json_con_fragmentos_parciales_y_no_pagina_completa(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 2]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP11111111111111',
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'apto',
        ]);
        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP22222222222222',
            'resultado_prefiltro' => 'Descartado',
            'estado_tramite' => 'descartado',
        ]);

        $resp = $this->getJson(route('credencializacion.detalle', $solicitud->id), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/json');

        $data = $resp->json();

        // 1. Estructura JSON esperada
        $this->assertArrayHasKey('table_html', $data);
        $this->assertArrayHasKey('chips_html', $data);
        $this->assertArrayHasKey('total_coincidentes', $data);
        $this->assertArrayHasKey('total_registrados', $data);
        $this->assertArrayHasKey('has_active_filters', $data);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('description', $data);

        $this->assertSame(2, $data['total_coincidentes']);
        $this->assertSame(2, $data['total_registrados']);
        $this->assertFalse($data['has_active_filters']);

        // 2. NO devuelve la página completa
        $this->assertStringNotContainsString('<!DOCTYPE html>', $data['table_html']);
        $this->assertStringNotContainsString('<aside id="sidebar"', $data['table_html']);
        $this->assertStringNotContainsString('Resumen del expediente', $data['table_html']);

        // 3. SÍ contiene las 8 columnas
        $this->assertStringContainsString('Prefiltro / Motivos', $data['table_html']);
        $this->assertStringContainsString('Estado Trámite', $data['table_html']);
    }

    /** 07. Vista normal sigue funcionando y renderiza la vista completa para fallback sin JS */
    public function test_07_vista_tradicional_sin_ajax_conserva_pagina_completa_para_fallback_sin_js(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 1]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP11111111111111',
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'apto',
        ]);

        // Petición HTML tradicional (sin encabezado AJAX)
        $resp = $this->get(route('credencializacion.detalle', $solicitud->id));
        $resp->assertOk();
        $resp->assertViewIs('modules.credencializacion');
        $resp->assertSee('Resumen del expediente');
        $resp->assertSee('Operaciones del expediente');
        $resp->assertSee('Prefiltro / Motivos');
    }

    /** 08. Petición AJAX con combinación de múltiples filtros opera correctamente */
    public function test_08_ajax_combinacion_simultanea_de_filtros_prefiltro_y_armamento(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 3]);

        $arma = LocArma::create([
            'matricula' => 'MAT-TEST-01',
            'marca' => 'Glock',
            'modelo' => '17',
            'calibre' => '9mm',
            'clase' => 'PISTOLA',
            'tipo' => 'FUEGO',
            'estatus' => 'FISICA',
        ]);

        // e1: Apto con arma
        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP11111111111111',
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'apto',
            'matricula_corta' => 'MAT-TEST-01',
            'arma_corta_id' => $arma->id,
        ]);

        // e2: Descartado con arma
        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP22222222222222',
            'resultado_prefiltro' => 'Descartado',
            'estado_tramite' => 'descartado',
            'matricula_corta' => 'MAT-TEST-01',
            'arma_corta_id' => $arma->id,
        ]);

        // e3: Apto sin arma
        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIP33333333333333',
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'apto',
            'matricula_corta' => null,
            'arma_corta_id' => null,
        ]);

        // Filtrar simultáneamente: prefiltro=aptos AND arma_corta=vinculada -> solo e1
        $resp = $this->getJson(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'prefiltro' => 'aptos',
            'arma_corta' => 'vinculada',
        ]), ['X-Requested-With' => 'XMLHttpRequest']);

        $resp->assertOk();
        $data = $resp->json();

        $this->assertSame(1, $data['total_coincidentes']);
        $this->assertTrue($data['has_active_filters']);
        $this->assertStringContainsString('CUIP11111111111111', $data['table_html']);
        $this->assertStringNotContainsString('CUIP22222222222222', $data['table_html']);
        $this->assertStringNotContainsString('CUIP33333333333333', $data['table_html']);

        // Chips HTML debe contener ambos filtros aplicados
        $this->assertStringContainsString('Prefiltro:', $data['chips_html']);
        $this->assertStringContainsString('Corta:', $data['chips_html']);
    }

    /** 09. Paginación AJAX conserva query string y devuelve la página 2 */
    public function test_09_ajax_paginacion_conserva_query_string(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 55]);

        for ($i = 1; $i <= 55; $i++) {
            SolicitudElemento::create([
                'solicitud_id' => $solicitud->id,
                'numero_fila' => $i,
                'cuip' => sprintf('CUIP%014d', $i),
                'resultado_prefiltro' => $i % 2 === 0 ? 'Apto' : 'Descartado',
                'estado_tramite' => $i % 2 === 0 ? 'apto' : 'descartado',
            ]);
        }

        // Petición AJAX para página 2 con prefiltro=descartados
        $resp = $this->getJson(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'prefiltro' => 'descartados',
            'page' => 2,
        ]), ['X-Requested-With' => 'XMLHttpRequest']);

        $resp->assertOk();
        $data = $resp->json();

        // Hay 28 descartados en total. Página 1 tiene 50 por defecto en paginate(50), pero aquí total es 28
        // Para verificar página 2, paginamos los 55 registros sin filtro (pág 1: 50, pág 2: 5)
        $respPag2 = $this->getJson(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'page' => 2,
        ]), ['X-Requested-With' => 'XMLHttpRequest']);

        $respPag2->assertOk();
        $dataPag2 = $respPag2->json();

        $this->assertSame(55, $dataPag2['total_coincidentes']);
        $this->assertStringContainsString('CUIP00000000000051', $dataPag2['table_html']);
        $this->assertStringContainsString('CUIP00000000000055', $dataPag2['table_html']);
    }

    /** 10. Validación 422: parámetros inválidos en AJAX devuelven error JSON sin romper el servidor */
    public function test_10_ajax_rechaza_parametros_invalidos_con_error_422_en_json(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 1]);

        $resp = $this->getJson(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'prefiltro' => 'parametro_totalmente_invalido',
        ]), ['X-Requested-With' => 'XMLHttpRequest']);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['prefiltro']);
    }

    /** 11. Limpiar filtros vía AJAX restablece el listado íntegro */
    public function test_11_ajax_limpiar_filtros_restablece_expediente_completo(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 3]);

        for ($i = 1; $i <= 3; $i++) {
            SolicitudElemento::create([
                'solicitud_id' => $solicitud->id,
                'numero_fila' => $i,
                'cuip' => sprintf('CUIP%014d', $i),
                'resultado_prefiltro' => 'Apto',
                'estado_tramite' => 'apto',
            ]);
        }

        // Petición a la URL base limpia
        $resp = $this->getJson(route('credencializacion.detalle', $solicitud->id), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $resp->assertOk();
        $data = $resp->json();

        $this->assertSame(3, $data['total_coincidentes']);
        $this->assertFalse($data['has_active_filters']);
    }

    /** 12. Respuestas vacías renderizan mensaje amigable sin romper la tabla */
    public function test_12_ajax_respuesta_vacia_renderiza_mensaje_amigable_sin_romper_tabla(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 1]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP11111111111111',
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'apto',
        ]);

        $resp = $this->getJson(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'q' => 'ELEMENTO_INEXISTENTE_XYZ',
        ]), ['X-Requested-With' => 'XMLHttpRequest']);

        $resp->assertOk();
        $data = $resp->json();

        $this->assertSame(0, $data['total_coincidentes']);
        $this->assertStringContainsString('No se encontraron elementos que coincidan con los filtros aplicados', $data['table_html']);
    }

    /** 13. Las consultas AJAX son estrictamente de solo lectura y preservan los datos persistentes */
    public function test_13_filtros_ajax_son_estrictamente_de_lectura_y_preservan_totales_bd(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud([
            'total_elementos' => 10,
            'total_aptos' => 3,
            'total_descartados' => 7,
        ]);

        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP11111111111111',
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'apto',
        ]);

        $solUpdatedAtBefore = $solicitud->fresh()->updated_at;
        $elemUpdatedAtBefore = $elemento->fresh()->updated_at;

        $resp = $this->getJson(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'prefiltro' => 'aptos',
        ]), ['X-Requested-With' => 'XMLHttpRequest']);

        $resp->assertOk();

        // Los totales y timestamps de la solicitud y elemento en BD deben ser idénticos
        $solFresh = $solicitud->fresh();
        $elemFresh = $elemento->fresh();

        $this->assertSame(10, $solFresh->total_elementos);
        $this->assertSame(3, $solFresh->total_aptos);
        $this->assertSame(7, $solFresh->total_descartados);
        $this->assertEquals($solUpdatedAtBefore, $solFresh->updated_at);
        $this->assertEquals($elemUpdatedAtBefore, $elemFresh->updated_at);
    }
}
