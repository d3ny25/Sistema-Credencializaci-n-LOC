<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudSecuencia extends Model
{
    protected $table = 'solicitud_secuencias';

    protected $fillable = [
        'clave',
        'ultimo_consecutivo',
        'descripcion',
    ];

    protected $casts = [
        'ultimo_consecutivo' => 'integer',
    ];
}

