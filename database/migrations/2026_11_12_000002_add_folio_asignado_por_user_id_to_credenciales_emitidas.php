<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separa quién asignó el folio (validación biométrica 4/4) de quién emitió la credencial.
     * La fecha de asignación es created_at, porque la credencial se crea al asignar el folio.
     */
    public function up(): void
    {
        Schema::table('credenciales_emitidas', function (Blueprint $table) {
            $table->foreignId('folio_asignado_por_user_id')
                ->nullable()
                ->after('emitida_por_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credenciales_emitidas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folio_asignado_por_user_id');
        });
    }
};
