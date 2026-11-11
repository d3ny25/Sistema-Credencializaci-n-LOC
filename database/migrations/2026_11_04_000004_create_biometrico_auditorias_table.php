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
        Schema::create('biometrico_auditorias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('solicitud_id')
                ->nullable()
                ->constrained('solicitudes')
                ->nullOnDelete();
            $table->foreignId('solicitud_elemento_id')
                ->nullable()
                ->constrained('solicitud_elementos')
                ->nullOnDelete();
            $table->foreignId('biometrico_archivo_id')
                ->nullable()
                ->constrained('biometrico_archivos')
                ->nullOnDelete();
            $table->foreignId('biometrico_expediente_id')
                ->nullable()
                ->constrained('biometrico_expedientes')
                ->nullOnDelete();
            $table->string('evento', 60);
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->json('detalles')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Índices para trazabilidad pericial y consultas de bitácora
            $table->index(['user_id', 'created_at'], 'idx_bio_audit_user');
            $table->index(['evento', 'created_at'], 'idx_bio_audit_evento');
            $table->index('biometrico_expediente_id', 'idx_bio_audit_expediente');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometrico_auditorias');
    }
};

