<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocArma;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitudPrefiltroSchemaPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    public function test_solicitud_persists_prefiltro_metrics_and_sources_snapshot_with_json_cast(): void
    {
        $evaluador = User::factory()->create();

        $fuentesSnapshot = [
            'personal_batch_id' => 5,
            'armamento_batch_id' => 9,
            'vigencias_batch_id' => 10,
            'catalogo_version_id' => 1,
            'catalogo_batch_id' => 11,
            'evaluado_en' => '2026-10-26T08:00:00Z',
        ];

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJETA-PREFILTRO-001',
            'numero_oficio' => 'OFICIO-PREFILTRO-001',
            'fecha_recepcion' => '2026-10-26',
            'estado_general' => 'evaluada',
            'area_solicitante' => 'Coordinación Operativa Toluca',
            'total_elementos' => 25,
            'total_aptos' => 20,
            'total_descartados' => 5,
            'fuentes_evaluacion' => $fuentesSnapshot,
            'fecha_prefiltro' => '2026-10-26 12:30:00',
            'usuario_prefiltro_id' => $evaluador->id,
        ]);

        $this->assertDatabaseHas('solicitudes', [
            'id' => $solicitud->id,
            'numero_tarjeta' => 'TARJETA-PREFILTRO-001',
            'total_elementos' => 25,
            'total_aptos' => 20,
            'total_descartados' => 5,
            'usuario_prefiltro_id' => $evaluador->id,
        ]);

        $solicitud->refresh();

        $this->assertSame(25, $solicitud->total_elementos);
        $this->assertSame(20, $solicitud->total_aptos);
        $this->assertSame(5, $solicitud->total_descartados);
        $this->assertIsArray($solicitud->fuentes_evaluacion);
        $this->assertSame(5, $solicitud->fuentes_evaluacion['personal_batch_id']);
        $this->assertSame(9, $solicitud->fuentes_evaluacion['armamento_batch_id']);
        $this->assertInstanceOf(Carbon::class, $solicitud->fecha_prefiltro);
        $this->assertTrue($solicitud->usuarioPrefiltro->is($evaluador));
    }

    public function test_solicitud_elemento_persists_prefiltro_results_weapons_and_accumulated_reasons(): void
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJETA-ELEMENTO-001',
            'numero_oficio' => 'OFICIO-ELEMENTO-001',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'TESTCUIP990101HDF00',
            'nombre' => 'ELEMENTO PRUEBA PREFILTRO',
        ]);

        $armaCorta = LocArma::create([
            'matricula' => 'MC-12345',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $armaLarga = LocArma::create([
            'matricula' => 'ML-67890',
            'tipo_arma' => 'Fusil',
            'estatus' => 'FISICA',
        ]);

        $motivosDescarte = [
            'El elemento cuenta con una credencial oficial activa en estatus ALTA.',
            'El arma corta excede el límite de 3 credenciales activas con estatus ALTA.',
        ];

        $filaCruda = [
            'CUIP' => 'TESTCUIP990101HDF00',
            'NOMBRE' => 'ELEMENTO PRUEBA PREFILTRO',
            'ARMA_CORTA' => 'MC-12345',
            'ARMA_LARGA' => 'ML-67890',
            'MUNICIPIO' => 'TOLUCA',
        ];

        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 14,
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'matricula_corta' => 'MC-12345',
            'matricula_larga' => 'ML-67890',
            'arma_corta_id' => $armaCorta->id,
            'arma_larga_id' => $armaLarga->id,
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => $motivosDescarte,
            'datos_originales_fila' => $filaCruda,
            'estado_tramite' => 'pendiente',
        ]);

        $this->assertDatabaseHas('solicitud_elementos', [
            'id' => $elemento->id,
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 14,
            'matricula_corta' => 'MC-12345',
            'matricula_larga' => 'ML-67890',
            'arma_corta_id' => $armaCorta->id,
            'arma_larga_id' => $armaLarga->id,
            'resultado_prefiltro' => 'Descartado',
        ]);

        $elemento->refresh();

        $this->assertSame(14, $elemento->numero_fila);
        $this->assertIsArray($elemento->motivos_descarte);
        $this->assertCount(2, $elemento->motivos_descarte);
        $this->assertSame($motivosDescarte[0], $elemento->motivos_descarte[0]);
        $this->assertIsArray($elemento->datos_originales_fila);
        $this->assertSame('TOLUCA', $elemento->datos_originales_fila['MUNICIPIO']);
        $this->assertTrue($elemento->armaCorta->is($armaCorta));
        $this->assertTrue($elemento->armaLarga->is($armaLarga));
    }

    public function test_solicitud_elemento_supports_nullable_weapons_cuip_and_empty_motivos(): void
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJETA-NULLABLE-001',
            'numero_oficio' => 'OFICIO-NULLABLE-001',
        ]);

        // Elemento defectuoso recibido sin CUIP ni armas asignadas aún
        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'persona_id' => null,
            'cuip' => null,
            'matricula_corta' => null,
            'matricula_larga' => null,
            'arma_corta_id' => null,
            'arma_larga_id' => null,
            'resultado_prefiltro' => null,
            'motivos_descarte' => null,
            'datos_originales_fila' => ['COLUMNA_DESCONOCIDA' => 'VALOR'],
        ]);

        $this->assertDatabaseHas('solicitud_elementos', [
            'id' => $elemento->id,
            'cuip' => null,
            'matricula_corta' => null,
            'matricula_larga' => null,
            'arma_corta_id' => null,
            'resultado_prefiltro' => null,
        ]);

        $this->assertNull($elemento->armaCorta);
        $this->assertNull($elemento->armaLarga);
        $this->assertNull($elemento->persona);
        $this->assertNull($elemento->resultado_prefiltro);
    }

    public function test_solicitud_elemento_accepts_apto_result_without_motivos(): void
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJETA-APTO-001',
            'numero_oficio' => 'OFICIO-APTO-001',
        ]);

        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'TESTCUIPAPTO001',
            'matricula_corta' => 'COR-001',
            'resultado_prefiltro' => 'Apto',
            'motivos_descarte' => [],
            'estado_tramite' => 'pendiente',
        ]);

        $elemento->refresh();

        $this->assertSame('Apto', $elemento->resultado_prefiltro);
        $this->assertIsArray($elemento->motivos_descarte);
        $this->assertEmpty($elemento->motivos_descarte);
    }
}

