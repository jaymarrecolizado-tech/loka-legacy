@echo off
REM LOKA Fleet Management - Archive Creation Script for Windows
REM This script creates a tar.gz archive of the production files

setlocal enabledelayedexpansion

echo ========================================
echo LOKA Fleet - Create Production Archive
echo ========================================
echo.

REM Get the directory of this script
set "SCRIPT_DIR=%~dp0"
cd /d "%SCRIPT_DIR%"

REM Check if 7-Zip is available
where 7z >nul 2>&1
if %errorlevel% == 0 (
    echo Using 7-Zip to create archive...
    7z a -tgzip loka-fleet.tar.gz . >nul
    if %errorlevel% == 0 (
        echo.
        echo SUCCESS: Archive created as loka-fleet.tar.gz
        echo Location: %SCRIPT_DIR%loka-fleet.tar.gz
        echo.
        echo Upload this file to your server using:
        echo   - Hostinger File Manager
        echo   - SFTP/SCP client
        echo   - Or follow the deployment guide
        echo.
        goto :end
    ) else (
        echo ERROR: Failed to create archive with 7-Zip
        goto :error
    )
)

REM Check if tar is available (Git Bash, WSL, etc.)
where tar >nul 2>&1
if %errorlevel% == 0 (
    echo Using tar to create archive...
    tar -czf loka-fleet.tar.gz .
    if %errorlevel% == 0 (
        echo.
        echo SUCCESS: Archive created as loka-fleet.tar.gz
        echo Location: %SCRIPT_DIR%loka-fleet.tar.gz
        echo.
        echo Upload this file to your server using:
        echo   - Hostinger File Manager
        echo   - SFTP/SCP client
        echo   - Or follow the deployment guide
        echo.
        goto :end
    ) else (
        echo ERROR: Failed to create archive with tar
        goto :error
    )
)

REM No archive tool found
echo ERROR: Neither 7-Zip nor tar was found on your system.
echo.
echo Please install one of the following:
echo   1. 7-Zip: https://www.7-zip.org/
echo   2. Git for Windows (includes tar): https://git-scm.com/download/win
echo.
echo Or manually zip the prod2prod folder and upload it.

goto :error

:error
echo.
echo ========================================
echo ERROR: Archive creation failed
echo ========================================
echo.
pause
exit /b 1

:end
echo Archive size:
dir loka-fleet.tar.gz | findstr "loka-fleet.tar.gz"
echo.
echo ========================================
echo Follow deployment instructions in:
echo   - QUICK_START.md (recommended)
echo   - DEPLOYMENT_GUIDE.md (detailed)
echo ========================================
pause
