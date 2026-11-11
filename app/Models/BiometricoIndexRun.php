<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoIndexRunEstado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BiometricoIndexRun extends Model
{
    protected $table = 'biometrico_index_runs';

    protected $attributes = [
        'ambito' => 'TODOS',
        'estado' => BiometricoIndexRunEstado::PENDIENTE,
        'es_dry_run' => false,
        'total_archivos_detectados' => 0,
        'total_archivos_procesados' => 0,
        'total_archivos_nuevos' => 0,
        'total_archivos_sin_cambios' => 0,
        'total_archivos_modificados' => 0,
        'total_archivos_desaparecidos' => 0,
        'total_archivos_invalidos' => 0,
        'total_archivos_duplicados' => 0,
        'total_incidencias' => 0,
        'duracion_segundos' => 0,
        'memoria_pico_mb' => 0.00,
    ];

    protected $fillable = [
        'uuid',
        'ambito',
        'estado',
        'es_dry_run',
        'iniciado_at',
        'finalizado_at',
        'iniciado_por_user_id',
        'ultimo_checkpoint_id',
        'ultimo_directorio_procesado',
        'ultima_ruta_procesada',
        'total_archivos_detectados',
        'total_archivos_procesados',
        'total_archivos_nuevos',
        'total_archivos_sin_cambios',
        'total_archivos_modificados',
        'total_archivos_desaparecidos',
        'total_archivos_invalidos',
        'total_archivos_duplicados',
        'total_incidencias',
        'duracion_segundos',
        'memoria_pico_mb',
        'error_mensaje',
        'resumen_interrupcion',
        'metadata',
    ];

    protected $casts = [
        'estado' => BiometricoIndexRunEstado::class,
        'es_dry_run' => 'boolean',
        'iniciado_at' => 'datetime',
        'finalizado_at' => 'datetime',
        'total_archivos_detectados' => 'integer',
        'total_archivos_procesados' => 'integer',
        'total_archivos_nuevos' => 'integer',
        'total_archivos_sin_cambios' => 'integer',
        'total_archivos_modificados' => 'integer',
        'total_archivos_desaparecidos' => 'integer',
        'total_archivos_invalidos' => 'integer',
        'total_archivos_duplicados' => 'integer',
        'total_incidencias' => 'integer',
        'duracion_segundos' => 'integer',
        'memoria_pico_mb' => 'float',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (BiometricoIndexRun $run): void {
            if (empty($run->uuid)) {
                $run->uuid = (string) Str::uuid();
            }
        });
    }

    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por_user_id');
    }

    public function incidencias(): HasMany
    {
        return $this->hasMany(BiometricoIncidencia::class, 'biometrico_index_run_id');
    }

    public function scopeFinalizadas(Builder $query): Builder
    {
        return $query->whereIn('estado', [
            BiometricoIndexRunEstado::COMPLETADA->value,
            BiometricoIndexRunEstado::COMPLETADA_CON_INCIDENCIAS->value,
            BiometricoIndexRunEstado::FALLIDA->value,
            BiometricoIndexRunEstado::INTERRUMPIDA->value,
            BiometricoIndexRunEstado::CANCELADA->value,
        ]);
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('estado', BiometricoIndexRunEstado::EN_EJECUCION->value);
    }

    public function scopeByAmbito(Builder $query, string|BiometricoAmbito $ambito): Builder
    {
        $val = $ambito instanceof BiometricoAmbito ? $ambito->value : $ambito;

        return $query->where('ambito', $val);
    }

    /**
     * Inicia formalmente el registro de ejecución marcando timestamp de inicio.
     */
    public function marcarInicio(): void
    {
        $this->estado = BiometricoIndexRunEstado::EN_EJECUCION;
        $this->iniciado_at = now();
        if (! $this->es_dry_run) {
            $this->save();
        }
    }

    /**
     * Finaliza la ejecución calculando la duración y determinando si hubo incidencias.
     */
    public function marcarFinalizada(): void
    {
        $this->finalizado_at = now();
        if ($this->iniciado_at) {
            $this->duracion_segundos = abs((int) $this->finalizado_at->diffInSeconds($this->iniciado_at));
        }

        $this->estado = $this->total_incidencias > 0
            ? BiometricoIndexRunEstado::COMPLETADA_CON_INCIDENCIAS
            : BiometricoIndexRunEstado::COMPLETADA;

        if (! $this->es_dry_run) {
            $this->save();
        }
    }

    /**
     * Registra una cancelación administrativa controlada guardando el motivo.
     */
    public function marcarCancelada(string $motivo): void
    {
        $this->finalizado_at = now();
        if ($this->iniciado_at) {
            $this->duracion_segundos = abs((int) $this->finalizado_at->diffInSeconds($this->iniciado_at));
        }

        $this->estado = BiometricoIndexRunEstado::CANCELADA;
        $this->resumen_interrupcion = $motivo;
        if (! $this->es_dry_run) {
            $this->save();
        }
    }

    /**
     * Registra una interrupción controlada guardando el último punto de procesamiento.
     */
    public function marcarInterrumpida(string $resumen, ?string $ultimoPunto = null): void
    {
        $this->finalizado_at = now();
        if ($this->iniciado_at) {
            $this->duracion_segundos = abs((int) $this->finalizado_at->diffInSeconds($this->iniciado_at));
        }

        $this->estado = BiometricoIndexRunEstado::INTERRUMPIDA;
        $this->resumen_interrupcion = $resumen;
        if ($ultimoPunto !== null) {
            $this->ultima_ruta_procesada = $ultimoPunto;
        }

        if (! $this->es_dry_run) {
            $this->save();
        }
    }

    /**
     * Registra un fallo crítico no recuperable.
     */
    public function marcarFallida(string $error): void
    {
        $this->finalizado_at = now();
        if ($this->iniciado_at) {
            $this->duracion_segundos = abs((int) $this->finalizado_at->diffInSeconds($this->iniciado_at));
        }

        $this->estado = BiometricoIndexRunEstado::FALLIDA;
        $this->error_mensaje = $error;
        if (! $this->es_dry_run) {
            $this->save();
        }
    }
}
