<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1B.2 — Cuatro componentes biométricos por preparación.
 * La evidencia (contenido preservado) es obligatoria para clasificacion='verificada' (CHECK en 000007);
 * archivo y versión de origen son informativos y opcionales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credencial_preparacion_biometricos', function (Blueprint $table) {
            $table->id();
            // Nombres explícitos: los generados superan el límite de 64 caracteres de MariaDB.
            $table->foreignId('credencial_preparacion_id')->constrained('credencial_preparaciones', 'id', 'cpb_preparacion_fk')->restrictOnDelete();
            $table->enum('categoria', ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo']);
            $table->foreignId('biometrico_evidencia_id')->nullable()->constrained('biometrico_evidencias', 'id', 'cpb_evidencia_fk')->restrictOnDelete();
            $table->char('sha256', 64)->nullable();
            // restrict: la tabla es de solo inserción; SET NULL divergiría entre SQLite (bloqueo) y MariaDB (cambio silencioso).
            $table->foreignId('biometrico_archivo_id')->nullable()->constrained('biometrico_archivos', 'id', 'cpb_archivo_fk')->restrictOnDelete();
            $table->foreignId('biometrico_archivo_version_id')->nullable()->constrained('biometrico_archivo_versiones', 'id', 'cpb_archivo_version_fk')->restrictOnDelete();
            $table->string('clasificacion', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['credencial_preparacion_id', 'categoria'], 'credencial_prep_bio_unique');
            $table->index('sha256', 'credencial_prep_bio_sha_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credencial_preparacion_biometricos');
    }
};
