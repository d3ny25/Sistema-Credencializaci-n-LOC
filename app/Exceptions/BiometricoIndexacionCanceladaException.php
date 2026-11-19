<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Señal interna: el proceso activo detectó, al inicio de un lote, una cancelación
 * solicitada con --cancel. No se propaga al operador: la corrida termina como CANCELADA.
 */
class BiometricoIndexacionCanceladaException extends RuntimeException
{
}
