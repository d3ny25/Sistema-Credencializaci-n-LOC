<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoEstadoArchivo;
use App\Enums\BiometricoEstadoRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricoExpediente extends Model
{
    protected $table = 'biometrico_expedientes';

    protected $fillable = [
        'cuip',
        'ambito',
        'persona_id',
        'foto_archivo_id',
        'firma_archivo_id',
        'pulgar_derecho_archivo_id',
        'pulgar_izquierdo_archivo_id',
        'total_biometricos',
        'es_completo',
        'faltantes',
        'estado_revision',
        'motivo_rechazo_biometrico',
        'revisado_por_user_id',
        'fecha_revision',
        'tiene_anomalias',
        'tiene_duplicados',
    ];

    protected $casts = [
        'ambito' => BiometricoAmbito::class,
        'estado_revision' => BiometricoEstadoRevision::class,
        'es_completo' => 'boolean',
        'tiene_anomalias' => 'boolean',
        'tiene_duplicados' => 'boolean',
        'total_biometricos' => 'integer',
        'faltantes' => 'array',
        'fecha_revision' => 'datetime',
    ];

    /**
     * Persona oficial del padrón estatal vinculada (cuando exista).
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(LocPersona::class, 'persona_id');
    }

    /**
     * Archivo biométrico de fotografía facial.
     */
    public function foto(): BelongsTo
    {
        return $this->belongsTo(BiometricoArchivo::class, 'foto_archivo_id');
    }

    /**
     * Archivo biométrico de firma digitalizada.
     */
    public function firma(): BelongsTo
    {
        return $this->belongsTo(BiometricoArchivo::class, 'firma_archivo_id');
    }

    /**
     * Archivo biométrico de huella dactilar de pulgar derecho.
     */
    public function pulgarDerecho(): BelongsTo
    {
        return $this->belongsTo(BiometricoArchivo::class, 'pulgar_derecho_archivo_id');
    }

    /**
     * Archivo biométrico de huella dactilar de pulgar izquierdo.
     */
    public function pulgarIzquierdo(): BelongsTo
    {
        return $this->belongsTo(BiometricoArchivo::class, 'pulgar_izquierdo_archivo_id');
    }

    /**
     * Usuario supervisor que realizó la evaluación técnica o de calidad.
     */
    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por_user_id');
    }

    /**
     * Elementos de solicitudes de credencialización asociados convencionalmente a este expediente.
     */
    public function elementosSolicitud(): HasMany
    {
        return $this->hasMany(SolicitudElemento::class, 'biometrico_expediente_id');
    }

    /**
     * Bitácora pericial de auditorías asociadas a este expediente.
     */
    public function auditorias(): HasMany
    {
        return $this->hasMany(BiometricoAuditoria::class, 'biometrico_expediente_id');
    }

    /**
     * Candidatos a slots biométricos registrados o en conflicto para este expediente (AUD-243-008).
     */
    public function candidatos(): HasMany
    {
        return $this->hasMany(BiometricoSlotCandidato::class, 'biometrico_expediente_id');
    }

    /**
     * Evalúa si un archivo biométrico específico cumple todas las condiciones de integridad técnica.
     *
     * @return array{valido: bool, motivo: string|null}
     */
    public function evaluarArchivoTecnico(?BiometricoArchivo $archivo, BiometricoCategoria $categoriaEsperada): array
    {
        if ($archivo === null) {
            return ['valido' => false, 'motivo' => $categoriaEsperada->value];
        }

        if (! $archivo->es_vigente) {
            return ['valido' => false, 'motivo' => "{$categoriaEsperada->value}_no_vigente"];
        }

        if ($archivo->estado_archivo !== BiometricoEstadoArchivo::VALIDO) {
            $estado = $archivo->estado_archivo instanceof BiometricoEstadoArchivo
                ? $archivo->estado_archivo->value
                : (string) $archivo->estado_archivo;

            return ['valido' => false, 'motivo' => "{$categoriaEsperada->value}_{$estado}"];
        }

        if ($archivo->categoria !== $categoriaEsperada) {
            return ['valido' => false, 'motivo' => "{$categoriaEsperada->value}_categoria_incorrecta"];
        }

        if ($archivo->cuip_normalizado && strtoupper(trim((string) $archivo->cuip_normalizado)) !== strtoupper(trim((string) $this->cuip))) {
            return ['valido' => false, 'motivo' => "{$categoriaEsperada->value}_discrepancia_cuip"];
        }

        if ($archivo->ambito && $archivo->ambito->value !== $this->ambito->value) {
            return ['valido' => false, 'motivo' => "{$categoriaEsperada->value}_discrepancia_ambito"];
        }

        if (empty($archivo->sha256)) {
            return ['valido' => false, 'motivo' => "{$categoriaEsperada->value}_hash_no_verificado"];
        }

        return ['valido' => true, 'motivo' => null];
    }

    /**
     * Evalúa y recalcula en memoria los indicadores de completitud técnica del expediente.
     *
     * Distingue claramente:
     * A. Completitud Estructural (tener 4 referencias no nulas).
     * B. Integridad Técnica (los 4 archivos son válidos, vigentes, íntegros y coincidentes).
     * C. Validación Humana (aprobación de calidad en estado_revision).
     *
     * @param array<string, BiometricoArchivo|null>|null $archivosPreloaded
     */
    public function recalcularCompletitud(?array $archivosPreloaded = null): void
    {
        $faltantes = [];
        $totalValidos = 0;

        $foto = $archivosPreloaded['foto'] ?? ($this->relationLoaded('foto') ? $this->foto : ($this->foto_archivo_id ? BiometricoArchivo::find($this->foto_archivo_id) : null));
        $firma = $archivosPreloaded['firma'] ?? ($this->relationLoaded('firma') ? $this->firma : ($this->firma_archivo_id ? BiometricoArchivo::find($this->firma_archivo_id) : null));
        $pulgarDer = $archivosPreloaded['pulgar_derecho'] ?? ($this->relationLoaded('pulgarDerecho') ? $this->pulgarDerecho : ($this->pulgar_derecho_archivo_id ? BiometricoArchivo::find($this->pulgar_derecho_archivo_id) : null));
        $pulgarIzq = $archivosPreloaded['pulgar_izquierdo'] ?? ($this->relationLoaded('pulgarIzquierdo') ? $this->pulgarIzquierdo : ($this->pulgar_izquierdo_archivo_id ? BiometricoArchivo::find($this->pulgar_izquierdo_archivo_id) : null));

        $evalFoto = $this->evaluarArchivoTecnico($foto, BiometricoCategoria::FOTO);
        if ($evalFoto['valido']) {
            $totalValidos++;
        } else {
            $faltantes[] = $evalFoto['motivo'];
        }

        $evalFirma = $this->evaluarArchivoTecnico($firma, BiometricoCategoria::FIRMA);
        if ($evalFirma['valido']) {
            $totalValidos++;
        } else {
            $faltantes[] = $evalFirma['motivo'];
        }

        $evalPulgarDer = $this->evaluarArchivoTecnico($pulgarDer, BiometricoCategoria::PULGAR_DERECHO);
        if ($evalPulgarDer['valido']) {
            $totalValidos++;
        } else {
            $faltantes[] = $evalPulgarDer['motivo'];
        }

        $evalPulgarIzq = $this->evaluarArchivoTecnico($pulgarIzq, BiometricoCategoria::PULGAR_IZQUIERDO);
        if ($evalPulgarIzq['valido']) {
            $totalValidos++;
        } else {
            $faltantes[] = $evalPulgarIzq['motivo'];
        }

        $this->total_biometricos = $totalValidos;
        $this->es_completo = ($totalValidos === 4);
        $this->faltantes = $faltantes;
    }

    /**
     * Determina si el expediente cuenta estructuralmente con fotografía.
     */
    public function tieneFoto(): bool
    {
        return $this->foto_archivo_id !== null;
    }

    /**
     * Determina si el expediente cuenta estructuralmente con firma.
     */
    public function tieneFirma(): bool
    {
        return $this->firma_archivo_id !== null;
    }

    /**
     * Determina si el expediente cuenta estructuralmente con pulgar derecho.
     */
    public function tienePulgarDerecho(): bool
    {
        return $this->pulgar_derecho_archivo_id !== null;
    }

    /**
     * Determina si el expediente cuenta estructuralmente con pulgar izquierdo.
     */
    public function tienePulgarIzquierdo(): bool
    {
        return $this->pulgar_izquierdo_archivo_id !== null;
    }

    /**
     * Verifica la completitud puramente estructural (4 referencias no nulas).
     */
    public function esCompletoEstructural(): bool
    {
        return $this->tieneFoto()
            && $this->tieneFirma()
            && $this->tienePulgarDerecho()
            && $this->tienePulgarIzquierdo();
    }

    /**
     * Verifica la integridad técnica integral de los cuatro archivos.
     */
    public function esIntegroTecnico(): bool
    {
        return $this->es_completo && $this->total_biometricos === 4 && empty($this->faltantes);
    }

    /**
     * Determina si el expediente cuenta con todos los biométricos requeridos.
     */
    public function tieneBiometricosCompletos(): bool
    {
        return $this->esIntegroTecnico();
    }

    /**
     * Determina si el expediente es formalmente apto para impresión física.
     */
    public function esAptoParaImpresion(): bool
    {
        return $this->esIntegroTecnico()
            && $this->estado_revision === BiometricoEstadoRevision::APROBADO_TECNICO;
    }

    /**
     * Scope para expedientes completos.
     */
    public function scopeCompletos(Builder $query): Builder
    {
        return $query->where('es_completo', true);
    }

    /**
     * Scope para expedientes incompletos.
     */
    public function scopeIncompletos(Builder $query): Builder
    {
        return $query->where('es_completo', false);
    }

    /**
     * Scope para filtrar por CUIP exacto.
     */
    public function scopeByCuip(Builder $query, string $cuip): Builder
    {
        return $query->where('cuip', strtoupper(trim($cuip)));
    }

    /**
     * Scope para filtrar por ámbito.
     */
    public function scopeByAmbito(Builder $query, BiometricoAmbito|string $ambito): Builder
    {
        $value = $ambito instanceof BiometricoAmbito ? $ambito->value : $ambito;

        return $query->where('ambito', $value);
    }
}

