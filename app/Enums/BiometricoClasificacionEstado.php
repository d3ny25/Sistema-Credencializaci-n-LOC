<?php

declare(strict_types=1);

namespace App\Enums;

enum BiometricoClasificacionEstado: string
{
    case PROVISIONAL = 'provisional';
    case VALIDADA = 'validada';
    case DEFINITIVA = 'definitiva';

    public function label(): string
    {
        return match ($this) {
            self::PROVISIONAL => 'Clasificación Provisional (Importación Excel)',
            self::VALIDADA => 'Clasificación Validada (Prefiltro Institucional)',
            self::DEFINITIVA => 'Clasificación Definitiva (Emisión / Impresión)',
        };
    }

    /**
     * Solo los estados VALIDADA y DEFINITIVA permiten asociar formalmente un expediente biométrico.
     * Un estado PROVISIONAL no puede vincular un expediente definitivo.
     */
    public function permiteAsociacionDefinitiva(): bool
    {
        return $this === self::VALIDADA || $this === self::DEFINITIVA;
    }
}

