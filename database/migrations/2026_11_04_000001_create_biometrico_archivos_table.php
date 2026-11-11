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
        Schema::create('biometrico_archivos', function (Blueprint $table): void {
            $table->id();
            $table->enum('ambito', ['ESTATALES', 'PREVENCION']);
            $table->enum('categoria', ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo']);
            $table->string('ruta_relativa', 255)->unique();
            $table->string('nombre_archivo', 150);
            $table->string('extension', 10);
            $table->unsignedInteger('tamano_bytes');
            $table->string('formato_real', 10);
            $table->string('mime_type', 50);
            $table->char('sha256', 64)->nullable();
            $table->string('cuip_detectado', 50)->nullable();
            $table->string('cuip_normalizado', 50)->nullable();
            $table->enum('tipo_identificacion', [
                'estandar',
                'corto_rfc',
                'no_estandar',
                'guid_hardware',
                'sin_nombre',
                'sistema',
            ])->default('estandar');
            $table->enum('estado_archivo', [
                'valido',
                'sospechoso',
                'invalido',
                'duplicado_sha',
                'no_imagen',
                'cuarentena',
                'desaparecido',
                'error_lectura',
            ])->default('valido');
            $table->boolean('es_vigente')->default(true);
            $table->unsignedSmallInteger('version_secuencia')->default(1);
            $table->string('observaciones', 255)->nullable();
            $table->dateTime('fecha_archivo_mtime')->nullable();
            $table->timestamps();

            // Índices optimizados para consulta y resolución de expedientes
            $table->index(['ambito', 'categoria', 'cuip_normalizado', 'es_vigente'], 'idx_bio_arch_lookup');
            $table->index('cuip_normalizado', 'idx_bio_arch_cuip');
            $table->index('sha256', 'idx_bio_arch_sha256');
            $table->index('estado_archivo', 'idx_bio_arch_estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometrico_archivos');
    }
};

