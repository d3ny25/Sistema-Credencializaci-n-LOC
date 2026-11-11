<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loc_persona_armas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('loc_personas')->cascadeOnDelete();
            $table->foreignId('arma_id')->constrained('loc_armas')->cascadeOnDelete();
            $table->date('fecha_asignacion')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->string('estatus_asignacion', 30)->default('activa')->index();
            $table->text('observaciones')->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained('loc_import_batches')->nullOnDelete();
            $table->timestamps();

            $table->index(['persona_id', 'arma_id'], 'loc_persona_armas_persona_arma_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loc_persona_armas');
    }
};
