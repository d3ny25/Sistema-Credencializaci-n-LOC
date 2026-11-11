<?php

declare(strict_types=1);

namespace App\Enums;

enum BiometricoCategoria: string
{
    case FOTO = 'foto';
    case FIRMA = 'firma';
    case PULGAR_DERECHO = 'pulgar_derecho';
    case PULGAR_IZQUIERDO = 'pulgar_izquierdo';

    public function label(): string
    {
        return match ($this) {
            self::FOTO => 'Fotografía Facial',
            self::FIRMA => 'Firma Digitalizada',
            self::PULGAR_DERECHO => 'Huella Pulgar Derecho',
            self::PULGAR_IZQUIERDO => 'Huella Pulgar Izquierdo',
        };
    }

    public function columnaExpediente(): string
    {
        return match ($this) {
            self::FOTO => 'foto_archivo_id',
            self::FIRMA => 'firma_archivo_id',
            self::PULGAR_DERECHO => 'pulgar_derecho_archivo_id',
            self::PULGAR_IZQUIERDO => 'pulgar_izquierdo_archivo_id',
        };
    }

    /**
     * Devuelve todas las categorías requeridas para un expediente completo.
     *
     * @return array<int, self>
     */
    public static function requeridas(): array
    {
        return [
            self::FOTO,
            self::FIRMA,
            self::PULGAR_DERECHO,
            self::PULGAR_IZQUIERDO,
        ];
    }
}

