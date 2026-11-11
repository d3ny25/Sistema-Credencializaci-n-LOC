<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LocCredentialsWeaponsReconciler;
use Illuminate\Console\Command;
use Throwable;

class ReconcileLocCredentialsWeaponsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loc:conciliar-armas-vigencias
                            {--dry-run : Ejecutar en modo simulación de solo lectura (predeterminado)}
                            {--execute : Ejecutar la conciliación real y persistir los cambios en base de datos}
                            {--chunk=500 : Tamaño del bloque de registros por transacción}
                            {--force : Omitir confirmación interactiva en modo --execute}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Concilia arma_corta_id y arma_larga_id en loc_vigencias_credenciales contra loc_armas por matrícula exacta.';

    public function handle(LocCredentialsWeaponsReconciler $reconciler): int
    {
        $isExecute = (bool) $this->option('execute');
        $isDryRunExplicit = (bool) $this->option('dry-run');

        // Modo seguro: por defecto es simulación a menos que se especifique --execute sin --dry-run
        $dryRun = ! $isExecute || $isDryRunExplicit;
        $chunkSize = (int) $this->option('chunk');
        $force = (bool) $this->option('force');

        $this->newLine();
        $this->line('========================================================================');
        $this->line('  SISTEMA LOC — CONCILIACIÓN DE ARMAMENTO EN VIGENCIAS DE CREDENCIALES');
        $this->line('========================================================================');

        if ($dryRun) {
            $this->info('  MODO DE OPERACIÓN: [SIMULACIÓN / DRY-RUN]');
            $this->comment('  (No se escribirán ni modificarán registros en la base de datos)');
        } else {
            $this->warn('  MODO DE OPERACIÓN: [PERSISTENCIA REAL / EXECUTE]');
            $this->warn('  (Se actualizarán arma_corta_id y arma_larga_id en loc_vigencias_credenciales)');
        }
        $this->line("  Tamaño de bloque: {$chunkSize} filas por transacción.");
        $this->line('------------------------------------------------------------------------');

        // Paso previo: Proyección rápida de conteos
        $this->info('Calculando proyección previa de relaciones enlazables...');
        try {
            $preview = $reconciler->preview();
        } catch (Throwable $e) {
            $this->error('Error al calcular la proyección previa: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Clasificación de Relación', 'Arma Corta', 'Arma Larga', 'Total'],
            [
                [
                    'Enlace inequívoco nuevo',
                    number_format($preview['arma_corta']['enlace_nuevo']),
                    number_format($preview['arma_larga']['enlace_nuevo']),
                    number_format($preview['relaciones_nuevas_totales']),
                ],
                [
                    'Ya enlazada correctamente (omitir)',
                    number_format($preview['arma_corta']['ya_enlazada_correcta']),
                    number_format($preview['arma_larga']['ya_enlazada_correcta']),
                    number_format($preview['arma_corta']['ya_enlazada_correcta'] + $preview['arma_larga']['ya_enlazada_correcta']),
                ],
                [
                    'Conflicto contradictorio (bloquear)',
                    number_format($preview['arma_corta']['conflicto_no_modificado']),
                    number_format($preview['arma_larga']['conflicto_no_modificado']),
                    number_format($preview['arma_corta']['conflicto_no_modificado'] + $preview['arma_larga']['conflicto_no_modificado']),
                ],
                [
                    'Sin coincidencia en catálogo (conservar)',
                    number_format($preview['arma_corta']['sin_coincidencia_conservada']),
                    number_format($preview['arma_larga']['sin_coincidencia_conservada']),
                    number_format($preview['arma_corta']['sin_coincidencia_conservada'] + $preview['arma_larga']['sin_coincidencia_conservada']),
                ],
                [
                    'Matrícula vacía o NULL (conservar)',
                    number_format($preview['arma_corta']['vacia_o_null']),
                    number_format($preview['arma_larga']['vacia_o_null']),
                    number_format($preview['arma_corta']['vacia_o_null'] + $preview['arma_larga']['vacia_o_null']),
                ],
            ]
        );

        $this->line(sprintf(
            'Total vigencias evaluadas: %s | Vigencias que recibirán actualización: %s',
            number_format($preview['vigencias_totales_evaluadas']),
            number_format($preview['vigencias_con_actualizacion'])
        ));

        // En modo de ejecución real, requerir confirmación explícita
        if (! $dryRun) {
            if (! empty($preview['filas_conflicto'])) {
                $this->error(sprintf(
                    'Se detectaron %d conflictos contradictorios. La ejecución real se detiene por seguridad.',
                    count($preview['filas_conflicto'])
                ));

                return self::FAILURE;
            }

            if (! $force) {
                $confirmed = $this->confirm(
                    '¿Autoriza la ejecución real para vincular ' . number_format($preview['relaciones_nuevas_totales']) . ' relaciones de armamento?',
                    false
                );

                if (! $confirmed) {
                    $this->warn('Operación cancelada por el usuario. No se modificó ningún dato.');

                    return self::SUCCESS;
                }
            }
        }

        // Ejecutar el proceso
        $this->newLine();
        $this->info($dryRun ? 'Iniciando simulación detallada...' : 'Iniciando aplicación de cambios por bloques...');

        $progressBar = $this->output->createProgressBar($preview['vigencias_totales_evaluadas']);
        $progressBar->start();

        try {
            $results = $reconciler->run(
                chunkSize: $chunkSize,
                dryRun: $dryRun,
                progressCallback: function (int $processed, int $total) use ($progressBar): void {
                    $progressBar->setProgress(min($processed, $total));
                }
            );
        } catch (Throwable $e) {
            $progressBar->finish();
            $this->newLine(2);
            $this->error('Error durante la ejecución: ' . $e->getMessage());

            return self::FAILURE;
        }

        $progressBar->finish();
        $this->newLine(2);

        // Validación posterior en caso de ejecución real
        if (! $dryRun) {
            $this->info('Verificando condiciones de integridad posteriores a la conciliación...');
            $postCheck = $reconciler->verifyPostConditions($results);

            if (! $postCheck['es_valido']) {
                $this->error('ERROR CRÍTICO: Los conteos reales posteriores no concuerdan con la proyección esperada:');
                $this->line(sprintf('  - Arma corta enlazadas: %s (esperadas >= %s)', number_format($postCheck['corta_enlazadas_reales']), number_format($results['arma_corta']['enlace_nuevo'])));
                $this->line(sprintf('  - Arma larga enlazadas: %s (esperadas >= %s)', number_format($postCheck['larga_enlazadas_reales']), number_format($results['arma_larga']['enlace_nuevo'])));
                $this->line(sprintf('  - Columna legada arma_id alterada: %s (esperado 0)', number_format($postCheck['arma_id_no_nulas'])));
                $this->line(sprintf('  - Inconsistencias en no-localizadas: %s (esperado 0)', number_format($postCheck['inconsistencias_sin_coincidencia'])));

                return self::FAILURE;
            }

            $this->info('✓ Verificación posterior exitosa:');
            $this->line(sprintf('  - Arma corta enlazadas en BD: %s', number_format($postCheck['corta_enlazadas_reales'])));
            $this->line(sprintf('  - Arma larga enlazadas en BD: %s', number_format($postCheck['larga_enlazadas_reales'])));
            $this->line(sprintf('  - Columna legada arma_id preservada intacta: %s registros modificados', number_format($postCheck['arma_id_no_nulas'])));
            $this->line(sprintf('  - Matrículas no localizadas preservadas con NULL: %d', LocCredentialsWeaponsReconciler::KNOWN_UNMATCHED_CORTA_COUNT));
            $this->newLine();
            $this->info('¡Conciliación de armamento completada exitosamente!');
        } else {
            $this->info('Simulación completada satisfactoriamente. Ningún dato fue modificado.');
            $this->comment('Para aplicar los cambios reales cuando esté autorizado, use: php artisan loc:conciliar-armas-vigencias --execute');
        }

        return self::SUCCESS;
    }
}

