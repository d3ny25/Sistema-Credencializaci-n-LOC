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
        Schema::create('biometrico_archivo_versiones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('biometrico_archivo_id')
                ->constrained('biometrico_archivos')
                ->restrictOnDelete();
            $table->foreignId('biometrico_index_run_id')
                ->nullable()
                ->constrained('biometrico_index_runs')
                ->nullOnDelete();
            $table->unsignedSmallInteger('version_secuencia')->default(1);
            $table->char('sha256_anterior', 64);
            $table->char('sha256_nuevo', 64);
            $table->unsignedInteger('tamano_bytes_anterior')->nullable();
            $table->unsignedInteger('tamano_bytes_nuevo');
            $table->enum('estado_revision', ['pendiente_revision', 'confirmado', 'descartado'])->default('pendiente_revision');
            $table->string('observaciones', 255)->nullable();
            $table->dateTime('fecha_deteccion');
            $table->timestamps();

            $table->unique(['biometrico_archivo_id', 'version_secuencia'], 'uq_bio_ver_archivo_seq');
            $table->index('sha256_nuevo', 'idx_bio_ver_sha_nuevo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometrico_archivo_versiones');
    }
};

