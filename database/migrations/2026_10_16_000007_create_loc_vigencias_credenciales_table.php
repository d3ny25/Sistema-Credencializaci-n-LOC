<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loc_vigencias_credenciales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->nullable()->constrained('loc_personas')->nullOnDelete();
            $table->foreignId('arma_id')->nullable()->constrained('loc_armas')->nullOnDelete();
            $table->string('cuip', 20)->nullable()->index();
            $table->string('matricula', 60)->nullable()->index();
            $table->string('tipo_credencial', 80)->nullable();
            $table->string('numero_oficio', 100)->nullable();
            $table->string('numero_credencial', 80)->nullable()->index();
            $table->string('estatus_credencial', 60)->nullable()->index();
            $table->date('fecha_solicitud')->nullable();
            $table->date('fecha_elaboracion')->nullable();
            $table->date('fecha_vigencia')->nullable();
            $table->string('motivo_situacion_especial', 120)->nullable();
            $table->string('agrupamiento', 150)->nullable();
            $table->string('region', 150)->nullable();
            $table->string('municipio', 150)->nullable();
            $table->text('observaciones')->nullable();
            $table->boolean('es_referencia_loc')->default(true)->index();
            $table->foreignId('import_batch_id')->nullable()->constrained('loc_import_batches')->nullOnDelete();
            $table->timestamps();

            $table->index(['persona_id', 'fecha_vigencia'], 'loc_vigencias_credenciales_persona_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loc_vigencias_credenciales');
    }
};
