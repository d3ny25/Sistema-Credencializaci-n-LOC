<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LocImportBatch extends Model
{
    protected $table = 'loc_import_batches';

    protected $fillable = [
        'tipo_fuente',
        'nombre_archivo',
        'fecha_procesamiento',
        'usuario_id',
        'estado',
        'total_leidas',
        'total_aceptadas',
        'total_rechazadas',
        'total_actualizadas',
        'resumen_errores',
        'hoja_procesada',
        'total_columnas',
        'total_nuevas',
        'total_sin_cambios',
        'total_insertadas',
        'archivo_sha256',
        'revision_sha256',
        'fecha_confirmacion',
    ];

    protected $casts = [
        'fecha_procesamiento' => 'datetime',
        'fecha_confirmacion' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function personas(): HasMany
    {
        return $this->hasMany(LocPersona::class, 'import_batch_id');
    }

    public function armas(): HasMany
    {
        return $this->hasMany(LocArma::class, 'import_batch_id');
    }

    public function catalogos(): HasMany
    {
        return $this->hasMany(LocCatalogoOrganizacional::class, 'import_batch_id');
    }

    public function catalogoVersion(): HasOne
    {
        return $this->hasOne(LocCatalogoVersion::class, 'import_batch_id');
    }

    public function vigencias(): HasMany
    {
        return $this->hasMany(LocVigenciaCredencial::class, 'import_batch_id');
    }

    public function solicitudes(): HasMany
    {
        return $this->hasMany(SolicitudCredencial::class, 'import_batch_id');
    }
}
