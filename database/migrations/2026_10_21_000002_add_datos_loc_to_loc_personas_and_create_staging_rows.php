<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loc_personas', function (Blueprint $table) {
            if (! Schema::hasColumn('loc_personas', 'datos_loc')) {
                $table->json('datos_loc')->nullable()->after('observaciones');
            }
        });

        Schema::create('loc_import_staging_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('loc_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('numero_fila');
            $table->string('cuip', 20)->nullable()->index();
            $table->json('datos')->nullable();
            $table->json('errores')->nullable();
            $table->string('accion', 24)->default('pendiente')->index();
            $table->timestamps();

            $table->unique(['import_batch_id', 'numero_fila']);
            $table->index(['import_batch_id', 'accion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loc_import_staging_rows');

        Schema::table('loc_personas', function (Blueprint $table) {
            if (Schema::hasColumn('loc_personas', 'datos_loc')) {
                $table->dropColumn('datos_loc');
            }
        });
    }
};
