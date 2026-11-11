<?php

declare(strict_types=1);

namespace App\Support;

/**
 * DTO inmutable que representa una entrada descubierta durante la exploración del escáner biométrico.
 */
class BiometricScanEntry
{
    public function __construct(
        public readonly string $rutaRelativa,
        public readonly string $rutaAbsoluta,
        public readonly string $nombreArchivo,
        public readonly string $extension,
        public readonly int $tamanoBytes,
        public readonly ?int $mtime = null,
        public readonly bool $esLegible = true,
        public readonly bool $esEnlace = false,
        public readonly bool $esDirectorio = false,
        public readonly bool $esArchivoRegular = true,
        public readonly bool $esSistema = false,
        public readonly bool $esOculto = false,
        public readonly bool $esValido = true,
        public readonly string $tipoEntrada = 'archivo',
        public readonly ?string $motivoRechazo = null,
        public readonly ?string $codigoError = null,
    ) {
    }

    /**
     * Retorna si la entrada es un archivo biométrico candidato a procesamiento sintáctico y de hash.
     */
    public function esCandidatoProcesamiento(): bool
    {
        return $this->esValido
            && $this->esArchivoRegular
            && $this->esLegible
            && ! $this->esEnlace
            && ! $this->esDirectorio
            && ! $this->esSistema;
    }
}

