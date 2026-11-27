@echo off
setlocal EnableExtensions

title FreeSub User SaaS Web App (127.0.0.1:8000)

:: Resolve project root directory (parent of /web/)
set "SCRIPT_DIR=%~dp0"
pushd "%SCRIPT_DIR%.."
set "PROJECT_ROOT=%CD%"

set "WEB_HOST=127.0.0.1"
set "WEB_PORT=8000"
set "WEB_URL=http://localhost:%WEB_PORT%"

echo ============================================================================
echo   FreeSub User SaaS Web Application
echo   Project Root : %PROJECT_ROOT%
echo   Public Root  : %PROJECT_ROOT%\web
echo   Local URL    : %WEB_URL%
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

echo [INFO] Opening %WEB_URL% in your default browser...
start "" "%WEB_URL%"

echo [INFO] Starting PHP built-in web server on %WEB_HOST%:%WEB_PORT% (-t web) ...
echo [INFO] Keep this console open while testing FreeSub. Press Ctrl+C to stop.
echo.

php -S 127.0.0.1:8000 -t web

popd
endlocal
