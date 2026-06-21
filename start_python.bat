@echo off
TITLE FaceAttend Python Server
echo ============================================
echo   FaceAttend - Starting Python AI Server
echo ============================================
echo.

REM ── Find Python ────────────────────────────────────────────────
SET PYTHON_EXE=
IF EXIST "%~dp0python\venv\Scripts\python.exe" (
    SET PYTHON_EXE=%~dp0python\venv\Scripts\python.exe
    echo [OK] Found virtual environment Python
) ELSE (
    WHERE python >nul 2>&1
    IF %ERRORLEVEL% EQU 0 (
        SET PYTHON_EXE=python
        echo [OK] Using system Python
    ) ELSE (
        WHERE python3 >nul 2>&1
        IF %ERRORLEVEL% EQU 0 (
            SET PYTHON_EXE=python3
            echo [OK] Using system Python3
        ) ELSE (
            echo [ERROR] Python not found. Please install Python 3.10+ from python.org
            pause
            exit /b 1
        )
    )
)

REM ── Auto-install packages if needed ────────────────────────────
echo [INFO] Checking/installing required packages...
%PYTHON_EXE% -m pip install --quiet -r "%~dp0python\requirements.txt"
IF %ERRORLEVEL% NEQ 0 (
    echo [WARN] Some packages may not have installed correctly. Trying to continue...
)

REM ── Start the server ────────────────────────────────────────────
echo [INFO] Starting Flask server on http://127.0.0.1:5001
echo [INFO] Press Ctrl+C to stop
echo.
cd /d "%~dp0python"
%PYTHON_EXE% app.py
pause
