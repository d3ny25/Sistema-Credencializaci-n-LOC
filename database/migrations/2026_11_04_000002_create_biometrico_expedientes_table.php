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
        Schema::create('biometrico_expedientes', function (Blueprint $table): void {
            $table->id();
            $table->string('cuip', 50);
            $table->enum('ambito', ['ESTATALES', 'PREVENCION']);
            $table->foreignId('persona_id')
                ->nullable()
                ->constrained('loc_personas')
                ->nullOnDelete();
            $table->foreignId('foto_archivo_id')
                ->nullable()
                ->constrained('biometrico_archivos')
                ->nullOnDelete();
            $table->foreignId('firma_archivo_id')
                ->nullable()
                ->constrained('biometrico_archivos')
                ->nullOnDelete();
            $table->foreignId('pulgar_derecho_archivo_id')
                ->nullable()
                ->constrained('biometrico_archivos')
                ->nullOnDelete();
            $table->foreignId('pulgar_izquierdo_archivo_id')
                ->nullable()
                ->constrained('biometrico_archivos')
                ->nullOnDelete();
            $table->unsignedTinyInteger('total_biometricos')->default(0);
            $table->boolean('es_completo')->default(false);
            $table->json('faltantes')->nullable();
            $table->enum('estado_revision', [
                'sin_evaluar',
                'aprobado_tecnico',
                'rechazado_calidad',
                'en_aclaracion',
            ])->default('sin_evaluar');
            $table->text('motivo_rechazo_biometrico')->nullable();
            $table->foreignId('revisado_por_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('fecha_revision')->nullable();
            $table->boolean('tiene_anomalias')->default(false);
            $table->boolean('tiene_duplicados')->default(false);
            $table->timestamps();

            // Garantía obligatoria de identidad: UNICIDAD (cuip, ambito)
            $table->unique(['cuip', 'ambito'], 'uq_bio_exp_cuip_ambito');

            // Índices para consultas de disponibilidad y filtros
            $table->index('persona_id', 'idx_bio_exp_persona');
            $table->index('es_completo', 'idx_bio_exp_completitud');
            $table->index('estado_revision', 'idx_bio_exp_revision');
            $table->index(['ambito', 'es_completo'], 'idx_bio_exp_ambito_comp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometrico_expedientes');
    }
};

