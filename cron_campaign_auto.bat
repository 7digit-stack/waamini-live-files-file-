@echo off
REM Automatic Campaign Sender - Run via Windows Task Scheduler every minute
REM This script sends pending WhatsApp campaign messages automatically

cd /d "C:\xampp\htdocs\waamini"
"C:\xampp\php\php.exe" -d memory_limit=256M cron_campaign_sender.php >> "C:\xampp\htdocs\waamini\logs\cron_campaign.log" 2>&1
exit /b 0
