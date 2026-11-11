<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricoArchivoVersion extends Model
{
    protected $table = 'biometrico_archivo_versiones';

    protected $fillable = [
        'biometrico_archivo_id',
        'biometrico_index_run_id',
        'version_secuencia',
        'sha256_anterior',
        'sha256_nuevo',
        'tamano_bytes_anterior',
        'tamano_bytes_nuevo',
        'estado_revision',
        'observaciones',
        'fecha_deteccion',
    ];

    protected $casts = [
        'version_secuencia' => 'integer',
        'tamano_bytes_anterior' => 'integer',
        'tamano_bytes_nuevo' => 'integer',
        'fecha_deteccion' => 'datetime',
    ];

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(BiometricoArchivo::class, 'biometrico_archivo_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BiometricoIndexRun::class, 'biometrico_index_run_id');
    }
}

