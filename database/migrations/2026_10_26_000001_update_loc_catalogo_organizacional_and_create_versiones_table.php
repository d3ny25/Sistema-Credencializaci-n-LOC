<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Adecuar loc_catalogo_organizacional: nivel_jerarquico nullable, eliminar índice obsoleto y agregar índice único compuesto
        Schema::table('loc_catalogo_organizacional', function (Blueprint $table) {
            $table->dropIndex('loc_catalogo_organizacional_clave_nivel_index');
            $table->string('nivel_jerarquico', 60)->nullable()->change();
            $table->unique(['import_batch_id', 'clave_origen'], 'loc_cat_org_batch_clave_unique');
        });

        // 2. Agregar clave_origen a loc_import_staging_rows para indexación y búsqueda en preview
        Schema::table('loc_import_staging_rows', function (Blueprint $table) {
            $table->string('clave_origen', 80)->nullable()->index('loc_staging_clave_origen_index');
        });

        // 3. Crear tabla autoritativa de versiones del catálogo
        Schema::create('loc_catalogo_versiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('loc_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('version_numero');
            $table->boolean('es_vigente')->default(false)->index();
            $table->unsignedInteger('total_registros')->default(0);
            $table->string('archivo_nombre', 255)->nullable();
            $table->char('archivo_sha256', 64)->nullable();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fecha_activacion')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->unique('import_batch_id', 'loc_cat_versiones_batch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loc_catalogo_versiones');

        Schema::table('loc_import_staging_rows', function (Blueprint $table) {
            $table->dropIndex('loc_staging_clave_origen_index');
            $table->dropColumn('clave_origen');
        });

        Schema::table('loc_catalogo_organizacional', function (Blueprint $table) {
            $table->dropUnique('loc_cat_org_batch_clave_unique');
            $table->string('nivel_jerarquico', 60)->nullable(false)->change();
            $table->index(['clave_origen', 'nivel_jerarquico'], 'loc_catalogo_organizacional_clave_nivel_index');
        });
    }
};

