<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * El ámbito ya tiene un propietario de lease (vigente, o vencido sin --takeover) o la adquisición
 * colisionó con otro proceso. No se creó ninguna corrida.
 */
class BiometricoLeaseOcupadoException extends RuntimeException
{
}
