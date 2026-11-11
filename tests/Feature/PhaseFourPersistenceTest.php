<?php

namespace Tests\Feature;

use App\Models\CredencialEmitida;
use App\Models\LocArma;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PhaseFourPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_tables_are_present_after_migrations(): void
    {
        $tables = [
            'loc_import_batches',
            'loc_personas',
            'loc_persona_antecedentes',
            'loc_armas',
            'loc_persona_armas',
            'loc_catalogo_organizacional',
            'loc_vigencias_credenciales',
            'solicitudes',
            'solicitud_elementos',
            'credenciales_emitidas',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), sprintf('La tabla %s no fue creada por las migraciones.', $table));
        }
    }

    public function test_person_can_have_many_weapons_and_request_can_have_many_elements(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'CUIP-0001',
            'nombre' => 'Usuario Prueba',
            'estatus' => 'activo',
            'categoria' => 'Operativo',
        ]);

        $armaUno = LocArma::create([
            'matricula' => 'MTZ-001',
            'tipo_arma' => 'Pistola',
            'estatus' => 'operativa',
            'activo' => true,
        ]);
        $armaDos = LocArma::create([
            'matricula' => 'MTZ-002',
            'tipo_arma' => 'Rifle',
            'estatus' => 'operativa',
            'activo' => true,
        ]);

        $persona->armas()->attach($armaUno->id, [
            'fecha_asignacion' => '2026-01-15',
            'estatus_asignacion' => 'activa',
        ]);
        $persona->armas()->attach($armaDos->id, [
            'fecha_asignacion' => '2026-02-20',
            'estatus_asignacion' => 'activa',
        ]);

        $this->assertCount(2, $persona->fresh()->armas);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-2026-0001',
            'numero_oficio' => 'OFI-2026-0001',
            'fecha_recepcion' => '2026-03-10',
            'estado_general' => 'en_revision',
            'area_solicitante' => 'Subdirección Operativa',
        ]);

        $primerElemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'tipo_elemento' => 'persona',
            'estado_tramite' => 'pendiente',
        ]);

        $segundoElemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'tipo_elemento' => 'persona',
            'estado_tramite' => 'pendiente',
        ]);

        $this->assertCount(2, $solicitud->fresh()->elementos);
        $this->assertSame($solicitud->numero_tarjeta, $primerElemento->solicitud->numero_tarjeta);
        $this->assertSame($solicitud->numero_tarjeta, $segundoElemento->solicitud->numero_tarjeta);
    }

    public function test_request_number_is_shared_and_credentials_have_distinct_folio(): void
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-2026-0002',
            'numero_oficio' => 'OFI-2026-0002',
            'fecha_recepcion' => '2026-04-01',
            'estado_general' => 'aprobada',
        ]);

        $credencialUno = CredencialEmitida::create([
            'solicitud_id' => $solicitud->id,
            'folio' => 'FOL-1001',
            'numero_tarjeta' => $solicitud->numero_tarjeta,
            'estado_biometria' => 'completo',
            'estado_emision' => 'emitido',
            'estado_entrega' => 'pendiente',
        ]);

        $credencialDos = CredencialEmitida::create([
            'solicitud_id' => $solicitud->id,
            'folio' => 'FOL-1002',
            'numero_tarjeta' => $solicitud->numero_tarjeta,
            'estado_biometria' => 'completo',
            'estado_emision' => 'emitido',
            'estado_entrega' => 'pendiente',
        ]);

        $this->assertSame($solicitud->numero_tarjeta, $credencialUno->numero_tarjeta);
        $this->assertSame($solicitud->numero_tarjeta, $credencialDos->numero_tarjeta);
        $this->assertNotSame($credencialUno->folio, $credencialDos->folio);
        $this->assertCount(2, $solicitud->fresh()->credencialesEmitidas);
    }

    public function test_significant_history_entries_are_recorded_for_changes(): void
    {
        $persona = LocPersona::create([
            'cuip' => 'CUIP-0002',
            'nombre' => 'Cambio Historial',
            'estatus' => 'activo',
        ]);

        $antecedente = $persona->antecedentes()->create([
            'campo' => 'estatus',
            'valor_anterior' => 'activo',
            'valor_nuevo' => 'retirado',
            'fecha_detectada' => '2026-05-01',
            'motivo' => 'Cambio de situación',
        ]);

        $this->assertSame('estatus', $antecedente->campo);
        $this->assertDatabaseHas('loc_persona_antecedentes', [
            'persona_id' => $persona->id,
            'campo' => 'estatus',
        ]);
    }
}
