<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1B.2 — Rectificación auditable de un resultado confirmado por error.
 * El registro original de credencial_impresiones nunca se reescribe; la rectificación se agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credencial_impresion_rectificaciones', function (Blueprint $table) {
            $table->id();
            // Nombre explícito: el generado supera el límite de 64 caracteres de MariaDB.
            $table->foreignId('credencial_impresion_id')->constrained('credencial_impresiones', 'id', 'cir_impresion_fk')->restrictOnDelete();
            $table->string('resultado_anterior', 20);
            $table->string('resultado_rectificado', 20);
            $table->text('motivo');
            // restrict: tabla de solo inserción (ver 2026_11_13_000002, preparada_por_user_id).
            $table->foreignId('usuario_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['credencial_impresion_id', 'created_at'], 'credencial_imp_rect_impresion_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credencial_impresion_rectificaciones');
    }
};
