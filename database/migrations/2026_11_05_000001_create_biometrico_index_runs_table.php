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
        Schema::create('biometrico_index_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->enum('ambito', ['ESTATALES', 'PREVENCION', 'TODOS'])->default('TODOS');
            $table->enum('estado', [
                'pendiente',
                'en_ejecucion',
                'completada',
                'completada_con_incidencias',
                'fallida',
                'interrumpida',
                'cancelada',
            ])->default('pendiente');

            $table->boolean('es_dry_run')->default(false);
            $table->timestamp('iniciado_at')->nullable();
            $table->timestamp('finalizado_at')->nullable();

            $table->foreignId('iniciado_por_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Puntos de control para reanudación segura (Checkpoints)
            $table->unsignedBigInteger('ultimo_checkpoint_id')->nullable();
            $table->string('ultimo_directorio_procesado', 500)->nullable();
            $table->string('ultima_ruta_procesada', 500)->nullable();

            // Métricas cuantitativas del procesamiento masivo
            $table->unsignedInteger('total_archivos_detectados')->default(0);
            $table->unsignedInteger('total_archivos_procesados')->default(0);
            $table->unsignedInteger('total_archivos_nuevos')->default(0);
            $table->unsignedInteger('total_archivos_sin_cambios')->default(0);
            $table->unsignedInteger('total_archivos_modificados')->default(0);
            $table->unsignedInteger('total_archivos_desaparecidos')->default(0);
            $table->unsignedInteger('total_archivos_invalidos')->default(0);
            $table->unsignedInteger('total_archivos_duplicados')->default(0);
            $table->unsignedInteger('total_incidencias')->default(0);

            // Rendimiento y diagnóstico técnico
            $table->unsignedInteger('duracion_segundos')->default(0);
            $table->decimal('memoria_pico_mb', 8, 2)->default(0.00);
            $table->text('error_mensaje')->nullable();
            $table->text('resumen_interrupcion')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Índices de búsqueda y concurrencia
            $table->index('estado', 'idx_bio_run_estado');
            $table->index('ambito', 'idx_bio_run_ambito');
            $table->index('created_at', 'idx_bio_run_created');
            $table->index(['ambito', 'estado'], 'idx_bio_run_ambito_estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometrico_index_runs');
    }
};

