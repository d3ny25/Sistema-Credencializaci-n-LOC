<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoIndexRunEstado;
use App\Models\BiometricoIndexLease;
use App\Models\BiometricoIndexRun;
use App\Services\BiometricIndexingService;
use App\Services\BiometricScannerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Comando Artisan para la indexaciÃ³n, catalogaciÃ³n y control operativo de archivos biomÃ©tricos.
 *
 * Implementa los controles operativos de la Etapa 2.4.4:
 * - Modo seguro por defecto: rechazo estricto de acceso al repositorio original institucional.
 * - ValidaciÃ³n exhaustiva fail-closed de Ã¡mbitos institucionales y rutas canÃ³nicas.
 * - Soporte de simulaciÃ³n (dry-run) sin persistencia ni adquisiciÃ³n de leases.
 * - Control de ejecuciÃ³n por lotes configurables con checkpoints deterministas.
 * - ReanudaciÃ³n segura validando config_fingerprint, Ã¡mbito y raÃ­z canÃ³nica.
 * - Consulta de estado de corridas en modo solo lectura (--status).
 * - CancelaciÃ³n atÃ³mica y segura de corridas activas liberando leases (--cancel).
 * - NotificaciÃ³n en consola de mÃ©tricas, memoria pico y tabla de incidencias.
 */
class IndexBiometricosCommand extends Command
{
    /**
     * Nombre y firma del comando Artisan.
     *
     * @var string
     */
    protected $signature = 'loc:biometricos:indexar
        {--ambito= : Ãmbito institucional obligatorio (ESTATALES o PREVENCION)}
        {--path= : Ruta del directorio biomÃ©trico autorizado para prueba controlada}
        {--resume= : ID o UUID de la ejecuciÃ³n interrumpida o cancelada que se desea reanudar}
        {--batch-size=500 : TamaÃ±o del lote de procesamiento (1 a 5000)}
        {--limit= : LÃ­mite mÃ¡ximo de archivos a procesar en la prueba controlada}
        {--dry-run : Ejecuta en modo simulaciÃ³n sin persistir en la base de datos}
        {--status= : Consulta el estado detallado de una ejecuciÃ³n (ID o UUID) en modo solo lectura}
        {--cancel= : Cancela de forma segura una ejecuciÃ³n activa (ID o UUID) liberando leases}
        {--force : Fuerza la confirmaciÃ³n en entornos no interactivos}';

    /**
     * DescripciÃ³n del comando Artisan.
     *
     * @var string
     */
    protected $description = 'Indexa, valida y cataloga archivos biomÃ©tricos institucionales bajo controles periciales seguros.';

    protected BiometricIndexingService $indexingService;
    protected BiometricScannerService $scannerService;

    /**
     * Ruta protegida institucional inyectable para pruebas de contenciÃ³n.
     */
    protected ?string $protectedRootOverride = null;

    public function setProtectedRootOverride(?string $path): void
    {
        $this->protectedRootOverride = $path;
    }

    public function __construct(
        ?BiometricIndexingService $indexingService = null,
        ?BiometricScannerService $scannerService = null
    ) {
        parent::__construct();
        $this->indexingService = $indexingService ?? new BiometricIndexingService();
        $this->scannerService = $scannerService ?? $this->indexingService->getScanner();
    }

    /**
     * Ejecuta la lÃ³gica del comando.
     */
    public function handle(): int
    {
        // 1. Subcomando de consulta de estado (--status)
        $statusTarget = $this->option('status');
        if ($statusTarget !== null && trim((string) $statusTarget) !== '') {
            return $this->handleStatus(trim((string) $statusTarget));
        }

        // 2. Subcomando de cancelaciÃ³n segura (--cancel)
        $cancelTarget = $this->option('cancel');
        if ($cancelTarget !== null && trim((string) $cancelTarget) !== '') {
            return $this->handleCancel(trim((string) $cancelTarget));
        }

        // 3. EjecuciÃ³n de indexaciÃ³n (Normal, SimulaciÃ³n o ReanudaciÃ³n)
        return $this->handleIndexacion();
    }

    /**
     * Gestiona la consulta de solo lectura del estado de una corrida.
     */
    protected function handleStatus(string $runIdentifier): int
    {
        $this->info("Consultando estado de la ejecuciÃ³n [{$runIdentifier}]...");

        try {
            $run = $this->indexingService->consultarEstado($runIdentifier);
        } catch (Throwable $e) {
            $this->error('Error al consultar el estado de la corrida: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($run === null) {
            $this->error("No se encontrÃ³ ningÃºn registro de ejecuciÃ³n con el identificador [{$runIdentifier}].");

            return Command::FAILURE;
        }

        $this->line('');
        $this->info('================================================================');
        $this->info('       ESTADO DE EJECUCIÃ“N BIOMÃ‰TRICA â€” LOC NO. 139             ');
        $this->info('================================================================');

        $estadoColor = match ($run->estado) {
            BiometricoIndexRunEstado::COMPLETADA => 'info',
            BiometricoIndexRunEstado::COMPLETADA_CON_INCIDENCIAS => 'comment',
            BiometricoIndexRunEstado::EN_EJECUCION => 'question',
            BiometricoIndexRunEstado::INTERRUMPIDA, BiometricoIndexRunEstado::CANCELADA => 'warn',
            default => 'error',
        };

        $this->table(
            ['ParÃ¡metro', 'Valor Registrado'],
            [
                ['ID / UUID', "{$run->id} / {$run->uuid}"],
                ['Ãmbito Institucional', $run->ambito],
                ['Estado Terminal / Actual', "<{$estadoColor}>{$run->estado->value} ({$run->estado->label()})</{$estadoColor}>"],
                ['Modo SimulaciÃ³n (Dry Run)', $run->es_dry_run ? 'SÃ (Sin escrituras en BD)' : 'NO (Persistencia activa)'],
                ['Fecha y Hora de Inicio', $run->iniciado_at ? $run->iniciado_at->format('Y-m-d H:i:s') : 'N/A'],
                ['Fecha y Hora de FinalizaciÃ³n', $run->finalizado_at ? $run->finalizado_at->format('Y-m-d H:i:s') : 'N/A'],
                ['DuraciÃ³n Total', "{$run->duracion_segundos} segundos"],
                ['Memoria Pico RAM', "{$run->memoria_pico_mb} MB"],
                ['Ãšltimo Checkpoint ID', $run->ultimo_checkpoint_id ?? 'N/A'],
                ['Ãšltima Ruta Procesada', $run->ultima_ruta_procesada ?? 'N/A'],
                ['Ãšltimo Directorio', $run->ultimo_directorio_procesado ?? 'N/A'],
                ['Motivo / Resumen de InterrupciÃ³n', $run->resumen_interrupcion ?? ($run->error_mensaje ?? 'Ninguno')],
            ]
        );

        $this->line('');
        $this->info('MÃ‰TRICAS DE PROCESAMIENTO:');
        $this->table(
            ['MÃ©trica', 'Total Archivos'],
            [
                ['Archivos Detectados en Repositorio', $run->total_archivos_detectados],
                ['Archivos Procesados Efectivamente', $run->total_archivos_procesados],
                ['Archivos Nuevos Registrados', $run->total_archivos_nuevos],
                ['Archivos Sin Cambios (Hash IdÃ©ntico)', $run->total_archivos_sin_cambios],
                ['Archivos Modificados (Hash Alterado)', $run->total_archivos_modificados],
                ['Archivos Duplicados por Contenido', $run->total_archivos_duplicados],
                ['Archivos Desaparecidos en Repositorio', $run->total_archivos_desaparecidos],
                ['Archivos InvÃ¡lidos o de Sistema', $run->total_archivos_invalidos],
                ['Total Incidencias Periciales', $run->total_incidencias],
            ]
        );

        // Si estÃ¡ en ejecuciÃ³n activa, consultar el lease activo
        if ($run->estado === BiometricoIndexRunEstado::EN_EJECUCION) {
            $lease = BiometricoIndexLease::where('biometrico_index_run_id', $run->id)->first();
            if ($lease !== null) {
                $this->line('');
                $this->warn('INFORMACIÃ“N DEL LEASE ACTIVO (CONCURRENCIA):');
                $this->table(
                    ['Propiedad', 'Valor'],
                    [
                        ['Worker Host', $lease->worker_host ?? 'N/A'],
                        ['Worker PID', $lease->worker_pid ?? 'N/A'],
                        ['Owner Token', $lease->owner_token],
                        ['Fencing Token', $lease->fencing_token],
                        ['Expira en', $lease->lease_expires_at],
                        ['Ãšltimo Heartbeat', $lease->heartbeat_at],
                    ]
                );
            }
        }

        // Desglose de incidencias si existen
        if ($run->incidencias->isNotEmpty()) {
            $this->line('');
            $this->info('RESUMEN DE INCIDENCIAS REGISTRADAS:');
            $porSeveridad = $run->incidencias->groupBy(fn ($i) => $i->severidad->value ?? (string) $i->severidad);
            $filasIncidencias = [];
            foreach ($porSeveridad as $sev => $items) {
                $filasIncidencias[] = [strtoupper($sev), count($items)];
            }
            $this->table(['Severidad', 'Cantidad'], $filasIncidencias);
        }

        return Command::SUCCESS;
    }

    /**
     * Gestiona la cancelaciÃ³n segura e idempotente de una corrida activa.
     */
    protected function handleCancel(string $runIdentifier): int
    {
        $this->warn("Solicitando cancelaciÃ³n controlada de la ejecuciÃ³n [{$runIdentifier}]...");

        try {
            $run = $this->indexingService->cancelarRun(
                $runIdentifier,
                'CancelaciÃ³n solicitada operativamente mediante comando Artisan loc:biometricos:indexar'
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        } catch (Throwable $e) {
            $this->error('Error inesperado al intentar cancelar la ejecuciÃ³n: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($run->estado === BiometricoIndexRunEstado::CANCELADA) {
            $this->info("La ejecuciÃ³n [{$run->uuid}] ha sido cancelada exitosamente.");
            $this->line("Estado resultante: {$run->estado->value}");
            $this->line("Ãšltimo checkpoint confirmado: {$run->ultima_ruta_procesada}");
            $this->line('Los leases asociados fueron liberados para evitar contenciÃ³n.');
        } else {
            $this->comment("La ejecuciÃ³n [{$run->uuid}] ya se encontraba en estado terminal [{$run->estado->value}]. No se requirieron acciones.");
        }

        return Command::SUCCESS;
    }

    /**
     * Gestiona el flujo principal de indexaciÃ³n, simulaciÃ³n o reanudaciÃ³n.
     */
    protected function handleIndexacion(): int
    {
        // 1. ValidaciÃ³n estricta de Ã¡mbito
        $ambitoStr = $this->option('ambito');
        if ($ambitoStr === null || trim((string) $ambitoStr) === '') {
            $this->error('El parÃ¡metro --ambito es obligatorio (Valores admitidos: ESTATALES o PREVENCION).');
            $this->line('Ejemplo de uso: php artisan loc:biometricos:indexar --ambito=ESTATALES --dry-run');

            return Command::INVALID;
        }

        $ambito = BiometricoAmbito::tryFrom(strtoupper(trim((string) $ambitoStr)));
        if ($ambito === null || ! in_array($ambito, [BiometricoAmbito::ESTATALES, BiometricoAmbito::PREVENCION], true)) {
            $this->error("El Ã¡mbito institucional '{$ambitoStr}' no es vÃ¡lido. Debe ser ESTATALES o PREVENCION.");

            return Command::INVALID;
        }

        // 2. ValidaciÃ³n de batch-size
        $batchSizeOpt = (int) $this->option('batch-size');
        if ($batchSizeOpt < 1 || $batchSizeOpt > 5000) {
            $this->error("El tamaÃ±o de lote (--batch-size={$batchSizeOpt}) debe ser un entero entre 1 y 5000.");

            return Command::INVALID;
        }

        // 3. ValidaciÃ³n de limit
        $limitOpt = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        if ($limitOpt !== null && $limitOpt <= 0) {
            $this->error("El lÃ­mite de procesamiento (--limit={$limitOpt}) debe ser un entero positivo mayor a cero.");

            return Command::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $resumeOpt = $this->option('resume');
        $isResume = ($resumeOpt !== null);
        $targetResumeIdOrUuid = $isResume && trim((string) $resumeOpt) !== '' && $resumeOpt !== '1'
            ? trim((string) $resumeOpt)
            : null;

        // 4. ValidaciÃ³n de seguridad de la ruta raÃ­z (MODO SEGURO FAIL-CLOSED)
        $rawPath = $this->option('path') ?? (string) config('filesystems.disks.biometricos.root');
        $validacionRuta = $this->validarRutaSegura($rawPath);
        if (! $validacionRuta['valido']) {
            foreach (explode("\n", $validacionRuta['mensaje']) as $linea) {
                if (trim($linea) !== '') {
                    $this->error($linea);
                }
            }

            return Command::FAILURE;
        }
        $canonicalRoot = $validacionRuta['ruta'];

        // 5. ValidaciÃ³n estructural de base de datos (AUD-244-005: solo requerida en ejecuciones persistentes)
        if (! $dryRun) {
            $validacionBD = $this->validarEstructuraBaseDatos();
            if (! $validacionBD['valido']) {
                $this->error('[ERROR DE ESQUEMA EN BASE DE DATOS]');
                $this->error('La base de datos no cuenta con la infraestructura biomÃ©trica completa requerida:');
                foreach ($validacionBD['errores'] as $err) {
                    $this->line(" - <fg=red>{$err}</>");
                }
                $this->line('AsegÃºrese de ejecutar las migraciones biomÃ©tricas en un entorno autorizado.');

                return Command::FAILURE;
            }
        }

        // PresentaciÃ³n de parÃ¡metros en consola
        $this->line('');
        $this->info('================================================================');
        $this->info('  SISTEMA ESTATAL LOC NO. 139 â€” INDEXADOR BIOMÃ‰TRICO (ETAPA 2.4)');
        $this->info('================================================================');
        $this->line(" Ãmbito Institucional : <comment>{$ambito->value}</comment>");
        $this->line(" Directorio RaÃ­z      : <comment>{$canonicalRoot}</comment>");
        $this->line(' Modo de OperaciÃ³n    : ' . ($dryRun ? '<fg=cyan>SIMULACIÃ“N (DRY-RUN / Sin escrituras en BD)</>' : '<fg=green>EJECUCIÃ“N CONTROLADA (Persistente)</>'));
        $this->line(" TamaÃ±o de Lote       : <comment>{$batchSizeOpt}</comment>");
        $this->line(' LÃ­mite de Archivos   : ' . ($limitOpt !== null ? "<comment>{$limitOpt}</comment>" : '<comment>Sin lÃ­mite (Exhaustivo)</comment>'));
        $this->line(' ReanudaciÃ³n          : ' . ($isResume ? '<fg=yellow>SÃ (Checkpoint previo)</>' : 'NO (Inicio fresco)'));
        if ($targetResumeIdOrUuid !== null) {
            $this->line(" Run Objetivo a Reanudar: <comment>{$targetResumeIdOrUuid}</comment>");
        }
        $this->line('================================================================');
        $this->line('');

        // 6. AutorizaciÃ³n operativa y confirmaciÃ³n (AUD-244-003)
        if (! $dryRun) {
            if (! $this->input->isInteractive()) {
                // En entornos no interactivos o desatendidos es ESTRICTAMENTE OBLIGATORIO --force
                if (! $this->option('force')) {
                    $this->error('[ERROR DE AUTORIZACIÃ“N] En entornos no interactivos o desatendidos es estrictamente obligatorio especificar la opciÃ³n --force para autorizar una ejecuciÃ³n persistente.');
                    $this->line('Para ejecutar una simulaciÃ³n segura sin persistencia, agregue la opciÃ³n --dry-run.');

                    return Command::INVALID;
                }
            } else {
                if (! $this->option('force')) {
                    if (! $this->confirm('Â¿Desea iniciar la ejecuciÃ³n controlada sobre el directorio especificado?', false)) {
                        $this->warn('OperaciÃ³n cancelada por el usuario.');

                        return Command::SUCCESS;
                    }
                }
            }
        }

        $progressBar = null;

        $progressCallback = function (int $procesados, int $detectados, BiometricoIndexRun $run) use (&$progressBar): void {
            if ($this->output->isVerbose()) {
                $mem = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
                $this->line(" [Progreso] Procesados: {$procesados} / Detectados: {$detectados} | Checkpoint: {$run->ultima_ruta_procesada} | RAM: {$mem} MB");
            } else {
                if ($progressBar === null && $detectados > 0) {
                    $progressBar = $this->output->createProgressBar();
                    $progressBar->setFormat(' %current% archivos procesados [%bar%] RAM: %memory%');
                    $progressBar->start();
                }
                if ($progressBar !== null) {
                    $progressBar->setProgress($procesados);
                }
            }
        };

        $force = (bool) $this->option('force');

        try {
            $run = $this->indexingService->indexar(
                ambito: $ambito,
                rootPath: $canonicalRoot,
                chunkSize: $batchSizeOpt,
                dryRun: $dryRun,
                resume: $isResume,
                limit: $limitOpt,
                progressCallback: $progressCallback,
                targetResumeIdOrUuid: $targetResumeIdOrUuid,
                force: $force
            );
        } catch (RuntimeException $e) {
            if ($progressBar !== null) {
                $progressBar->finish();
                $this->line('');
            }
            $this->error("\n[CONFLICTO DE CONCURRENCIA O LEASE]");
            $this->error($e->getMessage());

            return Command::FAILURE;
        } catch (InvalidArgumentException $e) {
            if ($progressBar !== null) {
                $progressBar->finish();
                $this->line('');
            }
            $this->error("\n[ERROR DE VALIDACIÃ“N O CONFIGURACIÃ“N]");
            $this->error($e->getMessage());

            return Command::FAILURE;
        } catch (Throwable $e) {
            if ($progressBar !== null) {
                $progressBar->finish();
                $this->line('');
            }
            $this->error("\n[ERROR CRÃTICO INESPERADO]");
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($progressBar !== null) {
            $progressBar->finish();
            $this->line('');
        }

        // PresentaciÃ³n final del resumen
        $this->line('');
        $this->info('================================================================');
        $this->info('           RESUMEN FINAL DE EJECUCIÃ“N BIOMÃ‰TRICA                ');
        $this->info('================================================================');

        $this->table(
            ['MÃ©trica de Control', 'Resultado'],
            [
                ['Identificador Run (UUID)', $run->uuid],
                ['Estado Final', $run->estado->value],
                ['Archivos Detectados', $run->total_archivos_detectados],
                ['Archivos Procesados', $run->total_archivos_procesados],
                ['Archivos Nuevos', $run->total_archivos_nuevos],
                ['Archivos Sin Cambios', $run->total_archivos_sin_cambios],
                ['Archivos Modificados', $run->total_archivos_modificados],
                ['Archivos Duplicados (SHA-256)', $run->total_archivos_duplicados],
                ['Archivos Desaparecidos', $run->total_archivos_desaparecidos],
                ['Archivos InvÃ¡lidos / Sistema', $run->total_archivos_invalidos],
                ['Total Incidencias Registradas', $run->total_incidencias],
                ['DuraciÃ³n Total', "{$run->duracion_segundos} seg"],
                ['Memoria Pico', "{$run->memoria_pico_mb} MB"],
            ]
        );

        if ($run->total_incidencias > 0) {
            $this->warn("Se detectaron {$run->total_incidencias} incidencias durante la indexaciÃ³n.");
            $this->line("Consulte los detalles ejecutando: php artisan loc:biometricos:indexar --status={$run->uuid}");
        }

        if ($run->estado === BiometricoIndexRunEstado::COMPLETADA) {
            $this->info('Proceso de indexaciÃ³n completado exitosamente.');

            return Command::SUCCESS;
        }

        if ($run->estado === BiometricoIndexRunEstado::COMPLETADA_CON_INCIDENCIAS) {
            $this->comment('Proceso completado con incidencias periciales registradas para revisiÃ³n.');

            return Command::SUCCESS;
        }

        $this->error("La ejecuciÃ³n terminÃ³ en estado: {$run->estado->value}");

        return Command::FAILURE;
    }

    /**
     * Valida de manera estricta y fail-closed la seguridad de la ruta especificada.
     * Combina filtrado nominal inicial, validaciÃ³n canÃ³nica estricta contra reparse points/symlinks,
     * segundo filtrado tras resoluciÃ³n y comparaciÃ³n pericial de identidad fÃ­sica (volumen y FRN/inodo NTFS).
     *
     * @return array{valido: bool, ruta: string, mensaje: string}
     */
    public function validarRutaSegura(?string $rawPath): array
    {
        if ($rawPath === null || trim($rawPath) === '') {
            return [
                'valido' => false,
                'ruta' => '',
                'mensaje' => 'No se especificÃ³ la ruta raÃ­z del repositorio ni se configurÃ³ filesystems.disks.biometricos.root.',
            ];
        }

        // 1. Filtrado pericial de repositorio protegido en pruebas automatizadas o vÃ­a override
        $normalized = str_replace('\\', '/', $rawPath);
        if ($this->protectedRootOverride !== null || app()->environment('testing')) {
            if (str_contains(strtolower($normalized), 'biometricos_estatal') || str_contains(strtolower($normalized), 'biometricos estatal')) {
                return [
                    'valido' => false,
                    'ruta' => '',
                    'mensaje' => "[BLOQUEO DE SEGURIDAD INSTITUCIONAL]\n" .
                        "EstÃ¡ estrictamente prohibido indexar o acceder al repositorio fÃ­sico original 'Biometricos_Estatal' durante la ejecuciÃ³n de pruebas.\n" .
                        'Para ejecutar pruebas controladas, proporcione un directorio sintÃ©tico temporal mediante la opciÃ³n --path=<directorio_sintetico>.',
                ];
            }

            if ($this->protectedRootOverride !== null && $this->esMismaRutaFisicaOAnidada($rawPath, $this->protectedRootOverride)) {
                return [
                    'valido' => false,
                    'ruta' => '',
                    'mensaje' => "[BLOQUEO DE SEGURIDAD INSTITUCIONAL]\n" .
                        "La ruta especificada '{$rawPath}' coincide con o estÃ¡ contenida dentro del repositorio protegido.\n" .
                        'Para ejecutar pruebas controladas, proporcione un directorio sintÃ©tico externo.',
                ];
            }
        }

        try {
            $canonical = $this->scannerService->validarRutaRaiz($rawPath);
        } catch (InvalidArgumentException $e) {
            return [
                'valido' => false,
                'ruta' => '',
                'mensaje' => 'Error de validaciÃ³n de ruta: ' . $e->getMessage(),
            ];
        }

        // 2. ComprobaciÃ³n fÃ­sica tras resoluciÃ³n canÃ³nica contra override
        if ($this->protectedRootOverride !== null && $this->esMismaRutaFisicaOAnidada($canonical, $this->protectedRootOverride)) {
            return [
                'valido' => false,
                'ruta' => '',
                'mensaje' => "[BLOQUEO DE SEGURIDAD INSTITUCIONAL]\n" .
                    "La ruta canÃ³nica resuelta coincide con o estÃ¡ contenida dentro del repositorio protegido.\n" .
                    'Para ejecutar pruebas controladas, proporcione un directorio sintÃ©tico externo.',
            ];
        }

        return [
            'valido' => true,
            'ruta' => $canonical,
            'mensaje' => '',
        ];
    }

    /**
     * Determina si la ruta candidata coincide fÃ­sicamente con la ruta protegida,
     * o si es un subdirectorio o ancestro de la misma, considerando unidades virtuales (subst),
     * aliases y resoluciÃ³n de dispositivos/inodos en sistemas NTFS.
     */
    public function esMismaRutaFisicaOAnidada(string $candidate, string $target): bool
    {
        $normCandidate = rtrim(str_replace('\\', '/', $candidate), '/');
        $normTarget = rtrim(str_replace('\\', '/', $target), '/');

        if ($normCandidate === '' || $normTarget === '') {
            return false;
        }

        // 1. ComparaciÃ³n nominal de cadenas normalizadas (case-insensitive)
        if (strcasecmp($normCandidate, $normTarget) === 0) {
            return true;
        }

        // 2. Subdirectorio nominal
        if (str_starts_with(strtolower($normCandidate) . '/', strtolower($normTarget) . '/')) {
            return true;
        }

        // 3. Ancestro nominal
        if (str_starts_with(strtolower($normTarget) . '/', strtolower($normCandidate) . '/')) {
            return true;
        }

        // 4. ComparaciÃ³n fÃ­sica por stat() (dev e ino) si ambas existen en disco
        if (file_exists($normCandidate) && file_exists($normTarget)) {
            $statCandidate = @stat($normCandidate);
            $statTarget = @stat($normTarget);

            if ($statCandidate !== false && $statTarget !== false) {
                // Mismo directorio fÃ­sico (identificador de volumen y FRN/inodo coinciden)
                if ($statCandidate['dev'] === $statTarget['dev'] && $statCandidate['ino'] === $statTarget['ino']) {
                    return true;
                }
            }

            // Verificar si el candidato es un subdirectorio fÃ­sico del target (caminando hacia la raÃ­z)
            $curr = $normCandidate;
            while (strlen($curr) > 3) {
                $parent = dirname($curr);
                if ($parent === $curr) {
                    break;
                }
                $statParent = @stat($parent);
                if ($statParent !== false && $statTarget !== false) {
                    if ($statParent['dev'] === $statTarget['dev'] && $statParent['ino'] === $statTarget['ino']) {
                        return true;
                    }
                }
                $curr = $parent;
            }

            // Verificar si el target es un subdirectorio fÃ­sico del candidato
            $currT = $normTarget;
            while (strlen($currT) > 3) {
                $parentT = dirname($currT);
                if ($parentT === $currT) {
                    break;
                }
                $statParentT = @stat($parentT);
                if ($statParentT !== false && $statCandidate !== false) {
                    if ($statParentT['dev'] === $statCandidate['dev'] && $statParentT['ino'] === $statCandidate['ino']) {
                        return true;
                    }
                }
                $currT = $parentT;
            }
        }

        return false;
    }

    /**
     * Comprueba exhaustivamente que las tablas y columnas crÃ­ticas requeridas
     * existan en la base de datos antes de permitir una ejecuciÃ³n persistente (AUD-244-005).
     *
     * @return array{valido: bool, errores: array<string>}
     */
    public function validarEstructuraBaseDatos(): array
    {
        $errores = [];

        $esquemaRequerido = [
            'biometrico_index_runs' => [
                'id', 'uuid', 'ambito', 'estado', 'es_dry_run', 'ultima_ruta_procesada', 'ultimo_checkpoint_id',
            ],
            'biometrico_archivos' => [
                'id', 'ambito', 'categoria', 'ruta_relativa', 'sha256', 'cuip_normalizado', 'estado_archivo',
            ],
            'biometrico_expedientes' => [
                'id', 'ambito', 'cuip', 'estado_revision', 'tiene_duplicados', 'tiene_anomalias',
            ],
            'biometrico_index_leases' => [
                'id', 'ambito', 'owner_token', 'fencing_token', 'lease_expires_at', 'heartbeat_at',
            ],
            'biometrico_incidencias' => [
                'id', 'biometrico_index_run_id', 'tipo', 'severidad', 'codigo', 'ruta_relativa', 'nombre_archivo',
            ],
            'biometrico_slot_candidatos' => [
                'id', 'biometrico_expediente_id', 'categoria', 'biometrico_archivo_id', 'estado_candidato',
            ],
            'biometrico_auditorias' => [
                'id', 'biometrico_archivo_id', 'evento', 'created_at',
            ],
        ];

        foreach ($esquemaRequerido as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                $errores[] = "Falta la tabla obligatoria: '{$tabla}'.";
                continue;
            }

            foreach ($columnas as $columna) {
                if (! Schema::hasColumn($tabla, $columna)) {
                    $errores[] = "En la tabla '{$tabla}' falta la columna obligatoria: '{$columna}'.";
                }
            }
        }

        return [
            'valido' => empty($errores),
            'errores' => $errores,
        ];
    }

    /**
     * Comprueba si las tablas biomÃ©tricas requeridas existen en la base de datos configurada.
     */
    protected function validarTablasBaseDatos(): bool
    {
        return $this->validarEstructuraBaseDatos()['valido'];
    }
}

