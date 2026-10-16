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

foreach ($file in $files) {
    $filepath = Join-Path $basePath $file
    $content = [System.IO.File]::ReadAllText($filepath)
    $fixed = $content -replace '\\n', "`n"
    [System.IO.File]::WriteAllText($filepath, $fixed, [System.Text.Encoding]::UTF8)
    Write-Host "Fixed: $file"
}
