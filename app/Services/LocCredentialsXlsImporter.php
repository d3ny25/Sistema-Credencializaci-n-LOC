<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LocArma;
use App\Models\LocImportBatch;
use App\Models\LocImportStagingRow;
use App\Models\LocPersona;
use App\Models\LocVigenciaCredencial;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use Throwable;

class LocCredentialsChunkReadFilter implements IReadFilter
{
    public function __construct(private int $startRow, private int $endRow) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row === 1 || ($row >= $this->startRow && $row <= $this->endRow);
    }
}

class LocCredentialsXlsImporter
{
    public const CHUNK_SIZE = 5000;

    public const SOURCE_TYPE = 'vigencias_credenciales';
    public const ACTION_OMITIDO = 'omitido';
    public const STATUS_APLICACION_OMITIDO = 'omitido';

    private LocCredentialsPolicyService $policy;

    public function __construct(?LocCredentialsPolicyService $policy = null)
    {
        $this->policy = $policy ?? new LocCredentialsPolicyService();
    }

    public function createReader(): LocCredentialsOleReuseReader|Xls
    {
        $useOleReuse = (bool) config('loc_import.credentials_reuse_ole', true);
        $reader = $useOleReuse
            ? new LocCredentialsOleReuseReader()
            : new Xls();

        $reader->setReadDataOnly(true);

        return $reader;
    }

    /**
     * Procesa un archivo XLS físico por bloques, utilizando la caché OLE si está habilitada.
     */
    public function stage(string $path, LocImportBatch $batch): void
    {
        if ($batch->tipo_fuente !== self::SOURCE_TYPE) {
            throw new \RuntimeException('El lote no corresponde a la fuente de vigencias de credenciales.');
        }

        $useOleReuse = (bool) config('loc_import.credentials_reuse_ole', true);
        if ($useOleReuse) {
            $scopeToken = 'batch_'.$batch->id.'_'.($batch->archivo_sha256 ?? (string) Str::uuid());
            LocCredentialsOleReuseReader::beginScope($scopeToken, $batch->archivo_sha256);
        }

        $startedAt = microtime(true);
        $book = null;
        $sheet = null;

        try {
            $workbookInfo = $this->createReader()->listWorksheetInfo($path);
            $worksheetInfo = $workbookInfo[0] ?? null;
            if (! $worksheetInfo || (int) $worksheetInfo['totalRows'] < 2) {
                throw new \RuntimeException('El libro de vigencias no contiene una hoja con filas de datos.');
            }

            $worksheetName = (string) $worksheetInfo['worksheetName'];
            $lastColumn = (string) $worksheetInfo['lastColumnLetter'];
            $totalRows = (int) $worksheetInfo['totalRows'];
            $totalColumns = (int) $worksheetInfo['totalColumns'];

            $readChunkSize = max(1, min(5000, (int) config('loc_import.credentials_read_chunk_size', 5000)));
            $totalRead = 0;
            $allCounts = [
                'nuevo' => 0,
                'nuevo_conciliado' => 0,
                'nuevo_sin_enlace' => 0,
                'actualizar' => 0,
                'sin_cambios' => 0,
                'cancelacion' => 0,
                'conflicto' => 0,
                'rechazado' => 0,
            ];
            $batchActiveAltas = [];
            $claimedPrealtaIds = [];

            $batch->update([
                'total_leidas' => 0,
                'total_aceptadas' => 0,
                'total_rechazadas' => 0,
                'total_actualizadas' => 0,
                'hoja_procesada' => $worksheetName,
                'total_columnas' => $totalColumns,
                'estado' => 'procesando',
            ]);

            for ($start = 2; $start <= $totalRows; $start += $readChunkSize) {
                $end = min($start + $readChunkSize - 1, $totalRows);
                $reader = $this->createReader();
                $reader->setReadFilter(new LocCredentialsChunkReadFilter($start, $end));
                $reader->setLoadSheetsOnly([$worksheetName]);
                $book = $reader->load($path);
                $sheet = $book->getSheetByName($worksheetName);
                if (! $sheet) {
                    throw new \RuntimeException('No fue posible abrir la hoja durante la lectura por bloques.');
                }

                // rangeToArray devuelve array 0-indexado numéricamente
                $matrix = $sheet->rangeToArray("A{$start}:{$lastColumn}{$end}", null, true, false);
                $book->disconnectWorksheets();
                unset($book, $sheet, $reader);
                gc_collect_cycles();

                $chunkRows = [];
                $rowNumbers = [];
                foreach ($matrix as $offset => $cells) {
                    $hasContent = false;
                    foreach ($cells as $val) {
                        if ($val !== null && trim((string) $val) !== '') {
                            $hasContent = true;
                            break;
                        }
                    }
                    if (! $hasContent) {
                        continue;
                    }
                    $rowNum = $start + $offset;
                    $chunkRows[] = $cells;
                    $rowNumbers[] = $rowNum;
                    $totalRead++;
                }

                if (! empty($chunkRows)) {
                    $this->processChunk(
                        $chunkRows,
                        $rowNumbers,
                        $batch,
                        $allCounts,
                        $batchActiveAltas,
                        $claimedPrealtaIds
                    );
                }

                // Actualizar progreso persistido
                $batch->update([
                    'total_leidas' => $totalRead,
                    'total_insertadas' => $allCounts['nuevo'],
                    'total_actualizadas' => $allCounts['actualizar'],
                    'total_sin_cambios' => $allCounts['sin_cambios'],
                    'total_rechazadas' => $allCounts['rechazado'] + $allCounts['conflicto'],
                    'total_aceptadas' => $allCounts['nuevo'] + $allCounts['actualizar'] + $allCounts['sin_cambios'],
                    'resumen_errores' => json_encode(array_merge($allCounts, [
                        'filas_estimadas' => $totalRows - 1,
                        'filas_leidas' => $totalRead,
                        'porcentaje' => round(($totalRead / max(1, $totalRows - 1)) * 100, 1),
                    ]), JSON_THROW_ON_ERROR),
                ]);
            }

            // Reconciliar sustituciones intra-lote (independiente del orden de filas en el Excel)
            $this->reconcileBatchSubstitutions($batch, $allCounts);

            $batch->estado = 'en_revision';
            $batch->total_leidas = $totalRead;
            $batch->total_insertadas = $allCounts['nuevo'];
            $batch->total_actualizadas = $allCounts['actualizar'];
            $batch->total_sin_cambios = $allCounts['sin_cambios'];
            $batch->total_rechazadas = $allCounts['rechazado'] + $allCounts['conflicto'];
            $batch->total_aceptadas = $allCounts['nuevo'] + $allCounts['actualizar'] + $allCounts['sin_cambios'];
            $batch->resumen_errores = json_encode(array_merge($allCounts, [
                'filas_estimadas' => $totalRows - 1,
                'filas_leidas' => $totalRead,
                'porcentaje' => 100.0,
            ]), JSON_THROW_ON_ERROR);
            $batch->revision_sha256 = $this->reviewFingerprint($batch);
            $batch->save();

            Log::info('Análisis de vigencias LOC completado.', [
                'batch_id' => $batch->id,
                'total_read' => $totalRead,
                'counts' => $allCounts,
                'duration' => round(microtime(true) - $startedAt, 3),
            ]);
        } finally {
            if ($useOleReuse) {
                LocCredentialsOleReuseReader::endScope();
            }
            gc_collect_cycles();
        }
    }

    /**
     * Procesa un array de datos en memoria (para pruebas o datasets directos).
     */
    public function stageFromRows(array $rows, LocImportBatch $batch): array
    {
        if ($batch->tipo_fuente !== self::SOURCE_TYPE) {
            throw new \RuntimeException('El lote no corresponde a la fuente de vigencias de credenciales.');
        }

        $startedAt = microtime(true);
        $totalRead = count($rows);
        $counts = [
            'nuevo' => 0,
            'nuevo_conciliado' => 0,
            'nuevo_sin_enlace' => 0,
            'actualizar' => 0,
            'sin_cambios' => 0,
            'cancelacion' => 0,
            'conflicto' => 0,
            'rechazado' => 0,
        ];
        $batchActiveAltas = [];
        $claimedPrealtaIds = [];
        $rowNumbers = range(2, $totalRead + 1);

        $this->processChunk($rows, $rowNumbers, $batch, $counts, $batchActiveAltas, $claimedPrealtaIds);

        // Reconciliar sustituciones intra-lote (independiente del orden de filas)
        $this->reconcileBatchSubstitutions($batch, $counts);

        $batch->total_leidas = $totalRead;
        $batch->total_insertadas = $counts['nuevo'];
        $batch->total_actualizadas = $counts['actualizar'];
        $batch->total_sin_cambios = $counts['sin_cambios'];
        $batch->total_rechazadas = $counts['rechazado'] + $counts['conflicto'];
        $batch->total_aceptadas = $counts['nuevo'] + $counts['actualizar'] + $counts['sin_cambios'];
        $batch->estado = 'en_revision';
        $batch->resumen_errores = json_encode($counts, JSON_THROW_ON_ERROR);
        $batch->revision_sha256 = $this->reviewFingerprint($batch);
        $batch->save();

        return [
            'total_read' => $totalRead,
            'counts' => $counts,
            'duration' => round(microtime(true) - $startedAt, 3),
        ];
    }

    /**
     * Procesa un bloque de filas resolviendo personas, armas y reglas de estatus.
     */
    public function processChunk(
        array $rows,
        array $rowNumbers,
        LocImportBatch $batch,
        array &$counts,
        array &$batchActiveAltas,
        array &$claimedPrealtaIds = []
    ): void {
        // Pre-cargar catálogos necesarios para conciliación eficiente en el bloque
        $allCuips = collect($rows)->pluck(1)->filter()->map(fn ($c) => strtoupper(trim((string) $c)))->unique()->values()->all();
        $personas = LocPersona::query()->whereIn('cuip', $allCuips)->get()->keyBy('cuip');

        $cortaMatriculas = collect($rows)->pluck(10)->map(fn ($m) => $this->normalizeSerial($m))->filter()->unique()->values()->all();
        $largaMatriculas = collect($rows)->pluck(18)->map(fn ($m) => $this->normalizeSerial($m))->filter()->unique()->values()->all();
        $allMatriculas = array_values(array_unique(array_merge($cortaMatriculas, $largaMatriculas)));

        $armas = LocArma::query()->whereIn('matricula', $allMatriculas)->get()->keyBy('matricula');

        // Consultar historial existente en base de datos para los CUIPs involucrados
        $existingHistory = LocVigenciaCredencial::query()
            ->whereIn('cuip', $allCuips)
            ->get()
            ->groupBy('cuip');

        // Consultar por número de credencial
        $allCredenciales = collect($rows)->pluck(24)->filter()->map(fn ($c) => $this->normalizeString($c))->filter()->unique()->values()->all();
        $existingByCredencial = LocVigenciaCredencial::query()
            ->whereIn('numero_credencial', $allCredenciales)
            ->get()
            ->keyBy('numero_credencial');

        $stagingRecords = [];

        foreach ($rows as $index => $rowValues) {
            $rowNumber = $rowNumbers[$index] ?? ($index + 2);
            $evaluated = $this->evaluateRow(
                $rowValues,
                $personas,
                $armas,
                $existingHistory,
                $existingByCredencial,
                $batchActiveAltas,
                $claimedPrealtaIds
            );

            $action = $evaluated['accion'];
            $counts[$action] = ($counts[$action] ?? 0) + 1;

            if ($action === 'nuevo') {
                if ($evaluated['conciliacion']['persona_resuelta']) {
                    $counts['nuevo_conciliado'] = ($counts['nuevo_conciliado'] ?? 0) + 1;
                } else {
                    $counts['nuevo_sin_enlace'] = ($counts['nuevo_sin_enlace'] ?? 0) + 1;
                }
            }

            if (($evaluated['campos']['estatus_credencial'] ?? '') === LocCredentialsPolicyService::STATUS_CANCELACION) {
                $counts['cancelacion'] = ($counts['cancelacion'] ?? 0) + 1;
            }

            if ($action === 'nuevo' && ($evaluated['campos']['estatus_credencial'] ?? '') === LocCredentialsPolicyService::STATUS_ALTA) {
                $batchActiveAltas[$evaluated['campos']['cuip']] = $evaluated['campos']['numero_credencial'] ?? 'S/N';
            }

            $stagingRecords[] = [
                'import_batch_id' => $batch->id,
                'numero_fila' => $rowNumber,
                'cuip' => $evaluated['campos']['cuip'] ?? null,
                'matricula' => $evaluated['campos']['matricula_corta'] ?? null,
                'id_armamento' => $evaluated['campos']['matricula_larga'] ?? null,
                'datos' => json_encode([
                    'campos' => $evaluated['campos'],
                    'prealta_id' => $evaluated['prealta_id'] ?? null,
                    'origen' => $evaluated['origen'],
                    'conciliacion' => $evaluated['conciliacion'],
                    'tramite_efecto' => $evaluated['tramite_efecto'],
                    'fila_hash' => $evaluated['fila_hash'],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'errores' => json_encode($evaluated['errores'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'accion' => $action,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($stagingRecords, 100) as $chunk) {
            DB::table('loc_import_staging_rows')->insert($chunk);
        }
    }

    /**
     * Evalúa una fila individual aplicando la matriz de decisión y las reglas de vinculación.
     */
    public function evaluateRow(
        array $row,
        Collection $personas,
        Collection $armas,
        Collection $existingHistory,
        Collection $existingByCredencial,
        array &$batchActiveAltas,
        array &$claimedPrealtaIds = []
    ): array {
        $rowFingerprint = $this->policy->computeRowFingerprint($row);
        $errores = [];
        $conciliacion = [
            'persona_resuelta' => false,
            'arma_corta_resuelta' => false,
            'arma_larga_resuelta' => false,
            'estado' => 'sin_enlace_total',
            'incidencias' => [],
            'advertencias' => [],
        ];

        // 1. Extracción y normalización de campos básicos
        $cuipRaw = (string) ($row[1] ?? '');
        $cuip = strtoupper(trim($cuipRaw));
        $rfc = strtoupper(trim((string) ($row[2] ?? '')));
        $nombre = trim((string) ($row[3] ?? ''));
        $categoria = trim((string) ($row[4] ?? ''));

        // Armamento Corto
        $cortaTipo = trim((string) ($row[5] ?? ''));
        $cortaClase = trim((string) ($row[6] ?? ''));
        $cortaMarca = trim((string) ($row[7] ?? ''));
        $cortaCalibre = trim((string) ($row[8] ?? ''));
        $cortaModelo = trim((string) ($row[9] ?? ''));
        $matCorta = $this->normalizeSerial($row[10] ?? null);
        $cortaFormaD = trim((string) ($row[11] ?? ''));
        $cortaResguardo = trim((string) ($row[12] ?? ''));

        // Armamento Largo
        $largaTipo = trim((string) ($row[13] ?? ''));
        $largaClase = trim((string) ($row[14] ?? ''));
        $largaMarca = trim((string) ($row[15] ?? ''));
        $largaCalibre = trim((string) ($row[16] ?? ''));
        $largaModelo = trim((string) ($row[17] ?? ''));
        $matLarga = $this->normalizeSerial($row[18] ?? null);
        $largaFormaD = trim((string) ($row[19] ?? ''));
        $largaResguardo = trim((string) ($row[20] ?? ''));

        // Gestión y Credencial
        $oficioBaja = $this->normalizeString($row[21] ?? null);
        $fechaBajaRaw = $row[22] ?? null;
        $cuerpoSeguridad = trim((string) ($row[23] ?? ''));
        $noCredencial = $this->normalizeString($row[24] ?? null);
        $oficioSol = $this->normalizeString($row[25] ?? null);
        $fechaSolRaw = $row[26] ?? null;
        $observaciones = trim((string) ($row[27] ?? ''));
        $periodoCred = trim((string) ($row[28] ?? ''));
        $estatusRaw = strtoupper(trim((string) ($row[38] ?? '')));
        $motivoSitEsp = trim((string) ($row[39] ?? ''));

        // 2. Validación de fechas (errores estructurales)
        $fechaSolicitud = $this->policy->parseDate($fechaSolRaw);
        if ($fechaSolicitud === false) {
            $errores[] = "Fecha de solicitud inválida: '{$fechaSolRaw}'. Debe tener formato DD/MM/YYYY.";
        }

        $fechaBaja = $this->policy->parseDate($fechaBajaRaw);
        if ($fechaBaja === false) {
            $errores[] = "Fecha de baja inválida: '{$fechaBajaRaw}'. Debe tener formato DD/MM/YYYY.";
        }

        if ($cuip === '') {
            $errores[] = 'El CUIP es obligatorio y no puede estar vacío.';
        }

        // 3. Resolución de identidad de la Persona
        $persona = $cuip !== '' ? $personas->get($cuip) : null;
        $personaId = null;
        $esReferenciaLoc = false;

        if ($persona !== null) {
            $personaId = $persona->id;
            $esReferenciaLoc = true;
            $conciliacion['persona_resuelta'] = true;

            // Verificación de consistencia RFC
            if ($rfc !== '' && $persona->rfc !== null && strtoupper(trim($persona->rfc)) !== $rfc) {
                $errores[] = "Discrepancia en RFC: en archivo es '{$rfc}', en padrón de personal es '{$persona->rfc}'.";
            }
        } else {
            // Incidencia informativa de conciliación (no bloquea importación del registro histórico)
            $conciliacion['advertencias'][] = 'persona_no_localizada';
            $conciliacion['incidencias'][] = "CUIP '{$cuip}' no localizado en Padrón de Personal LOC. Se conserva como registro histórico oficial no conciliado; no autorizará trámites hasta conciliar la identidad.";
        }

        // 4. Resolución de Armamento
        $armaCortaId = null;
        if ($matCorta !== null) {
            $armaCorta = $armas->get($matCorta);
            if ($armaCorta !== null) {
                $armaCortaId = $armaCorta->id;
                $conciliacion['arma_corta_resuelta'] = true;
            } else {
                $conciliacion['advertencias'][] = "arma_corta_no_encontrada: {$matCorta}";
                $conciliacion['incidencias'][] = "Arma corta matrícula '{$matCorta}' no localizada en Inventario de Armamento LOC. Se conserva la matrícula en texto.";
            }
        }

        $armaLargaId = null;
        if ($matLarga !== null) {
            $armaLarga = $armas->get($matLarga);
            if ($armaLarga !== null) {
                $armaLargaId = $armaLarga->id;
                $conciliacion['arma_larga_resuelta'] = true;
            } else {
                $conciliacion['advertencias'][] = "arma_larga_no_encontrada: {$matLarga}";
                $conciliacion['incidencias'][] = "Arma larga matrícula '{$matLarga}' no localizada en Inventario de Armamento LOC. Se conserva la matrícula en texto.";
            }
        }

        // Determinar estado global de conciliación
        if ($conciliacion['persona_resuelta']) {
            $hasUnresolvedWeapon = ($matCorta !== null && ! $conciliacion['arma_corta_resuelta'])
                || ($matLarga !== null && ! $conciliacion['arma_larga_resuelta']);
            $conciliacion['estado'] = $hasUnresolvedWeapon ? 'sin_enlace_armas' : 'conciliado';
        } else {
            $hasResolvedWeapon = $conciliacion['arma_corta_resuelta'] || $conciliacion['arma_larga_resuelta'];
            $conciliacion['estado'] = $hasResolvedWeapon ? 'sin_enlace_personal' : 'sin_enlace_total';
        }

        // 5. Validación de estatus oficial (error estructural si no es válido)
        if (! in_array($estatusRaw, LocCredentialsPolicyService::VALID_STATUSES, true)) {
            $errores[] = "Estatus de credencial no reconocido ('{$estatusRaw}'). Requiere revisión y asignación manual.";
        }

        if ($estatusRaw === LocCredentialsPolicyService::STATUS_CANCELACION) {
            $conciliacion['advertencias'][] = 'requiere_revision_cancelacion';
            $conciliacion['incidencias'][] = 'Credencial con estatus CANCELACIÓN: se conserva en historial registral; requerirá revisión manual en solicitudes de trámite.';
        }

        // 6. Clasificación según la Matriz de Decisión
        $accion = 'nuevo';
        $prealtaId = null;

        if ($fechaSolicitud === false || $fechaBaja === false || $cuip === '') {
            $accion = 'rechazado';
        } elseif (! in_array($estatusRaw, LocCredentialsPolicyService::VALID_STATUSES, true) || ! empty($errores)) {
            $accion = 'conflicto';
        } else {
            $userHistory = $existingHistory->get($cuip, collect());
            $exactDuplicate = $userHistory->first(function ($item) use ($rowFingerprint, $noCredencial, $estatusRaw) {
                if ($item->fila_hash === $rowFingerprint) {
                    return true;
                }
                if ($noCredencial !== null && $item->numero_credencial === $noCredencial && $item->estatus_credencial === $estatusRaw) {
                    return true;
                }

                return false;
            });

            if ($exactDuplicate !== null) {
                $accion = 'sin_cambios';
            } elseif ($noCredencial !== null && $existingByCredencial->has($noCredencial)) {
                $existingCred = $existingByCredencial->get($noCredencial);
                if ($existingCred->cuip !== $cuip) {
                    $reconciled = $this->reconcileCredentialIdentity($existingCred, $row, $cuip, $personas);
                    if ($reconciled['status'] === 'reconciliado') {
                        $conciliacion['cuip_anterior'] = $existingCred->cuip;
                        $conciliacion['cuip_nuevo'] = $cuip;
                        $conciliacion['conciliacion_identidad'] = true;
                        $conciliacion['criterio_conciliacion_identidad'] = $reconciled['criterio'];

                        if ($personaId === null && ! empty($reconciled['persona_id'])) {
                            $personaId = $reconciled['persona_id'];
                            $esReferenciaLoc = (bool) ($reconciled['es_referencia_loc'] ?? true);
                            $conciliacion['persona_resuelta'] = true;
                        }

                        if ($existingCred->estatus_credencial === $estatusRaw) {
                            $accion = 'actualizar';
                        } elseif ($this->policy->canTransitionStatus($existingCred->estatus_credencial, $estatusRaw)) {
                            $accion = 'actualizar';
                        } else {
                            $errores[] = "Transición no permitida: de '{$existingCred->estatus_credencial}' a '{$estatusRaw}' para la credencial '{$noCredencial}'.";
                            $accion = 'conflicto';
                        }
                    } else {
                        $errores[] = $reconciled['error'] ?? "El número de credencial '{$noCredencial}' ya está asignado a otro CUIP en la base de datos con identidad divergente.";
                        $accion = 'conflicto';
                    }
                } elseif ($existingCred->estatus_credencial === $estatusRaw) {
                    $accion = 'sin_cambios';
                } elseif ($this->policy->canTransitionStatus($existingCred->estatus_credencial, $estatusRaw)) {
                    $accion = 'actualizar';
                } else {
                    $errores[] = "Transición no permitida: de '{$existingCred->estatus_credencial}' a '{$estatusRaw}' para la credencial '{$noCredencial}'.";
                    $accion = 'conflicto';
                }
            } else {
                // Verificar si corresponde a una prealta existente (sin número de credencial formal en base)
                $matchedPrealta = null;
                $prealtas = $userHistory->filter(function ($item) use ($claimedPrealtaIds) {
                    return empty($item->numero_credencial)
                        && $item->estatus_credencial === LocCredentialsPolicyService::STATUS_ALTA
                        && ! isset($claimedPrealtaIds[$item->id]);
                });

                if ($estatusRaw === LocCredentialsPolicyService::STATUS_ALTA && $prealtas->isNotEmpty()) {
                    if ($prealtas->count() === 1) {
                        $cand = $prealtas->first();
                        $matchOficio = $oficioSol !== null && ! empty($cand->numero_oficio) && strtoupper(trim((string) $cand->numero_oficio)) === strtoupper(trim($oficioSol));
                        $matchFecha = $fechaSolicitud !== null && $fechaSolicitud !== false && ! empty($cand->fecha_solicitud) && Carbon::parse($cand->fecha_solicitud)->format('Y-m-d') === $fechaSolicitud;
                        $matchArma = (! empty($matCorta) && ! empty($cand->matricula_corta) && strtoupper(trim((string) $cand->matricula_corta)) === strtoupper(trim($matCorta)))
                            || (! empty($matLarga) && ! empty($cand->matricula_larga) && strtoupper(trim((string) $cand->matricula_larga)) === strtoupper(trim($matLarga)));

                        if ($matchOficio || $matchFecha || $matchArma) {
                            $conflictOficio = $oficioSol !== null && ! empty($cand->numero_oficio) && strtoupper(trim((string) $cand->numero_oficio)) !== strtoupper(trim($oficioSol));
                            $conflictFecha = $fechaSolicitud !== null && $fechaSolicitud !== false && ! empty($cand->fecha_solicitud) && Carbon::parse($cand->fecha_solicitud)->format('Y-m-d') !== $fechaSolicitud;

                            if (! ($conflictOficio && $conflictFecha)) {
                                $matchedPrealta = $cand;
                            }
                        } elseif (empty($cand->numero_oficio) && empty($cand->fecha_solicitud)) {
                            $errores[] = "Prealta ambigua para CUIP '{$cuip}': el registro existente sin número de credencial no contiene oficio ni fecha para validar coincidencia.";
                            $accion = 'conflicto';
                        }
                    } else {
                        // Múltiples prealtas para el mismo CUIP
                        $matchedCandidates = $prealtas->filter(function ($cand) use ($oficioSol, $fechaSolicitud, $matCorta, $matLarga) {
                            $matchOficio = $oficioSol !== null && ! empty($cand->numero_oficio) && strtoupper(trim((string) $cand->numero_oficio)) === strtoupper(trim($oficioSol));
                            $matchFecha = $fechaSolicitud !== null && $fechaSolicitud !== false && ! empty($cand->fecha_solicitud) && Carbon::parse($cand->fecha_solicitud)->format('Y-m-d') === $fechaSolicitud;
                            $matchArma = (! empty($matCorta) && ! empty($cand->matricula_corta) && strtoupper(trim((string) $cand->matricula_corta)) === strtoupper(trim($matCorta)))
                                || (! empty($matLarga) && ! empty($cand->matricula_larga) && strtoupper(trim((string) $cand->matricula_larga)) === strtoupper(trim($matLarga)));

                            return $matchOficio || $matchFecha || $matchArma;
                        });

                        if ($matchedCandidates->count() === 1) {
                            $matchedPrealta = $matchedCandidates->first();
                        } elseif ($matchedCandidates->count() > 1) {
                            $errores[] = "Prealta ambigua para CUIP '{$cuip}': existen múltiples registros sin número de credencial coincidentes.";
                            $accion = 'conflicto';
                        }
                    }

                    if ($matchedPrealta !== null) {
                        $accion = 'actualizar';
                        $prealtaId = $matchedPrealta->id;
                        $claimedPrealtaIds[$matchedPrealta->id] = true;
                    }
                }

                if ($accion !== 'actualizar' && $accion !== 'conflicto') {
                    $hasActiveAltaInDb = $userHistory->contains(function ($item) use ($claimedPrealtaIds) {
                        if (isset($claimedPrealtaIds[$item->id])) {
                            return false;
                        }

                        return $item->estatus_credencial === LocCredentialsPolicyService::STATUS_ALTA && empty($item->fecha_baja);
                    });

                    if ($estatusRaw === LocCredentialsPolicyService::STATUS_ALTA && ($hasActiveAltaInDb || isset($batchActiveAltas[$cuip]))) {
                        $activeFolio = $batchActiveAltas[$cuip] ?? 'Preexistente en base';
                        $errores[] = "Contradicción: ya existe una credencial activa con estatus ALTA para el CUIP '{$cuip}' (Folio: {$activeFolio}).";
                        $accion = 'conflicto';
                    } elseif ($estatusRaw === LocCredentialsPolicyService::STATUS_SITUACION_ESPECIAL) {
                        $conciliacion['advertencias'][] = 'situacion_especial_activa';
                        $accion = 'nuevo';
                    } else {
                        $accion = 'nuevo';
                    }
                }
            }
        }

        $tramiteEfecto = $this->policy->evaluateProcedureEffect(
            $estatusRaw,
            $conciliacion['persona_resuelta'],
            $fechaBaja ?: null
        );

        $campos = [
            'persona_id' => $personaId,
            'arma_id' => $armaCortaId ?? $armaLargaId,
            'arma_corta_id' => $armaCortaId,
            'arma_larga_id' => $armaLargaId,
            'cuip' => $cuip,
            'matricula' => $matCorta ?? $matLarga,
            'matricula_corta' => $matCorta,
            'matricula_larga' => $matLarga,
            'tipo_credencial' => 'CREDENCIAL DE VIGENCIA',
            'periodo_credencial' => $periodoCred !== '' ? $periodoCred : 'VIGENCIA 2025-2027',
            'numero_oficio' => $oficioSol,
            'numero_oficio_baja' => $oficioBaja,
            'numero_credencial' => $noCredencial,
            'estatus_credencial' => $estatusRaw,
            'fecha_solicitud' => $fechaSolicitud ?: null,
            'fecha_baja' => $fechaBaja ?: null,
            'motivo_situacion_especial' => $motivoSitEsp !== '' ? $motivoSitEsp : null,
            'cuerpo_seguridad' => $cuerpoSeguridad !== '' ? $cuerpoSeguridad : null,
            'agrupamiento' => trim((string) ($row[30] ?? '')),
            'region' => trim((string) ($row[31] ?? '')),
            'municipio' => trim((string) ($row[32] ?? '')),
            'observaciones' => $observaciones !== '' ? $observaciones : null,
            'es_referencia_loc' => $esReferenciaLoc,
            'fila_hash' => $rowFingerprint,
        ];

        return [
            'accion' => $accion,
            'campos' => $campos,
            'prealta_id' => $prealtaId,
            'origen' => $row,
            'conciliacion' => $conciliacion,
            'tramite_efecto' => $tramiteEfecto,
            'errores' => $errores,
            'fila_hash' => $rowFingerprint,
        ];
    }

    /**
     * Aplica la confirmación atómica del lote cuando está libre de bloqueos.
     */
    public function confirm(LocImportBatch $batch): array
    {
        return DB::transaction(function () use ($batch): array {
            $lockedBatch = LocImportBatch::query()->where('id', $batch->id)->lockForUpdate()->firstOrFail();

            if ($lockedBatch->tipo_fuente !== self::SOURCE_TYPE || ! in_array($lockedBatch->estado, ['en_revision', 'completado'], true)) {
                throw new \RuntimeException('El lote no se encuentra en estado de revisión para confirmación.');
            }

            if ($lockedBatch->estado === 'completado') {
                $pendingCount = LocImportStagingRow::query()
                    ->where('import_batch_id', $lockedBatch->id)
                    ->where('estado_aplicacion', 'pendiente')
                    ->count();

                if ($pendingCount === 0) {
                    return [
                        'insertados' => $lockedBatch->total_insertadas,
                        'actualizados' => $lockedBatch->total_actualizadas,
                        'sin_cambios' => $lockedBatch->total_sin_cambios,
                        'ya_confirmado' => true,
                    ];
                }
                // Si el lote figuraba como completado pero aún contiene filas pendientes (por ejemplo,
                // debido a una confirmación previa interrumpida o parcial), continuamos para procesar
                // las filas pendientes restantes sin duplicar las ya insertadas.
            }

            // Validar huella de revisión
            if (!hash_equals($lockedBatch->revision_sha256 ?? '', $this->reviewFingerprint($lockedBatch))) {
                throw new \RuntimeException('La revisión del lote cambió después del análisis. Vuelve a analizar el archivo antes de confirmar.');
            }

            // Comprobar que no existan filas rechazadas o en conflicto
            $conflictCount = LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->whereIn('accion', ['conflicto', 'rechazado'])
                ->count();

            if ($conflictCount > 0) {
                throw new \RuntimeException("El lote contiene {$conflictCount} filas rechazadas o en conflicto. No se aplicó ningún cambio.");
            }

            $inserted = 0;
            $updated = 0;
            $timestamp = now();

            // 1. Procesar inserciones ('nuevo') usando chunkById para evitar saltos por cambio en el filtro
            LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', 'nuevo')
                ->where('estado_aplicacion', 'pendiente')
                ->chunkById(250, function ($rows) use ($lockedBatch, $timestamp, &$inserted) {
                    $records = [];
                    $stagedIdsToMark = [];

                    // Deduplicación técnica: consultar huellas existentes en loc_vigencias_credenciales para este lote
                    $rowHashes = [];
                    foreach ($rows as $row) {
                        $data = is_array($row->datos) ? $row->datos : json_decode((string) $row->datos, true);
                        $hash = $data['fila_hash'] ?? $data['campos']['fila_hash'] ?? null;
                        if ($hash) {
                            $rowHashes[$row->id] = $hash;
                        }
                    }

                    $existingHashes = !empty($rowHashes)
                        ? DB::table('loc_vigencias_credenciales')
                            ->where('import_batch_id', $lockedBatch->id)
                            ->whereIn('fila_hash', array_values($rowHashes))
                            ->pluck('fila_hash')
                            ->flip()
                            ->all()
                        : [];

                    foreach ($rows as $row) {
                        $data = is_array($row->datos) ? $row->datos : json_decode((string) $row->datos, true);
                        $fields = $data['campos'] ?? [];
                        $rowHash = $rowHashes[$row->id] ?? null;

                        // Si ya existe en la base operativa para este lote, no duplicar; sólo sincronizar estado de staging
                        if ($rowHash && isset($existingHashes[$rowHash])) {
                            $stagedIdsToMark[] = $row->id;
                            continue;
                        }

                        unset($fields['prealta_id']);
                        $fields['import_batch_id'] = $lockedBatch->id;
                        $fields['datos_origen'] = json_encode($data['origen'] ?? [], JSON_UNESCAPED_UNICODE);
                        $fields['created_at'] = $timestamp;
                        $fields['updated_at'] = $timestamp;
                        $records[] = $fields;
                        $stagedIdsToMark[] = $row->id;
                    }

                    if (!empty($records)) {
                        DB::table('loc_vigencias_credenciales')->insert($records);
                        $inserted += count($records);
                    }

                    if (!empty($stagedIdsToMark)) {
                        DB::table('loc_import_staging_rows')
                            ->whereIn('id', $stagedIdsToMark)
                            ->update(['estado_aplicacion' => 'insertado', 'updated_at' => $timestamp]);
                    }
                });

            // 2. Procesar actualizaciones ('actualizar') usando chunkById
            LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', 'actualizar')
                ->where('estado_aplicacion', 'pendiente')
                ->chunkById(100, function ($rows) use ($timestamp, &$updated, $lockedBatch) {
                    foreach ($rows as $row) {
                        $data = is_array($row->datos) ? $row->datos : json_decode((string) $row->datos, true);
                        $fields = $data['campos'] ?? [];
                        $prealtaId = $data['prealta_id'] ?? $fields['prealta_id'] ?? null;

                        $updateData = [
                            'estatus_credencial' => $fields['estatus_credencial'] ?? LocCredentialsPolicyService::STATUS_ALTA,
                            'fecha_baja' => $fields['fecha_baja'] ?? null,
                            'numero_oficio_baja' => $fields['numero_oficio_baja'] ?? null,
                            'motivo_situacion_especial' => $fields['motivo_situacion_especial'] ?? null,
                            'observaciones' => $fields['observaciones'] ?? null,
                            'updated_at' => $timestamp,
                        ];

                        if (! empty($fields['numero_credencial'])) {
                            $updateData['numero_credencial'] = $fields['numero_credencial'];
                        }
                        if (! empty($fields['fecha_solicitud'])) {
                            $updateData['fecha_solicitud'] = $fields['fecha_solicitud'];
                        }
                        if (! empty($fields['numero_oficio'])) {
                            $updateData['numero_oficio'] = $fields['numero_oficio'];
                        }
                        if (! empty($fields['periodo_credencial'])) {
                            $updateData['periodo_credencial'] = $fields['periodo_credencial'];
                        }
                        if (! empty($fields['cuerpo_seguridad'])) {
                            $updateData['cuerpo_seguridad'] = $fields['cuerpo_seguridad'];
                        }
                        if (! empty($fields['agrupamiento'])) {
                            $updateData['agrupamiento'] = $fields['agrupamiento'];
                        }
                        if (! empty($fields['region'])) {
                            $updateData['region'] = $fields['region'];
                        }
                        if (! empty($fields['municipio'])) {
                            $updateData['municipio'] = $fields['municipio'];
                        }
                        if (! empty($fields['arma_id'])) {
                            $updateData['arma_id'] = $fields['arma_id'];
                        }
                        if (! empty($fields['arma_corta_id'])) {
                            $updateData['arma_corta_id'] = $fields['arma_corta_id'];
                        }
                        if (! empty($fields['arma_larga_id'])) {
                            $updateData['arma_larga_id'] = $fields['arma_larga_id'];
                        }
                        if (! empty($fields['matricula'])) {
                            $updateData['matricula'] = $fields['matricula'];
                        }
                        if (! empty($fields['matricula_corta'])) {
                            $updateData['matricula_corta'] = $fields['matricula_corta'];
                        }
                        if (! empty($fields['matricula_larga'])) {
                            $updateData['matricula_larga'] = $fields['matricula_larga'];
                        }
                        if (! empty($data['origen'])) {
                            $updateData['datos_origen'] = json_encode($data['origen'], JSON_UNESCAPED_UNICODE);
                        }

                        if (! empty($prealtaId)) {
                            $targetPrealta = DB::table('loc_vigencias_credenciales')
                                ->where('id', $prealtaId)
                                ->lockForUpdate()
                                ->first();

                            if (! $targetPrealta) {
                                throw new \RuntimeException("Fallo de integridad: No se localizó la prealta ID {$prealtaId} para el CUIP '{$fields['cuip']}'.");
                            }

                            DB::table('loc_vigencias_credenciales')
                                ->where('id', $targetPrealta->id)
                                ->update($updateData);

                            $updated++;
                        } elseif (! empty($fields['numero_credencial'])) {
                            $conciliacion = $data['conciliacion'] ?? [];
                            $cuipAnterior = $conciliacion['cuip_anterior'] ?? null;

                            $query = DB::table('loc_vigencias_credenciales')
                                ->where('numero_credencial', $fields['numero_credencial']);

                            if (! empty($cuipAnterior) && $cuipAnterior !== $fields['cuip']) {
                                $query->where(function ($q) use ($fields, $cuipAnterior) {
                                    $q->where('cuip', $fields['cuip'])
                                      ->orWhere('cuip', $cuipAnterior);
                                });
                            } else {
                                $query->where('cuip', $fields['cuip']);
                            }

                            $targets = $query->lockForUpdate()->get();

                            $matchCount = $targets->count();

                            if ($matchCount === 0) {
                                throw new \RuntimeException("Fallo de integridad: No se localizó la credencial '{$fields['numero_credencial']}' para el CUIP '{$fields['cuip']}'.");
                            }

                            if ($matchCount > 1) {
                                throw new \RuntimeException("Fallo de integridad: Ambigüedad en actualización; existen {$matchCount} registros operativos con la credencial '{$fields['numero_credencial']}' y CUIP '{$fields['cuip']}'.");
                            }

                            $targetRecord = $targets->first();
                            $existingOrigen = is_array($targetRecord->datos_origen)
                                ? $targetRecord->datos_origen
                                : (json_decode((string) ($targetRecord->datos_origen ?? ''), true) ?: []);

                            $origen = is_array($data['origen'] ?? null) ? $data['origen'] : [];
                            $historialCuip = $existingOrigen['_trazabilidad_cuip'] ?? [];
                            if (! is_array($historialCuip)) {
                                $historialCuip = [];
                            }

                            if (! empty($cuipAnterior) && $cuipAnterior !== $fields['cuip']) {
                                $updateData['cuip'] = $fields['cuip'];

                                $alreadyRecorded = false;
                                foreach ($historialCuip as $entry) {
                                    if (($entry['cuip_anterior'] ?? null) === $cuipAnterior
                                        && ($entry['cuip_nuevo'] ?? null) === $fields['cuip']
                                        && ($entry['import_batch_id'] ?? null) === $lockedBatch->id) {
                                        $alreadyRecorded = true;
                                        break;
                                    }
                                }

                                if (! $alreadyRecorded) {
                                    $historialCuip[] = [
                                        'cuip_anterior' => $cuipAnterior,
                                        'cuip_nuevo' => $fields['cuip'],
                                        'criterio' => $conciliacion['criterio_conciliacion_identidad'] ?? 'conciliacion_identidad_credencial',
                                        'import_batch_id' => $lockedBatch->id,
                                        'numero_fila' => $row->numero_fila,
                                        'fecha' => now()->toIso8601String(),
                                    ];
                                }
                            }

                            if (! empty($historialCuip)) {
                                $origen['_trazabilidad_cuip'] = $historialCuip;
                            }
                            $updateData['datos_origen'] = json_encode($origen, JSON_UNESCAPED_UNICODE);

                            if (! empty($fields['persona_id'])) {
                                $updateData['persona_id'] = $fields['persona_id'];
                                $updateData['es_referencia_loc'] = (bool) ($fields['es_referencia_loc'] ?? true);
                            }

                            DB::table('loc_vigencias_credenciales')
                                ->where('id', $targetRecord->id)
                                ->update($updateData);

                            $updated++;
                        } else {
                            throw new \RuntimeException("Fallo de integridad: Fila de actualización sin identificador unívoco para el CUIP '{$fields['cuip']}'.");
                        }
                    }
                    DB::table('loc_import_staging_rows')
                        ->whereIn('id', $rows->pluck('id'))
                        ->update(['estado_aplicacion' => 'actualizado', 'updated_at' => $timestamp]);
                });

            // 3. Marcar filas sin cambios
            LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', 'sin_cambios')
                ->update(['estado_aplicacion' => 'sin_cambios', 'updated_at' => $timestamp]);

            // 4. Marcar filas omitidas formalmente
            LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', self::ACTION_OMITIDO)
                ->update(['estado_aplicacion' => self::STATUS_APLICACION_OMITIDO, 'updated_at' => $timestamp]);

            // 5. Validaciones post-confirmación: completitud e integridad estricta
            $remainingPending = LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('estado_aplicacion', 'pendiente')
                ->count();

            if ($remainingPending > 0) {
                throw new \RuntimeException("La confirmación quedó incompleta: existen {$remainingPending} filas con estado_aplicacion 'pendiente'. No se marcó el lote como completado.");
            }

            // Verificar que ninguna fila formalmente omitida haya sido insertada en la tabla operativa
            $omittedHashes = LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', self::ACTION_OMITIDO)
                ->get()
                ->map(function ($row) {
                    $d = is_array($row->datos) ? $row->datos : json_decode((string) $row->datos, true);
                    return $d['fila_hash'] ?? $d['campos']['fila_hash'] ?? null;
                })
                ->filter()
                ->values()
                ->all();

            if (!empty($omittedHashes)) {
                $omittedInDb = DB::table('loc_vigencias_credenciales')
                    ->where('import_batch_id', $lockedBatch->id)
                    ->whereIn('fila_hash', $omittedHashes)
                    ->count();

                if ($omittedInDb > 0) {
                    throw new \RuntimeException("Violación de integridad: se detectaron {$omittedInDb} registros omitidos insertados en la tabla operativa.");
                }
            }

            // Total real de registros operativos insertados para este lote
            $totalOperativas = DB::table('loc_vigencias_credenciales')
                ->where('import_batch_id', $lockedBatch->id)
                ->count();

            $totalNuevasStaging = LocImportStagingRow::query()
                ->where('import_batch_id', $lockedBatch->id)
                ->where('accion', 'nuevo')
                ->where('estado_aplicacion', 'insertado')
                ->count();

            if ($totalOperativas !== $totalNuevasStaging) {
                throw new \RuntimeException("Discrepancia en confirmación: staging reporta {$totalNuevasStaging} filas insertadas pero loc_vigencias_credenciales contiene {$totalOperativas}.");
            }

            // Finalizar lote con fecha_confirmacion y totales efectivamente insertados
            $lockedBatch->estado = 'completado';
            $lockedBatch->total_insertadas = $totalOperativas;
            $lockedBatch->total_actualizadas = $updated;
            $lockedBatch->fecha_confirmacion = $lockedBatch->fecha_confirmacion ?? $timestamp;
            $lockedBatch->fecha_procesamiento = $timestamp;
            $lockedBatch->save();

            return [
                'insertados' => $totalOperativas,
                'actualizados' => $updated,
                'sin_cambios' => $lockedBatch->total_sin_cambios,
            ];
        });
    }

    /**
     * Realiza una auditoría exhaustiva de solo lectura entre el staging de un lote y la tabla operativa.
     * Categoriza cada fila en: insertadas verificadas, válidas pendientes, omitidas verificadas,
     * sin cambios verificadas, actualizadas verificadas y discrepancias detectadas.
     *
     * @return array{
     *     lote_id: int,
     *     lote_estado: string,
     *     staging_total: int,
     *     staging_por_accion: array<string, int>,
     *     staging_por_estado_aplicacion: array<string, int>,
     *     insertadas_verificadas: int,
     *     validas_pendientes: int,
     *     omitidas_verificadas: int,
     *     sin_cambios_verificadas: int,
     *     actualizadas_verificadas: int,
     *     operativa_total_lote: int,
     *     operativa_hashes_unicos: int,
     *     discrepancias: array<int, array{
     *         tipo: string,
     *         numero_fila: int,
     *         cuip: ?string,
     *         fila_hash: ?string,
     *         descripcion: string
     *     }>,
     *     es_reanudar_seguro: bool
     * }
     */
    public function auditBatchApplicationState(LocImportBatch $batch): array
    {
        $stagingTotal = LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->count();

        $actionCounts = LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->selectRaw('accion, count(*) as total')
            ->groupBy('accion')
            ->pluck('total', 'accion')
            ->all();

        $estadoAplicacionCounts = LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->selectRaw('estado_aplicacion, count(*) as total')
            ->groupBy('estado_aplicacion')
            ->pluck('total', 'estado_aplicacion')
            ->all();

        // Registros operativos existentes para este lote
        $operationalRecords = DB::table('loc_vigencias_credenciales')
            ->where('import_batch_id', $batch->id)
            ->select(['id', 'fila_hash', 'cuip', 'numero_credencial'])
            ->get();

        $operativaTotal = $operationalRecords->count();
        $operationalHashesGrouped = $operationalRecords->groupBy('fila_hash');
        $operativaHashesUnicos = $operationalHashesGrouped->count();

        $discrepancies = [];

        // Detectar si existen registros operativos duplicados para este lote
        foreach ($operationalHashesGrouped as $hash => $group) {
            if ($group->count() > 1 && $hash !== '') {
                $discrepancies[] = [
                    'tipo' => 'duplicado_operativo',
                    'numero_fila' => 0,
                    'cuip' => $group->first()->cuip ?? null,
                    'fila_hash' => (string) $hash,
                    'descripcion' => "El hash de fila '{$hash}' aparece {$group->count()} veces en loc_vigencias_credenciales para este lote.",
                ];
            }
        }

        $insertadasVerificadas = 0;
        $validasPendientes = 0;
        $omitidasVerificadas = 0;
        $sinCambiosVerificadas = 0;
        $actualizadasVerificadas = 0;

        LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->chunkById(500, function ($rows) use (
                $operationalHashesGrouped,
                &$insertadasVerificadas,
                &$validasPendientes,
                &$omitidasVerificadas,
                &$sinCambiosVerificadas,
                &$actualizadasVerificadas,
                &$discrepancies
            ) {
                foreach ($rows as $row) {
                    $data = is_array($row->datos) ? $row->datos : json_decode((string) $row->datos, true);
                    $hash = $data['fila_hash'] ?? $data['campos']['fila_hash'] ?? null;
                    $existsInDb = $hash ? $operationalHashesGrouped->has($hash) : false;

                    if ($row->accion === self::ACTION_OMITIDO) {
                        if ($existsInDb) {
                            $discrepancies[] = [
                                'tipo' => 'omitido_en_operativa',
                                'numero_fila' => $row->numero_fila,
                                'cuip' => $row->cuip,
                                'fila_hash' => $hash,
                                'descripcion' => "Fila formalmente omitida #{$row->numero_fila} (CUIP: {$row->cuip}) figura insertada en loc_vigencias_credenciales.",
                            ];
                        } else {
                            $omitidasVerificadas++;
                        }
                    } elseif ($row->accion === 'nuevo') {
                        if ($row->estado_aplicacion === 'insertado') {
                            if ($existsInDb) {
                                $insertadasVerificadas++;
                            } else {
                                $discrepancies[] = [
                                    'tipo' => 'staging_insertado_pero_no_en_operativa',
                                    'numero_fila' => $row->numero_fila,
                                    'cuip' => $row->cuip,
                                    'fila_hash' => $hash,
                                    'descripcion' => "Fila #{$row->numero_fila} figura como 'insertado' en staging pero no existe en loc_vigencias_credenciales.",
                                ];
                            }
                        } elseif ($row->estado_aplicacion === 'pendiente') {
                            if ($existsInDb) {
                                $discrepancies[] = [
                                    'tipo' => 'staging_pendiente_pero_ya_en_operativa',
                                    'numero_fila' => $row->numero_fila,
                                    'cuip' => $row->cuip,
                                    'fila_hash' => $hash,
                                    'descripcion' => "Fila #{$row->numero_fila} figura como 'pendiente' en staging pero ya existe en loc_vigencias_credenciales.",
                                ];
                            } else {
                                $validasPendientes++;
                            }
                        }
                    } elseif ($row->accion === 'sin_cambios') {
                        $sinCambiosVerificadas++;
                    } elseif ($row->accion === 'actualizar') {
                        if ($row->estado_aplicacion === 'actualizado') {
                            $actualizadasVerificadas++;
                        } elseif ($row->estado_aplicacion === 'pendiente') {
                            $validasPendientes++;
                        }
                    } elseif (in_array($row->accion, ['conflicto', 'rechazado'], true)) {
                        $discrepancies[] = [
                            'tipo' => 'conflicto_no_resuelto',
                            'numero_fila' => $row->numero_fila,
                            'cuip' => $row->cuip,
                            'fila_hash' => $hash,
                            'descripcion' => "Fila #{$row->numero_fila} tiene estado '{$row->accion}' no resuelto ni omitido.",
                        ];
                    }
                }
            });

        $blockingDiscrepancies = array_filter($discrepancies, function ($d) {
            return in_array($d['tipo'], ['duplicado_operativo', 'omitido_en_operativa', 'conflicto_no_resuelto'], true);
        });

        return [
            'lote_id' => $batch->id,
            'lote_estado' => $batch->estado,
            'staging_total' => $stagingTotal,
            'staging_por_accion' => $actionCounts,
            'staging_por_estado_aplicacion' => $estadoAplicacionCounts,
            'insertadas_verificadas' => $insertadasVerificadas,
            'validas_pendientes' => $validasPendientes,
            'omitidas_verificadas' => $omitidasVerificadas,
            'sin_cambios_verificadas' => $sinCambiosVerificadas,
            'actualizadas_verificadas' => $actualizadasVerificadas,
            'operativa_total_lote' => $operativaTotal,
            'operativa_hashes_unicos' => $operativaHashesUnicos,
            'discrepancias' => $discrepancies,
            'es_reanudar_seguro' => empty($blockingDiscrepancies),
        ];
    }

    /**
     * Reanuda de forma segura la confirmación de un lote parcialmente aplicado.
     * Inserta exclusivamente las filas pendientes que no existan previamente en la tabla operativa,
     * sincroniza el staging y finaliza el lote solo tras verificación exhaustiva.
     */
    public function resumeConfirmation(LocImportBatch $batch): array
    {
        return DB::transaction(function () use ($batch): array {
            $lockedBatch = LocImportBatch::query()->where('id', $batch->id)->lockForUpdate()->firstOrFail();

            if ($lockedBatch->tipo_fuente !== self::SOURCE_TYPE) {
                throw new \RuntimeException('El lote no corresponde a vigencias de credenciales.');
            }

            // Realizar auditoría exhaustiva previa
            $audit = $this->auditBatchApplicationState($lockedBatch);

            if (! $audit['es_reanudar_seguro']) {
                $reasons = array_column($audit['discrepancias'], 'descripcion');
                throw new \RuntimeException('No es posible reanudar el lote debido a inconsistencias bloqueantes: ' . implode('; ', array_slice($reasons, 0, 3)));
            }

            // Si hay filas marcadas 'pendiente' en staging que ya existían en BD operativa,
            // sincronizar su estado a 'insertado' para no reinsertarlas
            $discrepantPendingInDb = array_filter($audit['discrepancias'], fn ($d) => $d['tipo'] === 'staging_pendiente_pero_ya_en_operativa');
            if (!empty($discrepantPendingInDb)) {
                $pendingRowNumbers = array_column($discrepantPendingInDb, 'numero_fila');
                LocImportStagingRow::query()
                    ->where('import_batch_id', $lockedBatch->id)
                    ->whereIn('numero_fila', $pendingRowNumbers)
                    ->update(['estado_aplicacion' => 'insertado', 'updated_at' => now()]);
            }

            // Ejecutar la confirmación de las filas pendientes restantes
            return $this->confirm($lockedBatch);
        });
    }

    /**
     * Excluye formalmente una fila de staging registrando trazabilidad completa.
     */
    public function omitStagingRow(LocImportStagingRow $row, string $motivo, ?int $usuarioId = null): void
    {
        DB::transaction(function () use ($row, $motivo, $usuarioId) {
            $lockedRow = LocImportStagingRow::query()->where('id', $row->id)->lockForUpdate()->firstOrFail();
            $batch = LocImportBatch::query()->where('id', $lockedRow->import_batch_id)->lockForUpdate()->firstOrFail();

            if (! in_array($batch->estado, ['en_revision', 'pendiente'], true)) {
                throw new \RuntimeException('Solo se pueden excluir filas de un lote en revisión.');
            }

            $datos = is_array($lockedRow->datos) ? $lockedRow->datos : json_decode((string) $lockedRow->datos, true);
            $accionPrevia = $lockedRow->accion;

            $datos['exclusion'] = [
                'motivo' => trim($motivo),
                'accion_previa' => $accionPrevia,
                'fecha' => now()->toDateTimeString(),
                'usuario_id' => $usuarioId,
            ];

            $lockedRow->update([
                'accion' => self::ACTION_OMITIDO,
                'datos' => $datos,
                'updated_at' => now(),
            ]);

            $summary = json_decode((string) $batch->resumen_errores, true) ?: [];
            $summary['filas_omitidas'] = $summary['filas_omitidas'] ?? [];
            $summary['filas_omitidas'][] = [
                'numero_fila' => $lockedRow->numero_fila,
                'motivo' => trim($motivo),
                'usuario_id' => $usuarioId,
                'fecha' => now()->toDateTimeString(),
            ];

            $stagingCounts = LocImportStagingRow::query()
                ->where('import_batch_id', $batch->id)
                ->selectRaw('accion, COUNT(*) as total')
                ->groupBy('accion')
                ->pluck('total', 'accion')
                ->all();

            $batch->total_rechazadas = ($stagingCounts['conflicto'] ?? 0) + ($stagingCounts['rechazado'] ?? 0);
            $batch->resumen_errores = json_encode(array_merge($summary, [
                'conflicto' => $stagingCounts['conflicto'] ?? 0,
                'rechazado' => $stagingCounts['rechazado'] ?? 0,
                'omitido' => $stagingCounts[self::ACTION_OMITIDO] ?? 0,
            ]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $batch->revision_sha256 = $this->reviewFingerprint($batch);
            $batch->save();
        });
    }

    public function commit(LocImportBatch $batch, int $userId): array
    {
        $result = $this->confirm($batch);

        return [
            'inserted' => $result['insertados'] ?? 0,
            'updated' => $result['actualizados'] ?? 0,
            'unchanged' => $result['sin_cambios'] ?? 0,
            'rejected' => (int) $batch->total_rechazadas,
        ];
    }

    /**
     * Reconcilia sustituciones intra-lote donde un CUIP recibe una BAJA de una credencial anterior
     * y un ALTA de reemplazo con nuevo folio, independientemente del orden de las filas en el archivo.
     * Utiliza un emparejamiento bipartito determinista de máxima cardinalidad para resolver casos
     * con múltiples bajas y múltiples altas cruzadas.
     */
    public function reconcileBatchSubstitutions(LocImportBatch $batch, array &$counts): void
    {
        // Buscar filas en conflicto exclusivo por contradicción de alta activa con orden determinista
        $potentialConflicts = LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('accion', 'conflicto')
            ->where('errores', 'like', '%ya existe una credencial activa con estatus ALTA%')
            ->orderBy('numero_fila')
            ->get();

        if ($potentialConflicts->isEmpty()) {
            return;
        }

        $cuipsInConflict = $potentialConflicts->pluck('cuip')->filter()->unique()->values()->all();

        // Obtener todas las filas de staging de este lote para los CUIPs en conflicto con orden determinista
        $stagingForCuips = LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->whereIn('cuip', $cuipsInConflict)
            ->orderBy('numero_fila')
            ->get()
            ->groupBy('cuip');

        foreach ($cuipsInConflict as $cuip) {
            if (! $stagingForCuips->has($cuip)) {
                continue;
            }

            $cuipRows = $stagingForCuips->get($cuip);

            // Altas en conflicto para este CUIP ordenadas de forma estable por numero_fila
            $altas = $potentialConflicts->where('cuip', $cuip)->sortBy('numero_fila')->values();

            // Bajas disponibles para este CUIP ordenadas de forma estable por numero_fila
            $bajas = $cuipRows->filter(function ($r) {
                $d = is_array($r->datos) ? $r->datos : json_decode((string) $r->datos, true);
                return ($d['campos']['estatus_credencial'] ?? '') === LocCredentialsPolicyService::STATUS_BAJA;
            })->sortBy('numero_fila')->values();

            if ($bajas->isEmpty()) {
                // No existe ninguna baja para este CUIP -> permanecen en conflicto legítimo
                continue;
            }

            // Construir grafo bipartito entre Altas (U) y Bajas (V)
            // Una arista (u, v) existe si y solo si fecha_baja_v <= fecha_solicitud_u (o alguna es vacía)
            $adj = [];
            foreach ($altas as $u => $altaRow) {
                $altaData = is_array($altaRow->datos) ? $altaRow->datos : json_decode((string) $altaRow->datos, true);
                $fs = $altaData['campos']['fecha_solicitud'] ?? null;
                $adj[$u] = [];

                foreach ($bajas as $v => $bajaRow) {
                    $bajaData = is_array($bajaRow->datos) ? $bajaRow->datos : json_decode((string) $bajaRow->datos, true);
                    $fb = $bajaData['campos']['fecha_baja'] ?? null;

                    if ($this->policy->isValidSubstitution($fb, $fs)) {
                        $adj[$u][] = $v;
                    }
                }
            }

            // Algoritmo de Kuhn para Emparejamiento Bipartito Máximo
            // matchB[v] = u indica qué alta u está asignada a la baja v
            $numBajas = $bajas->count();
            $numAltas = $altas->count();
            $matchB = array_fill(0, $numBajas, -1);
            $matchA = array_fill(0, $numAltas, -1);

            $dfs = function (int $u, array &$visited, array $adj, array &$matchB) use (&$dfs): bool {
                foreach ($adj[$u] as $v) {
                    if ($visited[$v] ?? false) {
                        continue;
                    }
                    $visited[$v] = true;

                    if ($matchB[$v] < 0 || $dfs($matchB[$v], $visited, $adj, $matchB)) {
                        $matchB[$v] = $u;
                        return true;
                    }
                }
                return false;
            };

            foreach (range(0, $numAltas - 1) as $u) {
                $visited = [];
                $dfs($u, $visited, $adj, $matchB);
            }

            foreach ($matchB as $v => $u) {
                if ($u >= 0) {
                    $matchA[$u] = $v;
                }
            }

            // Aplicar el resultado óptimo y determinista a las filas de staging
            foreach ($altas as $u => $altaRow) {
                $altaData = is_array($altaRow->datos) ? $altaRow->datos : json_decode((string) $altaRow->datos, true);
                $altaErrors = is_array($altaRow->errores) ? $altaRow->errores : json_decode((string) $altaRow->errores, true);

                $otherErrors = array_values(array_filter($altaErrors, function ($err) {
                    return ! str_contains($err, 'ya existe una credencial activa con estatus ALTA');
                }));

                $matchedBajaIndex = $matchA[$u];

                if ($matchedBajaIndex >= 0) {
                    // Alta justificada exitosamente por una baja única
                    if (empty($otherErrors)) {
                        $altaRow->accion = 'nuevo';
                        $altaRow->errores = [];
                        $altaRow->save();

                        $counts['conflicto'] = max(0, ($counts['conflicto'] ?? 0) - 1);
                        $counts['nuevo'] = ($counts['nuevo'] ?? 0) + 1;
                        if (! empty($altaData['conciliacion']['persona_resuelta'])) {
                            $counts['nuevo_conciliado'] = ($counts['nuevo_conciliado'] ?? 0) + 1;
                        } else {
                            $counts['nuevo_sin_enlace'] = ($counts['nuevo_sin_enlace'] ?? 0) + 1;
                        }
                    } else {
                        $altaRow->errores = $otherErrors;
                        $altaRow->save();
                    }
                } else {
                    // Alta sin emparejar: si existen bajas incompatibles por fechas, documentar el motivo
                    $fs = $altaData['campos']['fecha_solicitud'] ?? null;
                    $firstIncompatibleBaja = $bajas->first(function ($bRow) use ($fs) {
                        $bd = is_array($bRow->datos) ? $bRow->datos : json_decode((string) $bRow->datos, true);
                        $fb = $bd['campos']['fecha_baja'] ?? null;
                        return ! $this->policy->isValidSubstitution($fb, $fs);
                    });

                    if ($firstIncompatibleBaja !== null && empty($adj[$u])) {
                        $bd = is_array($firstIncompatibleBaja->datos) ? $firstIncompatibleBaja->datos : json_decode((string) $firstIncompatibleBaja->datos, true);
                        $fb = $bd['campos']['fecha_baja'] ?? null;
                        $incompatibleError = sprintf(
                            "Sustitución rechazada por fechas incompatibles para el CUIP '%s': la fecha de baja ('%s') es posterior a la fecha de solicitud ('%s').",
                            $cuip,
                            $fb ?? 'S/F',
                            $fs ?? 'S/F'
                        );
                        $altaRow->errores = array_merge($otherErrors, [$incompatibleError]);
                        $altaRow->save();
                    }
                }
            }
        }
    }

    /**
     * Evalúa si una credencial existente con CUIP divergente corresponde
     * a la misma persona/identidad mediante identificadores secundarios.
     *
     * @param  LocVigenciaCredencial  $existingCred
     * @param  array  $row
     * @param  string  $incomingCuip
     * @param  Collection  $personas
     * @return array{status: 'reconciliado'|'conflicto', criterio?: string, error?: string, persona_id?: int|null, es_referencia_loc?: bool}
     */
    private function reconcileCredentialIdentity(
        LocVigenciaCredencial $existingCred,
        array $row,
        string $incomingCuip,
        Collection $personas
    ): array {
        $noCredencial = $this->normalizeString($row[24] ?? null);
        $incomingRfc = strtoupper(trim((string) ($row[2] ?? '')));
        $incomingNombre = strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($row[3] ?? ''))));
        $incomingMatCorta = $this->normalizeSerial($row[10] ?? null);
        $incomingMatLarga = $this->normalizeSerial($row[18] ?? null);
        $incomingOficio = $this->normalizeString($row[25] ?? $row[21] ?? null);

        // 1. Obtener datos de la persona asociada al registro existente si existe
        $existingPerson = null;
        if ($existingCred->persona_id !== null) {
            $existingPerson = LocPersona::query()->find($existingCred->persona_id);
        }
        if ($existingPerson === null && ! empty($existingCred->cuip)) {
            $existingPerson = LocPersona::query()->where('cuip', $existingCred->cuip)->first();
        }

        // Obtener persona entrante si existe en padrón
        $incomingPerson = $personas->get($incomingCuip) ?? LocPersona::query()->where('cuip', $incomingCuip)->first();

        // 1.1 Si ambas son la misma persona en el padrón
        if ($existingPerson !== null && $incomingPerson !== null && $existingPerson->id === $incomingPerson->id) {
            return [
                'status' => 'reconciliado',
                'criterio' => 'coincidencia_misma_persona_padron',
                'persona_id' => $existingPerson->id,
                'es_referencia_loc' => true,
            ];
        }

        // 1.2 Si existen dos personas en padrón pero comparten CURP o RFC+personal_id
        if ($existingPerson !== null && $incomingPerson !== null) {
            $curp1 = strtoupper(trim((string) $existingPerson->curp));
            $curp2 = strtoupper(trim((string) $incomingPerson->curp));
            if (strlen($curp1) === 18 && strlen($curp2) === 18 && $curp1 === $curp2) {
                return [
                    'status' => 'reconciliado',
                    'criterio' => 'coincidencia_padron_curp_identica',
                    'persona_id' => $incomingPerson->id,
                    'es_referencia_loc' => true,
                ];
            }

            $rfc1 = substr(strtoupper(trim((string) $existingPerson->rfc)), 0, 10);
            $rfc2 = substr(strtoupper(trim((string) $incomingPerson->rfc)), 0, 10);
            if (strlen($rfc1) === 10 && strlen($rfc2) === 10 && $rfc1 === $rfc2
                && ! empty($existingPerson->personal_id) && $existingPerson->personal_id === $incomingPerson->personal_id) {
                return [
                    'status' => 'reconciliado',
                    'criterio' => 'coincidencia_padron_rfc_personal_id',
                    'persona_id' => $incomingPerson->id,
                    'es_referencia_loc' => true,
                ];
            }

            // Coincidencia por mismo personal_id institucional y nombre idéntico (tolerancia a erratas tipográficas en fecha de nacimiento)
            $name1 = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $existingPerson->nombre)));
            $name2 = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $incomingPerson->nombre)));
            if (! empty($existingPerson->personal_id) && $existingPerson->personal_id === $incomingPerson->personal_id
                && ! empty($name1) && ! empty($name2) && $name1 === $name2) {
                return [
                    'status' => 'reconciliado',
                    'criterio' => 'coincidencia_padron_personal_id_nombre',
                    'persona_id' => $incomingPerson->id,
                    'es_referencia_loc' => true,
                ];
            }

            // Si tienen CURP o RFC que se contradicen fuertemente
            if (strlen($curp1) === 18 && strlen($curp2) === 18 && substr($curp1, 0, 10) !== substr($curp2, 0, 10)) {
                return [
                    'status' => 'conflicto',
                    'error' => "El número de credencial '{$noCredencial}' ya está asignado a otro CUIP en la base de datos (persona distinta en el padrón con CURP divergente).",
                ];
            }
        }

        // 1.3 Si existe persona histórica pero la entrante no está aún en padrón
        if ($existingPerson !== null && $incomingPerson === null) {
            $exRfc10 = substr(strtoupper(trim((string) $existingPerson->rfc)), 0, 10);
            $inRfc10 = substr($incomingRfc, 0, 10);

            if (strlen($exRfc10) === 10 && strlen($inRfc10) === 10 && $exRfc10 !== $inRfc10) {
                return [
                    'status' => 'conflicto',
                    'error' => "El número de credencial '{$noCredencial}' ya está asignado a otro CUIP en la base de datos (RFC divergente '{$inRfc10}' vs '{$exRfc10}').",
                ];
            }

            if (strlen($exRfc10) === 10 && strlen($inRfc10) === 10 && $exRfc10 === $inRfc10) {
                return [
                    'status' => 'reconciliado',
                    'criterio' => 'coincidencia_persona_historica_rfc',
                    'persona_id' => $existingPerson->id,
                    'es_referencia_loc' => true,
                ];
            }
        }

        // 2. Comparación a nivel de datos de origen / credencial (cuando persona_id es null o como validación secundaria)
        $origen = is_array($existingCred->datos_origen)
            ? $existingCred->datos_origen
            : (json_decode((string) $existingCred->datos_origen, true) ?: []);

        $existingRfc = strtoupper(trim((string) ($origen['RFC'] ?? $origen[2] ?? '')));
        $existingNombre = strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($origen['NOMBRE'] ?? $origen[3] ?? ''))));
        $existingOficio = $this->normalizeString($existingCred->numero_oficio ?? $origen['NO OFICIO'] ?? $origen['NO. DE OFICIO'] ?? $origen[25] ?? $origen[21] ?? null);
        $existingMatCorta = $this->normalizeSerial($existingCred->matricula_corta ?? $origen['MATRICULA'] ?? $origen[10] ?? null);
        $existingMatLarga = $this->normalizeSerial($existingCred->matricula_larga ?? $origen['MATRICULA 2'] ?? $origen[18] ?? null);

        $exRfc10 = substr($existingRfc, 0, 10);
        $inRfc10 = substr($incomingRfc, 0, 10);

        // Contradicción fuerte en RFC si ambos están presentes
        if (strlen($exRfc10) === 10 && strlen($inRfc10) === 10 && $exRfc10 !== $inRfc10) {
            return [
                'status' => 'conflicto',
                'error' => "El número de credencial '{$noCredencial}' ya está asignado a otro CUIP en la base de datos con RFC diferente ('{$existingRfc}').",
            ];
        }

        $rfcMatch = strlen($exRfc10) === 10 && strlen($inRfc10) === 10 && $exRfc10 === $inRfc10;

        $nameMatch = ! empty($existingNombre) && ! empty($incomingNombre) && (
            $existingNombre === $incomingNombre ||
            str_contains($existingNombre, $incomingNombre) ||
            str_contains($incomingNombre, $existingNombre)
        );

        $weaponMatch = (! empty($incomingMatCorta) && $incomingMatCorta === $existingMatCorta)
            || (! empty($incomingMatLarga) && $incomingMatLarga === $existingMatLarga);

        $oficioMatch = ! empty($incomingOficio) && ! empty($existingOficio) && strtoupper($incomingOficio) === strtoupper($existingOficio);

        if ($rfcMatch && ($nameMatch || $weaponMatch || $oficioMatch)) {
            $targetPersonaId = $incomingPerson?->id ?? $existingCred->persona_id;
            $targetEsRef = $targetPersonaId !== null;

            return [
                'status' => 'reconciliado',
                'criterio' => 'coincidencia_evidencia_secundaria_credencial',
                'persona_id' => $targetPersonaId,
                'es_referencia_loc' => $targetEsRef,
            ];
        }

        return [
            'status' => 'conflicto',
            'error' => "El número de credencial '{$noCredencial}' ya está asignado a otro CUIP en la base de datos sin evidencia secundaria suficiente para conciliar la identidad.",
        ];
    }

    public function reviewFingerprint(LocImportBatch $batch): string
    {
        $hash = hash_init('sha256');
        LocImportStagingRow::query()
            ->where('import_batch_id', $batch->id)
            ->orderBy('numero_fila')
            ->select(['numero_fila', 'cuip', 'matricula', 'accion'])
            ->chunk(1000, function ($rows) use ($hash) {
                foreach ($rows as $row) {
                    hash_update($hash, "{$row->numero_fila}|{$row->cuip}|{$row->matricula}|{$row->accion}\n");
                }
            });

        return hash_final($hash);
    }

    public function normalizeSerial(mixed $val): ?string
    {
        if ($val === null) {
            return null;
        }
        $str = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{FEFF}]/u', ' ', (string) $val) ?? (string) $val;
        $s = strtoupper(trim($str));
        if ($s === '' || $s === '0' || $s === 'S/N' || $s === 'S/A' || $s === 'SIN ASIGNAR' || $s === 'N/A' || $s === 'NULL') {
            return null;
        }
        return $s;
    }

    private function normalizeString(mixed $val): ?string
    {
        if ($val === null) {
            return null;
        }
        $s = trim((string) $val);
        if ($s === '' || $s === '0' || strtolower($s) === 's/n' || strtolower($s) === 'null') {
            return null;
        }
        return $s;
    }
}
