<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Falla de preservación o lectura de evidencia biométrica. El mensaje nunca contiene CUIP,
 * nombres, rutas de INGESTA ni contenido: solo un código técnico estable y referencias lógicas.
 */
class EvidenciaBiometricaException extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $detalles  Datos técnicos sin información personal.
     */
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly string $severidad = 'alta',
        public readonly array $detalles = [],
    ) {
        parent::__construct($mensaje);
    }
}
