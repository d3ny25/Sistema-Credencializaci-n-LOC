<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loc_armas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained('loc_import_batches')->nullOnDelete();
            $table->string('loc_id', 60)->nullable()->index();
            $table->string('matricula', 60)->nullable()->index();
            $table->string('tipo_arma', 80)->nullable();
            $table->string('clase_arma', 80)->nullable();
            $table->string('marca', 120)->nullable();
            $table->string('calibre', 60)->nullable();
            $table->string('modelo', 120)->nullable();
            $table->string('rfa', 100)->nullable();
            $table->string('resguardo', 150)->nullable();
            $table->string('propiedad', 120)->nullable();
            $table->string('agrupamiento', 150)->nullable()->index();
            $table->string('region', 150)->nullable()->index();
            $table->string('municipio', 150)->nullable()->index();
            $table->string('estatus', 80)->nullable()->index();
            $table->string('situacion', 120)->nullable()->index();
            $table->text('observaciones')->nullable();
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loc_armas');
    }
};
