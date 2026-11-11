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
            $table->unsignedBigInteger('consecutivo_tarjeta')
                ->nullable()
                ->unique('solicitudes_consecutivo_tarjeta_unique')
                ->after('numero_tarjeta');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitudes', function (Blueprint $table): void {
            $table->dropUnique('solicitudes_consecutivo_tarjeta_unique');
            $table->dropColumn('consecutivo_tarjeta');
        });
    }
};

