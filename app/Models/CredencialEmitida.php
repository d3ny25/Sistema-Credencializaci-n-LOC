<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
