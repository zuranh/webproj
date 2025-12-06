<#
PowerShell test script for Event Finder API (dev only).

Usage:
1. Start the PHP server in the project root:
   php -S localhost:8000
2. From another PowerShell window run:
   pwsh -ExecutionPolicy Bypass -File .\dev\test-api.ps1

The script will prompt for an admin secret if the environment variable is not set.
It uses a WebRequestSession to persist cookies between requests.
#>

$base = 'http://localhost:8000'

if (-not $env:EVENTS_ADMIN_SECRET) {
  $env:EVENTS_ADMIN_SECRET = Read-Host -Prompt 'Enter admin secret to use for admin actions (dev only)'
}
$headers = @{ 'X-Admin-Secret' = $env:EVENTS_ADMIN_SECRET }

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

function Pretty($obj) { $obj | ConvertTo-Json -Depth 6 }

Write-Host "Base URL: $base"

try {
  Write-Host "\n1) Register user"
  $regPayload = @{ name='Test User'; email='test+ps@example.com'; password='secret123'; age=25 }
  $reg = Invoke-RestMethod -Method Post -Uri "$base/api/register.php" -Body (ConvertTo-Json $regPayload) -ContentType 'application/json' -WebSession $session
  Write-Host (Pretty $reg)
} catch { Write-Host "Register failed: $_" }

try {
  Write-Host "\n2) Login (saves session cookie)"
  $loginPayload = @{ email='test+ps@example.com'; password='secret123' }
  $login = Invoke-RestMethod -Method Post -Uri "$base/api/login.php" -Body (ConvertTo-Json $loginPayload) -ContentType 'application/json' -WebSession $session
  Write-Host (Pretty $login)
} catch { Write-Host "Login failed: $_" }

try {
  Write-Host "\n3) Get profile (me)"
  $me = Invoke-RestMethod -Uri "$base/api/me.php" -WebSession $session
  Write-Host (Pretty $me)
} catch { Write-Host "Get profile failed: $_" }

try {
  Write-Host "\n4) Create event (admin)"
  $createPayload = @{ title='PS Test Event'; description='Created by PS script'; location='Script Park'; lat=51.5074; lng=-0.1278; date='2025-12-01'; time='19:00'; age_restriction=0; price=0 }
  $create = Invoke-RestMethod -Method Post -Uri "$base/api/events.php" -Headers $headers -Body (ConvertTo-Json $createPayload) -ContentType 'application/json' -WebSession $session
  Write-Host (Pretty $create)
  $createdId = $create.id
} catch { Write-Host "Create event failed: $_" }

try {
  Write-Host "\n5) List events (proximity)"
  $list = Invoke-RestMethod -Uri "$base/api/events.php?lat=51.5074&lng=-0.1278&radius=50"
  Write-Host (Pretty $list)
} catch { Write-Host "List events failed: $_" }

if ($createdId) {
  try {
    Write-Host "\n6) Update event (PUT) id=$createdId"
    $updatePayload = @{ id = $createdId; title = 'PS Updated Title'; price = 9.99 }
    $update = Invoke-RestMethod -Method Put -Uri "$base/api/events.php" -Headers $headers -Body (ConvertTo-Json $updatePayload) -ContentType 'application/json' -WebSession $session
    Write-Host (Pretty $update)
  } catch { Write-Host "Update failed: $_" }

  try {
    Write-Host "\n7) Delete event (DELETE) id=$createdId"
    $delete = Invoke-RestMethod -Method Delete -Uri "$base/api/events.php?id=$createdId" -Headers $headers -WebSession $session
    Write-Host (Pretty $delete)
  } catch { Write-Host "Delete failed: $_" }
}

try {
  Write-Host "\n8) Logout"
  $logout = Invoke-RestMethod -Method Post -Uri "$base/api/logout.php" -WebSession $session
  Write-Host (Pretty $logout)
} catch { Write-Host "Logout failed: $_" }

Write-Host "\nTest script finished. Check dev/db-debug.php or the DB file to verify state."
