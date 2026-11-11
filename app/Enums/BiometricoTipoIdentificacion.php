<?php

declare(strict_types=1);

namespace App\Enums;

enum BiometricoTipoIdentificacion: string
{
    case ESTANDAR = 'estandar';
    case CORTO_RFC = 'corto_rfc';
    case NO_ESTANDAR = 'no_estandar';
    case GUID_HARDWARE = 'guid_hardware';
    case SIN_NOMBRE = 'sin_nombre';
    case SISTEMA = 'sistema';

    public function label(): string
    {
        return match ($this) {
            self::ESTANDAR => 'Estándar CUIP (16-20 caracteres)',
            self::CORTO_RFC => 'Identificador Corto / RFC (10 caracteres)',
            self::NO_ESTANDAR => 'No Estándar / Fuera de patrón',
            self::GUID_HARDWARE => 'GUID de Hardware (Captura sin CUIP)',
            self::SIN_NOMBRE => 'Sin identificador detectable',
            self::SISTEMA => 'Archivo de sistema / Oculto',
        };
    }
}

