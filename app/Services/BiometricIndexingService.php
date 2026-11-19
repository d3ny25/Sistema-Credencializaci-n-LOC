<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoEstadoArchivo;
use App\Enums\BiometricoIncidenciaSeveridad;
use App\Enums\BiometricoIncidenciaTipo;
use App\Enums\BiometricoIndexRunEstado;
use App\Enums\BiometricoTipoIdentificacion;
use App\Exceptions\BiometricoIndexacionCanceladaException;
use App\Exceptions\BiometricoLeaseOcupadoException;
use App\Exceptions\BiometricoLeasePerdidoException;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoArchivoVersion;
use App\Models\BiometricoExpediente;
use App\Models\BiometricoIncidencia;
use App\Models\BiometricoIndexLease;
use App\Models\BiometricoIndexRun;
use App\Models\BiometricoSlotCandidato;
use App\Models\LocCatalogoOrganizacional;
use App\Models\LocPersona;
use App\Models\User;
use App\Support\BiometricFilenameParser;
use App\Support\BiometricScanEntry;
use App\Support\StreamingHashCalculator;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Servicio de indexación, catalogación y control pericial de archivos biométricos.
 *
 * Implementa remediaciones exhaustivas de la Etapa 2.4.3 (Segunda Remediación Controlada):
 * - AUD-243-001: Control de concurrencia atómico con leases, fencing tokens, adquisición atómica (cero runs huérfanos)
 *   y heartbeats en procesamiento y detección de desaparecidos.
 * - AUD-243-002: Supresión estricta de falsos desaparecidos ante errores de recorrido, interrupciones o escaneos parciales.
 * - AUD-243-003: Comparador canónico uniforme en ordenamiento global monótono y checkpoints de reanudación jerárquica.
 * - AUD-243-004: Detección y bloqueo de ciclos y junctions de Windows con normalización NTFS en mayúsculas.
 * - AUD-243-005: Validación estricta de identidad de raíz canónica, ámbito y config_fingerprint en reanudación.
 * - AUD-243-006: Historial estructurado de versiones criptográficas (BiometricoArchivoVersion) con actualización atómica de hash.
 * - AUD-243-007: Segregación institucional estricta: prohibido crear expediente en ámbito erróneo, cuarentena preventiva y anomalías.
 * - AUD-243-008: Registro estructurado de candidatos de slot (BiometricoSlotCandidato) y congelación a NULL ante colisión.
 */
class BiometricIndexingService
{
    public const DEFAULT_CHUNK_SIZE = 500;
    public const DEFAULT_LEASE_TTL_SECONDS = 300; // 5 minutos de vigencia de lease
    protected const INTENTOS_POR_LOTE = 3; // reintentos del lote ante deadlock / lock wait timeout

    protected BiometricScannerService $scanner;
    protected BiometricFilenameParser $parser;
    protected StreamingHashCalculator $hasher;

    protected string $ownerToken;
    protected int $fencingToken = 1;
    protected int $leaseTtlSeconds;

    protected bool $isInterrupted = false;

    public function __construct(
        ?BiometricScannerService $scanner = null,
        ?BiometricFilenameParser $parser = null,
        ?StreamingHashCalculator $hasher = null,
        ?string $ownerToken = null,
        int $leaseTtlSeconds = self::DEFAULT_LEASE_TTL_SECONDS
    ) {
        $this->scanner = $scanner ?? new BiometricScannerService();
        $this->parser = $parser ?? new BiometricFilenameParser();
        $this->hasher = $hasher ?? new StreamingHashCalculator();
        $this->ownerToken = $ownerToken ?? (string) Str::uuid();
        $this->leaseTtlSeconds = $leaseTtlSeconds > 0 ? $leaseTtlSeconds : self::DEFAULT_LEASE_TTL_SECONDS;
    }

    public function getOwnerToken(): string
    {
        return $this->ownerToken;
    }

    public function getFencingToken(): int
    {
        return $this->fencingToken;
    }

    public function getScanner(): BiometricScannerService
    {
        return $this->scanner;
    }

    /**
     * Interrumpe explícitamente el proceso de escaneo e indexación en curso.
     */
    public function abortar(): void
    {
        $this->isInterrupted = true;
    }

    /**
     * Consulta el estado de una corrida de indexación de forma segura en modo solo lectura.
     */
    public function consultarEstado(string|int $runIdOrUuid): ?BiometricoIndexRun
    {
        $val = trim((string) $runIdOrUuid);
        if ($val === '') {
            return null;
        }

        return BiometricoIndexRun::query()
            ->with(['incidencias', 'iniciadoPor'])
            ->where(function ($q) use ($val): void {
                if (is_numeric($val)) {
                    $q->where('id', (int) $val)->orWhere('uuid', $val);
                } else {
                    $q->where('uuid', $val);
                }
            })
            ->first();
    }

    /**
     * Cancela de forma idempotente una ejecución de indexación (H-02).
     *
     * - Si su proceso conserva un lease vigente, solo registra la solicitud: ese proceso la atiende al
     *   inicio de su siguiente lote, deja de escribir y termina como CANCELADA (la corrida sigue EN_EJECUCION).
     * - Si no tiene propietario vigente (sin lease o lease vencido), la cancela de inmediato y libera su lease.
     *   Un proceso lento que aún viviera perdería el lease y se detendría sin escribir.
     *
     * Orden de bloqueo lease → corrida, el mismo del worker, para no provocar deadlocks.
     */
    public function cancelarRun(string|int $runIdOrUuid, ?string $motivo = null): BiometricoIndexRun
    {
        $val = trim((string) $runIdOrUuid);
        if ($val === '') {
            throw new InvalidArgumentException('Debe especificarse un identificador de ejecución válido.');
        }

        $runId = BiometricoIndexRun::query()
            ->where(function ($q) use ($val): void {
                if (is_numeric($val)) {
                    $q->where('id', (int) $val)->orWhere('uuid', $val);
                } else {
                    $q->where('uuid', $val);
                }
            })
            ->value('id');
        if ($runId === null) {
            throw new InvalidArgumentException("No se encontró ninguna ejecución con el identificador '{$val}'.");
        }

        try {
            return $this->registrarCancelacion($runId, $motivo);
        } catch (QueryException $e) {
            if ((new ConcurrencyErrorDetector())->causedByConcurrencyError($e)) {
                throw new BiometricoLeaseOcupadoException(
                    'El proceso de indexación está confirmando un lote y retiene el bloqueo. No se modificó nada; repita --cancel en unos segundos.',
                    0,
                    $e
                );
            }

            throw $e;
        }
    }

    protected function registrarCancelacion(int $runId, ?string $motivo): BiometricoIndexRun
    {
        return DB::transaction(function () use ($runId, $motivo): BiometricoIndexRun {
            $ambito = BiometricoIndexRun::query()->whereKey($runId)->value('ambito');

            /** @var BiometricoIndexLease|null $lease */
            $lease = BiometricoIndexLease::query()->where('ambito', $ambito)->lockForUpdate()->first();

            /** @var BiometricoIndexRun $run */
            $run = BiometricoIndexRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();

            // Si ya está finalizada, retornarla sin alteración (idempotencia)
            if ($run->estado->isFinalizada()) {
                return $run;
            }

            $resumen = $motivo ?? 'Cancelación administrativa solicitada por el operador.';
            $leaseDeLaCorrida = $lease !== null && (int) $lease->biometrico_index_run_id === (int) $run->id;

            if ($leaseDeLaCorrida && ! $lease->isExpired()) {
                $run->metadata = array_merge($run->metadata ?? [], [
                    'cancelacion_solicitada_at' => now()->toIso8601String(),
                    'cancelacion_motivo' => $resumen,
                ]);
                $run->save();

                return $run;
            }

            $run->marcarCancelada($resumen);

            if ($leaseDeLaCorrida) {
                $lease->delete();
            }

            return $run;
        });
    }

    /**
     * AUD-243-005: Calcula el fingerprint criptográfico de la configuración de indexación.
     */
    public static function calcularFingerprintConfiguracion(BiometricoAmbito $ambito, string $canonicalRoot): string
    {
        $salt = (string) config('app.key', 'antigravity_biometric_salt');

        return hash('sha256', "{$ambito->value}:{$canonicalRoot}:{$salt}");
    }

    /**
     * Ejecuta el proceso de indexación sobre el ámbito institucional y ruta raíz especificados.
     */
    public function indexar(
        BiometricoAmbito $ambito,
        ?string $rootPath = null,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        bool $dryRun = false,
        bool $resume = false,
        ?int $limit = null,
        ?User $iniciadoPor = null,
        ?callable $progressCallback = null,
        string|int|null $targetResumeIdOrUuid = null,
        bool $takeover = false
    ): BiometricoIndexRun {
        if ($chunkSize <= 0) {
            throw new InvalidArgumentException("El tamaño de lote debe ser un entero positivo: {$chunkSize}");
        }

        $resolvedRoot = $rootPath ?? (string) config('filesystems.disks.biometricos.root');
        if (trim($resolvedRoot) === '') {
            throw new InvalidArgumentException('No se especificó la ruta raíz del repositorio ni se configuró filesystems.disks.biometricos.root.');
        }

        $canonicalRoot = $this->scanner->validarRutaRaiz($resolvedRoot);
        $configFingerprint = self::calcularFingerprintConfiguracion($ambito, $canonicalRoot);

        $startAfter = null;
        $reanudadoDeUuid = null;
        $reanudarRunId = null;

        // AUD-243-005: Validación de identidad de raíz, ámbito y fingerprint al reanudar.
        // H-02: solo corridas terminales, no reanudadas antes y sin lease que las referencie.
        if ($resume) {
            $prevQuery = BiometricoIndexRun::query()
                ->where('ambito', $ambito->value)
                ->whereIn('estado', [
                    BiometricoIndexRunEstado::INTERRUMPIDA->value,
                    BiometricoIndexRunEstado::CANCELADA->value,
                    BiometricoIndexRunEstado::FALLIDA->value,
                ])
                ->whereNull('metadata->reanudada_por_uuid')
                ->whereNotIn('id', BiometricoIndexLease::query()->select('biometrico_index_run_id'));

            if ($targetResumeIdOrUuid !== null && trim((string) $targetResumeIdOrUuid) !== '') {
                $targetVal = trim((string) $targetResumeIdOrUuid);
                $prevQuery->where(function ($q) use ($targetVal): void {
                    if (is_numeric($targetVal)) {
                        $q->where('id', (int) $targetVal)->orWhere('uuid', $targetVal);
                    } else {
                        $q->where('uuid', $targetVal);
                    }
                });
            } else {
                $prevQuery->latest('id');
            }

            $prevRun = $prevQuery->first();

            if ($prevRun === null && $targetResumeIdOrUuid !== null) {
                throw new InvalidArgumentException(
                    "No se encontró ninguna ejecución previa reanudable con el identificador '{$targetResumeIdOrUuid}' para el ámbito {$ambito->value} (debe estar interrumpida, cancelada o fallida, sin proceso propietario y sin haber sido reanudada antes)."
                );
            }

            if ($prevRun !== null) {
                $prevRoot = $prevRun->metadata['root_path'] ?? null;
                if ($prevRoot !== null && strcasecmp(rtrim($prevRoot, '/'), $canonicalRoot) !== 0) {
                    throw new InvalidArgumentException(
                        "No es posible reanudar la ejecución: la ruta raíz actual ('{$canonicalRoot}') no coincide con la ruta raíz de la corrida previa ('{$prevRoot}')."
                    );
                }

                $prevFingerprint = $prevRun->metadata['config_fingerprint'] ?? null;
                if ($prevFingerprint !== null && $prevFingerprint !== $configFingerprint) {
                    throw new InvalidArgumentException(
                        'No es posible reanudar la ejecución: el fingerprint de configuración actual no coincide con el de la corrida previa.'
                    );
                }

                if (empty($prevRun->ultima_ruta_procesada)) {
                    throw new InvalidArgumentException('La ejecución previa no cuenta con un checkpoint válido para reanudar.');
                }

                $startAfter = $prevRun->ultima_ruta_procesada;
                $reanudadoDeUuid = $prevRun->uuid;
                $reanudarRunId = (int) $prevRun->id;
            }
        }

        $runAttributes = [
            'ambito' => $ambito->value,
            'estado' => BiometricoIndexRunEstado::EN_EJECUCION,
            'es_dry_run' => $dryRun,
            'iniciado_at' => now(),
            'iniciado_por_user_id' => $iniciadoPor?->id,
            'metadata' => [
                'root_path' => $canonicalRoot,
                'config_fingerprint' => $configFingerprint,
                'chunk_size' => $chunkSize,
                'limit' => $limit,
                'resume' => $resume,
                'reanudado_de_uuid' => $reanudadoDeUuid,
                'start_after' => $startAfter,
                'owner_token' => $this->ownerToken,
            ],
        ];

        // AUD-243-001 / NUEVO-243-003: Adquisición atómica del lease creando el run en la misma transacción
        if (! $dryRun) {
            $run = $this->adquirirLease($ambito, $runAttributes, $takeover, $reanudarRunId);
        } else {
            // AUD-244-002: En modo dry-run NO persistir el run en base de datos; mantener estrictamente en memoria
            /** @var BiometricoIndexRun $run */
            $run = new BiometricoIndexRun($runAttributes);
            $run->uuid = (string) \Illuminate\Support\Str::uuid();
            $run->id = 0;
        }

        $observedPaths = [];
        $currentBatch = [];
        $batchIndex = 0;
        $this->isInterrupted = false;
        $huboErroresDeRecorrido = false;

        try {
            $generator = $this->scanner->scan($canonicalRoot, $startAfter);

            foreach ($generator as $entry) {
                if ($this->isInterrupted) {
                    break;
                }

                $run->total_archivos_detectados++;

                // AUD-243-002: Detectar anomalías en el recorrido
                if (! $entry->esValido || $entry->tipoEntrada !== 'archivo') {
                    $huboErroresDeRecorrido = true;
                }

                if ($entry->esArchivoRegular && $entry->esValido) {
                    $observedPaths[$entry->rutaRelativa] = true;
                }

                $currentBatch[] = $entry;

                if (count($currentBatch) >= $chunkSize) {
                    $batchIndex++;
                    $this->procesarLote($currentBatch, $ambito, $run, $dryRun, $batchIndex);
                    $currentBatch = [];

                    if ($progressCallback !== null) {
                        $progressCallback($run->total_archivos_procesados, $run->total_archivos_detectados, $run);
                    }

                    // Sin escrituras fuera del lote: el checkpoint y las métricas se confirman en su transacción.
                    gc_collect_cycles();
                }

                if ($limit !== null && $run->total_archivos_procesados + count($currentBatch) >= $limit) {
                    break;
                }
            }

            // Procesar remanente
            if (! empty($currentBatch) && ! $this->isInterrupted) {
                $batchIndex++;
                $this->procesarLote($currentBatch, $ambito, $run, $dryRun, $batchIndex);
                $currentBatch = [];
                gc_collect_cycles();
            }

            if ($this->isInterrupted) {
                $run->memoria_pico_mb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
                $this->cerrarCorridaSiSigueActiva(
                    $run,
                    fn (BiometricoIndexRun $r) => $r->marcarInterrumpida('Ejecución interrumpida por el operador antes de completar el escaneo; el último checkpoint confirmado es reanudable.')
                );

                return $run;
            }

            // AUD-243-002: Detección de desaparecidos condicionada estrictamente a una exploración 100% exhaustiva
            $isCompleteScan = ($limit === null)
                && (! $resume)
                && (! $this->isInterrupted)
                && (! $huboErroresDeRecorrido)
                && (! $this->scanner->huboErroresDeRecorrido());

            if ($isCompleteScan && ! $dryRun) {
                $this->detectarArchivosDesaparecidos($ambito, $canonicalRoot, $observedPaths, $run);
            }

            $run->memoria_pico_mb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

            if ($dryRun) {
                $run->marcarFinalizada();

                return $run;
            }

            // AUD-243-001 / H-02: la propiedad se verifica y el estado final se escribe en la misma transacción.
            DB::transaction(function () use ($ambito, $run): void {
                $this->verificarYRenovarLease($ambito);
                $this->verificarCancelacionSolicitada($run);
                $this->cerrarCorridaSiSigueActiva($run, fn (BiometricoIndexRun $r) => $r->marcarFinalizada());
            });

            return $run;
        } catch (BiometricoIndexacionCanceladaException $e) {
            // --cancel atendido en un punto seguro: se conservan los lotes y el checkpoint ya confirmados.
            $this->cerrarCorridaSiSigueActiva($run, fn (BiometricoIndexRun $r) => $r->marcarCancelada($e->getMessage()));

            return $run;
        } catch (BiometricoLeasePerdidoException $e) {
            // Split-brain prevenido: si nadie cerró la corrida (p. ej. lease borrado), queda reanudable y no huérfana.
            rescue(fn () => $this->cerrarCorridaSiSigueActiva(
                $run,
                fn (BiometricoIndexRun $r) => $r->marcarInterrumpida('El proceso perdió el lease de indexación y se detuvo sin escribir el lote en curso.')
            ), report: false);

            throw $e;
        } catch (\Throwable $e) {
            $run->memoria_pico_mb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
            rescue(fn () => $this->cerrarCorridaSiSigueActiva($run, fn (BiometricoIndexRun $r) => $r->marcarFallida($e->getMessage())), report: false);

            throw $e;
        } finally {
            if (! $dryRun) {
                $this->liberarLease($ambito);
            }
        }
    }

    /**
     * Procesa un lote individual de entradas garantizando atomicidad, verificación de lease y actualización de checkpoints.
     *
     * @param  array<int, BiometricScanEntry>  $batch
     */
    protected function procesarLote(
        array $batch,
        BiometricoAmbito $ambito,
        BiometricoIndexRun $run,
        bool $dryRun,
        int $batchIndex
    ): void {
        $operacion = function () use ($batch, $ambito, $run, $dryRun, $batchIndex): void {
            // AUD-243-001 / H-02: heartbeat con fencing (bloquea el lease hasta el commit) y punto seguro de --cancel,
            // ambos antes de cualquier escritura del lote.
            if (! $dryRun) {
                $this->verificarYRenovarLease($ambito);
                $this->verificarCancelacionSolicitada($run);
            }

            $lastProcessedEntry = null;

            foreach ($batch as $entry) {
                $run->total_archivos_procesados++;
                $lastProcessedEntry = $entry;

                // 1. Manejo de entradas anómalas
                if (! $entry->esValido || $entry->esDirectorio || $entry->esEnlace || ! $entry->esLegible) {
                    $this->manejarEntradaAnomala($entry, $run, $dryRun);
                    continue;
                }

                // 2. Interpretación sintáctica
                $parseResult = $this->parser->parse($entry->rutaRelativa);

                if ($parseResult->tipoIdentificacion === BiometricoTipoIdentificacion::SISTEMA) {
                    $run->total_archivos_invalidos++;
                    $this->registrarIncidencia($run, [
                        'tipo' => BiometricoIncidenciaTipo::NOMBRE_INVALIDO,
                        'severidad' => BiometricoIncidenciaSeveridad::INFO,
                        'codigo' => 'ARCHIVO_SISTEMA',
                        'descripcion' => 'Archivo de sistema operativo excluido de la indexación biométrica.',
                        'ruta_relativa' => $entry->rutaRelativa,
                        'nombre_archivo' => $entry->nombreArchivo,
                    ], $dryRun);

                    continue;
                }

                // 3. Cálculo criptográfico SHA-256
                try {
                    $hashResult = $this->hasher->calculate($entry->rutaAbsoluta);
                } catch (\Throwable $e) {
                    $run->total_archivos_invalidos++;
                    $this->registrarIncidencia($run, [
                        'tipo' => BiometricoIncidenciaTipo::HASH_NO_CALCULABLE,
                        'severidad' => BiometricoIncidenciaSeveridad::ALTA,
                        'codigo' => 'HASH_CALCULATION_ERROR',
                        'descripcion' => 'Fallo al calcular SHA-256 por streaming: ' . $e->getMessage(),
                        'ruta_relativa' => $entry->rutaRelativa,
                        'nombre_archivo' => $entry->nombreArchivo,
                    ], $dryRun);
                    continue;
                }

                // 4. Registro existente (seguro si las tablas no existen en dry-run)
                $existing = null;
                $canQueryDb = ! $dryRun || \Illuminate\Support\Facades\Schema::hasTable('biometrico_archivos');
                if ($canQueryDb) {
                    try {
                        $existing = BiometricoArchivo::query()->where('ruta_relativa', $entry->rutaRelativa)->first();
                    } catch (\Throwable) {
                        $existing = null;
                    }
                }

                if ($existing !== null) {
                    if ($existing->sha256 === $hashResult->hash && (int) $existing->tamano_bytes === $entry->tamanoBytes) {
                        $run->total_archivos_sin_cambios++;
                    } else {
                        // AUD-243-006: Historial estructurado de modificaciones de hash
                        $this->manejarArchivoModificado($existing, $hashResult->hash, $entry, $run, $dryRun);
                    }

                    continue;
                }

                // 5. Archivo nuevo
                if (! $parseResult->esValido || ! $parseResult->esCuipValido || $parseResult->categoria === null) {
                    $run->total_archivos_invalidos++;
                    $tipoIncidencia = ! $parseResult->esCuipValido
                        ? BiometricoIncidenciaTipo::CUIP_INVALIDO
                        : ($parseResult->categoria === null ? BiometricoIncidenciaTipo::CATEGORIA_DESCONOCIDA : BiometricoIncidenciaTipo::NOMBRE_INVALIDO);

                    $this->registrarIncidencia($run, [
                        'tipo' => $tipoIncidencia,
                        'severidad' => BiometricoIncidenciaSeveridad::MEDIA,
                        'codigo' => $parseResult->codigoIncidencia ?? 'NOMBRE_INVALIDO',
                        'descripcion' => $parseResult->motivoInvalidez ?? 'Nombre de archivo o identificador CUIP no interpretable',
                        'ruta_relativa' => $entry->rutaRelativa,
                        'nombre_archivo' => $entry->nombreArchivo,
                        'cuip_detectado' => $parseResult->cuipDetectado,
                        'categoria' => $parseResult->categoria,
                    ], $dryRun);

                    continue;
                }

                // 6. Detección de duplicados por contenido (seguro en dry-run si la tabla no existe)
                $dupSha = null;
                if ($canQueryDb) {
                    try {
                        $dupSha = BiometricoArchivo::query()->where('sha256', $hashResult->hash)->first();
                    } catch (\Throwable) {
                        $dupSha = null;
                    }
                }
                if ($dupSha !== null) {
                    $run->total_archivos_duplicados++;
                    $this->registrarIncidencia($run, [
                        'tipo' => BiometricoIncidenciaTipo::ARCHIVO_DUPLICADO,
                        'severidad' => BiometricoIncidenciaSeveridad::MEDIA,
                        'codigo' => 'DUPLICADO_CONTENIDO',
                        'descripcion' => "Archivo con contenido idéntico a registro previo: {$dupSha->ruta_relativa}",
                        'ruta_relativa' => $entry->rutaRelativa,
                        'nombre_archivo' => $entry->nombreArchivo,
                        'cuip_detectado' => $parseResult->cuipNormalizado,
                        'categoria' => $parseResult->categoria,
                        'detalles' => [
                            'sha256' => $hashResult->hash,
                            'ruta_existente' => $dupSha->ruta_relativa,
                        ],
                    ], $dryRun);
                }

                $run->total_archivos_nuevos++;

                if (! $dryRun) {
                    $estadoArchivo = $dupSha !== null
                        ? BiometricoEstadoArchivo::DUPLICADO_SHA
                        : BiometricoEstadoArchivo::VALIDO;

                    /** @var BiometricoArchivo $archivo */
                    $archivo = BiometricoArchivo::create([
                        'ambito' => $ambito,
                        'categoria' => $parseResult->categoria,
                        'ruta_relativa' => $entry->rutaRelativa,
                        'nombre_archivo' => $entry->nombreArchivo,
                        'extension' => $entry->extension,
                        'tamano_bytes' => $entry->tamanoBytes,
                        'formato_real' => $entry->extension,
                        'mime_type' => $this->inferMimeType($entry->extension),
                        'sha256' => $hashResult->hash,
                        'cuip_detectado' => $parseResult->cuipDetectado,
                        'cuip_normalizado' => $parseResult->cuipNormalizado,
                        'tipo_identificacion' => $parseResult->tipoIdentificacion,
                        'estado_archivo' => $estadoArchivo,
                        'es_vigente' => true,
                        'version_secuencia' => 1,
                        'observaciones' => $dupSha ? "Contenido duplicado de {$dupSha->ruta_relativa}" : null,
                        'fecha_archivo_mtime' => $entry->mtime ? date('Y-m-d H:i:s', $entry->mtime) : null,
                    ]);

                    // Vinculación controlada con el expediente institucional oficial
                    if ($archivo->estado_archivo === BiometricoEstadoArchivo::VALIDO && $parseResult->cuipNormalizado !== null) {
                        $this->vincularExpediente($archivo, $parseResult->cuipNormalizado, $ambito, $run);
                    }
                }
            }

            // Actualización del checkpoint confirmado en este lote
            if ($lastProcessedEntry !== null) {
                $run->ultimo_checkpoint_id = $batchIndex;
                $run->ultimo_directorio_procesado = dirname($lastProcessedEntry->rutaRelativa);
                $run->ultima_ruta_procesada = $lastProcessedEntry->rutaRelativa;
                $run->memoria_pico_mb = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
                if (! $dryRun) {
                    $run->save();
                }
            }
        };

        if ($dryRun) {
            $operacion();
        } else {
            $this->ejecutarLoteAtomico($run, $operacion);
        }
    }

    /**
     * AUD-243-006: Maneja la detección de modificaciones conservando el historial estructurado de versiones
     * y actualizando atómicamente el hash actual del registro para mantener la cadena genealógica.
     */
    protected function manejarArchivoModificado(
        BiometricoArchivo $existing,
        string $nuevoHash,
        BiometricScanEntry $entry,
        BiometricoIndexRun $run,
        bool $dryRun
    ): void {
        $run->total_archivos_modificados++;

        if ($dryRun) {
            $run->total_incidencias++;

            return;
        }

        // Verificar si esta modificación de hash específica ya fue versionada anteriormente
        $yaRegistrada = BiometricoArchivoVersion::query()
            ->where('biometrico_archivo_id', $existing->id)
            ->where('sha256_nuevo', $nuevoHash)
            ->exists();

        if (! $yaRegistrada) {
            $nuevaSecuencia = (int) $existing->version_secuencia + 1;
            $shaAnterior = (string) $existing->sha256;
            $tamanoAnterior = $existing->tamano_bytes;

            BiometricoArchivoVersion::create([
                'biometrico_archivo_id' => $existing->id,
                'biometrico_index_run_id' => $run->id,
                'version_secuencia' => $nuevaSecuencia,
                'sha256_anterior' => $shaAnterior,
                'sha256_nuevo' => $nuevoHash,
                'tamano_bytes_anterior' => $tamanoAnterior,
                'tamano_bytes_nuevo' => $entry->tamanoBytes,
                'estado_revision' => 'pendiente_revision',
                'observaciones' => "Modificación detectada en escaneo físico de {$run->ambito}",
                'fecha_deteccion' => now(),
            ]);

            // Actualización atómica de hash y tamaño para mantener la continuidad genealógica
            $existing->sha256 = $nuevoHash;
            $existing->tamano_bytes = $entry->tamanoBytes;
            $existing->estado_archivo = BiometricoEstadoArchivo::SOSPECHOSO;
            $existing->version_secuencia = $nuevaSecuencia;
            $existing->observaciones = substr("[MODIFICADO] Nuevo SHA-256 detectado: {$nuevoHash}", 0, 255);
            $existing->save();

            $this->registrarIncidencia($run, [
                'tipo' => BiometricoIncidenciaTipo::ERROR_PROCESAMIENTO,
                'severidad' => BiometricoIncidenciaSeveridad::CRITICA,
                'codigo' => 'HASH_MODIFICADO',
                'descripcion' => "Archivo modificado en almacenamiento. SHA anterior: {$shaAnterior}, nuevo: {$nuevoHash}",
                'ruta_relativa' => $entry->rutaRelativa,
                'nombre_archivo' => $entry->nombreArchivo,
                'cuip_detectado' => $existing->cuip_normalizado,
                'categoria' => $existing->categoria,
                'detalles' => [
                    'sha256_anterior' => $shaAnterior,
                    'sha256_nuevo' => $nuevoHash,
                    'tamano_anterior' => $tamanoAnterior,
                    'tamano_nuevo' => $entry->tamanoBytes,
                    'fecha_deteccion' => now()->toIso8601String(),
                ],
            ], false);
        }
    }

    /**
     * Vincula un archivo biométrico con el expediente institucional oficial.
     * Implementa:
     * - AUD-243-007 / NUEVO-243-002: Segregación institucional estricta. Prohibido crear expediente en ámbito erróneo,
     *   cuarentena preventiva del archivo y anomalía en el expediente oficial si existe.
     * - AUD-243-008: Registro estructurado de candidatos en BiometricoSlotCandidato y congelación del slot a NULL
     *   ante colisiones de archivos distintos para revisión pericial humana.
     */
    protected function vincularExpediente(
        BiometricoArchivo $archivo,
        string $cuipNormalizado,
        BiometricoAmbito $ambitoIngestion,
        BiometricoIndexRun $run
    ): void {
        // AUD-243-007 / NUEVO-243-002: Conciliación de ámbito físico vs ámbito institucional de catálogo
        $persona = LocPersona::where('cuip', $cuipNormalizado)->first();
        if ($persona !== null && ! empty($persona->clave_seguridad)) {
            $catRecord = LocCatalogoOrganizacional::query()
                ->where('clave_origen', trim((string) $persona->clave_seguridad))
                ->where('activo', true)
                ->first();

            $ambitoOficial = BiometricoAmbito::fromCatalogoRecord($catRecord);

            // Si el ámbito oficial está resuelto y no coincide con el ámbito físico de ingestión
            if ($ambitoOficial->isResuelto() && $ambitoOficial !== $ambitoIngestion) {
                $this->registrarIncidencia($run, [
                    'tipo' => BiometricoIncidenciaTipo::CONFLICTO_IDENTIDAD,
                    'severidad' => BiometricoIncidenciaSeveridad::ALTA,
                    'codigo' => 'DISCREPANCIA_AMBITO_CATALOGO',
                    'descripcion' => "Discrepancia crítica de ámbito: el archivo físico está en {$ambitoIngestion->value}, pero el catálogo oficial de la persona indica {$ambitoOficial->value}.",
                    'ruta_relativa' => $archivo->ruta_relativa,
                    'nombre_archivo' => $archivo->nombre_archivo,
                    'cuip_detectado' => $cuipNormalizado,
                    'categoria' => $archivo->categoria,
                    'detalles' => [
                        'ambito_ingestion_carpeta' => $ambitoIngestion->value,
                        'ambito_oficial_catalogo' => $ambitoOficial->value,
                        'clave_seguridad' => $persona->clave_seguridad,
                    ],
                ], false);

                // Cuarentena preventiva del archivo
                $archivo->estado_archivo = BiometricoEstadoArchivo::CUARENTENA;
                $archivo->observaciones = substr("[DISCREPANCIA_AMBITO: Carpeta física {$ambitoIngestion->value} != Catálogo oficial {$ambitoOficial->value}]", 0, 255);
                $archivo->save();

                // Si ya existe un expediente oficial en su ámbito correcto, alertar anomalía y bloquear credencialización
                $expOficial = BiometricoExpediente::where('cuip', $cuipNormalizado)
                    ->where('ambito', $ambitoOficial->value)
                    ->lockForUpdate()
                    ->first();

                if ($expOficial !== null) {
                    $expOficial->tiene_anomalias = true;
                    $expOficial->estado_revision = 'en_aclaracion';
                    $expOficial->motivo_rechazo_biometrico = substr(
                        trim(($expOficial->motivo_rechazo_biometrico ?? '') . " | Archivo extraviado detectado en {$ambitoIngestion->value}: {$archivo->ruta_relativa}"),
                        0,
                        255
                    );
                    $expOficial->save();
                }

                // PROHIBIDO crear expediente en el ámbito de ingestión erróneo
                return;
            }

            // Si tiene clave pero no resolvió ámbito en catálogo
            if ($ambitoOficial === BiometricoAmbito::NO_RESUELTO) {
                $this->registrarIncidencia($run, [
                    'tipo' => BiometricoIncidenciaTipo::CONFLICTO_IDENTIDAD,
                    'severidad' => BiometricoIncidenciaSeveridad::ALTA,
                    'codigo' => 'AMBITO_CATALOGO_NO_RESUELTO',
                    'descripcion' => "No fue posible resolver el ámbito institucional en catálogo para la clave de seguridad {$persona->clave_seguridad}.",
                    'ruta_relativa' => $archivo->ruta_relativa,
                    'nombre_archivo' => $archivo->nombre_archivo,
                    'cuip_detectado' => $cuipNormalizado,
                    'categoria' => $archivo->categoria,
                    'detalles' => [
                        'clave_seguridad' => $persona->clave_seguridad,
                    ],
                ], false);

                $archivo->estado_archivo = BiometricoEstadoArchivo::CUARENTENA;
                $archivo->observaciones = substr("[AMBITO_NO_RESUELTO: Clave {$persona->clave_seguridad}]", 0, 255);
                $archivo->save();

                return;
            }
        }

        $expediente = $this->obtenerExpedienteBloqueado($cuipNormalizado, $ambitoIngestion->value);

        if ($expediente->persona_id === null && $persona !== null) {
            $expediente->persona_id = $persona->id;
        }

        // AUD-243-008: Registro de candidatos y detección de colisiones múltiples en el mismo slot
        $slotKey = match ($archivo->categoria) {
            BiometricoCategoria::FOTO => 'foto_archivo_id',
            BiometricoCategoria::FIRMA => 'firma_archivo_id',
            BiometricoCategoria::PULGAR_DERECHO => 'pulgar_derecho_archivo_id',
            BiometricoCategoria::PULGAR_IZQUIERDO => 'pulgar_izquierdo_archivo_id',
        };

        $existingSlotId = $expediente->{$slotKey};

        // Si el slot está libre
        if ($existingSlotId === null) {
            $expediente->{$slotKey} = $archivo->id;
            $this->recalcularCompletitudActual($expediente);
            $expediente->save();

            BiometricoSlotCandidato::updateOrCreate([
                'biometrico_expediente_id' => $expediente->id,
                'categoria' => $archivo->categoria,
                'biometrico_archivo_id' => $archivo->id,
            ], [
                'biometrico_index_run_id' => $run->id,
                'sha256' => $archivo->sha256,
                'ruta_relativa' => $archivo->ruta_relativa,
                'tamano_bytes' => $archivo->tamano_bytes,
                'es_seleccion_oficial' => true,
                'estado_candidato' => 'seleccionado',
                'fecha_deteccion' => now(),
            ]);

            return;
        }

        // Si el slot ya apunta a este mismo archivo
        if ($existingSlotId === $archivo->id) {
            BiometricoSlotCandidato::updateOrCreate([
                'biometrico_expediente_id' => $expediente->id,
                'categoria' => $archivo->categoria,
                'biometrico_archivo_id' => $archivo->id,
            ], [
                'biometrico_index_run_id' => $run->id,
                'sha256' => $archivo->sha256,
                'ruta_relativa' => $archivo->ruta_relativa,
                'tamano_bytes' => $archivo->tamano_bytes,
                'es_seleccion_oficial' => true,
                'estado_candidato' => 'seleccionado',
                'fecha_deteccion' => now(),
            ]);

            return;
        }

        // Si apunta a otro archivo
        $existingArchivo = BiometricoArchivo::find($existingSlotId);

        // Caso: mismo contenido SHA-256 (duplicado físico idéntico)
        if ($existingArchivo !== null && $existingArchivo->sha256 === $archivo->sha256) {
            BiometricoSlotCandidato::updateOrCreate([
                'biometrico_expediente_id' => $expediente->id,
                'categoria' => $archivo->categoria,
                'biometrico_archivo_id' => $archivo->id,
            ], [
                'biometrico_index_run_id' => $run->id,
                'sha256' => $archivo->sha256,
                'ruta_relativa' => $archivo->ruta_relativa,
                'tamano_bytes' => $archivo->tamano_bytes,
                'es_seleccion_oficial' => false,
                'estado_candidato' => 'descartado_duplicado',
                'motivo_descarte' => "Contenido idéntico al archivo oficial ID {$existingArchivo->id}",
                'fecha_deteccion' => now(),
            ]);

            return;
        }

        // CASO CRÍTICO: Dos archivos distintos disputan el mismo slot
        // 1. Asegurar que el archivo existente esté registrado en la tabla de candidatos
        if ($existingArchivo !== null) {
            BiometricoSlotCandidato::updateOrCreate([
                'biometrico_expediente_id' => $expediente->id,
                'categoria' => $archivo->categoria,
                'biometrico_archivo_id' => $existingArchivo->id,
            ], [
                'biometrico_index_run_id' => $run->id,
                'sha256' => $existingArchivo->sha256,
                'ruta_relativa' => $existingArchivo->ruta_relativa,
                'tamano_bytes' => $existingArchivo->tamano_bytes,
                'es_seleccion_oficial' => false,
                'estado_candidato' => 'en_conflicto',
                'motivo_descarte' => 'Conflicto de slot: múltiples archivos distintos detectados',
                'fecha_deteccion' => now(),
            ]);
        }

        // 2. Registrar el nuevo archivo como candidato en conflicto
        BiometricoSlotCandidato::updateOrCreate([
            'biometrico_expediente_id' => $expediente->id,
            'categoria' => $archivo->categoria,
            'biometrico_archivo_id' => $archivo->id,
        ], [
            'biometrico_index_run_id' => $run->id,
            'sha256' => $archivo->sha256,
            'ruta_relativa' => $archivo->ruta_relativa,
            'tamano_bytes' => $archivo->tamano_bytes,
            'es_seleccion_oficial' => false,
            'estado_candidato' => 'en_conflicto',
            'motivo_descarte' => 'Conflicto de slot: múltiples archivos distintos detectados',
            'fecha_deteccion' => now(),
        ]);

        // 3. CONGELAR EL SLOT A NULL para evitar credencialización con datos ambiguos
        $expediente->{$slotKey} = null;
        $expediente->tiene_duplicados = true;
        $expediente->tiene_anomalias = true;
        $expediente->estado_revision = 'en_aclaracion';
        $this->recalcularCompletitudActual($expediente);
        $expediente->save();

        $this->registrarIncidencia($run, [
            'tipo' => BiometricoIncidenciaTipo::CONFLICTO_IDENTIDAD,
            'severidad' => BiometricoIncidenciaSeveridad::ALTA,
            'codigo' => 'CONFLICTO_SLOT_MULTIPLE',
            'descripcion' => "Múltiples archivos distintos disputan el slot {$archivo->categoria->value} para CUIP {$cuipNormalizado}. Slot congelado.",
            'ruta_relativa' => $archivo->ruta_relativa,
            'nombre_archivo' => $archivo->nombre_archivo,
            'cuip_detectado' => $cuipNormalizado,
            'categoria' => $archivo->categoria,
            'detalles' => [
                'archivo_existente_id' => $existingArchivo?->id,
                'archivo_existente_ruta' => $existingArchivo?->ruta_relativa,
                'archivo_existente_sha' => $existingArchivo?->sha256,
                'archivo_nuevo_id' => $archivo->id,
                'archivo_nuevo_ruta' => $archivo->ruta_relativa,
                'archivo_nuevo_sha' => $archivo->sha256,
            ],
        ], false);
    }

    /**
     * H-02: mismo protocolo que BiometricUpdateService (lockForUpdate del expediente) para serializar
     * captura manual e indexación sobre el mismo expediente. La exclusión del ámbito no lo sustituye.
     */
    protected function obtenerExpedienteBloqueado(string $cuip, string $ambito): BiometricoExpediente
    {
        $claves = ['cuip' => $cuip, 'ambito' => $ambito];

        $expediente = BiometricoExpediente::query()->where($claves)->lockForUpdate()->first();
        if ($expediente !== null) {
            return $expediente;
        }

        try {
            return BiometricoExpediente::create($claves);
        } catch (UniqueConstraintViolationException) {
            // La captura lo creó en paralelo: la lectura con bloqueo ve la fila confirmada, no la instantánea del lote.
            return BiometricoExpediente::query()->where($claves)->lockForUpdate()->firstOrFail();
        }
    }

    /**
     * Recalcula la completitud leyendo los archivos de los slots con bloqueo compartido: así ve lo que la
     * captura ya confirmó y no la instantánea REPEATABLE READ tomada al inicio del lote.
     */
    protected function recalcularCompletitudActual(BiometricoExpediente $expediente): void
    {
        $slots = [
            'foto' => 'foto_archivo_id',
            'firma' => 'firma_archivo_id',
            'pulgar_derecho' => 'pulgar_derecho_archivo_id',
            'pulgar_izquierdo' => 'pulgar_izquierdo_archivo_id',
        ];
        $ids = array_values(array_filter(array_map(fn (string $columna) => $expediente->{$columna}, $slots)));
        $archivos = $ids === []
            ? collect()
            : BiometricoArchivo::query()->whereKey($ids)->sharedLock()->get()->keyBy('id');

        $expediente->recalcularCompletitud(
            array_map(fn (string $columna) => $archivos->get($expediente->{$columna}), $slots)
        );
    }

    /**
     * AUD-243-001 / AUD-243-002: Detección de desaparecidos condicionada a carpetas accesibles,
     * dividida en lotes atómicos con heartbeat de renovación de lease para prevenir split-brain.
     */
    protected function detectarArchivosDesaparecidos(
        BiometricoAmbito $ambito,
        string $canonicalRoot,
        array $observedPaths,
        BiometricoIndexRun $run,
        int $chunkSize = 100
    ): void {
        BiometricoArchivo::query()
            ->byAmbito($ambito)
            ->vigentes()
            ->where('estado_archivo', '!=', BiometricoEstadoArchivo::DESAPARECIDO->value)
            ->chunkById($chunkSize, function ($archivosActivos) use ($ambito, $canonicalRoot, $observedPaths, $run): void {
                $this->ejecutarLoteAtomico($run, function () use ($archivosActivos, $ambito, $canonicalRoot, $observedPaths, $run): void {
                    $this->verificarYRenovarLease($ambito);
                    $this->verificarCancelacionSolicitada($run);

                    foreach ($archivosActivos as $activo) {
                        if (! isset($observedPaths[$activo->ruta_relativa])) {
                            $physicalPath = $canonicalRoot . '/' . $activo->ruta_relativa;
                            $parentDir = dirname($physicalPath);

                            // Si la carpeta contenedora no existe o no tiene permisos de lectura, no marcar desaparecido
                            if (! is_dir($parentDir) || ! is_readable($parentDir)) {
                                continue;
                            }

                            if (! file_exists($physicalPath)) {
                                $run->total_archivos_desaparecidos++;
                                $activo->estado_archivo = BiometricoEstadoArchivo::DESAPARECIDO;
                                $activo->observaciones = substr('[DESAPARECIDO] No encontrado durante la exploración completa', 0, 255);
                                $activo->save();

                                $this->registrarIncidencia($run, [
                                    'tipo' => BiometricoIncidenciaTipo::ARCHIVO_DESAPARECIDO,
                                    'severidad' => BiometricoIncidenciaSeveridad::MEDIA,
                                    'codigo' => 'ARCHIVO_DESAPARECIDO',
                                    'descripcion' => "Archivo físico ausente en almacenamiento tras escaneo completo: {$activo->ruta_relativa}",
                                    'ruta_relativa' => $activo->ruta_relativa,
                                    'nombre_archivo' => $activo->nombre_archivo ?? basename($activo->ruta_relativa),
                                    'cuip_detectado' => $activo->cuip_normalizado,
                                    'categoria' => $activo->categoria,
                                ], false);

                                if ($activo->cuip_normalizado !== null) {
                                    $exp = BiometricoExpediente::where('cuip', $activo->cuip_normalizado)
                                        ->where('ambito', $ambito->value)
                                        ->lockForUpdate()
                                        ->first();

                                    if ($exp !== null) {
                                        $this->recalcularCompletitudActual($exp);
                                        $exp->save();
                                    }
                                }
                            }
                        }
                    }
                    $run->save();
                });
            });
    }

    /**
     * AUD-243-001 / NUEVO-243-003 / H-02: Adquisición atómica del lease del ámbito con fencing token.
     * La corrida se crea en la misma transacción: si el ámbito está ocupado no queda ninguna corrida huérfana.
     *
     * - Lease vigente de otro propietario: siempre se rechaza (también con --force, que solo confirma la ejecución).
     * - Lease vencido de otro propietario: solo se recupera con $takeover (el operador verificó que el proceso murió);
     *   el fencing token se incrementa y la corrida anterior se cierra como INTERRUMPIDA (o CANCELADA si lo pidió).
     * - Reanudación: la corrida de origen se bloquea y se marca como reanudada, para que nadie más la reanude.
     */
    protected function adquirirLease(
        BiometricoAmbito $ambito,
        array $runAttributes,
        bool $takeover = false,
        ?int $reanudarRunId = null
    ): BiometricoIndexRun {
        try {
            return DB::transaction(function () use ($ambito, $runAttributes, $takeover, $reanudarRunId): BiometricoIndexRun {
                /** @var BiometricoIndexLease|null $lease */
                $lease = BiometricoIndexLease::query()
                    ->where('ambito', $ambito->value)
                    ->lockForUpdate()
                    ->first();

                $esAjeno = $lease !== null && $lease->owner_token !== $this->ownerToken;

                if ($esAjeno && ! $lease->isExpired()) {
                    throw new BiometricoLeaseOcupadoException(
                        "Ya existe un lease de indexación activo para el ámbito {$ambito->value} (corrida {$lease->biometrico_index_run_id}, host {$lease->worker_host}, PID {$lease->worker_pid}, vigente hasta {$lease->lease_expires_at} UTC). "
                        . 'Espere a que termine o cancélela con --cancel; --force solo confirma la ejecución y nunca desplaza a un propietario vigente.'
                    );
                }

                if ($esAjeno && ! $takeover) {
                    throw new BiometricoLeaseOcupadoException(
                        "El lease del ámbito {$ambito->value} está vencido desde {$lease->lease_expires_at} UTC (corrida {$lease->biometrico_index_run_id}, host {$lease->worker_host}, PID {$lease->worker_pid}). "
                        . 'Verifique que ese proceso ya no esté activo y repita con --takeover para recuperarlo.'
                    );
                }

                // El fencing solo sube en una adquisición nueva sobre un lease existente (takeover); nunca al renovar.
                $this->fencingToken = match (true) {
                    $lease === null => 1,
                    $esAjeno => (int) $lease->fencing_token + 1,
                    default => (int) $lease->fencing_token,
                };
                $runAttributes['metadata']['owner_token'] = $this->ownerToken;
                $runAttributes['metadata']['fencing_token'] = $this->fencingToken;
                if ($esAjeno) {
                    $runAttributes['metadata']['takeover_de_run_id'] = $lease->biometrico_index_run_id;
                }

                /** @var BiometricoIndexRun $run */
                $run = BiometricoIndexRun::create($runAttributes);

                if ($esAjeno) {
                    $this->cerrarCorridaDesplazada($lease, $run);
                }

                if ($reanudarRunId !== null) {
                    $this->marcarOrigenReanudado($reanudarRunId, $run);
                }

                $now = now();
                $datosLease = [
                    'owner_token' => $this->ownerToken,
                    'biometrico_index_run_id' => $run->id,
                    'fencing_token' => $this->fencingToken,
                    'lease_expires_at' => $now->copy()->addSeconds($this->leaseTtlSeconds),
                    'heartbeat_at' => $now,
                    'worker_pid' => getmypid() ?: null,
                    'worker_host' => substr((string) gethostname(), 0, 100) ?: null,
                ];

                if ($lease === null) {
                    BiometricoIndexLease::create(['ambito' => $ambito->value] + $datosLease);
                } else {
                    $lease->update($datosLease);
                }

                return $run;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new BiometricoLeaseOcupadoException(
                "Otro proceso adquirió el lease del ámbito {$ambito->value} en el mismo instante. No se creó ninguna corrida; reintente cuando termine.",
                0,
                $e
            );
        } catch (QueryException $e) {
            if ((new ConcurrencyErrorDetector())->causedByConcurrencyError($e)) {
                throw new BiometricoLeaseOcupadoException(
                    "El lease del ámbito {$ambito->value} está bloqueado por otro proceso (lote en curso o adquisición simultánea). No se creó ninguna corrida; reintente más tarde.",
                    0,
                    $e
                );
            }

            throw $e;
        }
    }

    /**
     * Cierra la corrida del propietario desplazado por --takeover sin alterar su identidad histórica.
     * Si el operador había pedido cancelarla, se respeta como CANCELADA.
     */
    protected function cerrarCorridaDesplazada(BiometricoIndexLease $lease, BiometricoIndexRun $nueva): void
    {
        $anterior = BiometricoIndexRun::query()->whereKey($lease->biometrico_index_run_id)->lockForUpdate()->first();
        if ($anterior === null || $anterior->estado !== BiometricoIndexRunEstado::EN_EJECUCION) {
            return;
        }

        $motivoCancelacion = $anterior->metadata['cancelacion_motivo'] ?? null;
        if ($motivoCancelacion !== null) {
            $anterior->marcarCancelada((string) $motivoCancelacion);

            return;
        }

        $anterior->marcarInterrumpida(
            "Lease expirado y recuperado por nuevo worker mediante --takeover (corrida {$nueva->id}, fencing {$lease->fencing_token} → {$this->fencingToken})."
        );
    }

    /**
     * Revalida bajo bloqueo que la corrida de origen sigue siendo reanudable y la marca como reanudada.
     */
    protected function marcarOrigenReanudado(int $origenId, BiometricoIndexRun $nueva): void
    {
        $origen = BiometricoIndexRun::query()->whereKey($origenId)->lockForUpdate()->first();
        $reanudables = [
            BiometricoIndexRunEstado::INTERRUMPIDA,
            BiometricoIndexRunEstado::CANCELADA,
            BiometricoIndexRunEstado::FALLIDA,
        ];

        if ($origen === null || ! in_array($origen->estado, $reanudables, true) || ! empty($origen->metadata['reanudada_por_uuid'])) {
            throw new RuntimeException("La corrida {$origenId} ya fue reanudada por otro proceso o dejó de ser reanudable. No se creó ninguna corrida.");
        }

        $origen->metadata = array_merge($origen->metadata ?? [], [
            'reanudada_por_uuid' => $nueva->uuid,
            'reanudada_por_run_id' => $nueva->id,
        ]);
        $origen->save();
    }

    /**
     * AUD-243-001 / H-02: Heartbeat con fencing. Debe ejecutarse dentro de la transacción del lote:
     * el UPDATE condicional bloquea la fila del lease hasta el commit, así nadie adquiere el ámbito
     * entre esta validación y las escrituras del lote. Renueva aunque el lease haya vencido si nadie
     * lo tomó (owner y fencing siguen coincidiendo); si no coincide exactamente una fila, el proceso
     * perdió la propiedad y aborta antes de escribir.
     * Requiere PDO::MYSQL_ATTR_FOUND_ROWS (config/database.php) para contar filas coincidentes y no solo cambiadas.
     */
    protected function verificarYRenovarLease(BiometricoAmbito $ambito): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La renovación del lease debe ejecutarse dentro de la transacción del lote.');
        }

        $now = now();
        $renovadas = DB::table('biometrico_index_leases')
            ->where('ambito', $ambito->value)
            ->where('owner_token', $this->ownerToken)
            ->where('fencing_token', $this->fencingToken)
            ->update([
                'lease_expires_at' => $now->copy()->addSeconds($this->leaseTtlSeconds),
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);

        if ($renovadas !== 1) {
            throw new BiometricoLeasePerdidoException(
                "El worker {$this->ownerToken} perdió el lease de indexación del ámbito {$ambito->value} (fencing token {$this->fencingToken} ya no vigente). El lote en curso se revirtió y el proceso se detuvo."
            );
        }
    }

    /**
     * Punto seguro de cancelación (inicio de lote, dentro de su transacción): atiende la solicitud
     * registrada por --cancel antes de escribir nada del lote.
     */
    protected function verificarCancelacionSolicitada(BiometricoIndexRun $run): void
    {
        $metadata = BiometricoIndexRun::query()->whereKey($run->id)->lockForUpdate()->value('metadata');
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        if (! empty($metadata['cancelacion_solicitada_at'])) {
            throw new BiometricoIndexacionCanceladaException(
                (string) ($metadata['cancelacion_motivo'] ?? 'Cancelación solicitada por el operador.')
            );
        }
    }

    /**
     * Aplica una transición terminal solo si la corrida sigue EN_EJECUCION en BD: nunca sobrescribe
     * CANCELADA, INTERRUMPIDA o COMPLETADA registradas por --cancel, --takeover u otra causa previa.
     */
    protected function cerrarCorridaSiSigueActiva(BiometricoIndexRun $run, callable $marcar): void
    {
        if ($run->es_dry_run) {
            $marcar($run);

            return;
        }

        DB::transaction(function () use ($run, $marcar): void {
            $estadoActual = BiometricoIndexRun::query()->whereKey($run->id)->lockForUpdate()->value('estado');

            if ($estadoActual !== BiometricoIndexRunEstado::EN_EJECUCION) {
                $run->refresh();

                return;
            }

            $marcar($run);
        });
    }

    /**
     * Ejecuta un lote en una transacción con reintento ante deadlock o espera de bloqueo agotada.
     * Los contadores en memoria de la corrida se restauran antes de cada intento y cuando el lote
     * se revierte, para que solo reflejen lo confirmado en BD.
     */
    protected function ejecutarLoteAtomico(BiometricoIndexRun $run, callable $operacion): void
    {
        $confirmado = $run->getAttributes();

        try {
            DB::transaction(function () use ($run, $operacion, $confirmado): void {
                $run->setRawAttributes($confirmado);
                $operacion();
            }, self::INTENTOS_POR_LOTE);
        } catch (\Throwable $e) {
            $run->setRawAttributes($confirmado);

            throw $e;
        }
    }

    /**
     * Libera el lease al concluir o abortar la ejecución validando owner_token y fencing_token.
     */
    protected function liberarLease(BiometricoAmbito $ambito): void
    {
        try {
            DB::table('biometrico_index_leases')
                ->where('ambito', $ambito->value)
                ->where('owner_token', $this->ownerToken)
                ->where('fencing_token', $this->fencingToken)
                ->delete();
        } catch (\Throwable) {
            // Ignorar fallos de liberación al cierre
        }
    }

    protected function registrarIncidencia(BiometricoIndexRun $run, array $datos, bool $dryRun): void
    {
        $run->total_incidencias++;

        if ($dryRun) {
            return;
        }

        $ambitoEnum = BiometricoAmbito::tryFrom((string) $run->ambito) ?? BiometricoAmbito::NO_RESUELTO;

        BiometricoIncidencia::create([
            'biometrico_index_run_id' => $run->id,
            'tipo' => $datos['tipo'],
            'severidad' => $datos['severidad'],
            'codigo' => $datos['codigo'],
            'ambito' => $ambitoEnum,
            'categoria' => $datos['categoria'] ?? null,
            'ruta_relativa' => $datos['ruta_relativa'] ?? null,
            'nombre_archivo' => $datos['nombre_archivo'] ?? null,
            'cuip_detectado' => $datos['cuip_detectado'] ?? null,
            'descripcion' => $datos['descripcion'],
            'detalles' => $datos['detalles'] ?? null,
        ]);
    }

    protected function manejarEntradaAnomala(BiometricScanEntry $entry, BiometricoIndexRun $run, bool $dryRun): void
    {
        $run->total_archivos_invalidos++;

        if ($entry->tipoEntrada === 'directorio_inaccesible') {
            $this->registrarIncidencia($run, [
                'tipo' => BiometricoIncidenciaTipo::ARCHIVO_ILEGIBLE,
                'severidad' => BiometricoIncidenciaSeveridad::ALTA,
                'codigo' => $entry->codigoError ?? 'DIR_INACCESIBLE',
                'descripcion' => $entry->motivoRechazo ?? 'Directorio no accesible para lectura',
                'ruta_relativa' => $entry->rutaRelativa,
                'nombre_archivo' => $entry->nombreArchivo,
            ], $dryRun);

            return;
        }

        if ($entry->tipoEntrada === 'ciclo_detectado') {
            $this->registrarIncidencia($run, [
                'tipo' => BiometricoIncidenciaTipo::RUTA_NO_PERMITIDA,
                'severidad' => BiometricoIncidenciaSeveridad::CRITICA,
                'codigo' => 'DIR_CYCLE_DETECTED',
                'descripcion' => $entry->motivoRechazo ?? 'Ciclo recursivo detectado en junction',
                'ruta_relativa' => $entry->rutaRelativa,
                'nombre_archivo' => $entry->nombreArchivo,
            ], $dryRun);

            return;
        }

        if ($entry->tipoEntrada === 'enlace_no_permitido' || $entry->esEnlace) {
            $this->registrarIncidencia($run, [
                'tipo' => BiometricoIncidenciaTipo::RUTA_NO_PERMITIDA,
                'severidad' => BiometricoIncidenciaSeveridad::ALTA,
                'codigo' => $entry->codigoError ?? 'SYMLINK_RECHAZADO',
                'descripcion' => $entry->motivoRechazo ?? 'Enlace simbólico no permitido',
                'ruta_relativa' => $entry->rutaRelativa,
                'nombre_archivo' => $entry->nombreArchivo,
            ], $dryRun);

            return;
        }

        if (! $entry->esLegible) {
            $this->registrarIncidencia($run, [
                'tipo' => BiometricoIncidenciaTipo::ARCHIVO_ILEGIBLE,
                'severidad' => BiometricoIncidenciaSeveridad::ALTA,
                'codigo' => $entry->codigoError ?? 'ARCHIVO_NO_LEGIBLE',
                'descripcion' => $entry->motivoRechazo ?? 'Archivo sin permisos de lectura',
                'ruta_relativa' => $entry->rutaRelativa,
                'nombre_archivo' => $entry->nombreArchivo,
            ], $dryRun);
        }
    }

    protected function inferMimeType(string $extension): string
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'wsq' => 'image/x-wsq',
            default => 'application/octet-stream',
        };
    }
}
