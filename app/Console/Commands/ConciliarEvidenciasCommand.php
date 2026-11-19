<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BiometricEvidenceStore;
use App\Services\CredencialPreparacionService;
use Illuminate\Console\Command;

/**
 * Fase 1B.2 — Conciliación del almacén de evidencia y de las preparaciones vencidas.
 * Por defecto solo informa y no lee el contenido: comprueba existencia, tamaño y huérfanos.
 * --integridad: además recalcula el SHA-256 de cada archivo (verificación criptográfica, costosa).
 * --aplicar: expira preparaciones vencidas y borra temporales .part abandonados (más de 24 h y sin
 * preparación vigente). Nunca modifica ni borra archivos publicados ni registros de evidencia.
 * La salida solo contiene hashes, identificadores y conteos: ningún dato personal.
 */
class ConciliarEvidenciasCommand extends Command
{
    protected $signature = 'credenciales:conciliar-evidencias
        {--aplicar : Expira preparaciones vencidas y borra temporales abandonados}
        {--integridad : Recalcula y compara el SHA-256 de todas las evidencias (lento en almacenes grandes)}';

    protected $description = 'Concilia el almacén de evidencia biométrica (temporales, huérfanos, existencia y tamaño; hash con --integridad) y las preparaciones vencidas.';

    public function handle(BiometricEvidenceStore $almacen, CredencialPreparacionService $preparaciones): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $integridad = (bool) $this->option('integridad');

        $expiradas = $aplicar ? $preparaciones->expirarVencidas() : 0;
        $informe = $almacen->conciliar($aplicar, 24, $integridad);
        $lista = static fn (array $ids): string => implode(', ', $ids) ?: 'ninguno';

        $this->line('Modo: '.($aplicar ? 'aplicar' : 'solo informe').' | verificación: '.($integridad ? 'integral (SHA-256)' : 'estructural (existencia y tamaño; sin hash)'));
        $this->line('Preparaciones vencidas marcadas como expiradas: '.$expiradas);
        $this->line('Temporales abandonados: '.count($informe['temporales_abandonados']).' (eliminados: '.$informe['temporales_eliminados'].')');
        $this->line('Archivos publicados sin registro (se conservan): '.count($informe['publicados_sin_registro']));
        foreach ($informe['publicados_sin_registro'] as $ruta) {
            $this->line('  - '.$ruta);
        }
        $this->line('Registros sin archivo: '.$lista($informe['registros_sin_archivo']));
        $this->line('Registros con tamaño discrepante: '.$lista($informe['registros_tamano_discrepante']));
        $this->line('Registros ilegibles: '.$lista($informe['registros_ilegibles']));
        $this->line('Registros con hash discrepante: '.($integridad ? $lista($informe['registros_con_hash_discrepante']) : 'no verificado (use --integridad)'));

        $integro = $informe['registros_sin_archivo'] === [] && $informe['registros_tamano_discrepante'] === []
            && $informe['registros_ilegibles'] === [] && $informe['registros_con_hash_discrepante'] === [];
        if (! $integro) {
            $this->error('Hay evidencia faltante, ilegible o alterada: revise y registre la incidencia correspondiente.');
        }

        return $integro ? self::SUCCESS : self::FAILURE;
    }
}
