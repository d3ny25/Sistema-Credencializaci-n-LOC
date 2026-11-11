<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceEvent;

class DeviceDiagnostics
{
    public function run(Device $device): array
    {
        $details = config('devices.types.'.$device->type);

        if (! is_array($details)) {
            throw new \LogicException('El dispositivo no tiene un tipo registrado en el catálogo.');
        }

        $message = match ($device->type) {
            'zebra_zxp_series_8' => 'No se envió un trabajo de impresión: Laravel no controla el diálogo ni el controlador Zebra de la estación. La prueba física debe realizarse desde el equipo operador.',
            'canon_eos_rebel_t6' => 'No se leyó la cámara: EOS Utility guarda las fotos en una carpeta local de la estación, inaccesible para el servidor Laravel.',
            'topaz_siglite_lcd_1x5' => 'No se consultó el pad: SigWeb y su biblioteca se ejecutan localmente en la estación del operador, no en el servidor Laravel.',
            'crossmatch_verifier_300_lc_2_0' => 'No se leyó el lector: requiere el programa del fabricante y una carpeta de exportación local en la estación del operador.',
            default => 'No existe un adaptador compatible con este dispositivo en el servidor Laravel.',
        };

        return [
            'operation' => 'server_hardware_availability',
            'result' => DeviceEvent::RESULT_UNAVAILABLE,
            'message' => $message,
            'duration_ms' => null,
        ];
    }
}
