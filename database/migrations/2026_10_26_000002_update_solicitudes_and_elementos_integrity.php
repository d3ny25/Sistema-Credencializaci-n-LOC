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
        Schema::table('solicitudes', function (Blueprint $table): void {
            $table->string('numero_oficio', 100)->unique()->after('numero_tarjeta');
            $table->string('numero_tarjeta', 100)->change();
        });

        Schema::table('credenciales_emitidas', function (Blueprint $table): void {
            $table->string('numero_tarjeta', 100)->nullable()->change();
            $table->dropForeign(['solicitud_id']);
            $table->foreign('solicitud_id')
                ->references('id')
                ->on('solicitudes')
                ->restrictOnDelete();
        });

        Schema::table('solicitud_elementos', function (Blueprint $table): void {
            $table->dropForeign(['solicitud_id']);
            $table->foreign('solicitud_id')
                ->references('id')
                ->on('solicitudes')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $lengthFunction = DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';

        if (DB::table('solicitudes')->whereNotNull('numero_oficio')->exists()) {
            throw new RuntimeException(
                'No se puede revertir la migración: existen solicitudes con número de oficio que se perderían al eliminar la columna.'
            );
        }

        if (DB::table('solicitudes')->whereNotNull('numero_tarjeta')->whereRaw("{$lengthFunction}(numero_tarjeta) > 60")->exists()) {
            throw new RuntimeException(
                'No se puede revertir la ampliación de número de tarjeta en solicitudes: existen registros con valores mayores a 60 caracteres.'
            );
        }

        if (DB::table('credenciales_emitidas')->whereNotNull('numero_tarjeta')->whereRaw("{$lengthFunction}(numero_tarjeta) > 60")->exists()) {
            throw new RuntimeException(
                'No se puede revertir la ampliación de número de tarjeta en credenciales emitidas: existen registros con valores mayores a 60 caracteres.'
            );
        }

        Schema::table('solicitud_elementos', function (Blueprint $table): void {
            $table->dropForeign(['solicitud_id']);
            $table->foreign('solicitud_id')
                ->references('id')
                ->on('solicitudes')
                ->cascadeOnDelete();
        });

        Schema::table('credenciales_emitidas', function (Blueprint $table): void {
            $table->dropForeign(['solicitud_id']);
            $table->foreign('solicitud_id')
                ->references('id')
                ->on('solicitudes')
                ->cascadeOnDelete();
            $table->string('numero_tarjeta', 60)->nullable()->change();
        });

        Schema::table('solicitudes', function (Blueprint $table): void {
            $table->dropUnique(['numero_oficio']);
            $table->dropColumn('numero_oficio');
            $table->string('numero_tarjeta', 60)->change();
        });
    }
};

