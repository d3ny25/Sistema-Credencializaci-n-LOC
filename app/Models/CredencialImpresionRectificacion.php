<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Rectificación auditable de un resultado de impresión confirmado por error.
 * El registro original no se reescribe; esta fila se agrega y es de solo inserción.
 */
class CredencialImpresionRectificacion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'credencial_impresion_rectificaciones';

    protected $fillable = ['credencial_impresion_id', 'resultado_anterior', 'resultado_rectificado', 'motivo', 'usuario_id'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Las rectificaciones de impresión son inmutables.');
        });
        static::deleting(static function (): void {
            throw new RuntimeException('Las rectificaciones de impresión no pueden eliminarse.');
        });
    }

    public function impresion(): BelongsTo
    {
        return $this->belongsTo(CredencialImpresion::class, 'credencial_impresion_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
