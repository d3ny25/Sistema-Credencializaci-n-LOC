<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class PrefiltroSourcesMissingException extends RuntimeException
{
    /**
     * @param list<string> $missingSources
     */
    public function __construct(
        protected array $missingSources,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $message = $message ?: 'Faltan fuentes oficiales requeridas para la ejecución del prefiltro: ' . implode(', ', $missingSources);
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return list<string>
     */
    public function getMissingSources(): array
    {
        return $this->missingSources;
    }
}

