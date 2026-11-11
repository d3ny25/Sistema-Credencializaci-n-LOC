<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocImportStagingRow extends Model
{
    protected $fillable = [
        'import_batch_id', 'numero_fila', 'cuip', 'datos', 'errores', 'accion',
    ];

    protected function casts(): array
    {
        return [
            'datos' => 'array',
            'errores' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LocImportBatch::class, 'import_batch_id');
    }
}
