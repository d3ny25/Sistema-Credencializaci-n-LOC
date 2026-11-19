<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * Preparación de impresión: dueña del snapshot documental y biométrico de una emisión o reimpresión.
 *
 * Estados: preparada → confirmada | cancelada | expirada (los tres son finales).
 * Una preparación cancelada o expirada conserva su snapshot y puede recibir una confirmación
 * extemporánea sin cambiar de estado. Solo pueden cambiar las columnas de ciclo de vida.
 */
class CredencialPreparacion extends Model
{
    public const PREPARADA = 'preparada';
    public const CONFIRMADA = 'confirmada';
    public const CANCELADA = 'cancelada';
    public const EXPIRADA = 'expirada';

    public const TIPO_EMISION = 'emision';
    public const TIPO_REIMPRESION_HISTORICA = 'reimpresion_historica';

    /** Columnas de ciclo de vida: las únicas que pueden cambiar después de crearse. */
    private const MUTABLES = ['estado', 'cancelada_por_user_id', 'motivo_cancelacion', 'cancelada_at', 'expirada_at', 'primera_vista_at', 'updated_at'];

    protected $table = 'credencial_preparaciones';

    protected $guarded = ['id', 'clave_activa'];

    protected $hidden = ['datos_variables_cifrados'];

    protected $casts = [
        'campos_por_defecto' => 'array',
        'render_huella' => 'array',
        'manifiesto_version' => 'integer',
        'plantilla_version' => 'integer',
        'preparada_at' => 'datetime',
        'vista_expira_en' => 'datetime',
        'expira_en' => 'datetime',
        'primera_vista_at' => 'datetime',
        'cancelada_at' => 'datetime',
        'expirada_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $preparacion): void {
            $cambios = array_diff(array_keys($preparacion->getDirty()), self::MUTABLES);
            if ($cambios !== []) {
                throw new RuntimeException('El snapshot de la preparación es inmutable.');
            }
            if ($preparacion->isDirty('estado') && $preparacion->getOriginal('estado') !== self::PREPARADA) {
                throw new RuntimeException('Una preparación en estado final no cambia de estado.');
            }
            if ($preparacion->isDirty('primera_vista_at') && $preparacion->getOriginal('primera_vista_at') !== null) {
                throw new RuntimeException('La primera visualización se registra una sola vez.');
            }
        });
        static::deleting(static function (): void {
            throw new RuntimeException('Las preparaciones de impresión no se eliminan.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function estaPreparada(): bool
    {
        return $this->estado === self::PREPARADA;
    }

    public function vistaVigente(): bool
    {
        return $this->estaPreparada() && now()->lt($this->vista_expira_en) && now()->lt($this->expira_en);
    }

    public function credencial(): BelongsTo
    {
        return $this->belongsTo(CredencialEmitida::class, 'credencial_emitida_id');
    }

    public function elemento(): BelongsTo
    {
        return $this->belongsTo(SolicitudElemento::class, 'solicitud_elemento_id');
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(BiometricoExpediente::class, 'biometrico_expediente_id');
    }

    public function plantillaVersion(): BelongsTo
    {
        return $this->belongsTo(CredentialTemplateVersion::class, 'credential_template_version_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'preparacion_origen_id');
    }

    public function reproducciones(): HasMany
    {
        return $this->hasMany(self::class, 'preparacion_origen_id');
    }

    public function biometricos(): HasMany
    {
        return $this->hasMany(CredencialPreparacionBiometrico::class, 'credencial_preparacion_id');
    }

    public function impresion(): HasOne
    {
        return $this->hasOne(CredencialImpresion::class, 'credencial_preparacion_id');
    }

    public function preparadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preparada_por_user_id');
    }
}
