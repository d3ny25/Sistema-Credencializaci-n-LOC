<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Operación de entrega física de una o varias credenciales de un número de tarjeta (Iteración 1.3).
 * Solo inserción: receptor, responsable, fecha y tarjetas incluidas no cambian (triggers en la base).
 */
class CredencialEntrega extends Model
{
    protected $table = 'credencial_entregas';

    protected $fillable = [
        'solicitud_id',
        'receptor_nombre',
        'entregado_por_user_id',
        'entregada_at',
        'clave_operacion',
    ];

    protected $casts = [
        'entregada_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Las entregas registradas no se modifican.');
        });
        static::deleting(static function (): void {
            throw new RuntimeException('Las entregas registradas no pueden eliminarse.');
        });
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCredencial::class, 'solicitud_id');
    }

    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregado_por_user_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(CredencialEntregaDetalle::class, 'credencial_entrega_id')->orderBy('id');
    }
}
