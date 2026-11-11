<?php

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
        Schema::table('solicitudes', function (Blueprint $table): void {
            $table->foreignId('catalogo_organizacional_id')
                ->nullable()
                ->after('area_solicitante')
                ->constrained('loc_catalogo_organizacional')
                ->nullOnDelete();

            $table->string('area_solicitante_clave', 80)
                ->nullable()
                ->after('catalogo_organizacional_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitudes', function (Blueprint $table): void {
            $table->dropForeign(['catalogo_organizacional_id']);
            $table->dropColumn(['catalogo_organizacional_id', 'area_solicitante_clave']);
        });
    }
};

