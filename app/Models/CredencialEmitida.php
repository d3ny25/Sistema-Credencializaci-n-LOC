<?php

namespace App\Models;

use App\Enums\EstadoEmision;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CredencialEmitida extends Model
{
    protected $table = 'credenciales_emitidas';

    protected $fillable = [
        'solicitud_id',
        'solicitud_elemento_id',
        'folio',
        'numero_tarjeta',
        'estado_biometria',
        'estado_emision',
        'estado_entrega',
        'fecha_emision',
        'fecha_entrega',
        'observaciones',
        'emitida_por_user_id',
        'folio_asignado_por_user_id',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_entrega' => 'date',
    ];

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCredencial::class, 'solicitud_id');
    }

    public function solicitudElemento(): BelongsTo
    {
        return $this->belongsTo(SolicitudElemento::class, 'solicitud_elemento_id');
    }

    public function emitidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitida_por_user_id');
    }

    /**
     * Usuario que asignó el folio al validar la biometría 4/4 (la fecha de asignación es created_at).
     * La emisión física queda en emitida_por_user_id y en credencial_impresiones.
     */
    public function folioAsignadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'folio_asignado_por_user_id');
    }

    public function impresiones(): HasMany
    {
        return $this->hasMany(CredencialImpresion::class, 'credencial_emitida_id')->orderBy('numero_intento');
    }

    public function preparaciones(): HasMany
    {
        return $this->hasMany(CredencialPreparacion::class, 'credencial_emitida_id');
    }

    public function estaEmitida(): bool
    {
        return $this->estado_emision === EstadoEmision::EMITIDA->value;
    }
}
