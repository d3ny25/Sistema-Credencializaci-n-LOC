<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Categorías del expediente documental de Entrega y Seguimiento. La base guarda el valor como texto (sin CHECK):
 * una categoría nueva se agrega aquí, sin migración. "otros" exige una descripción que identifique el archivo.
 */
enum CategoriaDocumentoEntrega: string
{
    case OFICIO_SOLICITUD = 'oficio_solicitud';
    case CONFIRMACION_RECEPCION = 'confirmacion_recepcion';
    case OTROS = 'otros';

    public function label(): string
    {
        return match ($this) {
            self::OFICIO_SOLICITUD => 'Oficio de solicitud',
            self::CONFIRMACION_RECEPCION => 'Confirmación de recepción',
            self::OTROS => 'Otros documentos',
        };
    }

    public function exigeDescripcion(): bool
    {
        return $this === self::OTROS;
    }

    /** @return array<string, string> valor => etiqueta */
    public static function opciones(): array
    {
        return array_column(array_map(static fn (self $c): array => [$c->value, $c->label()], self::cases()), 1, 0);
    }
}
