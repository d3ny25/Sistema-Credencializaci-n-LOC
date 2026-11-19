<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * Bitácora de intentos de impresión física de una credencial (emisión y reimpresiones).
 * Desde la Fase 1B.2 cada confirmación pertenece a una preparación (una sola por preparación) y es
 * de solo inserción: un error se corrige con una rectificación, nunca reescribiendo el registro.
 */
class CredencialImpresion extends Model
{
    public const RESULTADO_IMPRESA = 'impresa';
    public const RESULTADO_FALLIDA = 'fallida';

    public const MODALIDAD_NORMAL = 'normal';
    public const MODALIDAD_EXTEMPORANEA = 'extemporanea';

    protected $table = 'credencial_impresiones';

    protected $fillable = [
        'credencial_emitida_id',
        'solicitud_elemento_id',
        'credencial_preparacion_id',
        'usuario_id',
        'estacion_id',
        'impresora',
        'resultado',
        'modalidad_confirmacion',
        'justificacion_extemporanea',
        'motivo',
        'numero_intento',
    ];

    protected $casts = [
        'numero_intento' => 'integer',
        'estacion_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Los registros de impresión no se modifican; registre una rectificación.');
        });
        static::deleting(static function (): void {
            throw new RuntimeException('Los registros de impresión no pueden eliminarse.');
        });
    }

    public function credencial(): BelongsTo
    {
        return $this->belongsTo(CredencialEmitida::class, 'credencial_emitida_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function preparacion(): BelongsTo
    {
        return $this->belongsTo(CredencialPreparacion::class, 'credencial_preparacion_id');
    }

    public function rectificaciones(): HasMany
    {
        return $this->hasMany(CredencialImpresionRectificacion::class, 'credencial_impresion_id');
    }

    /** Entrega física de esta tarjeta, si ya se registró (Entrega y Seguimiento, Iteración 1.3). */
    public function entregaDetalle(): HasOne
    {
        return $this->hasOne(CredencialEntregaDetalle::class, 'credencial_impresion_id');
    }

    /** Resultado vigente: el de la última rectificación, si existe; si no, el original. */
    public function resultadoVigente(): string
    {
        // Misma regla con las rectificaciones ya cargadas (listados con eager loading, sin una consulta por impresión).
        if ($this->relationLoaded('rectificaciones')) {
            return $this->rectificaciones->sortByDesc('id')->first()?->resultado_rectificado ?? $this->resultado;
        }

        return $this->rectificaciones()->latest('id')->value('resultado_rectificado') ?? $this->resultado;
    }
}
