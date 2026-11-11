<?php

declare(strict_types=1);

namespace App\Support;

class StreamingHashResult
{
    public function __construct(
        public readonly string $hash,
        public readonly int $tamanoBytes,
        public readonly bool $esVacio,
        public readonly float $duracionMs,
        public readonly int $bloquesLeidos,
        public readonly ?string $ruta = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hash' => $this->hash,
            'tamano_bytes' => $this->tamanoBytes,
            'es_vacio' => $this->esVacio,
            'duracion_ms' => $this->duracionMs,
            'bloques_leidos' => $this->bloquesLeidos,
            'ruta' => $this->ruta,
        ];
    }
}

