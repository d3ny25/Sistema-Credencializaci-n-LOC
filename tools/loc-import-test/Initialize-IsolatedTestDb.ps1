$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$testRoot = Join-Path $env:TEMP 'sistema-loc-import-isolated'
$databasePath = Join-Path $testRoot 'database\loc-import-test.sqlite'
$storagePath = Join-Path $testRoot 'storage'
$configCache = Join-Path $storagePath 'framework\cache\config.php'

if (Test-Path -LiteralPath $testRoot) {
    $existingFiles = @(Get-ChildItem -LiteralPath $testRoot -File -Recurse)
    $unexpectedFiles = @($existingFiles | Where-Object {
        $_.FullName -ne $databasePath -or $_.Length -ne 0
    })
    if ($unexpectedFiles.Count -gt 0) {
        throw "El directorio de pruebas ya contiene archivos no vacíos o inesperados: $testRoot. No se modificó."
    }
    if (-not (Test-Path -LiteralPath $databasePath)) {
        throw "El directorio de pruebas ya existe pero no contiene la base esperada: $testRoot. No se modificó."
    }
} else {
    New-Item -ItemType Directory -Path (Split-Path $databasePath) -Force | Out-Null
    New-Item -ItemType File -Path $databasePath | Out-Null
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
    New-Item -ItemType Directory -Path $directory -Force | Out-Null
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
& php $guard $projectRoot $testRoot
if ($LASTEXITCODE -ne 0) {
    throw 'La comprobación de aislamiento falló; no se ejecutaron migraciones.'
}

Push-Location $projectRoot
try {
    & php artisan migrate --force
    if ($LASTEXITCODE -ne 0) {
        throw "Las migraciones fallaron con código $LASTEXITCODE."
    }

    & php $guard $projectRoot $testRoot --verify-schema
    if ($LASTEXITCODE -ne 0) {
        throw 'La verificación de esquema falló.'
    }
} finally {
    Pop-Location
}

Write-Output "Base temporal aislada inicializada: $databasePath"
Write-Output "No se inició servidor web ni se conectó a MySQL."
