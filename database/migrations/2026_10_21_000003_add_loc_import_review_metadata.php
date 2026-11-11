<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loc_import_batches', function (Blueprint $table) {
            $table->string('hoja_procesada', 255)->nullable();
            $table->unsignedInteger('total_columnas')->default(0);
            $table->unsignedInteger('total_nuevas')->default(0);
            $table->unsignedInteger('total_sin_cambios')->default(0);
            $table->unsignedInteger('total_insertadas')->default(0);
            $table->char('archivo_sha256', 64)->nullable();
            $table->char('revision_sha256', 64)->nullable();
            $table->timestamp('fecha_confirmacion')->nullable();
        });

        Schema::table('loc_import_staging_rows', function (Blueprint $table) {
            $table->string('estado_aplicacion', 24)->default('pendiente')->index();
        });
    }

    public function down(): void
    {
        Schema::table('loc_import_staging_rows', function (Blueprint $table) {
            $table->dropIndex(['estado_aplicacion']);
            $table->dropColumn('estado_aplicacion');
        });

        Schema::table('loc_import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'hoja_procesada',
                'total_columnas',
                'total_nuevas',
                'total_sin_cambios',
                'total_insertadas',
                'archivo_sha256',
                'revision_sha256',
                'fecha_confirmacion',
            ]);
        });
    }
};
