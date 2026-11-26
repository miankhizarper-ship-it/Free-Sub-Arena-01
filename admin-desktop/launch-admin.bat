@echo off
setlocal EnableExtensions

title FreeSub Local Admin Desktop Server (127.0.0.1:8787)

:: Resolve project root directory (parent of /admin-desktop/)
set "SCRIPT_DIR=%~dp0"
pushd "%SCRIPT_DIR%.."
set "PROJECT_ROOT=%CD%"

set "ADMIN_HOST=127.0.0.1"
set "ADMIN_PORT=8787"
set "ADMIN_URL=http://%ADMIN_HOST%:%ADMIN_PORT%"

echo ============================================================================
echo   FreeSub Admin Desktop Application
echo   Project Root : %PROJECT_ROOT%
echo   Binding Host : %ADMIN_URL% (Loopback-only local security)
echo ============================================================================
echo.

:: Verify PHP 8.3+ CLI is available in PATH
where php >nul 2>&1
if errorlevel 1 (
    echo [ERROR] PHP executable was not found in your system PATH.
    echo Please install PHP 8.3+ and ensure "php" is available in PATH.
    echo.
    pause
    popd
    exit /b 1
)

:: Launch standalone desktop window (Edge --app, Chrome --app, or default browser fallback)
echo [INFO] Opening FreeSub Admin Desktop window at %ADMIN_URL% ...

set "BROWSER_LAUNCHED=0"

:: 1. Try Microsoft Edge in standalone --app mode
where msedge >nul 2>&1
if not errorlevel 1 (
    start "" msedge --app=%ADMIN_URL% --window-size=1360,860
    set "BROWSER_LAUNCHED=1"
)

if "%BROWSER_LAUNCHED%"=="0" if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" --app=%ADMIN_URL% --window-size=1360,860
    set "BROWSER_LAUNCHED=1"
)

if "%BROWSER_LAUNCHED%"=="0" if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" --app=%ADMIN_URL% --window-size=1360,860
    set "BROWSER_LAUNCHED=1"
)

:: 2. Try Google Chrome in standalone --app mode
if "%BROWSER_LAUNCHED%"=="0" (
    where chrome >nul 2>&1
    if not errorlevel 1 (
        start "" chrome --app=%ADMIN_URL% --window-size=1360,860
        set "BROWSER_LAUNCHED=1"
    )
)

if "%BROWSER_LAUNCHED%"=="0" if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" (
    start "" "%ProgramFiles%\Google\Chrome\Application\chrome.exe" --app=%ADMIN_URL% --window-size=1360,860
    set "BROWSER_LAUNCHED=1"
)

if "%BROWSER_LAUNCHED%"=="0" if exist "%LocalAppData%\Google\Chrome\Application\chrome.exe" (
    start "" "%LocalAppData%\Google\Chrome\Application\chrome.exe" --app=%ADMIN_URL% --window-size=1360,860
    set "BROWSER_LAUNCHED=1"
)

:: 3. Fallback to system default browser
if "%BROWSER_LAUNCHED%"=="0" (
    start "" "%ADMIN_URL%"
)

echo [INFO] Starting PHP built-in server strictly on %ADMIN_HOST%:%ADMIN_PORT% ...
echo [INFO] Keep this console open while using Admin Desktop. Press Ctrl+C to stop.
echo.

php -S 127.0.0.1:8787 -t admin-desktop

popd
endlocal
