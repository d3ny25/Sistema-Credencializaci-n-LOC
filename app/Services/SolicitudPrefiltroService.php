<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PrefiltroSourcesMissingException;
use App\Models\LocArma;
use App\Models\LocCatalogoVersion;
use App\Models\LocImportBatch;
use App\Models\LocPersona;
use App\Models\LocVigenciaCredencial;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Support\MotivoDescarte;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SolicitudPrefiltroService
{
    /**
     * Marcadores textuales que representan un CUIP ausente o nulo.
     */
    public const CUIP_PLACEHOLDERS = [
        '',
        '0',
        'S/N',
        'SIN CUIP',
        'NULL',
        '-',
        '--',
        'NA',
        'N/A',
        'PENDIENTE',
    ];

    /**
     * Marcadores textuales que representan una matrícula de arma ausente o no asignada.
     */
    public const MATRICULA_PLACEHOLDERS = [
        '',
        '0',
        'S/N',
        'SIN MATRICULA',
        'SIN ARMA',
        'NULL',
        '-',
        '--',
        'NA',
        'N/A',
        'PENDIENTE',
    ];

    public function __construct(
        protected LocCredentialsPolicyService $policyService
    ) {}

    /**
     * Determina si un valor de CUIP es nulo, vacío o corresponde a un marcador de ausencia.
     */
    public static function isCuipPlaceholder(?string $cuip): bool
    {
        if ($cuip === null) {
            return true;
        }

        $norm = strtoupper(trim((string) $cuip));

        return in_array($norm, self::CUIP_PLACEHOLDERS, true);
    }

    /**
     * Normaliza una matrícula de arma: convierte a mayúsculas, elimina espacios y
     * descarta marcadores de ausencia. Retorna null si la matrícula es nula o vacía.
     */
    public static function normalizeMatricula(?string $matricula): ?string
    {
        if ($matricula === null) {
            return null;
        }

        $norm = strtoupper(trim((string) $matricula));

        if (in_array($norm, self::MATRICULA_PLACEHOLDERS, true)) {
            return null;
        }

        return $norm;
    }

    /**
     * Resuelve las fuentes oficiales autoritativas vigentes requeridas para ejecutar el prefiltro.
     * Selecciona el último lote confirmado y exitoso para cada fuente (no el ID máximo arbitrario),
     * y la versión del catálogo organizacional marcada explícitamente como vigente (es_vigente = true).
     *
     * @return array{
     *     personal_batch: LocImportBatch,
     *     armamento_batch: LocImportBatch,
     *     vigencias_batch: LocImportBatch,
     *     catalogo_version: LocCatalogoVersion
     * }
     *
     * @throws PrefiltroSourcesMissingException Si falta alguna fuente oficial requerida.
     */
    public function resolveOfficialSources(): array
    {
        $personalBatch = LocImportBatch::query()
            ->where('tipo_fuente', 'personal_autorizado_loc')
            ->where('estado', 'completado')
            ->whereNotNull('fecha_confirmacion')
            ->orderByDesc('fecha_confirmacion')
            ->orderByDesc('id')
            ->first();

        $armamentoBatch = LocImportBatch::query()
            ->where('tipo_fuente', 'armamento_loc')
            ->where('estado', 'completado')
            ->whereNotNull('fecha_confirmacion')
            ->orderByDesc('fecha_confirmacion')
            ->orderByDesc('id')
            ->first();

        $vigenciasBatch = LocImportBatch::query()
            ->where('tipo_fuente', LocCredentialsXlsImporter::SOURCE_TYPE)
            ->where('estado', 'completado')
            ->whereNotNull('fecha_confirmacion')
            ->orderByDesc('fecha_confirmacion')
            ->orderByDesc('id')
            ->first();

        $catalogoVersion = LocCatalogoVersion::query()
            ->where('es_vigente', true)
            ->first();

        $missing = [];
        if (! $personalBatch) {
            $missing[] = 'Lote confirmado de Personal Autorizado LOC';
        }
        if (! $armamentoBatch) {
            $missing[] = 'Lote confirmado de Armamento LOC';
        }
        if (! $vigenciasBatch) {
            $missing[] = 'Lote confirmado de Vigencias de Credenciales';
        }
        if (! $catalogoVersion) {
            $missing[] = 'Versión activa del Catálogo Organizacional';
        }

        if (! empty($missing)) {
            throw new PrefiltroSourcesMissingException($missing);
        }

        return [
            'personal_batch' => $personalBatch,
            'armamento_batch' => $armamentoBatch,
            'vigencias_batch' => $vigenciasBatch,
            'catalogo_version' => $catalogoVersion,
        ];
    }

    /**
     * Construye el snapshot de trazabilidad de fuentes oficiales utilizadas en la evaluación.
     */
    public function buildSourcesSnapshot(
        array $sources,
        ?User $user = null,
        ?SolicitudCredencial $solicitud = null,
        ?array $elementosPrevios = null
    ): array {
        $now = now();
        $personal = $sources['personal_batch'];
        $armamento = $sources['armamento_batch'];
        $vigencias = $sources['vigencias_batch'];
        $catalogo = $sources['catalogo_version'];

        $snapshot = [
            'personal' => [
                'batch_id' => $personal->id,
                'archivo' => $personal->nombre_archivo,
                'fecha_confirmacion' => $personal->fecha_confirmacion?->toIso8601String(),
            ],
            'armamento' => [
                'batch_id' => $armamento->id,
                'archivo' => $armamento->nombre_archivo,
                'fecha_confirmacion' => $armamento->fecha_confirmacion?->toIso8601String(),
            ],
            'vigencias' => [
                'batch_id' => $vigencias->id,
                'archivo' => $vigencias->nombre_archivo,
                'fecha_confirmacion' => $vigencias->fecha_confirmacion?->toIso8601String(),
            ],
            'catalogo' => [
                'version_id' => $catalogo->id,
                'version_numero' => $catalogo->version_numero,
                'batch_id' => $catalogo->import_batch_id,
                'archivo' => $catalogo->archivo_nombre,
                'fecha_activacion' => $catalogo->fecha_activacion?->toIso8601String(),
            ],
            'evaluado_en' => $now->toIso8601String(),
            'usuario_evaluador' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
            ],
            // Claves planas para interoperabilidad con pruebas de esquema de Fase 2.1
            'personal_batch_id' => $personal->id,
            'armamento_batch_id' => $armamento->id,
            'vigencias_batch_id' => $vigencias->id,
            'catalogo_version_id' => $catalogo->id,
            'catalogo_batch_id' => $catalogo->import_batch_id,
        ];

        // Conservar historial de ejecuciones previas si la solicitud ya había sido evaluada
        if ($solicitud !== null && $solicitud->fecha_prefiltro !== null) {
            $prevHistorial = $solicitud->fuentes_evaluacion['historial_ejecuciones'] ?? [];

            // Si no se proporcionó el snapshot previo en memoria, consultarlo
            if ($elementosPrevios === null) {
                $elementosPrevios = $solicitud->elementos()
                    ->orderBy('numero_fila')
                    ->orderBy('id')
                    ->get(['id', 'numero_fila', 'cuip', 'resultado_prefiltro', 'motivos_descarte', 'codigos_descarte', 'estado_tramite'])
                    ->map(fn (SolicitudElemento $e): array => [
                        'elemento_id' => $e->id,
                        'numero_fila' => $e->numero_fila,
                        'cuip' => $e->cuip,
                        'resultado_prefiltro' => $e->resultado_prefiltro,
                        'motivos_descarte' => $e->motivos_descarte,
                        'codigos_descarte' => $e->codigos_descarte,
                        'estado_tramite' => $e->estado_tramite,
                    ])
                    ->all();
            }

            $prevEntry = [
                'fecha_prefiltro' => $solicitud->fecha_prefiltro?->toIso8601String(),
                'usuario_prefiltro_id' => $solicitud->usuario_prefiltro_id,
                'total_elementos' => $solicitud->total_elementos,
                'total_aptos' => $solicitud->total_aptos,
                'total_descartados' => $solicitud->total_descartados,
                'fuentes' => array_diff_key($solicitud->fuentes_evaluacion ?? [], ['historial_ejecuciones' => true]),
                'elementos_previos' => $elementosPrevios,
            ];
            $prevHistorial[] = $prevEntry;
            $snapshot['historial_ejecuciones'] = $prevHistorial;
        }

        return $snapshot;
    }

    /**
     * Cuenta cuántas asignaciones activas tiene registrada un arma, considerando tanto
     * las vigencias oficiales activas (estatus ALTA y sin baja) como los cupos reservados
     * por elementos aptos en otras solicitudes que sigan en trámite (evaluada, enviada_impresion).
     */
    public function countActiveAssignments(LocArma $arma, ?int $excludeSolicitudId = null): int
    {
        $oficiales = (int) LocVigenciaCredencial::query()
            ->where(function ($q) use ($arma) {
                $q->where('arma_id', $arma->id)
                    ->orWhere('arma_corta_id', $arma->id)
                    ->orWhere('arma_larga_id', $arma->id)
                    ->orWhere('matricula', $arma->matricula)
                    ->orWhere('matricula_corta', $arma->matricula)
                    ->orWhere('matricula_larga', $arma->matricula);
            })
            ->where('estatus_credencial', LocCredentialsPolicyService::STATUS_ALTA)
            ->whereNull('fecha_baja')
            ->selectRaw('count(distinct coalesce(persona_id, cuip, id)) as total')
            ->value('total');

        $enTramite = (int) SolicitudElemento::query()
            ->whereHas('solicitud', function ($q) use ($excludeSolicitudId) {
                if ($excludeSolicitudId !== null) {
                    $q->where('id', '!=', $excludeSolicitudId);
                }
                $q->whereIn('estado_general', ['evaluada', 'enviada_impresion']);
            })
            ->where('resultado_prefiltro', 'Apto')
            ->where(function ($q) use ($arma) {
                $q->where('arma_corta_id', $arma->id)
                    ->orWhere('arma_larga_id', $arma->id)
                    ->orWhereRaw('UPPER(TRIM(matricula_corta)) = ?', [$arma->matricula])
                    ->orWhereRaw('UPPER(TRIM(matricula_larga)) = ?', [$arma->matricula]);
            })
            ->count();

        return $oficiales + $enTramite;
    }

    /**
     * Compatibilidad: cuenta asignaciones oficiales en el padrón de vigencias.
     */
    public function countActiveAssignmentsInOfficialVigencias(LocArma $arma): int
    {
        return $this->countActiveAssignments($arma, null);
    }

    /**
     * Precarga y calcula en lote las asignaciones activas de un conjunto de armas
     * en únicamente dos consultas SQL (una para vigencias oficiales y otra para solicitudes en trámite).
     *
     * @param \Illuminate\Support\Collection<int, LocArma> $armas
     * @return array<int, int> Mapa de arma_id => total_asignaciones_activas
     */
    public function batchCountActiveAssignments($armas, ?int $excludeSolicitudId = null): array
    {
        if ($armas->isEmpty()) {
            return [];
        }

        $armaIds = $armas->pluck('id')->filter()->unique()->values()->all();
        $armaMatriculas = $armas->pluck('matricula')->filter()->map(fn ($m) => strtoupper(trim((string) $m)))->unique()->values()->all();

        // 1. Asignaciones oficiales activas en una sola consulta
        $oficialesRecords = LocVigenciaCredencial::query()
            ->where('estatus_credencial', LocCredentialsPolicyService::STATUS_ALTA)
            ->whereNull('fecha_baja')
            ->where(function ($q) use ($armaIds, $armaMatriculas) {
                if (! empty($armaIds)) {
                    $q->whereIn('arma_id', $armaIds)
                        ->orWhereIn('arma_corta_id', $armaIds)
                        ->orWhereIn('arma_larga_id', $armaIds);
                }
                if (! empty($armaMatriculas)) {
                    $q->orWhereIn('matricula', $armaMatriculas)
                        ->orWhereIn('matricula_corta', $armaMatriculas)
                        ->orWhereIn('matricula_larga', $armaMatriculas);
                }
            })
            ->get(['id', 'persona_id', 'cuip', 'arma_id', 'arma_corta_id', 'arma_larga_id', 'matricula', 'matricula_corta', 'matricula_larga']);

        // 2. Asignaciones reservadas en solicitudes en trámite en una sola consulta
        $enTramiteRecords = SolicitudElemento::query()
            ->whereHas('solicitud', function ($q) use ($excludeSolicitudId) {
                if ($excludeSolicitudId !== null) {
                    $q->where('id', '!=', $excludeSolicitudId);
                }
                $q->whereIn('estado_general', ['evaluada', 'enviada_impresion']);
            })
            ->where('resultado_prefiltro', 'Apto')
            ->where(function ($q) use ($armaIds, $armaMatriculas) {
                if (! empty($armaIds)) {
                    $q->whereIn('arma_corta_id', $armaIds)
                        ->orWhereIn('arma_larga_id', $armaIds);
                }
                if (! empty($armaMatriculas)) {
                    $q->orWhereIn('matricula_corta', $armaMatriculas)
                        ->orWhereIn('matricula_larga', $armaMatriculas);
                }
            })
            ->get(['id', 'arma_corta_id', 'arma_larga_id', 'matricula_corta', 'matricula_larga']);

        $counts = [];
        foreach ($armas as $arma) {
            $armaId = (int) $arma->id;
            $matUpper = strtoupper(trim((string) $arma->matricula));

            // Conteo oficial: distinct coalesce(persona_id, cuip, id)
            $matchedKeys = [];
            foreach ($oficialesRecords as $rec) {
                $matches = ((int) $rec->arma_id === $armaId)
                    || ((int) $rec->arma_corta_id === $armaId)
                    || ((int) $rec->arma_larga_id === $armaId)
                    || ($rec->matricula !== null && strtoupper(trim((string) $rec->matricula)) === $matUpper)
                    || ($rec->matricula_corta !== null && strtoupper(trim((string) $rec->matricula_corta)) === $matUpper)
                    || ($rec->matricula_larga !== null && strtoupper(trim((string) $rec->matricula_larga)) === $matUpper);

                if ($matches) {
                    $key = $rec->persona_id !== null ? "p_{$rec->persona_id}" : ($rec->cuip !== null ? "c_{$rec->cuip}" : "id_{$rec->id}");
                    $matchedKeys[$key] = true;
                }
            }
            $oficialesCount = count($matchedKeys);

            // Conteo en trámite: elementos aptos reservados
            $tramiteCount = 0;
            foreach ($enTramiteRecords as $rec) {
                $matches = ((int) $rec->arma_corta_id === $armaId)
                    || ((int) $rec->arma_larga_id === $armaId)
                    || ($rec->matricula_corta !== null && strtoupper(trim((string) $rec->matricula_corta)) === $matUpper)
                    || ($rec->matricula_larga !== null && strtoupper(trim((string) $rec->matricula_larga)) === $matUpper);

                if ($matches) {
                    $tramiteCount++;
                }
            }

            $counts[$armaId] = $oficialesCount + $tramiteCount;
        }

        return $counts;
    }

    /**
     * Ejecuta el prefiltro institucional completo sobre una solicitud de credencialización.
     * Toda la operación se realiza de forma atómica bajo una transacción de base de datos.
     *
     * @return array{
     *     solicitud_id: int,
     *     total_elementos: int,
     *     total_aptos: int,
     *     total_descartados: int,
     *     fuentes: array
     * }
     */
    public function evaluate(SolicitudCredencial $solicitud, ?User $user = null): array
    {
        return DB::transaction(function () use ($solicitud, $user): array {
            // 1. Bloqueo pesimista de la solicitud
            /** @var SolicitudCredencial $solicitudLocked */
            $solicitudLocked = SolicitudCredencial::where('id', $solicitud->id)
                ->lockForUpdate()
                ->firstOrFail();

            $elementos = $solicitudLocked->elementos()
                ->orderBy('numero_fila')
                ->orderBy('id')
                ->get();

            if ($elementos->isEmpty()) {
                throw new RuntimeException('La solicitud no cuenta con elementos cargados para evaluar en el prefiltro.');
            }

            // Capturar el dictamen individual previo de cada elemento ANTES de que sean modificados por la reevaluación
            $elementosPreviosSnapshot = null;
            if ($solicitudLocked->fecha_prefiltro !== null) {
                $elementosPreviosSnapshot = $elementos->map(fn (SolicitudElemento $e): array => [
                    'elemento_id' => $e->id,
                    'numero_fila' => $e->numero_fila,
                    'cuip' => $e->cuip,
                    'resultado_prefiltro' => $e->resultado_prefiltro,
                    'motivos_descarte' => $e->motivos_descarte,
                    'codigos_descarte' => $e->codigos_descarte,
                    'estado_tramite' => $e->estado_tramite,
                ])->all();
            }

            // 2. Verificación y resolución de fuentes oficiales
            $sources = $this->resolveOfficialSources();

            // 3. Extracción y normalización previa de identificadores para Batch Queries
            $validCuips = [];
            $cuipGroups = [];
            $matriculasSolicitadas = [];

            foreach ($elementos as $elem) {
                $rawCuip = $elem->cuip;
                if (! self::isCuipPlaceholder($rawCuip)) {
                    $normCuip = strtoupper(trim((string) $rawCuip));
                    $validCuips[] = $normCuip;
                    $cuipGroups[$normCuip][] = $elem->numero_fila ?? $elem->id;
                }

                $cortaNorm = self::normalizeMatricula($elem->matricula_corta);
                if ($cortaNorm !== null) {
                    $matriculasSolicitadas[] = $cortaNorm;
                }

                $largaNorm = self::normalizeMatricula($elem->matricula_larga);
                if ($largaNorm !== null) {
                    $matriculasSolicitadas[] = $largaNorm;
                }
            }

            $validCuips = array_values(array_unique($validCuips));
            $matriculasSolicitadas = array_values(array_unique($matriculasSolicitadas));

            $duplicateCuips = [];
            foreach ($cuipGroups as $cuipKey => $filas) {
                if (count($filas) > 1) {
                    $duplicateCuips[$cuipKey] = $filas;
                }
            }

            // 4. Batch Query: Personas en el padrón oficial
            $personasMap = collect();
            if (! empty($validCuips)) {
                $personasMap = LocPersona::query()
                    ->whereIn(DB::raw('UPPER(TRIM(cuip))'), $validCuips)
                    ->get()
                    ->keyBy(fn (LocPersona $p): string => strtoupper(trim((string) $p->cuip)));
            }

            // 5. Batch Query & Bloqueo Determinista: Armas solicitadas
            // Para prevenir interbloqueos (deadlocks) entre solicitudes concurrentes que compiten
            // por las mismas armas, se obtienen y bloquean ordenadas estrictamente por ID ascendente.
            $armasMap = collect();
            if (! empty($matriculasSolicitadas)) {
                $armasEncontradas = LocArma::query()
                    ->whereIn(DB::raw('UPPER(TRIM(matricula))'), $matriculasSolicitadas)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                $armasMap = $armasEncontradas->keyBy(fn (LocArma $a): string => strtoupper(trim((string) $a->matricula)));
            }

            // 6. Batch Query: Vigencias de credenciales (por CUIP y por persona_id)
            $personasIds = $personasMap->pluck('id')->filter()->unique()->values()->all();
            $vigenciasList = collect();
            if (! empty($validCuips) || ! empty($personasIds)) {
                $vigenciasList = LocVigenciaCredencial::query()
                    ->where(function ($q) use ($validCuips, $personasIds) {
                        if (! empty($validCuips)) {
                            $q->whereIn(DB::raw('UPPER(TRIM(cuip))'), $validCuips);
                        }
                        if (! empty($personasIds)) {
                            $q->orWhereIn('persona_id', $personasIds);
                        }
                    })
                    ->get();
            }

            // 7. Mapa de conteo de asignaciones por arma (DESPUÉS de haber adquirido los bloqueos)
            // Se calcula en lote en 2 consultas SQL para todas las armas involucradas
            $armasAsignadas = $this->batchCountActiveAssignments($armasEncontradas ?? collect(), $solicitudLocked->id);

            $totalAptos = 0;
            $totalDescartados = 0;
            $rowsToUpdate = [];

            // 8. Evaluación de cada elemento usando los datos precargados en memoria (0 consultas SQL dentro del bucle)
            foreach ($elementos as $elem) {
                $motivos = [];
                $codigos = [];

                // --- 8.1. Evaluación de CUIP y pertenencia a Personal LOC ---
                $rawCuip = $elem->cuip;
                $isPlaceholder = self::isCuipPlaceholder($rawCuip);

                $persona = null;

                if ($isPlaceholder) {
                    $motivos[] = 'El elemento no cuenta con una clave CUIP válida para su identificación institucional.';
                    $codigos[] = MotivoDescarte::CUIP_VACIO;
                    $elem->persona_id = null;
                } else {
                    $cuipNorm = strtoupper(trim((string) $rawCuip));

                    // Verificación de duplicidad dentro de la misma solicitud
                    if (isset($duplicateCuips[$cuipNorm])) {
                        $filasStr = implode(', ', $duplicateCuips[$cuipNorm]);
                        $motivos[] = "CUIP duplicado dentro de la misma solicitud (presente en las filas {$filasStr}). Por política de integridad institucional, todas las filas con CUIP duplicado quedan descartadas.";
                        $codigos[] = MotivoDescarte::CUIP_DUPLICADO;
                    }

                    // Pertenencia al padrón oficial de Personal LOC
                    $persona = $personasMap->get($cuipNorm);
                    if (! $persona) {
                        $motivos[] = "El CUIP '{$cuipNorm}' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.";
                        $codigos[] = MotivoDescarte::CUIP_NO_LOCALIZADO;
                        $elem->persona_id = null;
                    } else {
                        $elem->persona_id = $persona->id;
                    }

                    // --- 8.2. Evaluación cronológica de vigencias oficiales ---
                    // Buscar historial por CUIP y por persona_id en las vigencias precargadas
                    $history = $vigenciasList->filter(function (LocVigenciaCredencial $v) use ($cuipNorm, $persona): bool {
                        $vCuip = $v->cuip !== null ? strtoupper(trim((string) $v->cuip)) : '';
                        if ($vCuip === $cuipNorm) {
                            return true;
                        }
                        if ($persona !== null && (int) $v->persona_id === (int) $persona->id) {
                            return true;
                        }
                        return false;
                    });

                    if ($history->isNotEmpty()) {
                        // REGLA CRONOLÓGICA PRIORITARIA:
                        // Si existe CUALQUIER registro con estatus ALTA activa (sin fecha de baja),
                        // bloquea inmediatamente el nuevo trámite ordinario.
                        // Una BAJA posterior de otro registro o corporación no debe ocultar un ALTA activa.
                        $activeAlta = $history->first(function (LocVigenciaCredencial $v): bool {
                            $st = strtoupper(trim((string) $v->estatus_credencial));
                            return $st === LocCredentialsPolicyService::STATUS_ALTA && empty($v->fecha_baja);
                        });

                        if ($activeAlta !== null) {
                            $motivos[] = sprintf(
                                'El elemento cuenta con una credencial oficial activa con estatus ALTA (Folio: %s, Oficio: %s). Bloquea nuevo trámite ordinario.',
                                $activeAlta->numero_credencial ?? 'S/N',
                                $activeAlta->numero_oficio ?? 'S/N'
                            );
                            $codigos[] = MotivoDescarte::EXP_ALTA_ACTIVA;
                        } else {
                            // No hay ninguna ALTA activa. Evaluamos el antecedente más reciente:
                            $latestRecord = $this->policyService->determineChronologicallyLatestRecord($history);

                            if ($latestRecord !== null) {
                                $status = strtoupper(trim((string) $latestRecord->estatus_credencial));

                                if ($status === LocCredentialsPolicyService::STATUS_SITUACION_ESPECIAL) {
                                    $motivos[] = sprintf(
                                        'El elemento se encuentra en SITUACIÓN ESPECIAL (%s). Bloquea nuevo trámite ordinario.',
                                        $latestRecord->motivo_situacion_especial ?? 'Sin motivo especificado'
                                    );
                                    $codigos[] = MotivoDescarte::EXP_SITUACION_ESPECIAL;
                                } elseif ($status === LocCredentialsPolicyService::STATUS_CANCELACION) {
                                    $motivos[] = 'El último antecedente oficial del elemento corresponde a una CANCELACIÓN de credencial. Bloquea el trámite ordinario y requiere resolución administrativa previa.';
                                    $codigos[] = MotivoDescarte::EXP_CANCELACION;
                                } elseif ($status === LocCredentialsPolicyService::STATUS_BAJA || ! empty($latestRecord->fecha_baja)) {
                                    // BAJA previa permite continuar sujeto a las demás validaciones
                                } else {
                                    $motivos[] = sprintf(
                                        "El estatus de la credencial oficial más reciente ('%s') no es reconocido para la tramitación ordinaria.",
                                        $status
                                    );
                                    $codigos[] = MotivoDescarte::EXP_ESTATUS_DESCONOCIDO;
                                }
                            }
                        }
                    }
                }

                // --- 8.3. Evaluación de Armamento ---
                $cortaMat = self::normalizeMatricula($elem->matricula_corta);
                $largaMat = self::normalizeMatricula($elem->matricula_larga);

                $armaCorta = null;
                $armaLarga = null;

                if ($cortaMat === null && $largaMat === null) {
                    $motivos[] = 'El elemento no cuenta con ninguna matrícula de arma asignada (se requiere al menos arma corta, larga o ambas).';
                    $codigos[] = MotivoDescarte::ARM_SIN_ASIGNACION;
                    $elem->arma_corta_id = null;
                    $elem->arma_larga_id = null;
                } else {
                    // Validación de Arma Corta
                    if ($cortaMat !== null) {
                        $armaCorta = $armasMap->get($cortaMat);
                        if (! $armaCorta) {
                            $motivos[] = "El arma corta con matrícula '{$cortaMat}' no fue localizada en el padrón oficial de armamento LOC.";
                            $codigos[] = MotivoDescarte::ARM_CORTA_NO_LOCALIZADA;
                            $elem->arma_corta_id = null;
                        } else {
                            $elem->arma_corta_id = $armaCorta->id;
                            if (strtoupper((string) $armaCorta->estatus) !== 'FISICA') {
                                $motivos[] = "El arma corta con matrícula '{$cortaMat}' tiene estatus '{$armaCorta->estatus}' en el padrón oficial de armamento (se requiere estatus exactamente 'FISICA').";
                                $codigos[] = MotivoDescarte::ARM_CORTA_ESTATUS_INVALIDO;
                            } else {
                                if (! isset($armasAsignadas[$armaCorta->id])) {
                                    $armasAsignadas[$armaCorta->id] = $this->countActiveAssignments($armaCorta, $solicitudLocked->id);
                                }
                                if ($armasAsignadas[$armaCorta->id] >= 3) {
                                    $motivos[] = "El arma corta con matrícula '{$cortaMat}' excede el límite máximo de 3 asignaciones activas con estatus ALTA (cuenta actualmente con {$armasAsignadas[$armaCorta->id]} asignaciones activas).";
                                    $codigos[] = MotivoDescarte::ARM_CORTA_CUPO_EXCEDIDO;
                                }
                            }
                        }
                    } else {
                        $elem->arma_corta_id = null;
                    }

                    // Validación de Arma Larga
                    if ($largaMat !== null) {
                        $armaLarga = $armasMap->get($largaMat);
                        if (! $armaLarga) {
                            $motivos[] = "El arma larga con matrícula '{$largaMat}' no fue localizada en el padrón oficial de armamento LOC.";
                            $codigos[] = MotivoDescarte::ARM_LARGA_NO_LOCALIZADA;
                            $elem->arma_larga_id = null;
                        } else {
                            $elem->arma_larga_id = $armaLarga->id;
                            if (strtoupper((string) $armaLarga->estatus) !== 'FISICA') {
                                $motivos[] = "El arma larga con matrícula '{$largaMat}' tiene estatus '{$armaLarga->estatus}' en el padrón oficial de armamento (se requiere estatus exactamente 'FISICA').";
                                $codigos[] = MotivoDescarte::ARM_LARGA_ESTATUS_INVALIDO;
                            } else {
                                if (! isset($armasAsignadas[$armaLarga->id])) {
                                    $armasAsignadas[$armaLarga->id] = $this->countActiveAssignments($armaLarga, $solicitudLocked->id);
                                }
                                if ($armasAsignadas[$armaLarga->id] >= 3) {
                                    $motivos[] = "El arma larga con matrícula '{$largaMat}' excede el límite máximo de 3 asignaciones activas con estatus ALTA (cuenta actualmente con {$armasAsignadas[$armaLarga->id]} asignaciones activas).";
                                    $codigos[] = MotivoDescarte::ARM_LARGA_CUPO_EXCEDIDO;
                                }
                            }
                        }
                    } else {
                        $elem->arma_larga_id = null;
                    }
                }

                // --- 8.4. Dictamen final de la fila ---
                if (empty($motivos)) {
                    $elem->resultado_prefiltro = 'Apto';
                    $elem->motivos_descarte = null;
                    $elem->codigos_descarte = null;
                    $elem->estado_tramite = 'apto';
                    $elem->motivo_rechazo = null;
                    $totalAptos++;

                    // Consumo de cupos para las armas válidas
                    if ($armaCorta !== null && strtoupper((string) $armaCorta->estatus) === 'FISICA') {
                        $armasAsignadas[$armaCorta->id]++;
                    }
                    if ($armaLarga !== null && strtoupper((string) $armaLarga->estatus) === 'FISICA') {
                        // Si es la misma arma para corta y larga en la misma persona, solo cuenta 1 vez
                        if (! $armaCorta || $armaLarga->id !== $armaCorta->id) {
                            $armasAsignadas[$armaLarga->id]++;
                        }
                    }
                } else {
                    $elem->resultado_prefiltro = 'Descartado';
                    $elem->motivos_descarte = array_values(array_unique($motivos));
                    $elem->codigos_descarte = array_values(array_unique($codigos));
                    $elem->estado_tramite = 'descartado';
                    $elem->motivo_rechazo = implode(' | ', $elem->motivos_descarte);
                    $totalDescartados++;
                }

                $rowsToUpdate[] = [
                    'id' => $elem->id,
                    'solicitud_id' => $elem->solicitud_id,
                    'numero_fila' => $elem->numero_fila,
                    'persona_id' => $elem->persona_id,
                    'cuip' => $elem->cuip,
                    'matricula_corta' => $elem->matricula_corta,
                    'matricula_larga' => $elem->matricula_larga,
                    'arma_corta_id' => $elem->arma_corta_id,
                    'arma_larga_id' => $elem->arma_larga_id,
                    'resultado_prefiltro' => $elem->resultado_prefiltro,
                    'motivos_descarte' => $elem->motivos_descarte !== null ? json_encode($elem->motivos_descarte, JSON_UNESCAPED_UNICODE) : null,
                    'codigos_descarte' => $elem->codigos_descarte !== null ? json_encode($elem->codigos_descarte, JSON_UNESCAPED_UNICODE) : null,
                    'estado_tramite' => $elem->estado_tramite,
                    'motivo_rechazo' => $elem->motivo_rechazo,
                    'updated_at' => now(),
                ];
            }

            // Actualización masiva de elementos por lotes para evitar consultas unitarias N+1
            if (! empty($rowsToUpdate)) {
                foreach (array_chunk($rowsToUpdate, 250) as $chunk) {
                    SolicitudElemento::upsert(
                        $chunk,
                        ['id'],
                        [
                            'persona_id',
                            'arma_corta_id',
                            'arma_larga_id',
                            'resultado_prefiltro',
                            'motivos_descarte',
                            'codigos_descarte',
                            'estado_tramite',
                            'motivo_rechazo',
                            'updated_at',
                        ]
                    );
                }
            }

            // 9. Persistencia del snapshot y métricas de la solicitud
            $snapshot = $this->buildSourcesSnapshot($sources, $user, $solicitudLocked, $elementosPreviosSnapshot);

            $solicitudLocked->total_elementos = count($elementos);
            $solicitudLocked->total_aptos = $totalAptos;
            $solicitudLocked->total_descartados = $totalDescartados;
            $solicitudLocked->estado_general = 'evaluada';
            $solicitudLocked->fecha_prefiltro = now();
            $solicitudLocked->usuario_prefiltro_id = $user?->id;
            $solicitudLocked->fuentes_evaluacion = $snapshot;
            $solicitudLocked->save();

            return [
                'solicitud_id' => $solicitudLocked->id,
                'total_elementos' => count($elementos),
                'total_aptos' => $totalAptos,
                'total_descartados' => $totalDescartados,
                'fuentes' => $snapshot,
            ];
        });
    }
}

