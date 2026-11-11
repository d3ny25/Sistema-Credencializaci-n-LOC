<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loc_catalogo_organizacional', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained('loc_import_batches')->nullOnDelete();
            $table->string('clave_origen', 80)->nullable()->index();
            $table->string('nivel_jerarquico', 60);
            $table->string('nombre', 255)->nullable();
            $table->string('direccion_general', 255)->nullable();
            $table->string('coordinacion', 255)->nullable();
            $table->string('subdireccion', 255)->nullable();
            $table->string('region', 150)->nullable()->index();
            $table->string('agrupamiento', 150)->nullable()->index();
            $table->string('municipio', 150)->nullable()->index();
            $table->string('zona_militar', 120)->nullable();
            $table->string('batallon', 120)->nullable();
            $table->foreignId('padre_id')->nullable()->constrained('loc_catalogo_organizacional')->nullOnDelete();
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();

            $table->index(['clave_origen', 'nivel_jerarquico'], 'loc_catalogo_organizacional_clave_nivel_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loc_catalogo_organizacional');
    }
};
