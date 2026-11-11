<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes', function (Blueprint $table) {
            $table->id();
            $table->string('numero_tarjeta', 60)->unique();
            $table->date('fecha_recepcion')->nullable();
            $table->string('estado_general', 40)->default('pendiente')->index();
            $table->string('area_solicitante', 180)->nullable();
            $table->foreignId('usuario_responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained('loc_import_batches')->nullOnDelete();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['numero_tarjeta', 'estado_general'], 'solicitudes_tarjeta_estado_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes');
    }
};
