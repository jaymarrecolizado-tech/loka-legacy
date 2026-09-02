# LOKA - Register Windows Task Scheduler jobs for XAMPP cron
# Run once as Administrator: powershell -ExecutionPolicy Bypass -File cron\setup-windows-crons.ps1
# To remove:  Unregister-ScheduledTask -TaskName "LOKA*" -Confirm:$false
# To verify:  Get-ScheduledTask -TaskName "LOKA*"; Get-ScheduledTaskInfo -TaskName "LOKA Email Queue"

$ErrorActionPreference = "Stop"

$WebRoot = "C:\xampp\htdocs\Projects\pred-loka-old-boots\public_html"
$PhpBin  = "C:\xampp\php\php.exe"
$LogDir  = Join-Path $WebRoot "logs"

if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir | Out-Null }

# Helper to register or update a task
function Register-LokaTask {
    param([string]$Name, [string]$Description, [string]$Script, [string]$Schedule)
    $Action  = New-ScheduledTaskAction -Execute $PhpBin -Argument "`"$Script`""
    # Every 2 minutes = repetition every 2 minutes for 24h
    $Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 2) -RepetitionDuration (New-TimeSpan -Days 1)
    $Principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
    $Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

    if (Get-ScheduledTask -TaskName $Name -ErrorAction SilentlyContinue) {
        Set-ScheduledTask -TaskName $Name -Action $Action -Trigger $Trigger -Principal $Principal -Settings $Settings | Out-Null
        Write-Host "Updated: $Name" -ForegroundColor Yellow
    } else {
        Register-ScheduledTask -TaskName $Name -Description $Description -Action $Action -Trigger $Trigger -Principal $Principal -Settings $Settings | Out-Null
        Write-Host "Created: $Name" -ForegroundColor Green
    }
}

# Single combined task (recommended for XAMPP — one Task Scheduler entry)
$BatchFile = Join-Path $WebRoot "cron\run-crons.bat"
$Action  = New-ScheduledTaskAction -Execute $BatchFile
$Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 2) -RepetitionDuration (New-TimeSpan -Days 1)
$Principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
$Settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

$TaskName = "LOKA Cron (XAMPP)"
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
    Set-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Principal $Principal -Settings $Settings | Out-Null
    Write-Host "Updated: $TaskName -> $BatchFile every 2 min" -ForegroundColor Yellow
} else {
    Register-ScheduledTask -TaskName $TaskName -Description "LOKA email/SMS/care/trip crons (XAMPP) every 2 min" -Action $Action -Trigger $Trigger -Principal $Principal -Settings $Settings | Out-Null
    Write-Host "Created: $TaskName -> $BatchFile every 2 min" -ForegroundColor Green
}

Write-Host ""
Write-Host "Verify:" -ForegroundColor Cyan
Write-Host "  Get-ScheduledTask -TaskName 'LOKA*'"
Write-Host "  Get-Content $LogDir\cron.log -Tail 20"
Write-Host ""
Write-Host "HTTP fallback (no Task Scheduler): curl http://localhost/Projects/pred-loka-old-boots/public_html/?page=cron&action=email&key=SECRET (and sms/care/trips)" -ForegroundColor DarkGray
Write-Host "  cron_secret in settings table: 132183bc6fb98b95f78b7f94f9c928fe" -ForegroundColor DarkGray
