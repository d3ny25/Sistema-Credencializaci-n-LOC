<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loc_persona_antecedentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('loc_personas')->cascadeOnDelete();
            $table->string('campo', 80);
            $table->text('valor_anterior')->nullable();
            $table->text('valor_nuevo')->nullable();
            $table->date('fecha_detectada');
            $table->date('fecha_vigencia')->nullable();
            $table->string('motivo', 180)->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained('loc_import_batches')->nullOnDelete();
            $table->timestamps();

            $table->index(['persona_id', 'campo', 'fecha_detectada'], 'loc_persona_antecedentes_historial_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loc_persona_antecedentes');
    }
};
