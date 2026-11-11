<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocPersona extends Model
{
    protected $table = 'loc_personas';

    protected $fillable = [
        'import_batch_id',
        'cuip',
        'loc_id',
        'personal_id',
        'nombre',
        'rfc',
        'curp',
        'categoria',
        'estatus',
        'subdireccion',
        'municipio',
        'region',
        'agrupamiento',
        'clave_seguridad',
        'cuerpo_seguridad',
        'adscripcion',
        'unidad_adscripcion',
        'fecha_ingreso',
        'fecha_baja',
        'activo',
        'observaciones',
        'datos_loc',
    ];

    protected $casts = [
        'fecha_ingreso' => 'date',
        'fecha_baja' => 'date',
        'activo' => 'boolean',
        'datos_loc' => 'array',
    ];

    public function antecedentes(): HasMany
    {
        return $this->hasMany(LocPersonaAntecedente::class, 'persona_id');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(LocPersonaArma::class, 'persona_id');
    }

    public function armas(): BelongsToMany
    {
        return $this->belongsToMany(LocArma::class, 'loc_persona_armas', 'persona_id', 'arma_id')
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
        return $this->hasMany(LocVigenciaCredencial::class, 'persona_id');
    }

    public function solicitudElementos(): HasMany
    {
        return $this->hasMany(SolicitudElemento::class, 'persona_id');
    }

    public function expedientesBiometricos(): HasMany
    {
        return $this->hasMany(BiometricoExpediente::class, 'persona_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(LocImportBatch::class, 'import_batch_id');
    }
}
