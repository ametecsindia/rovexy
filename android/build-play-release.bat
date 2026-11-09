@echo off
rem Builds the ROVEXY Play bundle (signed with the upload key from %USERPROFILE%\.gradle\gradle.properties).
cd /d "%~dp0"
rem Same Java as Android Studio uses for this project (Gradle JDK "jbr-21"); its own bundled Java 25 is too new for Gradle 8.14.
set "JAVA_HOME="
for /d %%D in ("%USERPROFILE%\.jdks\jbr-21*" "%USERPROFILE%\.jdks\*-21*" "C:\Program Files\Java\jdk-21*" "C:\Program Files\Eclipse Adoptium\jdk-21*" "C:\Program Files\Microsoft\jdk-21*") do if exist "%%~D\bin\java.exe" set "JAVA_HOME=%%~D"
if not defined JAVA_HOME (
  echo No Java 21 found in %USERPROFILE%\.jdks > build-play-release.log
  dir /b "%USERPROFILE%\.jdks" >> build-play-release.log 2>&1
  echo DONE >> build-play-release.log
  type build-play-release.log & pause & exit /b 1
)
echo Building ROVEXY release bundle with %JAVA_HOME% ... (this window closes when done)
echo JAVA_HOME=%JAVA_HOME% > build-play-release.log
call gradlew.bat :app:bundlePlayRelease --console=plain >> build-play-release.log 2>&1
echo EXIT %ERRORLEVEL% >> build-play-release.log
"%JAVA_HOME%\bin\jarsigner.exe" -verify app\build\outputs\bundle\playRelease\app-play-release.aab >> build-play-release.log 2>&1
echo DONE >> build-play-release.log
echo.
type build-play-release.log | findstr /C:"BUILD" /C:"EXIT" /C:"jar verified" /C:"JAVA_HOME" /C:"No Java"
echo.
echo Finished. Tell Claude "built". Press any key to close.
pause > nul
