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
        Schema::table('biometrico_auditorias', function (Blueprint $table): void {
            // Snapshots inmutables del actor
            $table->unsignedBigInteger('actor_user_id_historico')
                ->nullable()
                ->after('user_id');

            $table->string('actor_email_historico', 150)
                ->nullable()
                ->after('actor_user_id_historico');

            // Snapshots inmutables del recurso biométrico consultado
            $table->string('cuip_snapshot', 50)
                ->nullable()
                ->after('biometrico_expediente_id');

            $table->string('ambito_snapshot', 20)
                ->nullable()
                ->after('cuip_snapshot');

            $table->string('tipo_biometrico_snapshot', 30)
                ->nullable()
                ->after('ambito_snapshot');

            $table->string('ruta_relativa_snapshot', 255)
                ->nullable()
                ->after('tipo_biometrico_snapshot');

            $table->char('sha256_snapshot', 64)
                ->nullable()
                ->after('ruta_relativa_snapshot');

            // Índices para trazabilidad pericial rápida
            $table->index(['cuip_snapshot', 'created_at'], 'idx_bio_audit_cuip_fecha');
            $table->index('actor_email_historico', 'idx_bio_audit_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('biometrico_auditorias', function (Blueprint $table): void {
            $table->dropIndex('idx_bio_audit_cuip_fecha');
            $table->dropIndex('idx_bio_audit_email');

            $table->dropColumn([
                'actor_user_id_historico',
                'actor_email_historico',
                'cuip_snapshot',
                'ambito_snapshot',
                'tipo_biometrico_snapshot',
                'ruta_relativa_snapshot',
                'sha256_snapshot',
            ]);
        });
    }
};

