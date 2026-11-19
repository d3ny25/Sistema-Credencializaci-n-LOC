<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** Tarjeta física (credencial_impresion_id, única) incluida en una entrega. Solo inserción. */
class CredencialEntregaDetalle extends Model
{
    protected $table = 'credencial_entrega_detalles';

    protected $fillable = [
        'credencial_entrega_id',
        'credencial_impresion_id',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Los detalles de entrega no se modifican.');
        });
        static::deleting(static function (): void {
            throw new RuntimeException('Los detalles de entrega no pueden eliminarse.');
        });
    }

    public function entrega(): BelongsTo
    {
        return $this->belongsTo(CredencialEntrega::class, 'credencial_entrega_id');
    }

    public function impresion(): BelongsTo
    {
        return $this->belongsTo(CredencialImpresion::class, 'credencial_impresion_id');
    }
}
