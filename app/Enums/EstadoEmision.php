<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Valores canónicos de credenciales_emitidas.estado_emision.
 * La columna se mantiene como string (sin cast) porque servicios existentes comparan con el literal.
 */
enum EstadoEmision: string
{
    case PENDIENTE = 'pendiente';
    case EMITIDA = 'emitida';
    case CANCELADA = 'cancelada';
}
