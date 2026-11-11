<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoClasificacionEstado;
use App\Models\BiometricoExpediente;
use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;
use App\Models\LocPersona;
use App\Models\SolicitudElemento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class BiometricResolverService
{
    /**
     * Asocia de forma controlada y transaccional un expediente biométrico a un elemento de solicitud.
     *
     * @throws InvalidArgumentException Si existe alguna discrepancia de CUIP, ámbito o clasificación.
     * @throws RuntimeException Si el elemento ya cuenta con credencial emitida.
     */
    public function asociarExpedienteAElemento(
        SolicitudElemento $elemento,
        BiometricoExpediente $expediente,
        ?int $userId = null
    ): SolicitudElemento {
        return DB::transaction(function () use ($elemento, $expediente): SolicitudElemento {
            /** @var SolicitudElemento $lockedElemento */
            $lockedElemento = SolicitudElemento::query()
                ->lockForUpdate()
                ->findOrFail($elemento->id);

            /** @var BiometricoExpediente $lockedExpediente */
            $lockedExpediente = BiometricoExpediente::query()
                ->lockForUpdate()
                ->findOrFail($expediente->id);

            $lockedElemento->asociarExpediente($lockedExpediente);
            $lockedElemento->save();

            return $lockedElemento;
        });
    }

    /**
     * Desasocia de forma controlada y transaccional el expediente biométrico de un elemento.
     *
     * @throws RuntimeException Si el elemento ya cuenta con credencial emitida.
     */
    public function desasociarExpedienteDeElemento(
        SolicitudElemento $elemento,
        ?int $userId = null
    ): SolicitudElemento {
        return DB::transaction(function () use ($elemento): SolicitudElemento {
            /** @var SolicitudElemento $lockedElemento */
            $lockedElemento = SolicitudElemento::query()
                ->lockForUpdate()
                ->findOrFail($elemento->id);

            $lockedElemento->desasociarExpediente();
            $lockedElemento->save();

            return $lockedElemento;
        });
    }

    /**
     * Resuelve de forma determinista el ámbito biométrico para un elemento individual.
     */
    public function resolverAmbitoParaElemento(
        SolicitudElemento $elemento,
        ?LocCatalogoVersion $version = null
    ): BiometricoAmbito {
        $catalogoVersion = $version ?? LocCatalogoVersion::query()->where('es_vigente', true)->first();
        $versionId = $catalogoVersion?->id;
        $versionNum = $catalogoVersion?->version_numero;

        // Fuente 1: Persona vinculada oficialmente en loc_personas
        if ($elemento->persona_id !== null) {
            $persona = $elemento->persona ?? LocPersona::find($elemento->persona_id);
            if ($persona && $persona->clave_seguridad) {
                $clave = trim((string) $persona->clave_seguridad);
                $catRecord = LocCatalogoOrganizacional::query()
                    ->when($versionId, fn ($q) => $q->where('import_batch_id', $catalogoVersion->import_batch_id))
                    ->where('clave_origen', $clave)
                    ->where('activo', true)
                    ->first();

                $ambito = BiometricoAmbito::fromCatalogoRecord($catRecord, $catalogoVersion);

                if ($ambito->isResuelto()) {
                    // Verificación de discrepancia con el oficio
                    $solicitud = $elemento->solicitud;
                    $areaOficio = $solicitud?->area_solicitante_clave;
                    $tieneDiscrepancia = false;

                    if ($areaOficio) {
                        $catOficio = LocCatalogoOrganizacional::query()
                            ->when($versionId, fn ($q) => $q->where('import_batch_id', $catalogoVersion->import_batch_id))
                            ->where('clave_origen', trim((string) $areaOficio))
                            ->where('activo', true)
                            ->first();

                        $ambitoOficio = BiometricoAmbito::fromCatalogoRecord($catOficio, $catalogoVersion);
                        if ($ambitoOficio->isResuelto() && $ambitoOficio !== $ambito) {
                            $tieneDiscrepancia = true;
                        }
                    }

                    $elemento->ambito_biometrico = $ambito;
                    $elemento->estado_clasificacion_ambito = BiometricoClasificacionEstado::VALIDADA;
                    $elemento->clave_organizacional_origen = $clave;
                    $elemento->catalogo_version_id = $versionId;
                    $elemento->catalogo_version_numero = $versionNum;
                    $elemento->criterio_resolucion_ambito = 'persona_clave';
                    $elemento->tiene_discrepancia_oficio = $tieneDiscrepancia;
                    $elemento->fecha_resolucion_ambito = now();

                    return $ambito;
                }
            }
        }

        // Fuente 2: Fallback estricto por área solicitante del oficio (Solo terminal de Prevención sin persona)
        $solicitud = $elemento->solicitud;
        $areaOficio = $solicitud?->area_solicitante_clave;
        if ($areaOficio) {
            $claveOficio = trim((string) $areaOficio);
            $catOficio = LocCatalogoOrganizacional::query()
                ->when($versionId, fn ($q) => $q->where('import_batch_id', $catalogoVersion->import_batch_id))
                ->where('clave_origen', $claveOficio)
                ->where('activo', true)
                ->first();

            $ambitoOficio = BiometricoAmbito::fromCatalogoRecord($catOficio, $catalogoVersion);

            if ($ambitoOficio === BiometricoAmbito::PREVENCION && preg_match('/^D([3-9]|1[0-9]|2[0-7])$/', $claveOficio)) {
                $elemento->ambito_biometrico = BiometricoAmbito::PREVENCION;
                $elemento->estado_clasificacion_ambito = BiometricoClasificacionEstado::VALIDADA;
                $elemento->clave_organizacional_origen = $claveOficio;
                $elemento->catalogo_version_id = $versionId;
                $elemento->catalogo_version_numero = $versionNum;
                $elemento->criterio_resolucion_ambito = 'solicitud_area';
                $elemento->tiene_discrepancia_oficio = false;
                $elemento->fecha_resolucion_ambito = now();

                return BiometricoAmbito::PREVENCION;
            }
        }

        // Caso por defecto: NO RESUELTO
        $elemento->ambito_biometrico = BiometricoAmbito::NO_RESUELTO;
        $elemento->estado_clasificacion_ambito = BiometricoClasificacionEstado::PROVISIONAL;
        $elemento->criterio_resolucion_ambito = 'no_resuelto';

        return BiometricoAmbito::NO_RESUELTO;
    }

    /**
     * Resuelve en un solo paso optimizado por lotes los expedientes para una colección de elementos.
     *
     * @param Collection<int, SolicitudElemento> $elementos
     * @return Collection<int, SolicitudElemento>
     */
    public function resolveBatch(Collection $elementos): Collection
    {
        $pairs = $elementos
            ->filter(fn (SolicitudElemento $e) => $e->cuip && $e->isAmbitoResuelto())
            ->map(fn (SolicitudElemento $e) => [
                'cuip' => strtoupper(trim((string) $e->cuip)),
                'ambito' => $e->ambito_biometrico->value,
            ])
            ->unique(fn ($p) => $p['cuip'] . '|' . $p['ambito'])
            ->values();

        if ($pairs->isEmpty()) {
            return $elementos;
        }

        $cuips = $pairs->pluck('cuip')->all();
        $ambitos = $pairs->pluck('ambito')->unique()->all();

        $expedientes = BiometricoExpediente::query()
            ->whereIn('cuip', $cuips)
            ->whereIn('ambito', $ambitos)
            ->get()
            ->keyBy(fn (BiometricoExpediente $exp) => strtoupper(trim((string) $exp->cuip)) . '|' . $exp->ambito->value);

        foreach ($elementos as $elemento) {
            if ($elemento->cuip && $elemento->isAmbitoResuelto()) {
                $key = strtoupper(trim((string) $elemento->cuip)) . '|' . $elemento->ambito_biometrico->value;
                if ($expedientes->has($key)) {
                    $elemento->setRelation('expedienteBiometrico', $expedientes->get($key));
                }
            }
        }

        return $elementos;
    }
}

