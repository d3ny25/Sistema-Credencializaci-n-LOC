<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SolicitudCredencial extends Model
{
    protected $table = 'solicitudes';

    protected $fillable = [
        'numero_tarjeta',
        'consecutivo_tarjeta',
        'numero_oficio',
        'fecha_recepcion',
        'estado_general',
        'area_solicitante',
        'catalogo_organizacional_id',
        'area_solicitante_clave',
        'total_elementos',
        'total_aptos',
        'total_descartados',
        'fuentes_evaluacion',
        'fecha_prefiltro',
        'usuario_prefiltro_id',
        'usuario_responsable_id',
        'import_batch_id',
        'observaciones',
    ];

    protected $casts = [
        'consecutivo_tarjeta' => 'integer',
        'catalogo_organizacional_id' => 'integer',
        'fecha_recepcion' => 'date',
        'fecha_prefiltro' => 'datetime',
        'fuentes_evaluacion' => 'array',
        'total_elementos' => 'integer',
        'total_aptos' => 'integer',
        'total_descartados' => 'integer',
    ];

    public function usuarioPrefiltro(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_prefiltro_id');
    }

    public function usuarioResponsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_responsable_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(LocImportBatch::class, 'import_batch_id');
    }

    public function elementos(): HasMany
    {
        return $this->hasMany(SolicitudElemento::class, 'solicitud_id');
    }

    public function credencialesEmitidas(): HasMany
    {
        return $this->hasMany(CredencialEmitida::class, 'solicitud_id');
    }

    public function areaSolicitanteCatalogo(): BelongsTo
    {
        return $this->belongsTo(LocCatalogoOrganizacional::class, 'catalogo_organizacional_id');
    }
}
