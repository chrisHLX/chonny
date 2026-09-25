@echo off
REM ---------------------------------------------------------------------------
REM  MindCollector — look at the arena games you just played.
REM
REM  Reads your combat log, reviews anything new, then serves the site and opens
REM  Match Review. Double-click it after a session; that is the whole workflow.
REM
REM  Everything runs on this machine against this machine's database. Nothing is
REM  uploaded. Close the window to stop the server.
REM
REM  Paths come from .env (WOW_COMBATLOG_PATH, ARENA_LOG_ARCHIVE_PATH), so there
REM  is nothing to edit here.
REM ---------------------------------------------------------------------------

setlocal
cd /d "%~dp0.."

echo.
echo  Reading your combat log...
echo.

php artisan wow:sync
if errorlevel 1 (
    echo.
    echo  Sync failed. The output above says why.
    pause
    exit /b 1
)

echo.
echo  Starting the site on http://127.0.0.1:8321
echo  Close this window when you are done.
echo.

REM Give the server a moment to bind before the browser asks for a page.
start "" /b cmd /c "timeout /t 2 /nobreak >nul && start http://127.0.0.1:8321/wow/game-review"

php -S 127.0.0.1:8321 -t public

endlocal
