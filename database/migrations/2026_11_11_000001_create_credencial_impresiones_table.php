<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credencial_impresiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credencial_emitida_id')->constrained('credenciales_emitidas')->restrictOnDelete();
            $table->foreignId('solicitud_elemento_id')->constrained('solicitud_elementos')->restrictOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            // Sin FK: la tabla de estaciones aún no existe (Fase 1 de docs/auditoria-perifericos.md).
            $table->unsignedBigInteger('estacion_id')->nullable()->index();
            $table->string('impresora', 120)->nullable();
            $table->string('resultado', 20)->index();
            $table->text('motivo')->nullable();
            $table->unsignedInteger('numero_intento');
            $table->timestamps();

            $table->unique(['credencial_emitida_id', 'numero_intento'], 'credencial_impresiones_intento_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credencial_impresiones');
    }
};
