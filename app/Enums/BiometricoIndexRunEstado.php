<?php

declare(strict_types=1);

namespace App\Enums;

enum BiometricoIndexRunEstado: string
{
    case PENDIENTE = 'pendiente';
    case EN_EJECUCION = 'en_ejecucion';
    case COMPLETADA = 'completada';
    case COMPLETADA_CON_INCIDENCIAS = 'completada_con_incidencias';
    case FALLIDA = 'fallida';
    case INTERRUMPIDA = 'interrumpida';
    case CANCELADA = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::PENDIENTE => 'Pendiente de inicio',
            self::EN_EJECUCION => 'En ejecución activa',
            self::COMPLETADA => 'Completada satisfactoriamente',
            self::COMPLETADA_CON_INCIDENCIAS => 'Completada con incidencias registradas',
            self::FALLIDA => 'Fallida por error crítico',
            self::INTERRUMPIDA => 'Interrumpida por operador o sistema',
            self::CANCELADA => 'Cancelada administrativamente',
        };
    }

    /**
     * Determina si la ejecución se encuentra en un estado terminal definitivo.
     */
    public function isFinalizada(): bool
    {
        return match ($this) {
            self::COMPLETADA,
            self::COMPLETADA_CON_INCIDENCIAS,
            self::FALLIDA,
            self::INTERRUMPIDA,
            self::CANCELADA => true,
            self::PENDIENTE,
            self::EN_EJECUCION => false,
        };
    }

    /**
     * Determina si el estado permite reanudar el procesamiento desde un checkpoint.
     */
    public function permiteReanudacion(): bool
    {
        return match ($this) {
            self::INTERRUMPIDA,
            self::CANCELADA,
            self::FALLIDA => true,
            default => false,
        };
    }

    /**
     * Determina si la ejecución concluyó con éxito técnico total o parcial.
     */
    public function esExitosa(): bool
    {
        return $this === self::COMPLETADA || $this === self::COMPLETADA_CON_INCIDENCIAS;
    }
}

