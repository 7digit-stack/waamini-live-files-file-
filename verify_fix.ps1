$files = @(
    'cron_winback.php',
    'cron_indiamart_sync.php',
    'cron_school_fee_reminder.php',
    'cron_cart_recovery.php',
    'cron_festival_sender.php',
    'cron_festival.php',
    'cron_trial_nudge.php',
    'cron_medicine_reminder.php',
    'cron_review_request.php',
    'cron_vehicle_reminder.php',
    'cron_eod_sheets.php'
)

$basePath = 'C:\xampp\htdocs\waamini\'

Write-Host "Checking for literal backslash-n corruption:"
foreach ($file in $files) {
    $filepath = Join-Path $basePath $file
    $content = [System.IO.File]::ReadAllText($filepath)
    if ($content -match '\\n') {
        Write-Host "STILL CORRUPTED: $file"
    } else {
        Write-Host "OK: $file"
    }
}

Write-Host "`nSample content from cron_winback.php (first 15 lines):"
$sample = [System.IO.File]::ReadAllLines((Join-Path $basePath 'cron_winback.php'))
for ($i = 0; $i -lt [Math]::Min(15, $sample.Count); $i++) {
    Write-Host "$($i+1): $($sample[$i])"
}
