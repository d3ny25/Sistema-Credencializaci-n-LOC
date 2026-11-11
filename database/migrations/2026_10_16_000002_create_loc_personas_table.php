<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loc_personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained('loc_import_batches')->nullOnDelete();
            $table->string('cuip', 20)->unique();
            $table->string('loc_id', 60)->nullable()->index();
            $table->string('personal_id', 60)->nullable()->index();
            $table->string('nombre', 255);
            $table->string('rfc', 20)->nullable()->index();
            $table->string('curp', 20)->nullable()->index();
            $table->string('categoria', 120)->nullable()->index();
            $table->string('estatus', 80)->nullable()->index();
            $table->string('subdireccion', 150)->nullable();
            $table->string('municipio', 150)->nullable()->index();
            $table->string('region', 150)->nullable()->index();
            $table->string('agrupamiento', 150)->nullable()->index();
            $table->string('clave_seguridad', 50)->nullable();
            $table->string('cuerpo_seguridad', 80)->nullable();
            $table->string('adscripcion', 255)->nullable();
            $table->string('unidad_adscripcion', 255)->nullable();
            $table->date('fecha_ingreso')->nullable();
            $table->date('fecha_baja')->nullable();
            $table->boolean('activo')->default(true)->index();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['cuip', 'estatus'], 'loc_personas_cuip_estatus_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loc_personas');
    }
};
