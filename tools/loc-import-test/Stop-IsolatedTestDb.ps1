$ErrorActionPreference = 'Stop'

$testRoot = Join-Path $env:TEMP 'sistema-loc-import-isolated'
$databasePath = Join-Path $testRoot 'database\loc-import-test.sqlite'

if (-not (Test-Path -LiteralPath $databasePath)) {
    Write-Output 'No existe una base temporal activa que detener.'
    exit 0
}

Write-Output 'No hay un servidor iniciado por estos scripts; sólo se inicializa una base temporal SQLite.'
Write-Output "La base aislada se conserva para inspección: $databasePath"
Write-Output 'Para cerrar la ventana actual, ejecuta: exit'
