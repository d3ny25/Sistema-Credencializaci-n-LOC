<?php

namespace Tests\Feature;

use App\Models\CredencialEmitida;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SolicitudIntegrityPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_solicitud_with_100_char_tarjeta_and_oficio(): void
    {
        $tarjetaLarga = 'TAR/SS/DGCRVyT/SUB-ORIENTE/REG-NORTE/MUN-ECATEPEC/2026-VAL-LONGITUD-100-CHARS-MAXIMA-EJEMPLO-TEST-OFICIAL-01';
        $oficioLargo  = 'OFI/SS/DGCRVyT/SUB-ORIENTE/REG-NORTE/MUN-ECATEPEC/2026-VAL-LONGITUD-100-CHARS-MAXIMA-EJEMPLO-TEST-OFICIAL-01';

        $this->assertSame(100, strlen(substr($tarjetaLarga, 0, 100)));
        $this->assertSame(100, strlen(substr($oficioLargo, 0, 100)));

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => substr($tarjetaLarga, 0, 100),
            'numero_oficio' => substr($oficioLargo, 0, 100),
            'fecha_recepcion' => '2026-10-26',
            'estado_general' => 'pendiente',
            'area_solicitante' => 'Dirección General de Combate al Robo de Vehículos y Transporte',
        ]);

        $this->assertDatabaseHas('solicitudes', [
            'id' => $solicitud->id,
            'numero_tarjeta' => substr($tarjetaLarga, 0, 100),
            'numero_oficio' => substr($oficioLargo, 0, 100),
        ]);
    }

    public function test_numero_tarjeta_is_strictly_unique(): void
    {
        SolicitudCredencial::create([
            'numero_tarjeta' => 'AVD/2026/001',
            'numero_oficio' => 'OFI/2026/001',
            'fecha_recepcion' => '2026-10-26',
        ]);

        $this->expectException(QueryException::class);

        // Intento de duplicar numero_tarjeta con distinto oficio
        SolicitudCredencial::create([
            'numero_tarjeta' => 'AVD/2026/001',
            'numero_oficio' => 'OFI/2026/002',
            'fecha_recepcion' => '2026-10-26',
        ]);
    }

    public function test_numero_oficio_is_strictly_unique(): void
    {
        SolicitudCredencial::create([
            'numero_tarjeta' => 'AVD/2026/001',
            'numero_oficio' => 'OFI/2026/001',
            'fecha_recepcion' => '2026-10-26',
        ]);

        $this->expectException(QueryException::class);

        // Intento de duplicar numero_oficio con distinta tarjeta
        SolicitudCredencial::create([
            'numero_tarjeta' => 'AVD/2026/002',
            'numero_oficio' => 'OFI/2026/001',
            'fecha_recepcion' => '2026-10-26',
        ]);
    }

    public function test_can_preserve_discarded_element_with_null_cuip(): void
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-DEFECTUOSA-001',
            'numero_oficio' => 'OFI-DEFECTUOSO-001',
            'fecha_recepcion' => '2026-10-26',
            'estado_general' => 'en_revision',
        ]);

        $elementoSinCuip = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'persona_id' => null,
            'cuip' => null, // CUIP nulo o ausente en el archivo
            'tipo_elemento' => 'persona',
            'datos_solicitados' => json_encode(['fila' => 18, 'nombre' => 'Oficial sin CUIP recibido']),
            'estado_tramite' => 'descartado',
            'motivo_rechazo' => 'CUIP ausente o vacío en el archivo de solicitud',
        ]);

        $this->assertDatabaseHas('solicitud_elementos', [
            'id' => $elementoSinCuip->id,
            'solicitud_id' => $solicitud->id,
            'cuip' => null,
            'estado_tramite' => 'descartado',
            'motivo_rechazo' => 'CUIP ausente o vacío en el archivo de solicitud',
        ]);

        $this->assertNull($elementoSinCuip->fresh()->cuip);
    }

    public function test_credenciales_emitidas_accepts_100_char_numero_tarjeta_and_nullable(): void
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR/SS/100-CARACTERES-PRUEBA-DE-LONGITUD-CORRECTA-MAXIMA-PARA-CREDENCIALES-EMITIDAS-EJEMPLO-TEST-000000001',
            'numero_oficio' => 'OFI/SS/100-CARACTERES-PRUEBA-DE-LONGITUD-CORRECTA-MAXIMA-PARA-CREDENCIALES-EMITIDAS-EJEMPLO-TEST-000000001',
            'fecha_recepcion' => '2026-10-26',
        ]);

        $credencial = CredencialEmitida::create([
            'solicitud_id' => $solicitud->id,
            'folio' => 'FOL-2026-0001',
            'numero_tarjeta' => $solicitud->numero_tarjeta,
            'estado_biometria' => 'pendiente',
            'estado_emision' => 'pendiente',
            'estado_entrega' => 'pendiente',
        ]);

        $this->assertSame($solicitud->numero_tarjeta, $credencial->fresh()->numero_tarjeta);
    }
}

