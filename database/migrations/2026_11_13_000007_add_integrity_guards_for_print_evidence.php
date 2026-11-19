<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1B.2 — Restricciones de integridad e inmutabilidad para la evidencia de impresión.
 *
 * Laravel 13 no ofrece CHECK en el Schema Builder (validado en 1B.1) y SQLite no admite
 * ALTER TABLE ADD CONSTRAINT. Por motor:
 *   - MariaDB/MySQL: CHECK con ALTER TABLE; triggers BEFORE UPDATE/DELETE con SIGNAL.
 *   - SQLite (pruebas): triggers BEFORE INSERT/UPDATE con RAISE(ABORT) equivalentes a cada CHECK.
 *
 * Alcance real (no absoluto): TRUNCATE y DROP TRIGGER no se bloquean; quien tenga privilegios
 * TRIGGER/DROP/ALTER puede retirar estas protecciones. Las acciones en cascada de claves foráneas
 * no disparan triggers en MariaDB (este esquema usa restrict, sin cascadas hacia estas tablas).
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> nombre => [tabla, expresión con {n} = prefijo de fila] */
    private const CHECKS = [
        'chk_prep_estado' => ['credencial_preparaciones', "{n}estado IN ('preparada','confirmada','cancelada','expirada')"],
        'chk_prep_tipo' => ['credencial_preparaciones', "{n}tipo_operacion IN ('emision','reimpresion_historica')"],
        'chk_prep_origen' => ['credencial_preparaciones', "{n}origen IN ('nueva','migrada')"],
        'chk_prep_estado_evidencia' => ['credencial_preparaciones', "{n}estado_evidencia IN ('completa','inferida_pendiente_revision','parcial','no_recuperable')"],
        'chk_prep_reimpresion_origen' => ['credencial_preparaciones', "{n}tipo_operacion <> 'reimpresion_historica' OR {n}preparacion_origen_id IS NOT NULL"],
        'chk_prep_nueva_completa' => ['credencial_preparaciones', "{n}origen <> 'nueva' OR ({n}estado_evidencia = 'completa' AND {n}plantilla_config_sha256 IS NOT NULL AND {n}datos_variables_sha256 IS NOT NULL AND {n}manifiesto_sha256 IS NOT NULL AND {n}datos_variables_cifrados IS NOT NULL AND {n}plantilla_config_canonica IS NOT NULL)"],
        'chk_prep_bio_clasificacion' => ['credencial_preparacion_biometricos', "{n}clasificacion IN ('verificada','inferida','sin_contenido','desconocida')"],
        'chk_prep_bio_verificada' => ['credencial_preparacion_biometricos', "{n}clasificacion <> 'verificada' OR ({n}biometrico_evidencia_id IS NOT NULL AND {n}sha256 IS NOT NULL)"],
        'chk_imp_modalidad' => ['credencial_impresiones', "{n}modalidad_confirmacion IN ('normal','extemporanea')"],
        'chk_imp_extemporanea' => ['credencial_impresiones', "{n}modalidad_confirmacion <> 'extemporanea' OR {n}justificacion_extemporanea IS NOT NULL"],
        'chk_imp_rect_resultado' => ['credencial_impresion_rectificaciones', "{n}resultado_rectificado IN ('impresa','fallida') AND {n}resultado_rectificado <> {n}resultado_anterior"],
    ];

    /** Tablas de solo inserción: cualquier UPDATE o DELETE se rechaza. */
    private const SOLO_INSERCION = [
        'biometrico_evidencias',
        'credencial_preparacion_biometricos',
        'credencial_impresiones',
        'credencial_impresion_rectificaciones',
    ];

    /** Columnas del snapshot de una preparación que nunca cambian después de insertarse. */
    private const SNAPSHOT_PREPARACION = [
        'uuid', 'credencial_emitida_id', 'solicitud_elemento_id', 'biometrico_expediente_id', 'tipo_operacion',
        'preparacion_origen_id', 'origen', 'estado_evidencia', 'credential_template_version_id', 'plantilla_origen',
        'plantilla_version', 'plantilla_config_canonica', 'plantilla_config_sha256', 'datos_variables_cifrados',
        'datos_variables_sha256', 'campos_por_defecto', 'manifiesto_version', 'manifiesto_canonico', 'manifiesto_sha256',
        'render_huella', 'preparada_por_user_id', 'preparada_at', 'vista_expira_en', 'expira_en',
    ];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach (self::CHECKS as $nombre => [$tabla, $expresion]) {
                DB::statement("ALTER TABLE `{$tabla}` ADD CONSTRAINT `{$nombre}` CHECK (".str_replace('{n}', '', $expresion).')');
            }
            foreach (self::SOLO_INSERCION as $tabla) {
                foreach (['UPDATE', 'DELETE'] as $evento) {
                    $trigger = "trg_{$tabla}_no_".strtolower($evento);
                    DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE {$evento} ON `{$tabla}` FOR EACH ROW
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro de evidencia de solo insercion: {$evento} rechazado'");
                }
            }
            $iguales = implode(' AND ', array_map(static fn (string $c): string => "NEW.`{$c}` <=> OLD.`{$c}`", self::SNAPSHOT_PREPARACION));
            DB::unprepared("CREATE TRIGGER `trg_credencial_preparaciones_snapshot` BEFORE UPDATE ON `credencial_preparaciones` FOR EACH ROW
                BEGIN
                    IF NOT ({$iguales}) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'El snapshot de la preparacion es inmutable';
                    END IF;
                    IF OLD.estado <> 'preparada' AND NOT (NEW.estado <=> OLD.estado) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una preparacion en estado final no cambia de estado';
                    END IF;
                    IF OLD.primera_vista_at IS NOT NULL AND NOT (NEW.primera_vista_at <=> OLD.primera_vista_at) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La primera visualizacion se registra una sola vez';
                    END IF;
                END");
            DB::unprepared("CREATE TRIGGER `trg_credencial_preparaciones_no_delete` BEFORE DELETE ON `credencial_preparaciones` FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las preparaciones de impresion no se eliminan'");

            return;
        }

        if ($driver === 'sqlite') {
            foreach (self::CHECKS as $nombre => [$tabla, $expresion]) {
                $condicion = str_replace('{n}', 'NEW.', $expresion);
                foreach (['INSERT', 'UPDATE'] as $evento) {
                    $trigger = "{$nombre}_".strtolower($evento);
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$evento} ON {$tabla} WHEN NOT ({$condicion})
                        BEGIN SELECT RAISE(ABORT, 'CHECK constraint failed: {$nombre}'); END");
                }
            }
            foreach (self::SOLO_INSERCION as $tabla) {
                foreach (['UPDATE', 'DELETE'] as $evento) {
                    $trigger = "trg_{$tabla}_no_".strtolower($evento);
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$evento} ON {$tabla}
                        BEGIN SELECT RAISE(ABORT, 'Registro de evidencia de solo insercion: {$evento} rechazado'); END");
                }
            }
            $distintos = implode(' OR ', array_map(static fn (string $c): string => "NEW.{$c} IS NOT OLD.{$c}", self::SNAPSHOT_PREPARACION));
            DB::unprepared("CREATE TRIGGER trg_credencial_preparaciones_snapshot BEFORE UPDATE ON credencial_preparaciones
                WHEN ({$distintos}) OR (OLD.estado <> 'preparada' AND NEW.estado IS NOT OLD.estado)
                    OR (OLD.primera_vista_at IS NOT NULL AND NEW.primera_vista_at IS NOT OLD.primera_vista_at)
                BEGIN SELECT RAISE(ABORT, 'El snapshot de la preparacion es inmutable'); END");
            DB::unprepared("CREATE TRIGGER trg_credencial_preparaciones_no_delete BEFORE DELETE ON credencial_preparaciones
                BEGIN SELECT RAISE(ABORT, 'Las preparaciones de impresion no se eliminan'); END");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        $triggers = ['trg_credencial_preparaciones_snapshot', 'trg_credencial_preparaciones_no_delete'];
        foreach (self::SOLO_INSERCION as $tabla) {
            $triggers[] = "trg_{$tabla}_no_update";
            $triggers[] = "trg_{$tabla}_no_delete";
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach ($triggers as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS `{$trigger}`");
            }
            foreach (self::CHECKS as $nombre => [$tabla]) {
                DB::statement("ALTER TABLE `{$tabla}` DROP CONSTRAINT `{$nombre}`");
            }

            return;
        }

        if ($driver === 'sqlite') {
            foreach (array_keys(self::CHECKS) as $nombre) {
                $triggers[] = "{$nombre}_insert";
                $triggers[] = "{$nombre}_update";
            }
            foreach ($triggers as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }
};
