<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocCatalogoOrganizacional extends Model
{
    protected $table = 'loc_catalogo_organizacional';

    protected $fillable = [
        'import_batch_id',
        'clave_origen',
        'nivel_jerarquico',
        'nombre',
        'direccion_general',
        'coordinacion',
        'subdireccion',
        'region',
        'agrupamiento',
        'municipio',
        'zona_militar',
        'batallon',
        'padre_id',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'padre_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(LocImportBatch::class, 'import_batch_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(LocCatalogoVersion::class, 'import_batch_id', 'import_batch_id');
    }

    /**
     * Obtiene la versión autoritativa vigente del catálogo.
     */
    public static function versionVigente(): ?LocCatalogoVersion
    {
        return LocCatalogoVersion::query()->where('es_vigente', true)->first();
    }

    /**
     * Scope autoritativo para consultar únicamente los registros de la versión vigente.
     * Garantiza que nunca se mezclen registros de distintas versiones.
     */
    public function scopeVigente(Builder $query): Builder
    {
        $version = static::versionVigente();

        if (! $version) {
            // Si aún no existe una versión autoritativa activa en loc_catalogo_versiones,
            // no devolver filas para prevenir lecturas parciales o no confirmadas.
            return $query->whereRaw('1 = 0');
        }

        return $query->where('import_batch_id', $version->import_batch_id)
            ->where('activo', true);
    }

    /**
     * Scope para consultar los registros correspondientes a una versión o lote específico.
     */
    public function scopeDeVersion(Builder $query, int $batchId): Builder
    {
        return $query->where('import_batch_id', $batchId);
    }
}
