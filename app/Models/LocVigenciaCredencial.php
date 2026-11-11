<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocVigenciaCredencial extends Model
{
    protected $table = 'loc_vigencias_credenciales';

    protected $fillable = [
        'persona_id',
        'arma_id',
        'arma_corta_id',
        'arma_larga_id',
        'import_batch_id',
        'cuip',
        'matricula',
        'matricula_corta',
        'matricula_larga',
        'tipo_credencial',
        'periodo_credencial',
        'numero_oficio',
        'numero_oficio_baja',
        'numero_credencial',
        'estatus_credencial',
        'fecha_solicitud',
        'fecha_elaboracion',
        'fecha_vigencia',
        'fecha_baja',
        'motivo_situacion_especial',
        'cuerpo_seguridad',
        'agrupamiento',
        'region',
        'municipio',
        'observaciones',
        'datos_origen',
        'fila_hash',
        'es_referencia_loc',
    ];

    protected $casts = [
        'fecha_solicitud' => 'date',
        'fecha_elaboracion' => 'date',
        'fecha_vigencia' => 'date',
        'fecha_baja' => 'date',
        'datos_origen' => 'array',
        'es_referencia_loc' => 'boolean',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(LocPersona::class, 'persona_id');
    }

    public function arma(): BelongsTo
    {
        return $this->belongsTo(LocArma::class, 'arma_id');
    }

    public function armaCorta(): BelongsTo
    {
        return $this->belongsTo(LocArma::class, 'arma_corta_id');
    }

    public function armaLarga(): BelongsTo
    {
        return $this->belongsTo(LocArma::class, 'arma_larga_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(LocImportBatch::class, 'import_batch_id');
    }
}
