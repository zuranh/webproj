<#
PowerShell helper to set up a MySQL database for the Event Finder project (dev only).

What it does:
- Prompts for MySQL connection info (host, port, user, password, db name) or reads from env vars
- Tries to detect `mysql` CLI and import `api/schema.sql` into the target database
- If `mysql` CLI is missing, prints Docker instructions to run the provided docker-compose.yml

Usage:
- Run from project root (where `api/schema.sql` exists):
  powershell -ExecutionPolicy Bypass -File .\dev\mysql-setup.ps1

Note: This script is for local dev convenience. Do NOT store plaintext passwords in scripts for production.
#>

function PromptIfEmpty([string]$envName, [string]$prompt, [string]$default='') {
    $val = (Get-Item -Path Env:$envName -ErrorAction SilentlyContinue).Value
    if (-not $val) {
        $val = Read-Host -Prompt $prompt
        if ($val -eq '' -and $default -ne '') { $val = $default }
    }
    return $val
}

Write-Host "MySQL setup helper for Event Finder (dev-only)" -ForegroundColor Cyan

$host = PromptIfEmpty 'DB_HOST' 'MySQL host (127.0.0.1)' '127.0.0.1'
$port = PromptIfEmpty 'DB_PORT' 'MySQL port (3306)' '3306'
$user = PromptIfEmpty 'DB_USER' 'MySQL user (events_user)' 'events_user'
$pass = PromptIfEmpty 'DB_PASS' 'MySQL password (events_pass)' 'events_pass'
$dbname = PromptIfEmpty 'DB_NAME' 'Database name to create/use (events_db)' 'events_db'

# Resolve mysql binary
$mysqlCmd = Get-Command mysql -ErrorAction SilentlyContinue
if ($null -eq $mysqlCmd) {
    Write-Warning "The 'mysql' CLI was not found in PATH."
    Write-Host "Options:" -ForegroundColor Yellow
    Write-Host "  1) Install MySQL client (or MySQL server) and re-run this script."
    Write-Host "  2) Use Docker: the repository includes a docker-compose.yml example. Run 'docker-compose up -d' and then re-run this script."
    Write-Host "Docker quick-start (if Docker installed):" -ForegroundColor Green
    Write-Host "  docker-compose up -d" -ForegroundColor Gray
    Write-Host "Then import schema with the mysql CLI (or run this script inside a container)."
    exit 1
}

$schemaPath = Join-Path -Path (Get-Location) -ChildPath 'api\schema.sql'
if (-not (Test-Path $schemaPath)) {
    Write-Error "Schema file not found at $schemaPath. Make sure you're running this from the project root and that api/schema.sql exists."
    exit 1
}

# Create database if not exists
$createCmd = "CREATE DATABASE IF NOT EXISTS `$dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Write-Host "Creating database '$dbname' (if not exists) on $host:$port as $user..."
$escapedPass = $pass -replace '"','"'
& mysql -h $host -P $port -u $user -p$pass -e $createCmd
if ($LASTEXITCODE -ne 0) {
    Write-Error "Failed to create database. Check credentials and server availability."
    exit 1
}

# Import schema
Write-Host "Importing schema from api/schema.sql into $dbname..."
& mysql -h $host -P $port -u $user -p$pass $dbname < $schemaPath
if ($LASTEXITCODE -ne 0) {
    Write-Error "Schema import failed. See messages above."
    exit 1
}

Write-Host "Schema imported successfully. Set environment variables and run the PHP server:" -ForegroundColor Green
Write-Host "PowerShell example (current shell only):" -ForegroundColor Gray
Write-Host "  $env:DB_DRIVER = 'mysql'" -ForegroundColor Gray
Write-Host "  $env:DB_HOST = '$host'" -ForegroundColor Gray
Write-Host "  $env:DB_PORT = '$port'" -ForegroundColor Gray
Write-Host "  $env:DB_NAME = '$dbname'" -ForegroundColor Gray
Write-Host "  $env:DB_USER = '$user'" -ForegroundColor Gray
Write-Host "  $env:DB_PASS = '$pass'" -ForegroundColor Gray
Write-Host "  $env:EVENTS_ADMIN_SECRET = 'your-admin-secret'" -ForegroundColor Gray
Write-Host "  php -S localhost:8000" -ForegroundColor Gray

Write-Host "Done." -ForegroundColor Cyan
