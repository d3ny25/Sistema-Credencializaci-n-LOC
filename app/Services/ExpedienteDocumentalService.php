<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CategoriaDocumentoEntrega;
use App\Models\CredencialDocumento;
use App\Models\SolicitudCredencial;
use App\Models\User;
use finfo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/**
 * Expediente documental (Iteración 1.4): incorpora archivos al disco privado documentos_entrega y sirve su
 * contenido solo tras verificar el expediente. No modifica solicitudes, emisiones, impresiones ni entregas.
 * Sin antivirus integrado: la defensa es la lista de formatos, la verificación del contenido y servir sin ejecutar.
 */
class ExpedienteDocumentalService
{
    public const DISCO = 'documentos_entrega';

    /** Extensión => [MIME canónico, MIME detectados aceptados (finfo puede ver un OOXML como zip)]. */
    public const FORMATOS = [
        'pdf' => ['application/pdf', ['application/pdf']],
        'jpg' => ['image/jpeg', ['image/jpeg']],
        'jpeg' => ['image/jpeg', ['image/jpeg']],
        'png' => ['image/png', ['image/png']],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']],
    ];

    /** Parte principal obligatoria de cada paquete OOXML. */
    private const OOXML_PRINCIPAL = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml'];

    private const RUTA_VALIDA = '#^(\d+)/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(pdf|jpe?g|png|docx|xlsx)$#';

    public function incorporar(SolicitudCredencial $tarjeta, UploadedFile $archivo, CategoriaDocumentoEntrega $categoria, ?string $descripcion, User $actor): CredencialDocumento
    {
        [$extension, $mime] = $this->inspeccionar($archivo);
        $sha256 = hash_file('sha256', $archivo->getRealPath());
        $previo = CredencialDocumento::query()->where('solicitud_id', $tarjeta->id)->where('sha256', $sha256)->first();
        if ($previo !== null) {
            throw ValidationException::withMessages(['archivo' => "Este archivo ya forma parte del expediente ({$previo->nombre_original}, {$previo->categoria->label()})."]);
        }

        $uuid = (string) Str::uuid();
        $ruta = "{$tarjeta->id}/{$uuid}.{$extension}";
        $disco = Storage::disk(self::DISCO);
        try {
            $disco->putFileAs((string) $tarjeta->id, $archivo, "{$uuid}.{$extension}");
        } catch (Throwable $e) {
            Log::error('expediente_documental.almacenamiento_fallido', ['solicitud_id' => $tarjeta->id, 'error' => $e::class]);
            throw ValidationException::withMessages(['archivo' => 'No fue posible guardar el archivo. No se registró ningún documento; intente de nuevo.']);
        }

        try {
            return DB::transaction(fn (): CredencialDocumento => CredencialDocumento::create([
                'uuid' => $uuid,
                'solicitud_id' => $tarjeta->id,
                'categoria' => $categoria,
                'descripcion' => $descripcion,
                'nombre_original' => $this->nombreSeguro($archivo->getClientOriginalName(), $extension),
                'disco' => self::DISCO,
                'ruta' => $ruta,
                'extension' => $extension,
                'mime' => $mime,
                'tamano_bytes' => $archivo->getSize(),
                'sha256' => $sha256,
                'subido_por_user_id' => $actor->id,
                'subido_at' => now(),
            ]));
        } catch (Throwable $e) {
            // Sin registro no hay documento: se retira solo el archivo recién guardado (nunca uno anterior).
            $disco->delete($ruta);
            Log::error('expediente_documental.registro_fallido', ['solicitud_id' => $tarjeta->id, 'error' => $e::class]);
            throw ValidationException::withMessages(['archivo' => $e instanceof UniqueConstraintViolationException
                ? 'Este archivo ya forma parte del expediente.'
                : 'No fue posible registrar el documento. No se guardó ningún cambio; intente de nuevo.']);
        }
    }

    /**
     * Verifica en el servidor extensión, MIME detectado y estructura del contenido.
     *
     * @return array{0: string, 1: string} [extensión, MIME canónico]
     */
    public function inspeccionar(UploadedFile $archivo): array
    {
        $extension = strtolower($archivo->getClientOriginalExtension());
        $ruta = $archivo->getRealPath();
        $rechazo = static fn (string $motivo) => ValidationException::withMessages(['archivo' => "El archivo no cumple las validaciones: {$motivo}"]);

        if (! isset(self::FORMATOS[$extension]) || $ruta === false) {
            throw $rechazo('formato no permitido. Use PDF, JPG, PNG, DOCX o XLSX.');
        }
        [$canonico, $aceptados] = self::FORMATOS[$extension];
        $detectado = (string) (new finfo(FILEINFO_MIME_TYPE))->file($ruta);
        if (! in_array($detectado, $aceptados, true)) {
            throw $rechazo('el contenido no corresponde a la extensión .'.$extension.'.');
        }

        $valido = match ($extension) {
            'pdf' => file_get_contents($ruta, false, null, 0, 5) === '%PDF-',
            'jpg', 'jpeg' => (@getimagesize($ruta)[2] ?? null) === IMAGETYPE_JPEG,
            'png' => (@getimagesize($ruta)[2] ?? null) === IMAGETYPE_PNG,
            default => $this->ooxmlValido($ruta, $extension),
        };
        if (! $valido) {
            throw $rechazo('el contenido del archivo está dañado o no es un '.strtoupper($extension).' válido.');
        }

        return [$extension, $canonico];
    }

    /** Paquete OOXML con su parte principal y sin macros (vbaProject.bin). */
    private function ooxmlValido(string $ruta, string $extension): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName(self::OOXML_PRINCIPAL[$extension]) === false) {
                return false;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), 'vbaproject.bin')) {
                    return false;
                }
            }

            return true;
        } finally {
            $zip->close();
        }
    }

    /** Nombre original solo como metadato: sin rutas, caracteres de control ni separadores. */
    public function nombreSeguro(string $original, string $extension): string
    {
        $nombre = basename(str_replace('\\', '/', $original));
        $nombre = preg_replace('/\s+/u', ' ', $nombre) ?? '';
        $nombre = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|%]+/u', '', $nombre) ?? '', ' .');

        return $nombre === '' ? "documento.{$extension}" : mb_substr($nombre, 0, 255);
    }

    /** Ruta almacenada coherente con el expediente y existente en el disco privado. */
    public function disponible(CredencialDocumento $documento): bool
    {
        return preg_match(self::RUTA_VALIDA, $documento->ruta, $partes) === 1
            && (int) $partes[1] === (int) $documento->solicitud_id
            && $documento->disco === self::DISCO
            && Storage::disk(self::DISCO)->exists($documento->ruta);
    }

    public function respuesta(CredencialDocumento $documento, bool $enLinea): StreamedResponse
    {
        abort_unless($this->disponible($documento), 404, 'El archivo no está disponible.');
        $enLinea = $enLinea && $documento->previsualizable();

        return Storage::disk(self::DISCO)->response($documento->ruta, $documento->nombre_original, [
            'Content-Type' => $documento->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], $enLinea ? 'inline' : 'attachment');
    }
}
