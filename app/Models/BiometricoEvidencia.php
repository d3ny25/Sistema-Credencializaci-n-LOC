<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Contenido biométrico preservado, direccionado por SHA-256 en el disco privado `biometricos_evidencia`.
 * Solo inserción: el modelo rechaza save()/delete() sobre registros existentes y la base de datos
 * lo refuerza con triggers. Ninguna de las dos capas protege frente a un administrador del motor.
 */
class BiometricoEvidencia extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'biometrico_evidencias';

    protected $fillable = ['sha256', 'ruta_relativa', 'mime_real', 'formato', 'tamano_bytes', 'ancho_px', 'alto_px'];

    protected $casts = [
        'tamano_bytes' => 'integer',
        'ancho_px' => 'integer',
        'alto_px' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('La evidencia biométrica preservada es inmutable.');
        });
        static::deleting(static function (): void {
            throw new RuntimeException('La evidencia biométrica preservada no puede eliminarse.');
        });
    }

    public function usos(): HasMany
    {
        return $this->hasMany(CredencialPreparacionBiometrico::class, 'biometrico_evidencia_id');
    }
}
