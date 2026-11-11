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
        Schema::table('solicitud_elementos', function (Blueprint $table): void {
            $table->json('codigos_descarte')
                ->nullable()
                ->after('motivos_descarte');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitud_elementos', function (Blueprint $table): void {
            $table->dropColumn('codigos_descarte');
        });
    }
};

