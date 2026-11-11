<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoClasificacionEstado;
use App\Enums\BiometricoEstadoArchivo;
use App\Enums\BiometricoEstadoRevision;
use App\Enums\BiometricoTipoIdentificacion;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoArchivoVersion;
use App\Models\BiometricoAuditoria;
use App\Models\BiometricoExpediente;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class BiometricUpdateService
{
    public function __construct(
        protected ?BiometricResolverService $resolverService = null
    ) {
        $this->resolverService = $resolverService ?? new BiometricResolverService();
    }

    /**
     * Actualiza o registra un archivo biométrico para un elemento con versionado y trazabilidad pericial.
     *
     * @throws RuntimeException Si el elemento ya cuenta con credencial emitida e inmutable.
     * @throws InvalidArgumentException Si el archivo o los datos de identidad no cumplen las invariantes.
     */
    public function actualizarBiometrico(
        SolicitudElemento $elemento,
        BiometricoCategoria $categoria,
        UploadedFile $file,
        string $motivo,
        User $actor
    ): array {
        return DB::transaction(function () use ($elemento, $categoria, $file, $motivo, $actor): array {
            /** @var SolicitudElemento $lockedElemento */
            $lockedElemento = SolicitudElemento::query()
                ->lockForUpdate()
                ->findOrFail($elemento->id);

            // 1. Candado de inmutabilidad legal
            $credencial = $lockedElemento->credencialEmitida;
            if ($credencial && $credencial->estado_emision === 'emitida') {
                throw new RuntimeException('El elemento ya cuenta con credencial emitida; la información biométrica es legalmente inmutable.');
            }

            // 2. Resolver ámbito si no lo está o si su clasificación es provisional
            if (! $lockedElemento->isAmbitoResuelto() || $lockedElemento->estado_clasificacion_ambito === null || ! $lockedElemento->estado_clasificacion_ambito->permiteAsociacionDefinitiva()) {
                $this->resolverService->resolverAmbitoParaElemento($lockedElemento);
                if ($lockedElemento->isAmbitoResuelto() && ($lockedElemento->estado_clasificacion_ambito === null || ! $lockedElemento->estado_clasificacion_ambito->permiteAsociacionDefinitiva())) {
                    $lockedElemento->estado_clasificacion_ambito = BiometricoClasificacionEstado::VALIDADA;
                }
                $lockedElemento->save();
            }

            if (! $lockedElemento->isAmbitoResuelto()) {
                throw new InvalidArgumentException('No es posible actualizar biométricos de un elemento cuyo ámbito institucional no está resuelto.');
            }

            $cuip = strtoupper(trim((string) $lockedElemento->cuip));
            if ($cuip === '') {
                throw new InvalidArgumentException('El elemento no posee un CUIP válido registrado.');
            }

            // 3. Localizar o crear el expediente biométrico
            /** @var BiometricoExpediente|null $expediente */
            $expediente = null;
            if ($lockedElemento->biometrico_expediente_id !== null) {
                $expediente = BiometricoExpediente::query()
                    ->lockForUpdate()
                    ->find($lockedElemento->biometrico_expediente_id);
            }

            if ($expediente === null) {
                $expediente = BiometricoExpediente::query()
                    ->where('cuip', $cuip)
                    ->where('ambito', $lockedElemento->ambito_biometrico->value)
                    ->lockForUpdate()
                    ->first();
            }

            if ($expediente === null) {
                $expediente = BiometricoExpediente::create([
                    'cuip' => $cuip,
                    'ambito' => $lockedElemento->ambito_biometrico->value,
                    'persona_id' => $lockedElemento->persona_id,
                    'total_biometricos' => 0,
                    'es_completo' => false,
                    'faltantes' => ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo'],
                    'estado_revision' => BiometricoEstadoRevision::SIN_EVALUAR,
                ]);

                if ($lockedElemento->canAssociateExpediente()) {
                    $lockedElemento->asociarExpediente($expediente);
                    $lockedElemento->save();
                } else {
                    $lockedElemento->biometrico_expediente_id = $expediente->id;
                    $lockedElemento->save();
                }
            } elseif ($lockedElemento->biometrico_expediente_id !== $expediente->id) {
                if ($lockedElemento->canAssociateExpediente()) {
                    $lockedElemento->asociarExpediente($expediente);
                } else {
                    $lockedElemento->biometrico_expediente_id = $expediente->id;
                }
                $lockedElemento->save();
            }

            // 4. Calcular hash y almacenar en disco de ingesta
            $realPath = $file->getRealPath();
            $sha256 = hash_file('sha256', $realPath);
            $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $timestamp = time();

            $subDir = "INGESTA/{$lockedElemento->ambito_biometrico->value}/" . strtoupper($categoria->value);
            $filename = "{$cuip}_{$categoria->value}_{$timestamp}.{$extension}";
            $relativePath = "{$subDir}/{$filename}";

            $stored = Storage::disk('biometricos_ingesta')->putFileAs($subDir, $file, $filename);
            if (! $stored) {
                throw new RuntimeException('No fue posible almacenar el archivo biométrico en el disco de ingesta.');
            }

            // 5. Determinar slot del expediente y actualizar/versionar
            $columna = $categoria->columnaExpediente();
            $archivoActualId = $expediente->getAttribute($columna);
            /** @var BiometricoArchivo|null $archivoActual */
            $archivoActual = $archivoActualId ? BiometricoArchivo::find($archivoActualId) : null;

            if ($archivoActual) {
                $nuevaSecuencia = $archivoActual->version_secuencia + 1;

                // Registrar versión histórica previa
                BiometricoArchivoVersion::create([
                    'biometrico_archivo_id' => $archivoActual->id,
                    'biometrico_index_run_id' => null,
                    'version_secuencia' => $nuevaSecuencia,
                    'sha256_anterior' => $archivoActual->sha256 ?? str_repeat('0', 64),
                    'sha256_nuevo' => $sha256,
                    'tamano_bytes_anterior' => $archivoActual->tamano_bytes,
                    'tamano_bytes_nuevo' => $file->getSize(),
                    'estado_revision' => 'confirmado',
                    'observaciones' => $motivo,
                    'fecha_deteccion' => now(),
                ]);

                // Actualizar registro del archivo activo
                $archivoActual->update([
                    'ruta_relativa' => $relativePath,
                    'nombre_archivo' => $file->getClientOriginalName(),
                    'extension' => $extension,
                    'tamano_bytes' => $file->getSize(),
                    'formato_real' => $extension,
                    'mime_type' => $file->getMimeType() ?: 'image/jpeg',
                    'sha256' => $sha256,
                    'version_secuencia' => $nuevaSecuencia,
                    'es_vigente' => true,
                    'estado_archivo' => BiometricoEstadoArchivo::VALIDO,
                    'observaciones' => $motivo,
                    'fecha_archivo_mtime' => now(),
                ]);

                $archivoFinal = $archivoActual;
            } else {
                // Crear nuevo registro de archivo biométrico
                $archivoFinal = BiometricoArchivo::create([
                    'ambito' => $lockedElemento->ambito_biometrico->value,
                    'categoria' => $categoria->value,
                    'ruta_relativa' => $relativePath,
                    'nombre_archivo' => $file->getClientOriginalName(),
                    'extension' => $extension,
                    'tamano_bytes' => $file->getSize(),
                    'formato_real' => $extension,
                    'mime_type' => $file->getMimeType() ?: 'image/jpeg',
                    'sha256' => $sha256,
                    'cuip_detectado' => $cuip,
                    'cuip_normalizado' => $cuip,
                    'tipo_identificacion' => BiometricoTipoIdentificacion::ESTANDAR,
                    'estado_archivo' => BiometricoEstadoArchivo::VALIDO,
                    'es_vigente' => true,
                    'version_secuencia' => 1,
                    'observaciones' => $motivo,
                    'fecha_archivo_mtime' => now(),
                ]);

                $expediente->setAttribute($columna, $archivoFinal->id);
            }

            // 6. Recalcular completitud técnica del expediente
            $expediente->load(['foto', 'firma', 'pulgarDerecho', 'pulgarIzquierdo']);
            $expediente->recalcularCompletitud();
            $expediente->save();

            // 7. Sincronizar estado del elemento y credencial
            if ($expediente->es_completo) {
                if ($lockedElemento->estado_tramite === 'pendiente_biometrico' || empty($lockedElemento->estado_tramite)) {
                    $lockedElemento->estado_tramite = 'listo_impresion';
                    $lockedElemento->save();
                }

                if ($credencial) {
                    $credencial->estado_biometria = 'completo';
                    $credencial->save();
                }
            }

            // 8. Trazabilidad pericial inmutable
            BiometricoAuditoria::registrarAcceso(
                evento: 'actualizacion_biometrica',
                actor: $actor,
                expediente: $expediente,
                archivo: $archivoFinal,
                elemento: $lockedElemento,
                ipAddress: request()?->ip(),
                userAgent: request()?->userAgent(),
                detalles: [
                    'categoria' => $categoria->value,
                    'motivo' => $motivo,
                    'sha256_nuevo' => $sha256,
                    'version_secuencia' => $archivoFinal->version_secuencia,
                    'es_completo' => $expediente->es_completo,
                    'total_biometricos' => $expediente->total_biometricos,
                ]
            );

            return [
                'success' => true,
                'archivo' => $archivoFinal,
                'expediente' => $expediente,
                'elemento' => $lockedElemento,
            ];
        });
    }
}
