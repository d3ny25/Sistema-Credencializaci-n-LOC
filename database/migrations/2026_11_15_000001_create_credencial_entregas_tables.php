<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega y Seguimiento, Iteración 1.3 — entregas físicas de credenciales.
 *
 * Cabecera por operación (quién recibe, quién entrega, cuándo) y un detalle por tarjeta física
 * (credencial_impresion_id, único: una impresión se entrega una sola vez). No altera credencial_impresiones
 * ni credenciales_emitidas. Ambas tablas son de solo inserción (triggers por motor, como en 2026_11_13_000007);
 * devoluciones o anulaciones requerirán un procedimiento propio. Un trigger de inserción rechaza impresiones de
 * otra tarjeta o cuyo resultado vigente (con rectificaciones) no sea "impresa": última barrera tras el servicio.
 */
return new class extends Migration
{
    private const TABLAS = ['credencial_entregas', 'credencial_entrega_detalles'];

    /** Condición de validez del detalle; {n} = prefijo de la fila nueva. */
    private const VALIDEZ = "EXISTS (SELECT 1 FROM credencial_impresiones i
            JOIN solicitud_elementos e ON e.id = i.solicitud_elemento_id
            JOIN credencial_entregas ce ON ce.solicitud_id = e.solicitud_id
            WHERE i.id = {n}credencial_impresion_id AND ce.id = {n}credencial_entrega_id
              AND COALESCE((SELECT r.resultado_rectificado FROM credencial_impresion_rectificaciones r
                            WHERE r.credencial_impresion_id = i.id ORDER BY r.id DESC LIMIT 1), i.resultado) = 'impresa')";

    public function up(): void
    {
        Schema::create('credencial_entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes')->restrictOnDelete();
            $table->string('receptor_nombre', 150);
            // restrict (no nullOnDelete): la fila es inmutable y el responsable forma parte de la evidencia.
            $table->foreignId('entregado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('entregada_at');
            // Clave de idempotencia generada por el servidor al confirmar: un doble envío no duplica la operación.
            $table->uuid('clave_operacion')->unique();
            $table->timestamps();

            $table->index(['solicitud_id', 'entregada_at'], 'credencial_entregas_solicitud_fecha_index');
        });

        Schema::create('credencial_entrega_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credencial_entrega_id')->constrained('credencial_entregas')->restrictOnDelete();
            $table->foreignId('credencial_impresion_id')->unique()->constrained('credencial_impresiones')->restrictOnDelete();
            $table->timestamps();
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach (self::TABLAS as $tabla) {
                foreach (['UPDATE', 'DELETE'] as $evento) {
                    DB::unprepared("CREATE TRIGGER `trg_{$tabla}_no_".strtolower($evento)."` BEFORE {$evento} ON `{$tabla}` FOR EACH ROW
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro de entrega de solo insercion: {$evento} rechazado'");
                }
            }
            DB::unprepared('CREATE TRIGGER `trg_credencial_entrega_detalles_validez` BEFORE INSERT ON `credencial_entrega_detalles` FOR EACH ROW
                BEGIN
                    IF NOT '.str_replace('{n}', 'NEW.', self::VALIDEZ)." THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La impresion no pertenece a la tarjeta de la entrega o no es una impresion efectiva';
                    END IF;
                END");
        } elseif ($driver === 'sqlite') {
            foreach (self::TABLAS as $tabla) {
                foreach (['UPDATE', 'DELETE'] as $evento) {
                    DB::unprepared("CREATE TRIGGER trg_{$tabla}_no_".strtolower($evento)." BEFORE {$evento} ON {$tabla}
                        BEGIN SELECT RAISE(ABORT, 'Registro de entrega de solo insercion: {$evento} rechazado'); END");
                }
            }
            DB::unprepared('CREATE TRIGGER trg_credencial_entrega_detalles_validez BEFORE INSERT ON credencial_entrega_detalles
                WHEN NOT '.str_replace('{n}', 'NEW.', self::VALIDEZ)."
                BEGIN SELECT RAISE(ABORT, 'La impresion no pertenece a la tarjeta de la entrega o no es una impresion efectiva'); END");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_credencial_entrega_detalles_validez');
        foreach (self::TABLAS as $tabla) {
            foreach (['update', 'delete'] as $evento) {
                DB::unprepared("DROP TRIGGER IF EXISTS trg_{$tabla}_no_{$evento}");
            }
        }
        Schema::dropIfExists('credencial_entrega_detalles');
        Schema::dropIfExists('credencial_entregas');
    }
};
