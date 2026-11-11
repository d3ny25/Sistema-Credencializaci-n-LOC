$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$testRoot = Join-Path $env:TEMP 'sistema-loc-import-isolated'
$databasePath = Join-Path $testRoot 'database\loc-import-test.sqlite'
$storagePath = Join-Path $testRoot 'storage'
$configCache = Join-Path $storagePath 'framework\cache\config.php'

if (-not (Test-Path -LiteralPath $databasePath)) {
    throw "No existe la base temporal. Ejecuta Initialize-IsolatedTestDb.ps1 primero."
}

foreach ($directory in @(
    (Split-Path $configCache),
    (Join-Path $storagePath 'app\private'),
    (Join-Path $storagePath 'framework\cache\data'),
    (Join-Path $storagePath 'framework\sessions'),
    (Join-Path $storagePath 'framework\testing'),
    (Join-Path $storagePath 'framework\views'),
    (Join-Path $storagePath 'logs')
)) {
    if (-not (Test-Path -LiteralPath $directory)) {
        throw "Falta directorio de almacenamiento aislado esperado: $directory"
    }
}

$env:APP_ENV = 'testing'
$env:APP_CONFIG_CACHE = $configCache
$env:LARAVEL_STORAGE_PATH = $storagePath
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $databasePath
$env:DB_URL = ''
$env:DB_FOREIGN_KEYS = 'true'
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'array'
$env:QUEUE_CONNECTION = 'sync'

$guard = Join-Path $PSScriptRoot 'assert-isolated-environment.php'
Push-Location $projectRoot
try {
    & php artisan migrate:status
    if ($LASTEXITCODE -ne 0) {
        throw "No se pudo consultar el estado de migraciones ($LASTEXITCODE)."
    }

    & php $guard $projectRoot $testRoot --verify-schema
    if ($LASTEXITCODE -ne 0) {
        throw 'La verificación de esquema aislado falló.'
    }

    & php artisan test tests\Feature\LocPersonalImportTest.php
    if ($LASTEXITCODE -ne 0) {
        throw "Las pruebas sintéticas del importador fallaron ($LASTEXITCODE)."
    }
} finally {
    Pop-Location
}

Write-Output 'Verificación completada. Las pruebas de importación utilizan hojas XLS sintéticas y SQLite en memoria.'
