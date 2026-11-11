<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$projectRoot = realpath($argv[1] ?? '');
$testRoot = realpath($argv[2] ?? '');
$verifySchema = in_array('--verify-schema', $argv, true);

if ($projectRoot === false || $testRoot === false) {
    fwrite(STDERR, "No se pudieron resolver las rutas del proyecto o del entorno aislado.\n");
    exit(2);
}

$expectedDatabase = $testRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'loc-import-test.sqlite';
$databasePath = realpath((string) getenv('DB_DATABASE'));
$storagePath = realpath((string) getenv('LARAVEL_STORAGE_PATH'));
$configCache = (string) getenv('APP_CONFIG_CACHE');

if (getenv('DB_CONNECTION') !== 'sqlite'
    || ! in_array(getenv('DB_URL'), ['', false], true)
    || $databasePath !== realpath($expectedDatabase)
    || $storagePath === false
    || ! str_starts_with($storagePath, $testRoot.DIRECTORY_SEPARATOR)
    || str_starts_with($databasePath, $projectRoot.DIRECTORY_SEPARATOR)
    || str_starts_with($configCache, $projectRoot.DIRECTORY_SEPARATOR)
    || is_file($configCache)) {
    fwrite(STDERR, "Aislamiento rechazado: conexión, base, almacenamiento o caché de configuración no corresponden al entorno temporal.\n");
    exit(3);
}

require $projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
$app = require $projectRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== $expectedDatabase) {
    fwrite(STDERR, "Aislamiento rechazado: Laravel no tiene cargada la base SQLite temporal esperada.\n");
    exit(4);
}

if (! $verifySchema) {
    fwrite(STDOUT, "Aislamiento verificado: SQLite temporal; conexión MySQL no seleccionada.\n");
    exit(0);
}

$requiredColumns = [
    'loc_personas' => ['datos_loc'],
    'loc_import_batches' => [
        'hoja_procesada',
        'total_columnas',
        'total_nuevas',
        'total_sin_cambios',
        'total_insertadas',
        'archivo_sha256',
        'revision_sha256',
        'fecha_confirmacion',
    ],
    'loc_import_staging_rows' => ['estado_aplicacion'],
];

foreach ($requiredColumns as $table => $columns) {
    if (! Schema::hasTable($table)
        || ! Schema::hasColumns($table, $columns)) {
        fwrite(STDERR, "Falta tabla o columna requerida: {$table}.\n");
        exit(5);
    }
}

$stagingIndexes = DB::select('PRAGMA index_list("loc_import_staging_rows")');
$indexNames = array_column(array_map(static fn (object $row): array => (array) $row, $stagingIndexes), 'name');
foreach ([
    'loc_import_staging_rows_import_batch_id_numero_fila_unique',
    'loc_import_staging_rows_import_batch_id_accion_index',
    'loc_import_staging_rows_estado_aplicacion_index',
] as $indexName) {
    if (! in_array($indexName, $indexNames, true)) {
        fwrite(STDERR, "Falta índice requerido: {$indexName}.\n");
        exit(6);
    }
}

$foreignKeys = DB::select('PRAGMA foreign_key_list("loc_import_staging_rows")');
$hasBatchForeignKey = collect($foreignKeys)->contains(
    static fn (object $foreignKey): bool => $foreignKey->table === 'loc_import_batches'
        && $foreignKey->from === 'import_batch_id'
        && $foreignKey->to === 'id'
        && strtolower($foreignKey->on_delete) === 'cascade'
);

if (! $hasBatchForeignKey) {
    fwrite(STDERR, "No está presente la FK de staging a lotes con borrado en cascada.\n");
    exit(7);
}

fwrite(STDOUT, "Esquema temporal verificado: columnas del importador, índices únicos/compuestos y FK de staging correctos.\n");
