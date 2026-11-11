<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoEstadoArchivo;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoAuditoria;
use App\Models\BiometricoExpediente;
use App\Models\User;
use App\Support\Storage\SafeReadOnlyLocalAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class BiometricDeliveryService
{
    /**
     * Entrega un archivo biométrico institucional en streaming bajo estricta
     * verificación de autenticación, autorización, integridad técnica, pertenencia
     * obligatoria al expediente institucional y auditoría pericial previa.
     *
     * @param  BiometricoArchivo  $archivo
     * @param  User  $actor
     * @param  Request  $request
     * @param  BiometricoExpediente  $expediente
     * @return StreamedResponse
     *
     * @throws AccessDeniedHttpException
     * @throws UnprocessableEntityHttpException
     * @throws NotFoundHttpException
     */
    public function streamArchivo(
        BiometricoArchivo $archivo,
        User $actor,
        Request $request,
        BiometricoExpediente $expediente
    ): StreamedResponse {
        // 1. Comprobar que el usuario exista y esté autenticado
        if (! $actor->exists || $actor->id === null) {
            throw new AccessDeniedHttpException('Usuario no autenticado.');
        }

        // 2. Comprobar la Gate institucional view-biometrics
        if (! Gate::forUser($actor)->allows('view-biometrics')) {
            throw new AccessDeniedHttpException('No cuenta con autorización para visualizar archivos biométricos.');
        }

        // 3. Comprobar que el expediente sea válido, esté persistido y tenga un ámbito institucional definitivo
        if (! $expediente->exists || $expediente->id === null) {
            throw new UnprocessableEntityHttpException('El expediente biométrico proporcionado no es válido o no ha sido persistido.');
        }

        if (! $expediente->ambito instanceof BiometricoAmbito || ! $expediente->ambito->isResuelto()) {
            throw new UnprocessableEntityHttpException('El expediente biométrico no cuenta con un ámbito institucional definitivo resuelto.');
        }

        // 4. Comprobar concordancia estricta de CUIP y Ámbito entre archivo y expediente
        $cuipExpediente = strtoupper(trim((string) $expediente->cuip));
        $cuipArchivo = strtoupper(trim((string) ($archivo->cuip_normalizado ?: $archivo->cuip_detectado)));

        if ($cuipExpediente === '' || $cuipExpediente !== $cuipArchivo) {
            throw new UnprocessableEntityHttpException(
                "Discrepancia de CUIP: El CUIP del archivo ('{$cuipArchivo}') no coincide con el CUIP del expediente ('{$cuipExpediente}')."
            );
        }

        if ($expediente->ambito !== $archivo->ambito) {
            throw new UnprocessableEntityHttpException(
                "Discrepancia de ámbito: El archivo pertenece al ámbito '{$archivo->ambito?->value}' y el expediente al ámbito '{$expediente->ambito->value}'."
            );
        }

        // 5. Comprobar categoría válida y pertenencia exacta del archivo a la columna del expediente
        if (! $archivo->categoria instanceof BiometricoCategoria) {
            throw new UnprocessableEntityHttpException('El archivo biométrico no posee una categoría institucional válida.');
        }

        $columnaEsperada = $archivo->categoria->columnaExpediente();
        $idEnExpediente = $expediente->getAttribute($columnaEsperada);

        if ($idEnExpediente === null) {
            throw new UnprocessableEntityHttpException(
                "El expediente biométrico no tiene ningún archivo vinculado en la posición '{$archivo->categoria->value}' ({$columnaEsperada})."
            );
        }

        if (! is_numeric($idEnExpediente) || ! is_numeric($archivo->id) || (string) $idEnExpediente !== (string) $archivo->id) {
            throw new UnprocessableEntityHttpException(
                "El archivo biométrico solicitado (ID {$archivo->id}) no corresponde al archivo registrado en la posición '{$archivo->categoria->value}' del expediente (ID {$idEnExpediente})."
            );
        }

        // 6. Comprobar que el archivo esté vigente
        if (! $archivo->es_vigente) {
            throw new UnprocessableEntityHttpException('El archivo biométrico no se encuentra vigente.');
        }

        // 7. Comprobar que su estado técnico sea válido
        if ($archivo->estado_archivo !== BiometricoEstadoArchivo::VALIDO) {
            throw new UnprocessableEntityHttpException('El archivo biométrico no posee un estado técnico válido.');
        }

        // 8. Comprobar existencia en disco de ingesta o en disco histórico de solo lectura
        if (config('filesystems.disks.biometricos_ingesta') && Storage::disk('biometricos_ingesta')->fileExists($archivo->ruta_relativa)) {
            $disk = Storage::disk('biometricos_ingesta');
        } else {
            $disk = Storage::disk('biometricos');
            $adapter = $disk->getAdapter();
            if ($adapter instanceof SafeReadOnlyLocalAdapter) {
                $adapter->assertSafePath($archivo->ruta_relativa);
            }

            // 9. Comprobar que el archivo exista físicamente en el repositorio
            if (! $disk->fileExists($archivo->ruta_relativa)) {
                throw new NotFoundHttpException('El archivo biométrico no existe físicamente en el repositorio.');
            }
        }

        // 10. Registrar la auditoría pericial con snapshots inmutables ANTES de iniciar el streaming
        BiometricoAuditoria::registrarAcceso(
            evento: 'streaming_biometrico',
            actor: $actor,
            expediente: $expediente,
            archivo: $archivo,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            detalles: [
                'mime_type' => $archivo->mime_type,
                'tamano_bytes' => $archivo->tamano_bytes,
                'categoria' => $archivo->categoria->value,
                'columna_posicion' => $columnaEsperada,
                'sha256' => $archivo->sha256,
                'expediente_id' => $expediente->id,
            ]
        );

        // 11. Determinar el MIME type mediante información técnica confiable
        $mimeType = $archivo->mime_type;
        if (empty($mimeType) || $mimeType === 'application/octet-stream') {
            try {
                $detected = $disk->mimeType($archivo->ruta_relativa);
                $mimeType = $detected ?: 'image/jpeg';
            } catch (\Throwable) {
                $mimeType = 'image/jpeg';
            }
        }

        // 12. Construir cabeceras HTTP de máxima protección anti-caché
        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) ($archivo->tamano_bytes > 0 ? $archivo->tamano_bytes : $disk->fileSize($archivo->ruta_relativa)),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="biometrico.dat"',
        ];

        // 13. Entregar el contenido mediante streaming binario con cierre garantizado de recursos
        return response()->stream(function () use ($disk, $archivo): void {
            $stream = $disk->readStream($archivo->ruta_relativa);
            try {
                if (is_resource($stream)) {
                    fpassthru($stream);
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, $headers);
    }
}

