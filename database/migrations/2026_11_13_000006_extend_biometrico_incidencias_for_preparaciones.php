<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1B.2 — Incidencias de evidencia y de impresión fuera de una corrida del indexador.
 * - biometrico_index_run_id pasa a nullable (la clave foránea restrictOnDelete se conserva).
 * - Relación opcional con la preparación que originó la incidencia.
 * - Resolución explícita y auditable (p. ej. posible tarjeta duplicada).
 * El enum `tipo` no cambia: las incidencias nuevas usan `codigo` propio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometrico_incidencias', function (Blueprint $table) {
            $table->unsignedBigInteger('biometrico_index_run_id')->nullable()->change();
        });

        Schema::table('biometrico_incidencias', function (Blueprint $table) {
            $table->foreignId('credencial_preparacion_id')->nullable()->after('biometrico_index_run_id')
                ->constrained('credencial_preparaciones')->restrictOnDelete();
            $table->dateTime('resuelta_at')->nullable();
            $table->foreignId('resuelta_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolucion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('biometrico_incidencias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resuelta_por_user_id');
            $table->dropConstrainedForeignId('credencial_preparacion_id');
            $table->dropColumn(['resuelta_at', 'resolucion']);
        });
        // run_id no vuelve a NOT NULL: podría haber incidencias sin corrida registradas después.
    }
};
