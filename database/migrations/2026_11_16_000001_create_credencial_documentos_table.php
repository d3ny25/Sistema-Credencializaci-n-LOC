<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega y Seguimiento, Iteración 1.4 — expediente documental por número de tarjeta.
 *
 * Metadatos de archivos guardados en el disco privado documentos_entrega (el binario no se guarda en la tabla).
 * La categoría es texto validado por App\Enums\CategoriaDocumentoEntrega (sin CHECK, para ampliar el catálogo
 * sin migraciones). Solo inserción, como las entregas: una corrección se incorpora como un documento nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credencial_documentos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('solicitud_id')->constrained('solicitudes')->restrictOnDelete();
            $table->string('categoria', 40);
            $table->string('descripcion', 200)->nullable();
            $table->string('nombre_original', 255);
            $table->string('disco', 40);
            $table->string('ruta', 255)->unique();
            $table->string('extension', 10);
            $table->string('mime', 120);
            $table->unsignedBigInteger('tamano_bytes');
            $table->char('sha256', 64);
            $table->foreignId('subido_por_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('subido_at');
            $table->timestamps();

            $table->index(['solicitud_id', 'categoria', 'subido_at'], 'credencial_documentos_tarjeta_index');
            // Un mismo archivo no se incorpora dos veces al mismo expediente.
            $table->unique(['solicitud_id', 'sha256'], 'credencial_documentos_tarjeta_sha_unique');
        });

        $driver = DB::connection()->getDriverName();
        foreach (['UPDATE', 'DELETE'] as $evento) {
            $trigger = 'trg_credencial_documentos_no_'.strtolower($evento);
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE {$evento} ON `credencial_documentos` FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Documento del expediente de solo insercion: {$evento} rechazado'");
            } elseif ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$evento} ON credencial_documentos
                    BEGIN SELECT RAISE(ABORT, 'Documento del expediente de solo insercion: {$evento} rechazado'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $evento) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_credencial_documentos_no_'.$evento);
        }
        Schema::dropIfExists('credencial_documentos');
    }
};
