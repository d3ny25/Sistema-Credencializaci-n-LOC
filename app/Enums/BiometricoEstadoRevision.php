<?php

declare(strict_types=1);

namespace App\Enums;

enum BiometricoEstadoRevision: string
{
    case SIN_EVALUAR = 'sin_evaluar';
    case APROBADO_TECNICO = 'aprobado_tecnico';
    case RECHAZADO_CALIDAD = 'rechazado_calidad';
    case EN_ACLARACION = 'en_aclaracion';

    public function label(): string
    {
        return match ($this) {
            self::SIN_EVALUAR => 'Sin evaluar',
            self::APROBADO_TECNICO => 'Aprobado técnicamente',
            self::RECHAZADO_CALIDAD => 'Rechazado por control de calidad',
            self::EN_ACLARACION => 'En proceso de aclaración técnica',
        };
    }

    public function esAprobado(): bool
    {
        return $this === self::APROBADO_TECNICO;
    }
}

