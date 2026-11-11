<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BiometricoCategoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricoSlotCandidato extends Model
{
    protected $table = 'biometrico_slot_candidatos';

    protected $fillable = [
        'biometrico_expediente_id',
        'categoria',
        'biometrico_archivo_id',
        'sha256',
        'ruta_relativa',
        'tamano_bytes',
        'biometrico_index_run_id',
        'es_seleccion_oficial',
        'estado_candidato',
        'motivo_descarte',
        'notas_resolucion',
        'fecha_deteccion',
    ];

    protected $casts = [
        'categoria' => BiometricoCategoria::class,
        'tamano_bytes' => 'integer',
        'es_seleccion_oficial' => 'boolean',
        'fecha_deteccion' => 'datetime',
    ];

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(BiometricoExpediente::class, 'biometrico_expediente_id');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(BiometricoArchivo::class, 'biometrico_archivo_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BiometricoIndexRun::class, 'biometrico_index_run_id');
    }
}
