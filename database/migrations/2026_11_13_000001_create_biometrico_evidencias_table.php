<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1B.2 — Catálogo de contenido biométrico preservado (uno por contenido único).
 * La ruta deriva del SHA-256; el archivo vive en el disco privado `biometricos_evidencia`.
 * Inmutable: sin updated_at; triggers de solo inserción en 2026_11_13_000007.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometrico_evidencias', function (Blueprint $table) {
            $table->id();
            $table->char('sha256', 64)->unique();
            $table->string('ruta_relativa', 120)->unique();
            $table->string('mime_real', 50);
            $table->string('formato', 10);
            $table->unsignedInteger('tamano_bytes');
            $table->unsignedSmallInteger('ancho_px')->nullable();
            $table->unsignedSmallInteger('alto_px')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometrico_evidencias');
    }
};
