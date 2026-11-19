<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * El proceso perdió la propiedad del lease (otro proceso lo adquirió o fue eliminado):
 * debe dejar de escribir de inmediato. El lote en curso se revierte completo.
 */
class BiometricoLeasePerdidoException extends RuntimeException
{
}
