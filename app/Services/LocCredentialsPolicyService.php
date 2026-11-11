<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LocArma;
use App\Models\LocPersona;
use App\Models\LocVigenciaCredencial;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LocCredentialsPolicyService
{
    public const STATUS_ALTA = 'ALTA';
    public const STATUS_BAJA = 'BAJA';
    public const STATUS_SITUACION_ESPECIAL = 'SITUACION ESPECIAL';
    public const STATUS_CANCELACION = 'CANCELACION';

    public const VALID_STATUSES = [
        self::STATUS_ALTA,
        self::STATUS_BAJA,
        self::STATUS_SITUACION_ESPECIAL,
        self::STATUS_CANCELACION,
    ];

    /**
     * Determina si una transición entre dos estatus institucionales es válida.
     */
    public function canTransitionStatus(string $currentStatus, string $newStatus): bool
    {
        $current = strtoupper(trim($currentStatus));
        $new = strtoupper(trim($newStatus));

        if ($current === $new) {
            return true;
        }

        if (! in_array($current, self::VALID_STATUSES, true) || ! in_array($new, self::VALID_STATUSES, true)) {
            return false;
        }

        // CANCELACION es un estatus registral terminal que requiere revisión manual
        if ($current === self::STATUS_CANCELACION || $new === self::STATUS_CANCELACION) {
            return false;
        }

        return match ($current) {
            self::STATUS_ALTA => in_array($new, [self::STATUS_BAJA, self::STATUS_SITUACION_ESPECIAL], true),
            self::STATUS_SITUACION_ESPECIAL => in_array($new, [self::STATUS_BAJA, self::STATUS_ALTA], true),
            self::STATUS_BAJA => in_array($new, [self::STATUS_ALTA], true),
            default => false,
        };
    }

    /**
     * Valida si las fechas entre una baja y una alta de sustitución son consistentes.
     * La fecha de baja de la credencial anterior debe ser anterior o igual a la fecha de solicitud de la nueva.
     */
    public function isValidSubstitution(?string $fechaBaja, ?string $fechaSolicitud): bool
    {
        if (empty($fechaBaja) || empty($fechaSolicitud)) {
            return true;
        }

        try {
            $dBaja = Carbon::parse($fechaBaja);
            $dSol = Carbon::parse($fechaSolicitud);

            return $dBaja->lte($dSol);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Evalúa si un CUIP puede realizar un nuevo trámite de credencial ordinario
     * en función de su historial en loc_vigencias_credenciales.
     *
     * @return array{permitido: bool, motivo: string, mensaje: string, credencial_activa_id: ?int}
     */
    public function evaluateProcedureEligibility(string $cuip, string $tipoTramite = 'normal'): array
    {
        $normalizedCuip = strtoupper(trim($cuip));
        if ($normalizedCuip === '') {
            return [
                'permitido' => false,
                'motivo' => 'cuip_invalido',
                'mensaje' => 'El CUIP proporcionado está vacío.',
                'credencial_activa_id' => null,
            ];
        }

        // Consultamos todo el historial para este CUIP
        $history = LocVigenciaCredencial::query()
            ->where('cuip', $normalizedCuip)
            ->get();

        if ($history->isEmpty()) {
            return [
                'permitido' => true,
                'motivo' => 'sin_antecedentes',
                'mensaje' => 'Sin antecedentes en base de vigencias. Trámite permitido.',
                'credencial_activa_id' => null,
            ];
        }

        // Determinamos el evento más reciente de forma cronológica (usando fechas explícitas)
        $latestRecord = $this->determineChronologicallyLatestRecord($history);

        if ($latestRecord === null) {
            return [
                'permitido' => false,
                'motivo' => 'cronologia_ambigua',
                'mensaje' => 'El historial contiene fechas ambiguas o incompletas. Requiere revisión manual antes de proceder.',
                'credencial_activa_id' => null,
            ];
        }

        $status = strtoupper(trim((string) $latestRecord->estatus_credencial));

        // Regla 0: Si el antecedente en vigencias no tiene correspondencia en Personal LOC, bloquea trámite
        if ($latestRecord->persona_id === null || ! (bool) $latestRecord->es_referencia_loc) {
            return [
                'permitido' => false,
                'motivo' => 'bloqueado_sin_enlace_personal',
                'mensaje' => 'El antecedente en vigencias no cuenta con conciliación en el Padrón de Personal LOC. Bloquea trámite hasta conciliar la identidad.',
                'credencial_activa_id' => $latestRecord->id,
            ];
        }

        // Regla 1: ALTA activa bloquea trámite normal
        if ($status === self::STATUS_ALTA && empty($latestRecord->fecha_baja)) {
            return [
                'permitido' => false,
                'motivo' => 'bloqueado_alta_activa',
                'mensaje' => sprintf(
                    'El elemento cuenta con una credencial activa con estatus ALTA (Folio: %s, Oficio: %s). Bloquea nuevo trámite ordinario.',
                    $latestRecord->numero_credencial ?? 'S/N',
                    $latestRecord->numero_oficio ?? 'S/N'
                ),
                'credencial_activa_id' => $latestRecord->id,
            ];
        }

        // Regla 2: SITUACION ESPECIAL bloquea trámite normal
        if ($status === self::STATUS_SITUACION_ESPECIAL) {
            return [
                'permitido' => false,
                'motivo' => 'bloqueado_situacion_especial',
                'mensaje' => sprintf(
                    'El elemento se encuentra en SITUACIÓN ESPECIAL (%s). Bloquea nuevo trámite ordinario.',
                    $latestRecord->motivo_situacion_especial ?? 'Sin motivo especificado'
                ),
                'credencial_activa_id' => $latestRecord->id,
            ];
        }

        // Regla 3: CANCELACION pendiente de regla explícita -> requiere revisión
        if ($status === self::STATUS_CANCELACION) {
            return [
                'permitido' => false,
                'motivo' => 'requiere_revision_cancelacion',
                'mensaje' => 'El último antecedente corresponde a una CANCELACIÓN sin regla de resolución explícita. Requiere revisión manual.',
                'credencial_activa_id' => $latestRecord->id,
            ];
        }

        // Regla 4: BAJA previa permite continuar sujeto a validaciones
        if ($status === self::STATUS_BAJA || !empty($latestRecord->fecha_baja)) {
            return [
                'permitido' => true,
                'motivo' => 'permitido_baja_previa',
                'mensaje' => sprintf(
                    'Antecedente de BAJA (Fecha: %s, Oficio: %s) verificado. Trámite permitido sujeto a validaciones.',
                    $latestRecord->fecha_baja?->format('d/m/Y') ?? 'Registrada',
                    $latestRecord->numero_oficio_baja ?? $latestRecord->numero_oficio ?? 'S/N'
                ),
                'credencial_activa_id' => null,
            ];
        }

        return [
            'permitido' => false,
            'motivo' => 'estatus_desconocido',
            'mensaje' => 'El estatus del registro más reciente no es reconocido. Requiere revisión.',
            'credencial_activa_id' => $latestRecord->id,
        ];
    }

    /**
     * Ordena cronológicamente una colección de registros usando fechas explícitas,
     * SIN asumir que el orden físico de las filas del Excel o de la base representa orden cronológico.
     */
    public function determineChronologicallyLatestRecord(Collection $records): ?LocVigenciaCredencial
    {
        if ($records->isEmpty()) {
            return null;
        }

        if ($records->count() === 1) {
            return $records->first();
        }

        // Ponderamos cada registro por su fecha más representativa:
        // 1. fecha_baja si existe
        // 2. fecha_solicitud
        // 3. fecha_elaboracion
        // 4. fecha_vigencia
        $sorted = $records->sort(function (LocVigenciaCredencial $a, LocVigenciaCredencial $b): int {
            $dateA = $this->extractComparableDate($a);
            $dateB = $this->extractComparableDate($b);

            if ($dateA === null && $dateB === null) {
                return 0;
            }
            if ($dateA === null) {
                return -1;
            }
            if ($dateB === null) {
                return 1;
            }

            $cmp = $dateA->getTimestamp() <=> $dateB->getTimestamp();
            if ($cmp !== 0) {
                return $cmp;
            }

            // Si las fechas son idénticas, una BAJA explícita es cronológicamente de cierre frente a una ALTA
            $statusA = strtoupper((string) $a->estatus_credencial);
            $statusB = strtoupper((string) $b->estatus_credencial);
            if ($statusA === self::STATUS_BAJA && $statusB !== self::STATUS_BAJA) {
                return 1;
            }
            if ($statusB === self::STATUS_BAJA && $statusA !== self::STATUS_BAJA) {
                return -1;
            }

            return 0;
        });

        return $sorted->last();
    }

    public function extractComparableDate(LocVigenciaCredencial $record): ?Carbon
    {
        if ($record->fecha_baja !== null) {
            return Carbon::parse($record->fecha_baja);
        }
        if ($record->fecha_solicitud !== null) {
            return Carbon::parse($record->fecha_solicitud);
        }
        if ($record->fecha_elaboracion !== null) {
            return Carbon::parse($record->fecha_elaboracion);
        }
        if ($record->fecha_vigencia !== null) {
            return Carbon::parse($record->fecha_vigencia);
        }

        return null;
    }

    /**
     * Calcula una huella SHA-256 reproducible de los 40 campos crudos normalizados.
     * Sirve exclusivamente para deduplicación técnica e idempotencia, no para identidad de negocio.
     */
    public function computeRowFingerprint(array $rowValues): string
    {
        $normalized = array_map(static fn ($val) => trim((string) $val), $rowValues);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Normaliza y valida una cadena de fecha DD/MM/YYYY a YYYY-MM-DD.
     * Retorna null si la fecha es vacía, o false si es sintácticamente inválida.
     *
     * @return string|null|false
     */
    /**
     * Evalúa el efecto en trámite que tendrá una credencial en staging o en base.
     *
     * @return array{elegible: bool, motivo: string, etiqueta: string, tono: string}
     */
    public function evaluateProcedureEffect(string $status, bool $personaResuelta = true, ?string $fechaBaja = null): array
    {
        $statusUpper = strtoupper(trim($status));

        if (! $personaResuelta) {
            return [
                'elegible' => false,
                'motivo' => 'bloqueado_sin_enlace_personal',
                'etiqueta' => 'Bloqueado (Sin enlace personal)',
                'tono' => 'danger',
            ];
        }

        if ($statusUpper === self::STATUS_ALTA && empty($fechaBaja)) {
            return [
                'elegible' => false,
                'motivo' => 'bloqueado_alta_activa',
                'etiqueta' => 'Bloquea trámite (ALTA activa)',
                'tono' => 'danger',
            ];
        }

        if ($statusUpper === self::STATUS_SITUACION_ESPECIAL) {
            return [
                'elegible' => false,
                'motivo' => 'bloqueado_situacion_especial',
                'etiqueta' => 'Bloquea trámite (SITUACIÓN ESPECIAL)',
                'tono' => 'danger',
            ];
        }

        if ($statusUpper === self::STATUS_CANCELACION) {
            return [
                'elegible' => false,
                'motivo' => 'requiere_revision_cancelacion',
                'etiqueta' => 'Requiere revisión (CANCELACIÓN)',
                'tono' => 'warning',
            ];
        }

        if ($statusUpper === self::STATUS_BAJA || ! empty($fechaBaja)) {
            return [
                'elegible' => true,
                'motivo' => 'permitido_baja_previa',
                'etiqueta' => 'Permite trámite (BAJA)',
                'tono' => 'success',
            ];
        }

        return [
            'elegible' => false,
            'motivo' => 'estatus_desconocido',
            'etiqueta' => 'Estatus no reconocido',
            'tono' => 'neutral',
        ];
    }

    public function parseDate(mixed $value): string|null|false
    {
        if ($value === null) {
            return null;
        }

        $str = trim((string) $value);
        if ($str === '' || $str === '0' || strtolower($str) === 'null' || strtolower($str) === 's/n') {
            return null;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $str, $matches)) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            if (checkdate($month, $day, $year) && $year >= 1970 && $year <= 2050) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            return false;
        }

        // Si ya viene en formato ISO YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $str, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            if (checkdate($month, $day, $year) && $year >= 1970 && $year <= 2050) {
                return $str;
            }
            return false;
        }

        return false;
    }
}

