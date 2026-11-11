<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocCatalogoVersion extends Model
{
    protected $table = 'loc_catalogo_versiones';

    protected $fillable = [
        'import_batch_id',
        'version_numero',
        'es_vigente',
        'total_registros',
        'archivo_nombre',
        'archivo_sha256',
        'usuario_id',
        'fecha_activacion',
        'notas',
    ];

    protected $casts = [
        'es_vigente' => 'boolean',
        'fecha_activacion' => 'datetime',
        'version_numero' => 'integer',
        'total_registros' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LocImportBatch::class, 'import_batch_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function registros(): HasMany
    {
        return $this->hasMany(LocCatalogoOrganizacional::class, 'import_batch_id', 'import_batch_id');
    }
}

