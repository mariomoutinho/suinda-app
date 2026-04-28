$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$backendRoot = Join-Path $projectRoot "backend"

$env:SUINDA_DB_DRIVER = "mysql"
$env:SUINDA_DB_HOST = "127.0.0.1"
$env:SUINDA_DB_PORT = "3306"
$env:SUINDA_DB_NAME = "suinda_app"
$env:SUINDA_DB_USER = "root"
$env:SUINDA_DB_PASSWORD = ""

Write-Host "Servidor local do Suinda usando MySQL"
Write-Host "API: http://127.0.0.1:8000"
Write-Host "Banco: suinda_app"
Write-Host "Usuario: root"
Write-Host ""

php -S 127.0.0.1:8000 -t (Join-Path $backendRoot "public")
