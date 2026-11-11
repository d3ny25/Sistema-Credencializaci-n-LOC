<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class BiometricoAuditoria extends Model
{
    protected $table = 'biometrico_auditorias';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_user_id_historico',
        'actor_email_historico',
        'solicitud_id',
        'solicitud_elemento_id',
        'biometrico_archivo_id',
        'biometrico_expediente_id',
        'cuip_snapshot',
        'ambito_snapshot',
        'tipo_biometrico_snapshot',
        'ruta_relativa_snapshot',
        'sha256_snapshot',
        'evento',
        'ip_address',
        'user_agent',
        'detalles',
    ];

    protected $casts = [
        'actor_user_id_historico' => 'integer',
        'detalles' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCredencial::class, 'solicitud_id');
    }

    public function solicitudElemento(): BelongsTo
    {
        return $this->belongsTo(SolicitudElemento::class, 'solicitud_elemento_id');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(BiometricoArchivo::class, 'biometrico_archivo_id');
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(BiometricoExpediente::class, 'biometrico_expediente_id');
    }

    public function scopeByEvento(Builder $query, string $evento): Builder
    {
        return $query->where('evento', $evento);
    }

    /**
     * Registra un evento de acceso biométrico sellando automáticamente los snapshots históricos.
     */
    public static function registrarAcceso(
        string $evento,
        ?User $actor = null,
        ?BiometricoExpediente $expediente = null,
        ?BiometricoArchivo $archivo = null,
        ?SolicitudElemento $elemento = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?array $detalles = null
    ): self {
        $cuip = $expediente?->cuip ?? $archivo?->cuip_normalizado ?? $elemento?->cuip;
        $ambito = $expediente?->ambito?->value ?? $archivo?->ambito?->value ?? $elemento?->ambito_biometrico?->value;
        $categoria = $archivo?->categoria?->value;
        $ruta = $archivo?->ruta_relativa;
        $sha256 = $archivo?->sha256;

        return self::create([
            'user_id' => $actor?->id,
            'actor_user_id_historico' => $actor?->id,
            'actor_email_historico' => $actor?->email,
            'solicitud_id' => $elemento?->solicitud_id,
            'solicitud_elemento_id' => $elemento?->id,
            'biometrico_archivo_id' => $archivo?->id,
            'biometrico_expediente_id' => $expediente?->id,
            'cuip_snapshot' => $cuip ? strtoupper(trim((string) $cuip)) : null,
            'ambito_snapshot' => $ambito,
            'tipo_biometrico_snapshot' => $categoria,
            'ruta_relativa_snapshot' => $ruta,
            'sha256_snapshot' => $sha256,
            'evento' => $evento,
            'ip_address' => $ipAddress ?? (function_exists('request') ? (request()?->ip() ?? '127.0.0.1') : '127.0.0.1'),
            'user_agent' => $userAgent ?? (function_exists('request') ? request()?->userAgent() : null),
            'detalles' => $detalles,
        ]);
    }

    /**
     * Candados de inmutabilidad en la capa de modelo para garantizar una bitácora pericial de solo inserción.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException("Los registros de auditoría biométrica son legalmente inmutables y no pueden modificarse.");
        });

        static::deleting(function (): void {
            throw new RuntimeException("Prohibido eliminar registros de auditoría biométrica; la bitácora es de estricto solo inserción.");
        });
    }
}
