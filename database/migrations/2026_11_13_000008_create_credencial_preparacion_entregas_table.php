<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1B.2 (corrección) — Entregas verificadas de cada evidencia del snapshot a la vista de impresión.
 * Una fila por (preparación, categoría); la confirmación "impresa" exige las cuatro.
 * Control técnico de consistencia: no prueba que el operador viera la imagen ni que se imprimiera.
 * Solo inserción (triggers por motor, como en 2026_11_13_000007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credencial_preparacion_entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credencial_preparacion_id')->constrained('credencial_preparaciones', 'id', 'cpe_preparacion_fk')->restrictOnDelete();
            $table->enum('categoria', ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo']);
            $table->foreignId('biometrico_evidencia_id')->constrained('biometrico_evidencias', 'id', 'cpe_evidencia_fk')->restrictOnDelete();
            $table->char('sha256', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['credencial_preparacion_id', 'categoria'], 'credencial_prep_entrega_unique');
        });

        $driver = DB::connection()->getDriverName();
        foreach (['UPDATE', 'DELETE'] as $evento) {
            $trigger = 'trg_credencial_preparacion_entregas_no_'.strtolower($evento);
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE {$evento} ON `credencial_preparacion_entregas` FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro de evidencia de solo insercion: {$evento} rechazado'");
            } elseif ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$evento} ON credencial_preparacion_entregas
                    BEGIN SELECT RAISE(ABORT, 'Registro de evidencia de solo insercion: {$evento} rechazado'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $evento) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_credencial_preparacion_entregas_no_'.$evento);
        }
        Schema::dropIfExists('credencial_preparacion_entregas');
    }
};
