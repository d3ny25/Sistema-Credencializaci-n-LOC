<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocArma extends Model
{
    protected $table = 'loc_armas';

    protected $fillable = [
        'import_batch_id',
        'loc_id',
        'matricula',
        'id_armamento',
        'datos_origen',
        'tipo_arma',
        'clase_arma',
        'marca',
        'calibre',
        'modelo',
        'rfa',
        'resguardo',
        'propiedad',
        'agrupamiento',
        'region',
        'municipio',
        'estatus',
        'situacion',
        'observaciones',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'datos_origen' => 'array',
    ];

    public function asignaciones(): HasMany
    {
        return $this->hasMany(LocPersonaArma::class, 'arma_id');
    }

    public function personas(): BelongsToMany
    {
        return $this->belongsToMany(LocPersona::class, 'loc_persona_armas', 'arma_id', 'persona_id')
            ->withPivot([
                'fecha_asignacion',
                'fecha_fin',
                'estatus_asignacion',
                'observaciones',
                'import_batch_id',
            ])
            ->withTimestamps();
    }

    public function vigencias(): HasMany
    {
        return $this->hasMany(LocVigenciaCredencial::class, 'arma_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(LocImportBatch::class, 'import_batch_id');
    }
}
