@echo off
rem ROVEXY licence activation (Windows). Drag the .lic file from Ametecs onto this file, or:
rem   activate-licence.bat C:\path\RX-....lic
rem Without a file it shows this server's ID, which Ametecs needs to make the licence file.
rem Installed with setup.exe: run it as Administrator (right-click - Run as administrator), or use
rem the console: Plan & licence - Import & activate.
setlocal EnableExtensions
title ROVEXY licence
cd /d "%~dp0rovexy" || ( echo [ERROR] The rovexy folder was not found next to this file. & pause & exit /b 1 )
rem the Windows installer (setup.exe) puts its own PHP next to this file
if not defined ROVEXY_PHP if exist "%~dp0php\php.exe" set "ROVEXY_PHP=%~dp0php\php.exe"
if not defined ROVEXY_PHP set "ROVEXY_PHP=php"
if "%~1"=="" (
  echo.
  echo  This server's ID ^(send it to Ametecs; they send back a .lic file locked to this server^):
  echo.
  "%ROVEXY_PHP%" artisan rovexy:licence id
  echo.
  echo  When the .lic file arrives, drag it onto activate-licence.bat.
  echo.
  "%ROVEXY_PHP%" artisan rovexy:licence status
  pause
  exit /b 0
)
"%ROVEXY_PHP%" artisan rovexy:licence import "%~f1"
set "RC=%errorlevel%"
echo.
pause
exit /b %RC%
