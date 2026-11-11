<?php

declare(strict_types=1);

namespace App\Enums;

enum BiometricoIncidenciaSeveridad: string
{
    case CRITICA = 'critica';
    case ALTA = 'alta';
    case MEDIA = 'media';
    case BAJA = 'baja';
    case INFO = 'info';

    public function label(): string
    {
        return match ($this) {
            self::CRITICA => 'Crítica (Bloqueo de lote o daño físico)',
            self::ALTA => 'Alta (Identidad no resuelta o archivo corrupto)',
            self::MEDIA => 'Media (Anomalía de nombre o duplicado)',
            self::BAJA => 'Baja (Archivo de sistema o advertencia leve)',
            self::INFO => 'Informativa (Detalle de diagnóstico)',
        };
    }
}

