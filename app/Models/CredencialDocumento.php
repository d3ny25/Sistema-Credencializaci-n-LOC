<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoriaDocumentoEntrega;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Documento del expediente documental de un número de tarjeta (Iteración 1.4). Solo inserción: no se edita,
 * sustituye ni elimina; una versión corregida es otro documento.
 */
class CredencialDocumento extends Model
{
    protected $table = 'credencial_documentos';

    protected $fillable = [
        'uuid',
        'solicitud_id',
        'categoria',
        'descripcion',
        'nombre_original',
        'disco',
        'ruta',
        'extension',
        'mime',
        'tamano_bytes',
        'sha256',
        'subido_por_user_id',
        'subido_at',
    ];

    protected $casts = [
        'categoria' => CategoriaDocumentoEntrega::class,
        'tamano_bytes' => 'integer',
        'subido_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new RuntimeException('Los documentos del expediente no se modifican; incorpore una versión nueva.');
        });
        static::deleting(static function (): void {
            throw new RuntimeException('Los documentos del expediente no pueden eliminarse.');
        });
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCredencial::class, 'solicitud_id');
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por_user_id');
    }

    /** Solo PDF e imágenes se muestran en el navegador; el resto se descarga. */
    public function previsualizable(): bool
    {
        return in_array($this->mime, ['application/pdf', 'image/jpeg', 'image/png'], true);
    }
}
