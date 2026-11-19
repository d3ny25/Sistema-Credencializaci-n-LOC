<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1B.2 — Preparación de impresión: dueña del snapshot (plantilla, datos y 4 biométricos).
 * `clave_activa` es una columna generada declarada en la creación (SQLite no admite añadir
 * columnas STORED después): una sola preparación 'preparada' por credencial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credencial_preparaciones', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('credencial_emitida_id')->constrained('credenciales_emitidas')->restrictOnDelete();
            $table->foreignId('solicitud_elemento_id')->constrained('solicitud_elementos')->restrictOnDelete();
            $table->foreignId('biometrico_expediente_id')->nullable()->constrained('biometrico_expedientes')->restrictOnDelete();
            $table->string('tipo_operacion', 30);
            $table->foreignId('preparacion_origen_id')->nullable()->constrained('credencial_preparaciones')->restrictOnDelete();
            $table->string('estado', 20);
            $table->string('origen', 10)->default('nueva');
            $table->string('estado_evidencia', 30);

            // Evidencia documental: bytes canónicos exactos (no se recodifican para verificar).
            $table->foreignId('credential_template_version_id')->nullable()->constrained('credential_template_versions')->restrictOnDelete();
            $table->string('plantilla_origen', 20)->nullable();
            $table->unsignedInteger('plantilla_version')->nullable();
            $table->longText('plantilla_config_canonica')->nullable();
            $table->char('plantilla_config_sha256', 64)->nullable();
            $table->longText('datos_variables_cifrados')->nullable();
            $table->char('datos_variables_sha256', 64)->nullable();
            $table->json('campos_por_defecto')->nullable();
            $table->unsignedTinyInteger('manifiesto_version')->nullable();
            $table->longText('manifiesto_canonico')->nullable();
            $table->char('manifiesto_sha256', 64)->nullable();
            $table->json('render_huella')->nullable();

            // restrict (no nullOnDelete): es columna del snapshot. Con SET NULL, SQLite lo bloquearía por el trigger
            // y MariaDB lo anularía en silencio (las cascadas no disparan triggers). Un usuario se desactiva, no se borra.
            $table->foreignId('preparada_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelada_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_cancelacion', 255)->nullable();
            $table->dateTime('preparada_at');
            $table->dateTime('vista_expira_en');
            $table->dateTime('expira_en');
            $table->dateTime('primera_vista_at')->nullable();
            $table->dateTime('cancelada_at')->nullable();
            $table->dateTime('expirada_at')->nullable();
            $table->timestamps();

            $table->unsignedBigInteger('clave_activa')->nullable()
                ->storedAs("case when estado = 'preparada' then credencial_emitida_id end");
            $table->unique('clave_activa', 'credencial_preparaciones_clave_activa_unique');

            $table->index(['estado', 'expira_en'], 'credencial_preparaciones_estado_expira_index');
            $table->index(['credencial_emitida_id', 'created_at'], 'credencial_preparaciones_credencial_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credencial_preparaciones');
    }
};
