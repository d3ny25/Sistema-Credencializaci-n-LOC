<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SolicitudElemento;
use App\Support\MotivoDescarte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClasificarMotivosHistoricosCommand extends Command
{
    protected $signature = 'credencializacion:clasificar-motivos
                            {--force : Aplica las modificaciones reales en la base de datos bajo protección transaccional}
                            {--dry-run : Ejecuta en modo simulación de solo lectura (comportamiento predeterminado)}';

    protected $description = 'Clasifica de forma estructurada e idempotente los motivos de descarte históricos mediante reglas deterministas';

    /**
     * Patrones de expresión regular anclados para mapeo unívoco y determinista.
     *
     * @var array<string, string>
     */
    public const PATRONES = [
        MotivoDescarte::CUIP_VACIO => '/^El elemento no cuenta con una clave CUIP válida para su identificación institucional\.$/',
        MotivoDescarte::CUIP_DUPLICADO => '/^CUIP duplicado dentro de la misma solicitud \(presente en las filas .*\)\. Por política de integridad institucional, todas las filas con CUIP duplicado quedan descartadas\.$/',
        MotivoDescarte::CUIP_NO_LOCALIZADO => '/^El CUIP \'.*\' no fue localizado en el padrón oficial vigente de personal autorizado de LOC\.$/',
        MotivoDescarte::EXP_ALTA_ACTIVA => '/^El elemento cuenta con una credencial oficial activa con estatus ALTA \(Folio: .*, Oficio: .*\)\. Bloquea nuevo trámite ordinario\.$/',
        MotivoDescarte::EXP_SITUACION_ESPECIAL => '/^El elemento se encuentra en SITUACIÓN ESPECIAL \(.*\)\. Bloquea nuevo trámite ordinario\.$/',
        MotivoDescarte::EXP_CANCELACION => '/^El último antecedente oficial del elemento corresponde a una CANCELACIÓN de credencial\. Bloquea el trámite ordinario y requiere resolución administrativa previa\.$/',
        MotivoDescarte::EXP_ESTATUS_DESCONOCIDO => '/^El estatus de la credencial oficial más reciente \(\'.*\'\) no es reconocido para la tramitación ordinaria\.$/',
        MotivoDescarte::ARM_SIN_ASIGNACION => '/^El elemento no cuenta con ninguna matrícula de arma asignada \(se requiere al menos arma corta, larga o ambas\)\.$/',
        MotivoDescarte::ARM_CORTA_NO_LOCALIZADA => '/^El arma corta con matrícula \'.*\' no fue localizada en el padrón oficial de armamento LOC\.$/',
        MotivoDescarte::ARM_CORTA_ESTATUS_INVALIDO => '/^El arma corta con matrícula \'.*\' tiene estatus \'.*\' en el padrón oficial de armamento \(se requiere estatus exactamente \'FISICA\'\)\.$/',
        MotivoDescarte::ARM_CORTA_CUPO_EXCEDIDO => '/^El arma corta con matrícula \'.*\' excede el límite máximo de 3 asignaciones activas con estatus ALTA \(cuenta actualmente con \d+ asignaciones activas\)\.$/',
        MotivoDescarte::ARM_LARGA_NO_LOCALIZADA => '/^El arma larga con matrícula \'.*\' no fue localizada en el padrón oficial de armamento LOC\.$/',
        MotivoDescarte::ARM_LARGA_ESTATUS_INVALIDO => '/^El arma larga con matrícula \'.*\' tiene estatus \'.*\' en el padrón oficial de armamento \(se requiere estatus exactamente \'FISICA\'\)\.$/',
        MotivoDescarte::ARM_LARGA_CUPO_EXCEDIDO => '/^El arma larga con matrícula \'.*\' excede el límite máximo de 3 asignaciones activas con estatus ALTA \(cuenta actualmente con \d+ asignaciones activas\)\.$/',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $explicitDryRun = (bool) $this->option('dry-run');

        // Conflicto de opciones: Rechazar explícitamente si se combinan ambas
        if ($force && $explicitDryRun) {
            $this->error('Conflicto de opciones: No se puede especificar simultáneamente --force y --dry-run. Operación cancelada por seguridad.');
            return Command::FAILURE;
        }

        // Por defecto es simulación de solo lectura, a menos que se haya pasado --force explícitamente
        $isDryRun = ! $force;

        $this->info('=== Herramienta de Clasificación de Motivos Históricos LOC ===');
        if ($isDryRun) {
            $this->warn('MODO SIMULACIÓN (DRY-RUN) ACTIVO: Operación de solo lectura. No se modificará la base de datos.');
            $this->comment('Para aplicar las modificaciones reales, ejecute el comando con la opción explícita --force.');
        } else {
            $this->alert('MODO ESCRITURA ACTIVO (--force): Se aplicarán modificaciones en la base de datos bajo protección transaccional.');
        }

        $elementos = SolicitudElemento::all();

        $totalElementos = $elementos->count();
        $totalSinMotivos = 0;
        $totalYaClasificados = 0;
        $totalClasificables = 0;
        $totalAmbiguos = 0;
        $totalContradicciones = 0;
        $conteoPorCodigo = [];
        $registrosAActualizar = [];

        foreach (MotivoDescarte::all() as $code) {
            $conteoPorCodigo[$code] = 0;
        }

        foreach ($elementos as $elem) {
            $motivos = $elem->motivos_descarte;

            if (empty($motivos)) {
                $totalSinMotivos++;
                continue;
            }

            // Detección de contradicciones entre motivos_descarte y motivo_rechazo
            $rechazoTexto = trim((string) $elem->motivo_rechazo);
            $esperadoTexto = implode(' | ', $motivos);
            if ($rechazoTexto !== '' && $rechazoTexto !== $esperadoTexto) {
                $totalContradicciones++;
                $this->warn("Contradicción detectada en Elemento ID {$elem->id} (Fila #{$elem->numero_fila}): motivo_rechazo discrepa de motivos_descarte. El registro queda excluido de la clasificación automática.");
                continue;
            }

            $codigosElemento = [];
            $tieneAmbiguo = false;

            foreach ($motivos as $motivoStr) {
                $motivoStr = trim((string) $motivoStr);
                $codigoAsociado = null;

                foreach (self::PATRONES as $code => $regex) {
                    if (preg_match($regex, $motivoStr)) {
                        $codigoAsociado = $code;
                        break;
                    }
                }

                if ($codigoAsociado !== null) {
                    $codigosElemento[] = $codigoAsociado;
                    $conteoPorCodigo[$codigoAsociado]++;
                } else {
                    $tieneAmbiguo = true;
                    $codigosElemento[] = MotivoDescarte::MOT_SIN_CLASIFICAR;
                    $conteoPorCodigo[MotivoDescarte::MOT_SIN_CLASIFICAR]++;
                    $this->warn("Motivo no clasificado (ambiguo) en Elemento ID {$elem->id}: '{$motivoStr}'");
                }
            }

            $codigosElemento = array_values(array_unique($codigosElemento));

            if ($tieneAmbiguo) {
                $totalAmbiguos++;
            } else {
                $totalClasificables++;
            }

            // Si el elemento ya tiene asignados exactamente estos mismos códigos, se contabiliza como ya clasificado
            if (! empty($elem->codigos_descarte) && $elem->codigos_descarte === $codigosElemento) {
                $totalYaClasificados++;
                continue;
            }

            $registrosAActualizar[] = [
                'id' => $elem->id,
                'codigos' => $codigosElemento,
            ];
        }

        $this->newLine();
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Total elementos inspeccionados', $totalElementos],
                ['Elementos sin motivos (Aptos o sin evaluar)', $totalSinMotivos],
                ['Elementos ya clasificados previamente', $totalYaClasificados],
                ['Elementos clasificables con certeza unívoca', $totalClasificables],
                ['Elementos con motivos ambiguos / sin clasificar', $totalAmbiguos],
                ['Elementos con contradicciones históricas (bloqueados)', $totalContradicciones],
            ]
        );

        $this->newLine();
        $this->info('Distribución de códigos identificados:');
        $filasCodigos = [];
        foreach ($conteoPorCodigo as $codigo => $cantidad) {
            if ($cantidad > 0 || $codigo === MotivoDescarte::MOT_SIN_CLASIFICAR) {
                $filasCodigos[] = [
                    $codigo,
                    MotivoDescarte::label($codigo),
                    $cantidad,
                ];
            }
        }
        $this->table(['Código', 'Etiqueta Institucional', 'Ocurrencias'], $filasCodigos);

        if (! $isDryRun) {
            if (empty($registrosAActualizar)) {
                $this->newLine();
                $this->info('Todos los elementos elegibles ya cuentan con su clasificación estructurada. No se requieren cambios en la base de datos.');
                return Command::SUCCESS;
            }

            $this->newLine();
            $this->info('Procediendo con la actualización real bajo transacción de ' . count($registrosAActualizar) . ' registros...');

            try {
                DB::transaction(function () use ($registrosAActualizar) {
                    foreach ($registrosAActualizar as $reg) {
                        DB::table('solicitud_elementos')
                            ->where('id', $reg['id'])
                            ->update([
                                'codigos_descarte' => json_encode($reg['codigos'], JSON_UNESCAPED_UNICODE),
                            ]);
                    }
                });

                $this->info('Clasificación histórica aplicada exitosamente de forma transaccional e idempotente.');
            } catch (\Throwable $e) {
                $this->error("Error crítico durante la persistencia transaccional: {$e->getMessage()}. Se realizó rollback total de los cambios.");
                return Command::FAILURE;
            }
        } else {
            $this->newLine();
            $this->comment('Simulación concluida. Ningún registro fue modificado en la base de datos.');
        }

        return Command::SUCCESS;
    }
}
