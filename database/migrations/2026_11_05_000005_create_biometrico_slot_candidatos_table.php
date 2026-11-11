<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('biometrico_slot_candidatos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('biometrico_expediente_id')
                ->constrained('biometrico_expedientes')
                ->cascadeOnDelete();
            $table->enum('categoria', ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo']);
            $table->foreignId('biometrico_archivo_id')
                ->constrained('biometrico_archivos')
                ->restrictOnDelete();
            $table->char('sha256', 64);
            $table->string('ruta_relativa', 255);
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->foreignId('biometrico_index_run_id')
                ->nullable()
                ->constrained('biometrico_index_runs')
                ->nullOnDelete();
            $table->boolean('es_seleccion_oficial')->default(false);
            $table->enum('estado_candidato', ['en_conflicto', 'seleccionado', 'descartado', 'descartado_duplicado', 'pendiente'])->default('en_conflicto');
            $table->string('motivo_descarte', 255)->nullable();
            $table->string('notas_resolucion', 255)->nullable();
            $table->dateTime('fecha_deteccion')->useCurrent();
            $table->timestamps();

            $table->unique(
                ['biometrico_expediente_id', 'categoria', 'biometrico_archivo_id'],
                'uq_slot_candidato_archivo'
            );
            $table->index(['biometrico_expediente_id', 'categoria'], 'idx_slot_cand_exp_cat');
            $table->index('sha256', 'idx_slot_cand_sha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometrico_slot_candidatos');
    }
};
