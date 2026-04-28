$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$backendRoot = Join-Path $projectRoot "backend"
$storageRoot = Join-Path $backendRoot "local-data"
$databaseDir = Join-Path $storageRoot "database"
$databasePath = Join-Path $databaseDir "suinda.sqlite"

New-Item -ItemType Directory -Force -Path $databaseDir | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $storageRoot "uploads") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $storageRoot "imports") | Out-Null

$env:SUINDA_DB_DRIVER = "sqlite"
$env:SUINDA_SQLITE_PATH = $databasePath
$env:SUINDA_STORAGE_PATH = $storageRoot

Write-Host "Servidor local do Suinda"
Write-Host "API: http://127.0.0.1:8000"
Write-Host "Banco SQLite: $databasePath"
Write-Host ""

php -S 127.0.0.1:8000 -t (Join-Path $backendRoot "public")
