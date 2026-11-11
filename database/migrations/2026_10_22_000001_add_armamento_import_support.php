<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $usesMysqlCollations = in_array($driver, ['mysql', 'mariadb'], true);
        $duplicateQuery = DB::table('loc_armas')
            ->whereNotNull('matricula');

        if ($usesMysqlCollations) {
            $duplicateQuery
                ->selectRaw('COUNT(*)')
                ->groupBy(DB::raw('`matricula` COLLATE utf8mb4_bin'));
        } else {
            $duplicateQuery->select('matricula')->groupBy('matricula');
        }

        if ($duplicateQuery->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('La migración de armamento se canceló porque ya existen matrículas duplicadas.');
        }

        Schema::table('loc_armas', function (Blueprint $table): void {
            $table->dropIndex('loc_armas_matricula_index');
        });

        if ($usesMysqlCollations) {
            Schema::table('loc_armas', function (Blueprint $table): void {
                $table->string('matricula', 60)
                    ->nullable()
                    ->collation('utf8mb4_bin')
                    ->change();
            });
        }

        Schema::table('loc_armas', function (Blueprint $table): void {
            $table->string('id_armamento', 100)->nullable()->index();
            $table->json('datos_origen')->nullable();
            $table->unique('matricula', 'loc_armas_matricula_unique');
        });

        Schema::table('loc_import_staging_rows', function (Blueprint $table) use ($usesMysqlCollations): void {
            $matricula = $table->string('matricula', 60)->nullable();
            $externalId = $table->string('id_armamento', 100)->nullable();

            if ($usesMysqlCollations) {
                $matricula->collation('utf8mb4_bin');
                $externalId->collation('utf8mb4_bin');
            }

            $table->index(['import_batch_id', 'matricula'], 'loc_import_staging_batch_matricula_index');
            $table->index(['import_batch_id', 'id_armamento'], 'loc_import_staging_batch_armamento_id_index');
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        $usesMysqlCollations = in_array($driver, ['mysql', 'mariadb'], true);
        if (DB::table('loc_import_batches')->where('tipo_fuente', 'armamento_loc')->exists()
            || DB::table('loc_armas')->whereNotNull('id_armamento')->exists()
            || DB::table('loc_armas')->whereNotNull('datos_origen')->exists()) {
            throw new RuntimeException('No se puede revertir la migración de armamento mientras existan lotes o registros importados.');
        }

        Schema::table('loc_import_staging_rows', function (Blueprint $table): void {
            $table->dropIndex('loc_import_staging_batch_matricula_index');
            $table->dropIndex('loc_import_staging_batch_armamento_id_index');
            $table->dropColumn(['matricula', 'id_armamento']);
        });

        Schema::table('loc_armas', function (Blueprint $table): void {
            $table->dropUnique('loc_armas_matricula_unique');
            $table->dropIndex('loc_armas_id_armamento_index');
            $table->dropColumn(['id_armamento', 'datos_origen']);
        });

        if ($usesMysqlCollations) {
            Schema::table('loc_armas', function (Blueprint $table): void {
                $table->string('matricula', 60)
                    ->nullable()
                    ->collation('utf8mb4_unicode_ci')
                    ->change();
            });
        }

        Schema::table('loc_armas', function (Blueprint $table): void {
            $table->index('matricula', 'loc_armas_matricula_index');
        });
    }
};
