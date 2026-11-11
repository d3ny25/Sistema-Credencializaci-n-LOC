<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;
use App\Models\LocImportBatch;
use App\Models\SolicitudCredencial;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SolicitudAreaSolicitanteCatalogoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private LocImportBatch $batchVigente;
    private LocCatalogoVersion $versionVigente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Crear lote y versión vigente base para las pruebas
        $this->batchVigente = LocImportBatch::create([
            'tipo_fuente' => 'catalogo_loc',
            'nombre_archivo' => 'catalogo_vigente.xlsx',
            'usuario_id' => $this->user->id,
            'estado' => 'completado',
        ]);

        $this->versionVigente = LocCatalogoVersion::create([
            'import_batch_id' => $this->batchVigente->id,
            'version_numero' => 1,
            'es_vigente' => true,
            'total_registros' => 10,
            'archivo_nombre' => 'catalogo_vigente.xlsx',
            'usuario_id' => $this->user->id,
            'fecha_activacion' => now(),
        ]);
    }

    private function createCatItem(array $attributes = []): LocCatalogoOrganizacional
    {
        return LocCatalogoOrganizacional::create(array_merge([
            'import_batch_id' => $this->batchVigente->id,
            'clave_origen' => 'SEC-01',
            'nombre' => 'DIRECCION DE SEGURIDAD PUBLICA',
            'direccion_general' => 'SEGURIDAD CIUDADANA',
            'coordinacion' => 'VALLE DE TOLUCA',
            'subdireccion' => 'OPERATIVA',
            'region' => 'I',
            'agrupamiento' => 'PRIMER AGRUPAMIENTO',
            'activo' => true,
        ], $attributes));
    }

    // ==========================================
    // PRUEBAS DE ENDPOINT (BÚSQUEDA JSON)
    // ==========================================

    /**
     * 1. Usuario autorizado puede buscar.
     */
    public function test_01_usuario_autorizado_puede_buscar(): void
    {
        $this->actingAs($this->user);
        $this->createCatItem(['clave_origen' => 'SEC-01', 'nombre' => 'DIRECCION DE SEGURIDAD']);

        $response = $this->getJson(route('credencializacion.catalogo.buscar', ['q' => 'SEG']));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'clave', 'nombre', 'descripcion'],
                ],
            ]);
    }

    /**
     * 2. Usuario no autorizado no puede acceder.
     */
    public function test_02_usuario_no_autorizado_no_puede_acceder(): void
    {
        // Petición como invitado no autenticado
        $response = $this->getJson(route('credencializacion.catalogo.buscar', ['q' => 'SEG']));

        // Debe ser rechazado (401 o 403)
        $this->assertTrue(in_array($response->status(), [401, 403], true));
    }

    /**
     * 3. La búsqueda devuelve solo registros vigentes y activos.
     */
    public function test_03_la_busqueda_devuelve_solo_registros_vigentes_y_activos(): void
    {
        $this->actingAs($this->user);

        // 1. Registro activo en versión vigente (DEBE APARECER)
        $valido = $this->createCatItem(['clave_origen' => 'VIG-ACT', 'nombre' => 'AREA VIGENTE ACTIVA', 'activo' => true]);

        // 2. Registro inactivo en versión vigente (NO DEBE APARECER)
        $inactivo = $this->createCatItem(['clave_origen' => 'VIG-INA', 'nombre' => 'AREA VIGENTE INACTIVA', 'activo' => false]);

        // 3. Lote y versión no vigente (NO DEBE APARECER)
        $batchAntiguo = LocImportBatch::create([
            'tipo_fuente' => 'catalogo_loc',
            'nombre_archivo' => 'catalogo_antiguo.xlsx',
            'usuario_id' => $this->user->id,
            'estado' => 'completado',
        ]);
        LocCatalogoVersion::create([
            'import_batch_id' => $batchAntiguo->id,
            'version_numero' => 99,
            'es_vigente' => false,
            'total_registros' => 1,
            'usuario_id' => $this->user->id,
            'fecha_activacion' => now()->subYear(),
        ]);
        $noVigente = LocCatalogoOrganizacional::create([
            'import_batch_id' => $batchAntiguo->id,
            'clave_origen' => 'NO-VIG',
            'nombre' => 'AREA NO VIGENTE',
            'activo' => true,
        ]);

        $response = $this->getJson(route('credencializacion.catalogo.buscar', ['q' => 'AREA']));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($valido->id, $ids);
        $this->assertNotContains($inactivo->id, $ids);
        $this->assertNotContains($noVigente->id, $ids);
    }

    /**
     * 4. La búsqueda encuentra por clave institucional.
     */
    public function test_04_la_busqueda_encuentra_por_clave_institucional(): void
    {
        $this->actingAs($this->user);
        $item = $this->createCatItem(['clave_origen' => 'CLV-9876', 'nombre' => 'DIRECCION ESPECIAL']);

        $response = $this->getJson(route('credencializacion.catalogo.buscar', ['q' => '9876']));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('CLV-9876', $response->json('data.0.clave'));
        $this->assertSame($item->id, $response->json('data.0.id'));
    }

    /**
     * 5. La búsqueda encuentra por denominación.
     */
    public function test_05_la_busqueda_encuentra_por_denominacion(): void
    {
        $this->actingAs($this->user);
        $item = $this->createCatItem(['clave_origen' => 'CMP-01', 'nombre' => 'COMANDANCIA DE PATRULLAS TOLUCA']);

        $response = $this->getJson(route('credencializacion.catalogo.buscar', ['q' => 'PATRULLAS']));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($item->id, $response->json('data.0.id'));
        $this->assertSame('COMANDANCIA DE PATRULLAS TOLUCA', $response->json('data.0.nombre'));
    }

    /**
     * 6. La búsqueda limita resultados (máximo 15).
     */
    public function test_06_la_busqueda_limita_resultados(): void
    {
        $this->actingAs($this->user);

        // Crear 25 registros que coincidan con la búsqueda
        for ($i = 1; $i <= 25; $i++) {
            $this->createCatItem([
                'clave_origen' => sprintf('LIM-%03d', $i),
                'nombre' => "MODULO OPERATIVO MULTIPLE {$i}",
            ]);
        }

        $response = $this->getJson(route('credencializacion.catalogo.buscar', ['q' => 'MULTIPLE']));

        $response->assertOk();
        $this->assertCount(15, $response->json('data'));
    }

    /**
     * 7. Una consulta demasiado corta se maneja correctamente devolviendo array vacío.
     */
    public function test_07_una_consulta_demasiado_corta_se_maneja_correctamente(): void
    {
        $this->actingAs($this->user);
        $this->createCatItem(['clave_origen' => 'AA', 'nombre' => 'AREA ALFA']);

        // Solo 1 carácter
        $response = $this->getJson(route('credencializacion.catalogo.buscar', ['q' => 'A']));
        $response->assertOk()->assertExactJson(['data' => []]);

        // Vacío o solo espacios
        $responseVacio = $this->getJson(route('credencializacion.catalogo.buscar', ['q' => '   ']));
        $responseVacio->assertOk()->assertExactJson(['data' => []]);
    }

    /**
     * 8. No se exponen campos internos innecesarios.
     */
    public function test_08_no_se_exponen_campos_internos_innecesarios(): void
    {
        $this->actingAs($this->user);
        $this->createCatItem(['clave_origen' => 'SEC-EXPOSURE', 'nombre' => 'AREA DE AUDITORIA']);

        $response = $this->getJson(route('credencializacion.catalogo.buscar', ['q' => 'AUDITORIA']));

        $response->assertOk();
        $first = $response->json('data.0');

        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('clave', $first);
        $this->assertArrayHasKey('nombre', $first);
        $this->assertArrayHasKey('descripcion', $first);

        // Campos internos que NO deben exponerse
        $this->assertArrayNotHasKey('import_batch_id', $first);
        $this->assertArrayNotHasKey('created_at', $first);
        $this->assertArrayNotHasKey('updated_at', $first);
        $this->assertArrayNotHasKey('padre_id', $first);
    }

    // ==========================================
    // PRUEBAS DE PERSISTENCIA Y VALIDACIÓN
    // ==========================================

    /**
     * 9. Selección válida guarda ID, clave institucional y descripción congelada.
     */
    public function test_09_seleccion_valida_guarda_id_clave_y_descripcion(): void
    {
        $this->actingAs($this->user);
        $item = $this->createCatItem([
            'clave_origen' => 'ORG-55',
            'nombre' => 'COORDINACION REGIONAL SUR',
        ]);

        $response = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-CAT-001',
            'fecha_recepcion' => '2026-10-30',
            'catalogo_organizacional_id' => $item->id,
            'area_solicitante' => $item->nombre,
        ]);

        $solicitud = SolicitudCredencial::where('numero_oficio', 'OF-CAT-001')->firstOrFail();

        $this->assertSame($item->id, $solicitud->catalogo_organizacional_id);
        $this->assertSame('ORG-55', $solicitud->area_solicitante_clave);
        $this->assertSame('COORDINACION REGIONAL SUR', $solicitud->area_solicitante);

        $response->assertRedirect(route('credencializacion.detalle', $solicitud->id));
    }

    /**
     * 10. La clave y descripción se obtienen autoritativamente del catálogo, no del cliente.
     */
    public function test_10_la_clave_y_descripcion_se_obtienen_del_catalogo_no_del_cliente(): void
    {
        $this->actingAs($this->user);
        $item = $this->createCatItem([
            'clave_origen' => 'AUT-77',
            'nombre' => 'DEPARTAMENTO AUTENTICO',
        ]);

        // El cliente intenta enviar datos manipulados que discrepan del catálogo real
        $response = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-MANIPULADO-01',
            'fecha_recepcion' => '2026-10-30',
            'catalogo_organizacional_id' => $item->id,
            'area_solicitante' => 'TEXTO FORJADO POR EL CLIENTE',
            'area_solicitante_clave' => 'CLAVE_FALSA_999',
        ]);

        $solicitud = SolicitudCredencial::where('numero_oficio', 'OF-MANIPULADO-01')->firstOrFail();

        // El backend debe ignorar las manipulaciones del cliente e imponer los datos autoritativos del registro
        $this->assertSame($item->id, $solicitud->catalogo_organizacional_id);
        $this->assertSame('AUT-77', $solicitud->area_solicitante_clave);
        $this->assertSame('DEPARTAMENTO AUTENTICO', $solicitud->area_solicitante);
    }

    /**
     * 11. Texto libre guarda ID y clave como NULL y preserva el texto libre.
     */
    public function test_11_texto_libre_guarda_id_y_clave_como_null(): void
    {
        $this->actingAs($this->user);

        $response = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-LIBRE-01',
            'fecha_recepcion' => '2026-10-30',
            'area_solicitante' => 'AREA DE NUEVA CREACION NO CATALOGADA',
        ]);

        $solicitud = SolicitudCredencial::where('numero_oficio', 'OF-LIBRE-01')->firstOrFail();

        $this->assertNull($solicitud->catalogo_organizacional_id);
        $this->assertNull($solicitud->area_solicitante_clave);
        $this->assertSame('AREA DE NUEVA CREACION NO CATALOGADA', $solicitud->area_solicitante);
    }

    /**
     * 12. Solicitudes históricas conservan sus valores y NULL en los nuevos campos.
     */
    public function test_12_solicitudes_historicas_conservan_sus_valores_y_null_en_los_nuevos_campos(): void
    {
        // Simular registro histórico insertado sin campos de catálogo
        $id = DB::table('solicitudes')->insertGetId([
            'numero_tarjeta' => 'TJ-HISTORICA-001',
            'numero_oficio' => 'OF-HIST-001',
            'area_solicitante' => 'Comandancia Histórica 1999',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $solicitud = SolicitudCredencial::findOrFail($id);

        $this->assertSame('TJ-HISTORICA-001', $solicitud->numero_tarjeta);
        $this->assertSame('Comandancia Histórica 1999', $solicitud->area_solicitante);
        $this->assertNull($solicitud->catalogo_organizacional_id);
        $this->assertNull($solicitud->area_solicitante_clave);
    }

    /**
     * 13. ID inexistente es rechazado con error de validación.
     */
    public function test_13_id_inexistente_es_rechazado(): void
    {
        $this->actingAs($this->user);

        $response = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-ERR-01',
            'fecha_recepcion' => '2026-10-30',
            'catalogo_organizacional_id' => 999999,
            'area_solicitante' => 'Texto cualquiera',
        ]);

        $response->assertSessionHasErrors(['catalogo_organizacional_id']);
        $this->assertDatabaseMissing('solicitudes', ['numero_oficio' => 'OF-ERR-01']);
    }

    /**
     * 14. ID inactivo es rechazado con error de validación.
     */
    public function test_14_id_inactivo_es_rechazado(): void
    {
        $this->actingAs($this->user);
        $inactivo = $this->createCatItem(['clave_origen' => 'INA-99', 'activo' => false]);

        $response = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-ERR-02',
            'fecha_recepcion' => '2026-10-30',
            'catalogo_organizacional_id' => $inactivo->id,
            'area_solicitante' => 'Texto inactivo',
        ]);

        $response->assertSessionHasErrors(['catalogo_organizacional_id']);
        $this->assertDatabaseMissing('solicitudes', ['numero_oficio' => 'OF-ERR-02']);
    }

    /**
     * 15. ID fuera del catálogo vigente es rechazado.
     */
    public function test_15_id_fuera_del_catalogo_vigente_es_rechazado(): void
    {
        $this->actingAs($this->user);

        // Crear otro lote no vigente
        $batchViejo = LocImportBatch::create([
            'tipo_fuente' => 'catalogo_loc',
            'nombre_archivo' => 'catalogo_viejo.xlsx',
            'usuario_id' => $this->user->id,
            'estado' => 'completado',
        ]);
        LocCatalogoVersion::create([
            'import_batch_id' => $batchViejo->id,
            'version_numero' => 98,
            'es_vigente' => false,
            'total_registros' => 1,
            'usuario_id' => $this->user->id,
        ]);
        $itemViejo = LocCatalogoOrganizacional::create([
            'import_batch_id' => $batchViejo->id,
            'clave_origen' => 'VIEJO-01',
            'nombre' => 'AREA DE VERSION CADUCA',
            'activo' => true,
        ]);

        $response = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-ERR-03',
            'fecha_recepcion' => '2026-10-30',
            'catalogo_organizacional_id' => $itemViejo->id,
            'area_solicitante' => 'Texto caduco',
        ]);

        $response->assertSessionHasErrors(['catalogo_organizacional_id']);
        $this->assertDatabaseMissing('solicitudes', ['numero_oficio' => 'OF-ERR-03']);
    }

    /**
     * 16. Modificar el texto después de seleccionar no conserva una asociación contradictoria.
     */
    public function test_16_modificar_texto_despues_de_seleccionar_no_conserva_asociacion_contradictoria(): void
    {
        $this->actingAs($this->user);

        // Simula la interacción en Alpine.js donde el usuario limpió el ID al modificar el input
        $response = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-MOD-01',
            'fecha_recepcion' => '2026-10-30',
            'catalogo_organizacional_id' => null, // Alpine limpió el ID oculto
            'area_solicitante' => 'Comandancia Modificada Manualmente',
        ]);

        $solicitud = SolicitudCredencial::where('numero_oficio', 'OF-MOD-01')->firstOrFail();

        $this->assertNull($solicitud->catalogo_organizacional_id);
        $this->assertNull($solicitud->area_solicitante_clave);
        $this->assertSame('Comandancia Modificada Manualmente', $solicitud->area_solicitante);
    }

    /**
     * 17. Error de validación en catálogo no consume consecutivo de tarjeta.
     */
    public function test_17_error_de_validacion_no_consume_consecutivo_de_tarjeta(): void
    {
        $this->actingAs($this->user);
        Carbon::setTestNow('2026-10-30 12:00:00');

        $consecutivoInicial = (int) DB::table('solicitud_secuencias')
            ->where('clave', 'tarjeta_credencial')
            ->value('ultimo_consecutivo');

        $this->assertSame(50000, $consecutivoInicial);

        // Petición errónea: ID de catálogo inválido
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-FALLO-CONSEC',
            'fecha_recepcion' => '2026-10-30',
            'catalogo_organizacional_id' => 888888,
            'area_solicitante' => 'Area Invalida',
        ]);

        // La secuencia debe continuar intacta en 50000
        $consecutivoDespues = (int) DB::table('solicitud_secuencias')
            ->where('clave', 'tarjeta_credencial')
            ->value('ultimo_consecutivo');
        $this->assertSame(50000, $consecutivoDespues);

        // Petición válida subsecuente recibe 50001
        $cat = $this->createCatItem(['clave_origen' => 'OK-01', 'nombre' => 'AREA CORRECTA']);
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-EXITO-CONSEC',
            'fecha_recepcion' => '2026-10-30',
            'catalogo_organizacional_id' => $cat->id,
        ]);

        $solicitud = SolicitudCredencial::where('numero_oficio', 'OF-EXITO-CONSEC')->firstOrFail();
        $this->assertSame(50001, $solicitud->consecutivo_tarjeta);
        $this->assertSame('CE/TA25-27/50001/2026', $solicitud->numero_tarjeta);
    }

    /**
     * 18. La creación con catálogo sigue generando el formato institucional correcto.
     */
    public function test_18_la_creacion_sigue_generando_el_formato_institucional_correcto(): void
    {
        $this->actingAs($this->user);
        Carbon::setTestNow('2026-10-30 14:00:00');

        $item = $this->createCatItem(['clave_origen' => 'SEC-FMT', 'nombre' => 'AREA CON FORMATO']);

        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-FMT-001',
            'fecha_recepcion' => '2026-10-30',
            'catalogo_organizacional_id' => $item->id,
        ]);

        $solicitud = SolicitudCredencial::where('numero_oficio', 'OF-FMT-001')->firstOrFail();

        $this->assertSame('CE/TA25-27/50001/2026', $solicitud->numero_tarjeta);
        $this->assertSame(50001, $solicitud->consecutivo_tarjeta);
        $this->assertSame('SEC-FMT', $solicitud->area_solicitante_clave);
    }

    /**
     * 19. La relación Eloquent devuelve el registro correcto cuando existe y null cuando no.
     */
    public function test_19_la_relacion_eloquent_devuelve_el_registro_correcto_cuando_existe(): void
    {
        $this->actingAs($this->user);
        $item = $this->createCatItem(['clave_origen' => 'REL-01', 'nombre' => 'DIRECCION DE LOGISTICA']);

        // Solicitud con catálogo
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-REL-01',
            'fecha_recepcion' => '2026-10-30',
            'catalogo_organizacional_id' => $item->id,
        ]);
        $solicitudConCat = SolicitudCredencial::where('numero_oficio', 'OF-REL-01')->firstOrFail();

        $this->assertInstanceOf(LocCatalogoOrganizacional::class, $solicitudConCat->areaSolicitanteCatalogo);
        $this->assertSame($item->id, $solicitudConCat->areaSolicitanteCatalogo->id);
        $this->assertSame('REL-01', $solicitudConCat->areaSolicitanteCatalogo->clave_origen);

        // Solicitud libre sin catálogo
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-REL-02',
            'fecha_recepcion' => '2026-10-30',
            'area_solicitante' => 'Área sin catálogo',
        ]);
        $solicitudLibre = SolicitudCredencial::where('numero_oficio', 'OF-REL-02')->firstOrFail();

        $this->assertNull($solicitudLibre->areaSolicitanteCatalogo);
    }
}

