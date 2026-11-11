<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class SequenceNotInitializedException extends RuntimeException
{
    public function __construct(
        protected string $sequenceKey = 'tarjeta_credencial',
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $message = $message ?: "La secuencia institucional '{$sequenceKey}' no está inicializada en la tabla 'solicitud_secuencias'. Ejecute las migraciones del sistema antes de procesar solicitudes.";
        parent::__construct($message, $code, $previous);
    }

    public function getSequenceKey(): string
    {
        return $this->sequenceKey;
    }
}

