<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitud_elementos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes')->cascadeOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('loc_personas')->nullOnDelete();
            $table->string('cuip', 20)->nullable()->index();
            $table->string('tipo_elemento', 40)->default('persona');
            $table->text('datos_solicitados')->nullable();
            $table->string('estado_tramite', 40)->default('pendiente')->index();
            $table->text('motivo_rechazo')->nullable();
            $table->text('motivo_retencion')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['solicitud_id', 'persona_id'], 'solicitud_elementos_solicitud_persona_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_elementos');
    }
};
