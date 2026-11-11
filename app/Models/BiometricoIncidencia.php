<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoIncidenciaSeveridad;
use App\Enums\BiometricoIncidenciaTipo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricoIncidencia extends Model
{
    protected $table = 'biometrico_incidencias';

    protected $fillable = [
        'biometrico_index_run_id',
        'tipo',
        'severidad',
        'codigo',
        'ambito',
        'categoria',
        'ruta_relativa',
        'nombre_archivo',
        'cuip_detectado',
        'descripcion',
        'detalles',
        'fecha_deteccion',
    ];

    protected $casts = [
        'tipo' => BiometricoIncidenciaTipo::class,
        'severidad' => BiometricoIncidenciaSeveridad::class,
        'ambito' => BiometricoAmbito::class,
        'categoria' => BiometricoCategoria::class,
        'detalles' => 'array',
        'fecha_deteccion' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (BiometricoIncidencia $incidencia): void {
            if (empty($incidencia->fecha_deteccion)) {
                $incidencia->fecha_deteccion = now();
            }
        });

        static::created(function (BiometricoIncidencia $incidencia): void {
            // Incrementar de forma atómica el contador de incidencias en la ejecución asociada
            $incidencia->run()->increment('total_incidencias');
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BiometricoIndexRun::class, 'biometrico_index_run_id');
    }

    public function scopeByTipo(Builder $query, string|BiometricoIncidenciaTipo $tipo): Builder
    {
        $val = $tipo instanceof BiometricoIncidenciaTipo ? $tipo->value : $tipo;

        return $query->where('tipo', $val);
    }

    public function scopeBySeveridad(Builder $query, string|BiometricoIncidenciaSeveridad $severidad): Builder
    {
        $val = $severidad instanceof BiometricoIncidenciaSeveridad ? $severidad->value : $severidad;

        return $query->where('severidad', $val);
    }

    public function scopeCriticas(Builder $query): Builder
    {
        return $query->where('severidad', BiometricoIncidenciaSeveridad::CRITICA->value);
    }

    public function scopeByCuip(Builder $query, string $cuip): Builder
    {
        return $query->where('cuip_detectado', strtoupper(trim($cuip)));
    }
}

