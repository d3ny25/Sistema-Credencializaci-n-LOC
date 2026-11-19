<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BiometricoCategoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Componente biométrico del snapshot de una preparación (cuatro por preparación).
 * `clasificacion`: verificada (contenido preservado y hash comprobado), inferida, sin_contenido o desconocida.
 */
class CredencialPreparacionBiometrico extends Model
{
    public const UPDATED_AT = null;

    public const VERIFICADA = 'verificada';

    protected $table = 'credencial_preparacion_biometricos';

    protected $fillable = [
        'credencial_preparacion_id', 'categoria', 'biometrico_evidencia_id', 'sha256',
        'biometrico_archivo_id', 'biometrico_archivo_version_id', 'clasificacion',
    ];

    protected $casts = [
        'categoria' => BiometricoCategoria::class,
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Los componentes del snapshot biométrico son inmutables.');
        });
        static::deleting(static function (): void {
            throw new RuntimeException('Los componentes del snapshot biométrico no pueden eliminarse.');
        });
    }

    public function preparacion(): BelongsTo
    {
        return $this->belongsTo(CredencialPreparacion::class, 'credencial_preparacion_id');
    }

    public function evidencia(): BelongsTo
    {
        return $this->belongsTo(BiometricoEvidencia::class, 'biometrico_evidencia_id');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(BiometricoArchivo::class, 'biometrico_archivo_id');
    }

    public function archivoVersion(): BelongsTo
    {
        return $this->belongsTo(BiometricoArchivoVersion::class, 'biometrico_archivo_version_id');
    }
}
