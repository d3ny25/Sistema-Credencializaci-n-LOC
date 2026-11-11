<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loc_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_fuente', 40);
            $table->string('nombre_archivo', 255);
            $table->timestamp('fecha_procesamiento')->useCurrent();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado', 30)->default('pendiente');
            $table->unsignedInteger('total_leidas')->default(0);
            $table->unsignedInteger('total_aceptadas')->default(0);
            $table->unsignedInteger('total_rechazadas')->default(0);
            $table->unsignedInteger('total_actualizadas')->default(0);
            $table->text('resumen_errores')->nullable();
            $table->timestamps();

            $table->index(['tipo_fuente', 'estado'], 'loc_import_batches_tipo_estado_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loc_import_batches');
    }
};
