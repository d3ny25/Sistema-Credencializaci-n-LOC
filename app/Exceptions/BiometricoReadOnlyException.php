<?php

declare(strict_types=1);

namespace App\Exceptions;

use League\Flysystem\FilesystemException;
use RuntimeException;

class BiometricoReadOnlyException extends RuntimeException implements FilesystemException
{
    /**
     * Intento de mutación o escritura en el repositorio biométrico.
     */
    public static function writeAttempt(string $operation, string $path): self
    {
        return new self("Operación de mutación prohibida en el repositorio biométrico institucional: [{$operation}] sobre '{$path}'.");
    }

    /**
     * Intento de evasión de directorio o acceso fuera de la raíz autorizada.
     */
    public static function pathTraversalAttempt(string $path, string $reason): self
    {
        return new self("Violación de seguridad de ruta en el repositorio biométrico: {$reason} (Ruta evaluada: '{$path}').");
    }
}

