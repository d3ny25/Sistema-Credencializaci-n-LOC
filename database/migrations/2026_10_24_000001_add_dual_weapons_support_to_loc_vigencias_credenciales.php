<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loc_vigencias_credenciales', function (Blueprint $table): void {
            $table->foreignId('arma_corta_id')->nullable()->after('arma_id')->constrained('loc_armas')->nullOnDelete();
            $table->string('matricula_corta', 60)->nullable()->after('matricula')->index();

            $table->foreignId('arma_larga_id')->nullable()->after('arma_corta_id')->constrained('loc_armas')->nullOnDelete();
            $table->string('matricula_larga', 60)->nullable()->after('matricula_corta')->index();

            $table->string('periodo_credencial', 50)->nullable()->after('tipo_credencial');
            $table->string('numero_oficio_baja', 100)->nullable()->after('numero_oficio');
            $table->date('fecha_baja')->nullable()->after('fecha_vigencia');
            $table->string('cuerpo_seguridad', 150)->nullable()->after('motivo_situacion_especial');

            $table->json('datos_origen')->nullable()->after('observaciones');
            $table->char('fila_hash', 64)->nullable()->after('datos_origen')->index();
        });
    }

    public function down(): void
    {
        Schema::table('loc_vigencias_credenciales', function (Blueprint $table): void {
            $table->dropForeign(['arma_corta_id']);
            $table->dropForeign(['arma_larga_id']);
            $table->dropIndex(['matricula_corta']);
            $table->dropIndex(['matricula_larga']);
            $table->dropIndex(['fila_hash']);

            $table->dropColumn([
                'arma_corta_id',
                'matricula_corta',
                'arma_larga_id',
                'matricula_larga',
                'periodo_credencial',
                'numero_oficio_baja',
                'fecha_baja',
                'cuerpo_seguridad',
                'datos_origen',
                'fila_hash',
            ]);
        });
    }
};

