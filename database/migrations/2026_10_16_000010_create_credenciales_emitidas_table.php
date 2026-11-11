<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credenciales_emitidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('solicitudes')->cascadeOnDelete();
            $table->foreignId('solicitud_elemento_id')->nullable()->constrained('solicitud_elementos')->nullOnDelete();
            $table->string('folio', 60)->unique();
            $table->string('numero_tarjeta', 60)->nullable()->index();
            $table->string('estado_biometria', 40)->default('pendiente')->index();
            $table->string('estado_emision', 40)->default('pendiente')->index();
            $table->string('estado_entrega', 40)->default('pendiente')->index();
            $table->date('fecha_emision')->nullable();
            $table->date('fecha_entrega')->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('emitida_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['solicitud_id', 'folio'], 'credenciales_emitidas_solicitud_folio_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credenciales_emitidas');
    }
};
