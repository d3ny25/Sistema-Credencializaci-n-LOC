<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoEstadoArchivo;
use App\Enums\BiometricoTipoIdentificacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricoArchivo extends Model
{
    protected $table = 'biometrico_archivos';

    protected $fillable = [
        'ambito',
        'categoria',
        'ruta_relativa',
        'nombre_archivo',
        'extension',
        'tamano_bytes',
        'formato_real',
        'mime_type',
        'sha256',
        'cuip_detectado',
        'cuip_normalizado',
        'tipo_identificacion',
        'estado_archivo',
        'es_vigente',
        'version_secuencia',
        'observaciones',
        'fecha_archivo_mtime',
    ];

    protected $casts = [
        'ambito' => BiometricoAmbito::class,
        'categoria' => BiometricoCategoria::class,
        'tipo_identificacion' => BiometricoTipoIdentificacion::class,
        'estado_archivo' => BiometricoEstadoArchivo::class,
        'es_vigente' => 'boolean',
        'tamano_bytes' => 'integer',
        'version_secuencia' => 'integer',
        'fecha_archivo_mtime' => 'datetime',
    ];

    /**
     * Relación con los expedientes donde este archivo actúa como fotografía facial.
     */
    public function expedientesFoto(): HasMany
    {
        return $this->hasMany(BiometricoExpediente::class, 'foto_archivo_id');
    }

    /**
     * Relación con los expedientes donde este archivo actúa como firma digitalizada.
     */
    public function expedientesFirma(): HasMany
    {
        return $this->hasMany(BiometricoExpediente::class, 'firma_archivo_id');
    }

    /**
     * Relación con los expedientes donde este archivo actúa como huella de pulgar derecho.
     */
    public function expedientesPulgarDerecho(): HasMany
    {
        return $this->hasMany(BiometricoExpediente::class, 'pulgar_derecho_archivo_id');
    }

    /**
     * Relación con los expedientes donde este archivo actúa como huella de pulgar izquierdo.
     */
    public function expedientesPulgarIzquierdo(): HasMany
    {
        return $this->hasMany(BiometricoExpediente::class, 'pulgar_izquierdo_archivo_id');
    }

    /**
     * Bitácora pericial de consultas y streaming de este archivo.
     */
    public function auditorias(): HasMany
    {
        return $this->hasMany(BiometricoAuditoria::class, 'biometrico_archivo_id');
    }

    /**
     * Scope para filtrar únicamente archivos vigentes (no sustituidos ni dados de baja).
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('es_vigente', true);
    }

    /**
     * Scope para filtrar archivos con estado técnico válido.
     */
    public function scopeValidos(Builder $query): Builder
    {
        return $query->where('estado_archivo', BiometricoEstadoArchivo::VALIDO);
    }

    /**
     * Scope para filtrar por CUIP normalizado.
     */
    public function scopeByCuip(Builder $query, string $cuip): Builder
    {
        return $query->where('cuip_normalizado', strtoupper(trim($cuip)));
    }

    /**
     * Scope para filtrar por ámbito institucional.
     */
    public function scopeByAmbito(Builder $query, BiometricoAmbito|string $ambito): Builder
    {
        $value = $ambito instanceof BiometricoAmbito ? $ambito->value : $ambito;

        return $query->where('ambito', $value);
    }

    /**
     * Scope para filtrar por categoría biométrica.
     */
    public function scopeByCategoria(Builder $query, BiometricoCategoria|string $categoria): Builder
    {
        $value = $categoria instanceof BiometricoCategoria ? $categoria->value : $categoria;

        return $query->where('categoria', $value);
    }
}

