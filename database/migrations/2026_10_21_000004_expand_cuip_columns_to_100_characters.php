<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'loc_import_staging_rows',
        'loc_personas',
        'loc_vigencias_credenciales',
        'solicitud_elementos',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $column = $table->string('cuip', 100);
                if ($tableName !== 'loc_personas') {
                    $column->nullable();
                }
                $column->change();
            });
        }
    }

    public function down(): void
    {
        $lengthFunction = DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
        foreach (self::TABLES as $tableName) {
            if (DB::table($tableName)->whereNotNull('cuip')->whereRaw("{$lengthFunction}(cuip) > 20")->exists()) {
                throw new RuntimeException(
                    "No se puede revertir la ampliación de CUIP: {$tableName} contiene valores mayores a 20 caracteres."
                );
            }
        }

        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $column = $table->string('cuip', 20);
                if ($tableName !== 'loc_personas') {
                    $column->nullable();
                }
                $column->change();
            });
        }
    }
};
