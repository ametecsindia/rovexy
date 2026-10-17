@echo off
rem ROVEXY on-premises install (Windows). Run from the unpacked package folder, as Administrator.
rem   install.bat                      set up; the licence can be activated later with activate-licence.bat
rem   install.bat C:\path\RX-....lic   set up and activate with the licence file from Ametecs
rem Safe to run again: finished steps are skipped.
setlocal EnableExtensions EnableDelayedExpansion
title ROVEXY on-premises install
set "APP=%~dp0rovexy"
if not exist "%APP%\artisan" (
  echo [ERROR] The rovexy folder was not found next to install.bat. Unpack the whole package and run install.bat from it.
  goto :fail
)
cd /d "%APP%"
echo.
echo  ROVEXY on-premises install
echo  ==========================
for /f "usebackq delims=" %%V in ("%~dp0VERSION.txt") do if not defined VERLINE set "VERLINE=%%V"
echo  %VERLINE%
echo.

rem - 1. PHP -
echo [1/5] Checking PHP
if not defined ROVEXY_PHP set "ROVEXY_PHP=php"
"%ROVEXY_PHP%" -r "exit(version_compare(PHP_VERSION, '8.3.0', '<') ? 1 : 0);" >nul 2>&1
if errorlevel 1 (
  echo [ERROR] PHP 8.3 or newer was not found.
  echo         Install PHP 8.3 ^(https://windows.php.net^) and add it to PATH, or run:  set ROVEXY_PHP=C:\php\php.exe
  goto :fail
)
rem full path of php.exe, read back from a temp file (no for /f: its quote handling broke this line on some PCs)
set "PHPEXE="
"%ROVEXY_PHP%" -r "echo PHP_BINARY;" > "%TEMP%\rovexy-phpexe.txt" 2>nul
set /p PHPEXE=<"%TEMP%\rovexy-phpexe.txt"
del "%TEMP%\rovexy-phpexe.txt" >nul 2>&1
if not defined PHPEXE (
  echo [ERROR] Could not find the full path of php.exe. Run:  set ROVEXY_PHP=C:\php\php.exe   then install.bat again.
  goto :fail
)
set "MISSING="
for %%E in (openssl pdo mbstring curl fileinfo zip dom xml) do (
  "%PHPEXE%" -r "exit(extension_loaded('%%E') ? 0 : 1);" >nul 2>&1 || set "MISSING=!MISSING! %%E"
)
if defined MISSING (
  echo [ERROR] These PHP extensions are not enabled:!MISSING!
  echo         Enable them in php.ini ^(extension=...^) and run install.bat again.
  goto :fail
)
echo       %PHPEXE%

rem - 2. Settings (.env) -
echo [2/5] Settings
if not exist ".env" (
  copy /y "%~dp0env.onprem" ".env" >nul || ( echo [ERROR] Could not create rovexy\.env & goto :fail )
  echo       Created rovexy\.env. Notepad opens it now: set APP_URL and the DB_ lines, save, close Notepad.
  start /wait notepad ".env"
) else (
  echo       rovexy\.env exists
)
"%PHPEXE%" artisan --version >nul 2>&1
if errorlevel 1 (
  echo [ERROR] ROVEXY cannot start with this PHP and rovexy\.env:
  "%PHPEXE%" artisan --version
  goto :fail
)

rem - 3. App key, database, company, administrator, licence -
echo [3/5] Setting up ROVEXY
rem the licence path goes through a variable and goto: paths may hold spaces or brackets, e.g. "RX-....(1).lic"
set "LIC="
if "%~1"=="" goto :nolicence
if not exist "%~f1" goto :nofile
set "LIC=--licence=%~f1"
:nolicence
if defined LIC ( "%PHPEXE%" artisan rovexy:setup "!LIC!" ) else ( "%PHPEXE%" artisan rovexy:setup )
if errorlevel 1 (
  echo.
  echo [ERROR] Setup did not finish. Fix what the message above says, then run install.bat again.
  goto :fail
)

rem - 4. Background tasks (every minute): scheduler + job queue -
echo [4/5] Background tasks
schtasks /create /f /tn "ROVEXY Scheduler" /sc minute /mo 1 /ru SYSTEM /tr "\"%PHPEXE%\" \"%APP%\artisan\" schedule:run" >nul 2>&1
set "T1=%errorlevel%"
schtasks /create /f /tn "ROVEXY Queue" /sc minute /mo 1 /ru SYSTEM /tr "\"%PHPEXE%\" \"%APP%\artisan\" queue:work --stop-when-empty --max-time=55" >nul 2>&1
if "%T1%%errorlevel%"=="00" (
  echo       Task Scheduler: "ROVEXY Scheduler" and "ROVEXY Queue" run every minute
) else (
  echo [WARN] Could not add the scheduled tasks ^(run install.bat as Administrator^). Add them by hand - see INSTALL.md, step 4.
)

rem - 5. Web server -
echo [5/5] Web server
echo       Point the web server's document root ^(https^) to:
echo         %APP%\public
echo       Apache / Laragon / XAMPP: works as is ^(.htaccess included^). IIS or nginx: see INSTALL.md.
echo.
echo  Server ID ^(send it to Ametecs for the licence file^):
"%PHPEXE%" artisan rovexy:licence id
echo.
echo  Done. Licence file from Ametecs? Drag it onto activate-licence.bat.
echo.
pause
exit /b 0

:nofile
echo [ERROR] Licence file not found: "%~f1"
:fail
echo.
pause
exit /b 1
