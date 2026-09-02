@echo off
REM LOKA - Windows Task Scheduler entry point (XAMPP)
REM Runs every 2 minutes via Task Scheduler. Keep this file in cron/.
REM Each processor is idempotent + flock-protected, so overlapping runs are safe.

set WEB_ROOT=C:\xampp\htdocs\Projects\pred-loka-old-boots\public_html
set PHP_BIN=C:\xampp\php\php.exe
set LOG_FILE=%WEB_ROOT%\logs\cron.log

REM Ensure log dir exists
if not exist "%WEB_ROOT%\logs" mkdir "%WEB_ROOT%\logs%"

echo [%date% %time%] run-crons started >> "%LOG_FILE%"

REM Email queue (every 2 min — the 10-30s hang fix depends on this)
"%PHP_BIN%" "%WEB_ROOT%\cron\process_queue.php" >> "%LOG_FILE%" 2>&1

REM SMS queue (every 1-2 min, no-op when sms_enabled=0)
"%PHP_BIN%" "%WEB_ROOT%\cron\process_sms_queue.php" >> "%LOG_FILE%" 2>&1

REM Trip confirmations / overdue / evaluation reminders (every 5 min)
REM process_trip_confirmations.php handles its own 5-min cadence; running it every
REM 2 min is harmless (only queued rows with scheduled_send_at <= NOW are sent).
"%PHP_BIN%" "%WEB_ROOT%\cron\process_trip_confirmations.php" >> "%LOG_FILE%" 2>&1

REM Care reminders (daily cadence inside: 7d/1d/due/overdue — safe to run every 2 min)
"%PHP_BIN%" "%WEB_ROOT%\cron\process_care_reminders.php" >> "%LOG_FILE%" 2>&1

echo [%date% %time%] run-crons finished >> "%LOG_FILE%"
