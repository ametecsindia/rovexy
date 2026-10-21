@echo off
rem Builds the Play bundle with JDK 21 and copies it here as 01-rovexy-release.aab (upload this file)
set "JAVA_HOME="
for /d %%D in ("%USERPROFILE%\.jdks\*21*") do set "JAVA_HOME=%%D"
if not defined JAVA_HOME for /d %%D in ("C:\Program Files\Java\jdk-21*" "C:\Program Files\Eclipse Adoptium\jdk-21*" "C:\Program Files\Microsoft\jdk-21*") do set "JAVA_HOME=%%~D"
if not defined JAVA_HOME (echo JDK 21 not found. In Android Studio: File - Settings - Build Tools - Gradle - Gradle JDK - Download JDK - version 21. & pause & exit /b 1)
echo Using Java: %JAVA_HOME%
set "PATH=%JAVA_HOME%\bin;%PATH%"
cd /d "%~dp0..\android"
call gradlew.bat --stop >nul 2>&1
call gradlew.bat --no-daemon clean :app:bundlePlayRelease || (echo BUILD FAILED & pause & exit /b 1)
copy /y "app\build\outputs\bundle\playRelease\app-play-release.aab" "%~dp001-rovexy-release.aab"
echo Done: %~dp001-rovexy-release.aab
pause