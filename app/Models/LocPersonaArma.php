<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocPersonaArma extends Model
{
    protected $table = 'loc_persona_armas';

    protected $fillable = [
        'persona_id',
        'arma_id',
        'fecha_asignacion',
        'fecha_fin',
        'estatus_asignacion',
        'observaciones',
        'import_batch_id',
    ];

    protected $casts = [
        'fecha_asignacion' => 'date',
        'fecha_fin' => 'date',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(LocPersona::class, 'persona_id');
    }

    public function arma(): BelongsTo
    {
        return $this->belongsTo(LocArma::class, 'arma_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(LocImportBatch::class, 'import_batch_id');
    }
}
