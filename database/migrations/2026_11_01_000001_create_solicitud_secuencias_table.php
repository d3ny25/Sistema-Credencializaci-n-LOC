<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('solicitud_secuencias', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->unsignedBigInteger('ultimo_consecutivo');
            $table->string('descripcion', 255)->nullable();
            $table->timestamps();
        });

        // Inicialización controlada y determinista
        DB::table('solicitud_secuencias')->insert([
            'clave' => 'tarjeta_credencial',
            'ultimo_consecutivo' => 50000,
            'descripcion' => 'Secuencia global institucional de tarjetas de credencialización LOC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solicitud_secuencias');
    }
};

