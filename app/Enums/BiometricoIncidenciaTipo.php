<?php

declare(strict_types=1);

namespace App\Enums;

enum BiometricoIncidenciaTipo: string
{
    case NOMBRE_INVALIDO = 'nombre_invalido';
    case CUIP_INVALIDO = 'cuip_invalido';
    case CATEGORIA_DESCONOCIDA = 'categoria_desconocida';
    case ARCHIVO_ILEGIBLE = 'archivo_ilegible';
    case HASH_NO_CALCULABLE = 'hash_no_calculable';
    case ARCHIVO_DUPLICADO = 'archivo_duplicado';
    case RUTA_NO_PERMITIDA = 'ruta_no_permitida';
    case ARCHIVO_DESAPARECIDO = 'archivo_desaparecido';
    case CONFLICTO_IDENTIDAD = 'conflicto_identidad';
    case ERROR_PROCESAMIENTO = 'error_procesamiento';

    public function label(): string
    {
        return match ($this) {
            self::NOMBRE_INVALIDO => 'Estructura o sintaxis de nombre inválida',
            self::CUIP_INVALIDO => 'Identificador CUIP no estándar o no reconocido',
            self::CATEGORIA_DESCONOCIDA => 'Categoría biométrica no identificable',
            self::ARCHIVO_ILEGIBLE => 'Descriptor de archivo ilegible o dañado',
            self::HASH_NO_CALCULABLE => 'Fallo al calcular suma criptográfica SHA-256',
            self::ARCHIVO_DUPLICADO => 'Colisión o duplicidad de archivo/contenido',
            self::RUTA_NO_PERMITIDA => 'Ruta relativa anómala o intento de traversal',
            self::ARCHIVO_DESAPARECIDO => 'Archivo físico ausente en almacenamiento',
            self::CONFLICTO_IDENTIDAD => 'Discrepancia entre datos de archivo y expediente',
            self::ERROR_PROCESAMIENTO => 'Excepción no controlada durante el procesamiento',
        };
    }
}

