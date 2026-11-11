<?php

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
        Schema::table('solicitudes', function (Blueprint $table): void {
            $table->unsignedInteger('total_elementos')->default(0)->after('area_solicitante');
            $table->unsignedInteger('total_aptos')->default(0)->after('total_elementos');
            $table->unsignedInteger('total_descartados')->default(0)->after('total_aptos');
            $table->json('fuentes_evaluacion')->nullable()->after('total_descartados');
            $table->timestamp('fecha_prefiltro')->nullable()->after('fuentes_evaluacion');
            $table->foreignId('usuario_prefiltro_id')
                ->nullable()
                ->after('fecha_prefiltro')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('solicitud_elementos', function (Blueprint $table): void {
            $table->unsignedInteger('numero_fila')->nullable()->after('solicitud_id');
            $table->string('matricula_corta', 60)->nullable()->after('cuip')->index();
            $table->string('matricula_larga', 60)->nullable()->after('matricula_corta')->index();
            $table->foreignId('arma_corta_id')
                ->nullable()
                ->after('matricula_larga')
                ->constrained('loc_armas')
                ->nullOnDelete();
            $table->foreignId('arma_larga_id')
                ->nullable()
                ->after('arma_corta_id')
                ->constrained('loc_armas')
                ->nullOnDelete();
            $table->string('resultado_prefiltro', 30)->nullable()->after('estado_tramite')->index();
            $table->json('motivos_descarte')->nullable()->after('resultado_prefiltro');
            $table->json('datos_originales_fila')->nullable()->after('motivos_descarte');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('solicitud_elementos', function (Blueprint $table): void {
            $table->dropForeign(['arma_corta_id']);
            $table->dropForeign(['arma_larga_id']);
            $table->dropIndex(['matricula_corta']);
            $table->dropIndex(['matricula_larga']);
            $table->dropIndex(['resultado_prefiltro']);
            $table->dropColumn([
                'numero_fila',
                'matricula_corta',
                'matricula_larga',
                'arma_corta_id',
                'arma_larga_id',
                'resultado_prefiltro',
                'motivos_descarte',
                'datos_originales_fila',
            ]);
        });

        Schema::table('solicitudes', function (Blueprint $table): void {
            $table->dropForeign(['usuario_prefiltro_id']);
            $table->dropColumn([
                'total_elementos',
                'total_aptos',
                'total_descartados',
                'fuentes_evaluacion',
                'fecha_prefiltro',
                'usuario_prefiltro_id',
            ]);
        });
    }
};

