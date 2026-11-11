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
        Schema::create('biometrico_incidencias', function (Blueprint $table): void {
            $table->id();

            // Relación con la ejecución del indexador. restrictOnDelete preserva el historial pericial
            $table->foreignId('biometrico_index_run_id')
                ->constrained('biometrico_index_runs')
                ->restrictOnDelete();

            $table->enum('tipo', [
                'nombre_invalido',
                'cuip_invalido',
                'categoria_desconocida',
                'archivo_ilegible',
                'hash_no_calculable',
                'archivo_duplicado',
                'ruta_no_permitida',
                'archivo_desaparecido',
                'conflicto_identidad',
                'error_procesamiento',
            ]);

            $table->enum('severidad', [
                'critica',
                'alta',
                'media',
                'baja',
                'info',
            ])->default('media');

            // Código técnico de catalogación estable
            $table->string('codigo', 80);

            $table->enum('ambito', ['ESTATALES', 'PREVENCION'])->nullable();
            $table->enum('categoria', ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo'])->nullable();

            // Referencia lógica y nombre original del archivo (sin rutas absolutas de servidor)
            $table->string('ruta_relativa', 500);
            $table->string('nombre_archivo', 150);

            // CUIP detectado (cuando sea interpretable)
            $table->string('cuip_detectado', 50)->nullable();

            // Diagnóstico pericial y metadatos complementarios estructurados
            $table->text('descripcion');
            $table->json('detalles')->nullable();

            $table->timestamp('fecha_deteccion');
            $table->timestamps();

            // Índices de filtrado y agregación forense
            $table->index('biometrico_index_run_id', 'idx_bio_inc_run');
            $table->index(['tipo', 'severidad'], 'idx_bio_inc_tipo_sev');
            $table->index('cuip_detectado', 'idx_bio_inc_cuip');
            $table->index('codigo', 'idx_bio_inc_codigo');
            $table->index(['ambito', 'categoria'], 'idx_bio_inc_ambito_cat');
            $table->index('fecha_deteccion', 'idx_bio_inc_fecha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometrico_incidencias');
    }
};

