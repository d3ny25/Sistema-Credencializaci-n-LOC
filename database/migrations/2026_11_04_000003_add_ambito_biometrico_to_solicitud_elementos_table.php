<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('solicitud_elementos', function (Blueprint $table): void {
            $table->unsignedBigInteger('biometrico_expediente_id')
                ->nullable()
                ->after('persona_id');

            $table->enum('ambito_biometrico', ['ESTATALES', 'PREVENCION', 'NO_RESUELTO'])
                ->default('NO_RESUELTO')
                ->after('estado_tramite');

            $table->enum('estado_clasificacion_ambito', ['provisional', 'validada', 'definitiva'])
                ->default('provisional')
                ->after('ambito_biometrico');

            $table->string('clave_organizacional_origen', 80)
                ->nullable()
                ->after('estado_clasificacion_ambito');

            $table->unsignedBigInteger('catalogo_version_id')
                ->nullable()
                ->after('clave_organizacional_origen');

            $table->unsignedInteger('catalogo_version_numero')
                ->nullable()
                ->after('catalogo_version_id');

            $table->string('criterio_resolucion_ambito', 50)
                ->nullable()
                ->after('catalogo_version_numero');

            $table->boolean('tiene_discrepancia_oficio')
                ->default(false)
                ->after('criterio_resolucion_ambito');

            $table->dateTime('fecha_resolucion_ambito')
                ->nullable()
                ->after('tiene_discrepancia_oficio');

            // Índices de búsqueda
            $table->index('ambito_biometrico', 'idx_sol_elem_ambito');
            $table->index('biometrico_expediente_id', 'idx_sol_elem_bio_exp');

            // Claves foráneas:
            // 1. Expediente biométrico: SET NULL si el expediente llegara a desvincularse
            // 2. Versión de catálogo: RESTRICT para garantizar la trazabilidad inmutable
            $table->foreign('biometrico_expediente_id', 'fk_sol_elem_bio_exp')
                ->references('id')
                ->on('biometrico_expedientes')
                ->nullOnDelete();

            $table->foreign('catalogo_version_id', 'fk_sol_elem_cat_version')
                ->references('id')
                ->on('loc_catalogo_versiones')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitud_elementos', function (Blueprint $table): void {
            $table->dropForeign('fk_sol_elem_bio_exp');
            $table->dropForeign('fk_sol_elem_cat_version');

            $table->dropIndex('idx_sol_elem_ambito');
            $table->dropIndex('idx_sol_elem_bio_exp');

            $table->dropColumn([
                'biometrico_expediente_id',
                'ambito_biometrico',
                'estado_clasificacion_ambito',
                'clave_organizacional_origen',
                'catalogo_version_id',
                'catalogo_version_numero',
                'criterio_resolucion_ambito',
                'tiene_discrepancia_oficio',
                'fecha_resolucion_ambito',
            ]);
        });
    }
};

