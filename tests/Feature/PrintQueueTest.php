<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoEstadoArchivo;
use App\Enums\BiometricoEstadoRevision;
use App\Enums\BiometricoTipoIdentificacion;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoExpediente;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Services\BiometricResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'impresion',
        ]);
    }

    public function test_vista_cola_impresion_renderiza_componente_reactivo_alpine(): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->get(route('impresion'));

        $response->assertOk();
        $response->assertSee('x-data="printQueue()"', false);
        $response->assertSee('data-tarjeta-trabajo-impresion', false);
        $response->assertSee('x-model="selectedTarjeta"', false);
        $response->assertSee('validarBiometria()', false);
        $response->assertSee('elementosFiltrados', false);
        $response->assertSee('Semáforo Biometría');
    }

    public function test_endpoint_elementos_retorna_solo_aptos_y_calcula_metricas(): void
    {
        $this->actingAs($this->adminUser);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TJ-TEST-PRINT-001',
            'consecutivo_tarjeta' => 1,
            'numero_oficio' => 'OF-TEST-PRINT-001',
            'fecha_recepcion' => now(),
            'estado_general' => 'recibida',
            'area_solicitante' => 'DIRECCION DE SEGURIDAD PUBLICA',
            'total_elementos' => 3,
            'total_aptos' => 2,
        ]);

        // Elemento 1: Apto
        $apto1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'TESTCUIP0000000001',
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'pendiente_biometrico',
            'datos_originales_fila' => ['NOMBRE' => 'OFICIAL UNO'],
            'datos_solicitados' => ['nombre' => 'OFICIAL UNO'],
        ]);

        // Elemento 2: Apto
        $apto2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'TESTCUIP0000000002',
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'pendiente_biometrico',
            'datos_originales_fila' => ['NOMBRE' => 'OFICIAL DOS'],
            'datos_solicitados' => ['nombre' => 'OFICIAL DOS'],
        ]);

        // Elemento 3: Excepción (no debe aparecer en cola de impresión)
        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'TESTCUIP0000000003',
            'resultado_prefiltro' => 'Excepcion',
            'estado_tramite' => 'rechazado_prefiltro',
            'datos_originales_fila' => ['NOMBRE' => 'OFICIAL TRES'],
            'datos_solicitados' => ['nombre' => 'OFICIAL TRES'],
        ]);

        $response = $this->getJson(route('impresion.tarjeta.elementos', $solicitud));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'solicitud_id' => $solicitud->id,
            'numero_tarjeta' => 'TJ-TEST-PRINT-001',
            'metricas' => [
                'trabajos_en_cola' => 2,
                'listos_para_imprimir' => 0,
                'biometria_pendiente' => 2,
                'impresos' => 0,
            ],
        ]);

        $elementos = $response->json('elementos');
        $this->assertCount(2, $elementos);
        $this->assertEquals('TESTCUIP0000000001', $elementos[0]['cuip']);
        $this->assertEquals('TESTCUIP0000000002', $elementos[1]['cuip']);
    }

    public function test_endpoint_validar_biometria_asigna_folio_y_actualiza_estado_si_es_valido(): void
    {
        $this->actingAs($this->adminUser);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TJ-TEST-PRINT-002',
            'consecutivo_tarjeta' => 2,
            'numero_oficio' => 'OF-TEST-PRINT-002',
            'fecha_recepcion' => now(),
            'estado_general' => 'recibida',
            'area_solicitante' => 'DIRECCION DE SEGURIDAD PUBLICA',
            'total_elementos' => 1,
            'total_aptos' => 1,
        ]);

        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'TESTCUIP0000000010',
            'resultado_prefiltro' => 'Apto',
            'ambito_biometrico' => BiometricoAmbito::ESTATALES,
            'estado_clasificacion_ambito' => \App\Enums\BiometricoClasificacionEstado::VALIDADA,
            'estado_tramite' => 'pendiente_biometrico',
            'datos_originales_fila' => ['NOMBRE' => 'OFICIAL COMPLETO'],
            'datos_solicitados' => ['nombre' => 'OFICIAL COMPLETO'],
        ]);

        // Expediente biométrico completo 4/4
        $expediente = BiometricoExpediente::create([
            'cuip' => 'TESTCUIP0000000010',
            'ambito' => BiometricoAmbito::ESTATALES,
            'total_biometricos' => 4,
            'es_completo' => true,
            'estado_revision' => BiometricoEstadoRevision::SIN_EVALUAR,
            'faltantes' => [],
        ]);

        foreach (BiometricoCategoria::requeridas() as $tipo) {
            $archivo = BiometricoArchivo::create([
                'cuip_detectado' => 'TESTCUIP0000000010',
                'cuip_normalizado' => 'TESTCUIP0000000010',
                'tipo_identificacion' => BiometricoTipoIdentificacion::ESTANDAR,
                'ambito' => BiometricoAmbito::ESTATALES,
                'categoria' => $tipo,
                'nombre_archivo' => "{$tipo->value}.jpg",
                'ruta_relativa' => "TESTCUIP0000000010/{$tipo->value}.jpg",
                'sha256' => hash('sha256', "test-{$tipo->value}"),
                'tamano_bytes' => 1024,
                'formato_real' => 'jpg',
                'extension' => 'jpg',
                'mime_type' => 'image/jpeg',
                'estado_archivo' => BiometricoEstadoArchivo::VALIDO,
                'es_vigente' => true,
            ]);

            $expediente->update([$tipo->columnaExpediente() => $archivo->id]);
        }

        $response = $this->postJson(route('impresion.tarjeta.validar-biometria', $solicitud));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'metricas' => [
                'trabajos_en_cola' => 1,
                'listos_para_imprimir' => 1,
                'biometria_pendiente' => 0,
            ],
        ]);

        $detalles = $response->json('detalles');
        $this->assertCount(1, $detalles);
        $this->assertTrue($detalles[0]['biometria_valida']);
        $this->assertNotEmpty($detalles[0]['folio']);
        $this->assertEquals('listo_impresion', $detalles[0]['estado_tramite']);

        // Verificamos persistencia
        $elemento->refresh();
        $this->assertEquals('listo_impresion', $elemento->estado_tramite);
        $this->assertNotNull($elemento->credencialEmitida);
        $this->assertNotEmpty($elemento->credencialEmitida->folio);
    }

    public function test_endpoint_validar_biometria_detecta_archivos_faltantes_y_marca_pendiente(): void
    {
        $this->actingAs($this->adminUser);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TJ-TEST-PRINT-003',
            'consecutivo_tarjeta' => 3,
            'numero_oficio' => 'OF-TEST-PRINT-003',
            'fecha_recepcion' => now(),
            'estado_general' => 'recibida',
            'area_solicitante' => 'DIRECCION DE SEGURIDAD PUBLICA',
            'total_elementos' => 1,
            'total_aptos' => 1,
        ]);

        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'TESTCUIPINCOMPLETO1',
            'resultado_prefiltro' => 'Apto',
            'ambito_biometrico' => BiometricoAmbito::ESTATALES,
            'estado_clasificacion_ambito' => \App\Enums\BiometricoClasificacionEstado::VALIDADA,
            'estado_tramite' => 'pendiente_biometrico',
            'datos_originales_fila' => ['NOMBRE' => 'OFICIAL INCOMPLETO'],
            'datos_solicitados' => ['nombre' => 'OFICIAL INCOMPLETO'],
        ]);

        // Expediente con sólo 3 de 4 archivos (falta FIRMA)
        $expediente = BiometricoExpediente::create([
            'cuip' => 'TESTCUIPINCOMPLETO1',
            'ambito' => BiometricoAmbito::ESTATALES,
            'total_biometricos' => 3,
            'es_completo' => false,
            'estado_revision' => BiometricoEstadoRevision::SIN_EVALUAR,
            'faltantes' => ['firma'],
        ]);

        foreach ([BiometricoCategoria::FOTO, BiometricoCategoria::PULGAR_DERECHO, BiometricoCategoria::PULGAR_IZQUIERDO] as $tipo) {
            $archivo = BiometricoArchivo::create([
                'cuip_detectado' => 'TESTCUIPINCOMPLETO1',
                'cuip_normalizado' => 'TESTCUIPINCOMPLETO1',
                'tipo_identificacion' => BiometricoTipoIdentificacion::ESTANDAR,
                'ambito' => BiometricoAmbito::ESTATALES,
                'categoria' => $tipo,
                'nombre_archivo' => "{$tipo->value}.jpg",
                'ruta_relativa' => "TESTCUIPINCOMPLETO1/{$tipo->value}.jpg",
                'sha256' => hash('sha256', "incompleto-{$tipo->value}"),
                'tamano_bytes' => 1024,
                'formato_real' => 'jpg',
                'extension' => 'jpg',
                'mime_type' => 'image/jpeg',
                'estado_archivo' => BiometricoEstadoArchivo::VALIDO,
                'es_vigente' => true,
            ]);

            $expediente->update([$tipo->columnaExpediente() => $archivo->id]);
        }

        $response = $this->postJson(route('impresion.tarjeta.validar-biometria', $solicitud));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'metricas' => [
                'trabajos_en_cola' => 1,
                'listos_para_imprimir' => 0,
                'biometria_pendiente' => 1,
            ],
        ]);

        $detalles = $response->json('detalles');
        $this->assertCount(1, $detalles);
        $this->assertFalse($detalles[0]['biometria_valida']);
        $this->assertNull($detalles[0]['folio']);
        $this->assertEquals('pendiente_biometrico', $detalles[0]['estado_tramite']);
        $this->assertNotEmpty($detalles[0]['faltantes']);
        $this->assertFalse($detalles[0]['archivos']['firma']);
        $this->assertTrue($detalles[0]['archivos']['foto']);

        // Verificamos que NO se creó credencial emitida
        $elemento->refresh();
        $this->assertEquals('pendiente_biometrico', $elemento->estado_tramite);
        $this->assertNull($elemento->credencialEmitida);
    }
}
