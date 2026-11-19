<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1B.2 — Cada confirmación de impresión pertenece a una preparación (UNIQUE: una sola
 * confirmación por preparación). Las filas anteriores conservan preparación nula.
 * Plantilla, tipo de operación y origen de una reimpresión se obtienen de la preparación
 * (no se duplican). `created_at` es la fecha de confirmación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credencial_impresiones', function (Blueprint $table) {
            $table->foreignId('credencial_preparacion_id')->nullable()->after('solicitud_elemento_id')
                ->constrained('credencial_preparaciones')->restrictOnDelete();
            $table->string('modalidad_confirmacion', 20)->default('normal')->after('resultado');
            $table->text('justificacion_extemporanea')->nullable()->after('modalidad_confirmacion');
            $table->unique('credencial_preparacion_id', 'credencial_impresiones_preparacion_unique');
        });
    }

    public function down(): void
    {
        Schema::table('credencial_impresiones', function (Blueprint $table) {
            $table->dropUnique('credencial_impresiones_preparacion_unique');
            $table->dropConstrainedForeignId('credencial_preparacion_id');
            $table->dropColumn(['modalidad_confirmacion', 'justificacion_extemporanea']);
        });
    }
};
