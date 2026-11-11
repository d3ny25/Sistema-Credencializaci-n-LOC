<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoTipoIdentificacion;

class BiometricParseResult
{
    public function __construct(
        public readonly string $nombreArchivo,
        public readonly ?string $rutaRelativa,
        public readonly string $extension,
        public readonly ?string $cuipDetectado,
        public readonly ?string $cuipNormalizado,
        public readonly ?BiometricoCategoria $categoria,
        public readonly BiometricoTipoIdentificacion $tipoIdentificacion,
        public readonly bool $esValido,
        public readonly bool $esCuipValido,
        public readonly bool $esExtensionAdmitida,
        public readonly ?string $motivoInvalidez = null,
        public readonly ?string $codigoIncidencia = null,
    ) {}

    /**
     * Determina si el archivo cuenta con todos los elementos para asociarse a un expediente válido.
     */
    public function esAptoParaIndexacion(): bool
    {
        return $this->esValido
            && $this->esCuipValido
            && $this->cuipNormalizado !== null
            && $this->categoria !== null
            && $this->esExtensionAdmitida;
    }

    /**
     * Determina si el archivo representa una incidencia que debe ser registrada.
     */
    public function tieneIncidencia(): bool
    {
        return ! $this->esValido || $this->codigoIncidencia !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nombre_archivo' => $this->nombreArchivo,
            'ruta_relativa' => $this->rutaRelativa,
            'extension' => $this->extension,
            'cuip_detectado' => $this->cuipDetectado,
            'cuip_normalizado' => $this->cuipNormalizado,
            'categoria' => $this->categoria?->value,
            'tipo_identificacion' => $this->tipoIdentificacion->value,
            'es_valido' => $this->esValido,
            'es_cuip_valido' => $this->esCuipValido,
            'es_extension_admitida' => $this->esExtensionAdmitida,
            'motivo_invalidez' => $this->motivoInvalidez,
            'codigo_incidencia' => $this->codigoIncidencia,
        ];
    }
}

