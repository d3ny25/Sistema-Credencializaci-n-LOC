<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocPersonaAntecedente extends Model
{
    protected $table = 'loc_persona_antecedentes';

    protected $fillable = [
        'persona_id',
        'campo',
        'valor_anterior',
        'valor_nuevo',
        'fecha_detectada',
        'fecha_vigencia',
        'motivo',
        'import_batch_id',
    ];

    protected $casts = [
        'fecha_detectada' => 'date',
        'fecha_vigencia' => 'date',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(LocPersona::class, 'persona_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(LocImportBatch::class, 'import_batch_id');
    }
}
