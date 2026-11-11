<?php

declare(strict_types=1);

namespace App\Enums;

enum BiometricoEstadoArchivo: string
{
    case VALIDO = 'valido';
    case SOSPECHOSO = 'sospechoso';
    case INVALIDO = 'invalido';
    case DUPLICADO_SHA = 'duplicado_sha';
    case NO_IMAGEN = 'no_imagen';
    case CUARENTENA = 'cuarentena';
    case DESAPARECIDO = 'desaparecido';
    case ERROR_LECTURA = 'error_lectura';

    public function label(): string
    {
        return match ($this) {
            self::VALIDO => 'Válido e íntegro',
            self::SOSPECHOSO => 'Sospechoso (anomalía de estructura)',
            self::INVALIDO => 'Inválido / Formato desconocido',
            self::DUPLICADO_SHA => 'Duplicado Criptográfico SHA-256',
            self::NO_IMAGEN => 'No es una imagen válida',
            self::CUARENTENA => 'En cuarentena preventiva',
            self::DESAPARECIDO => 'Desaparecido en sistema de archivos',
            self::ERROR_LECTURA => 'Error de lectura de E/S',
        };
    }

    public function esUtilizable(): bool
    {
        return $this === self::VALIDO;
    }
}

