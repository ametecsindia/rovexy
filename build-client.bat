@echo off
rem ============================================================================================
rem  build-client.bat - builds the ONE ROVEXY on-premises installer that every client gets.
rem
rem  Double-click it (or run it in a terminal) from the project folder. It:
rem    1. checks PHP, Composer, Node/npm and Inno Setup (installs Inno Setup into build\tools if missing)
rem    2. builds the admin console          (admin -> backend\public\admin)
rem    3. copies the backend to build\client-stage (your working copy is not touched)
rem    4. installs PHP packages for production there (composer --no-dev)
rem    5. packs dist\ROVEXY-OnPrem-<version>.zip with php artisan rovexy:build-client,
rem       then unpacks it in a temp folder, installs it and runs the health check
rem    6. downloads the Windows runtime once into build\cache and checks it (SHA-256 / signature):
rem       PHP 8.3, Caddy web server, WinSW service wrapper, Visual C++ runtime, CA certificates
rem    7. puts the app and the runtime together in build\setup-stage
rem    8. compiles dist\ROVEXY-Setup-<version>.exe with Inno Setup (deploy\windows\rovexy-setup.iss)
rem
rem  Nothing client-specific is inside: no client name, licence, .env, app key, password or private
rem  key. The client double-clicks ROVEXY-Setup-<version>.exe; a .lic file placed next to it is
rem  used automatically, otherwise the wizard asks for it (or it is added later).
rem  build-client.bat /quiet   no pauses (for scripts)
rem ============================================================================================
setlocal EnableExtensions
title ROVEXY - build on-premises installer
set "ROOT=%~dp0"
set "ROOT=%ROOT:~0,-1%"
set "STAGE=%ROOT%\build\client-stage"
set "OUT=%ROOT%\dist"
set "CACHE=%ROOT%\build\cache"
set "SSTAGE=%ROOT%\build\setup-stage"
rem Windows runtime put into the installer. To move to a newer release, change the version and its SHA-256 here.
if not defined PHP_ZIP set "PHP_ZIP=php-8.3.35-nts-Win32-vs16-x64.zip"
if not defined PHP_SHA256 set "PHP_SHA256=25a8e2ac9ff30f1d768d1447c09a600617fa6e6082729f6e95f008b59c91fe45"
if not defined CADDY_VER set "CADDY_VER=2.10.2"
if not defined CADDY_SHA256 set "CADDY_SHA256=9fd1ef9be5d9b05852b66ccc25f96f23d8651bcab20779861a745bdffa273722"
if not defined WINSW_VER set "WINSW_VER=2.12.0"
if not defined IS_VER set "IS_VER=6.4.3"
if not defined IS_TAG set "IS_TAG=6_4_3"
if not defined IS_SHA256 set "IS_SHA256=f3c42116542c4cc57263c5ba6c4feabfc49fe771f2f98a79d2f7628b8762723b"
if not defined WINSW_SHA256 set "WINSW_SHA256=b5066b7bbdfba1293e5d15cda3caaea88fbeab35bd5b38c41c913d492aadfc4f"
cd /d "%ROOT%"
echo.
echo  ROVEXY - on-premises installer
echo  ==============================
echo.

rem ---- 1. tools ---------------------------------------------------------------------------
echo [1/8] Checking tools
where php >nul 2>&1 || ( echo [ERROR] php was not found. Laragon: Menu - Tools - Path - Add Laragon to Path, then open a new window. & goto :fail )
php -r "exit(version_compare(PHP_VERSION, '8.3.0', '<') ? 1 : 0);" || ( echo [ERROR] PHP 8.3 or newer is needed. & goto :fail )
php -r "exit(extension_loaded('zip') ? 0 : 1);" || ( echo [ERROR] The PHP zip extension is off. Enable extension=zip in php.ini. & goto :fail )
where composer >nul 2>&1 || ( echo [ERROR] composer was not found on PATH. & goto :fail )
where npm >nul 2>&1 || ( echo [ERROR] npm ^(Node.js^) was not found on PATH. & goto :fail )
where curl.exe >nul 2>&1 || ( echo [ERROR] curl.exe was not found ^(Windows 10 1803 or newer has it^). & goto :fail )
where tar.exe >nul 2>&1 || ( echo [ERROR] tar.exe was not found ^(Windows 10 1803 or newer has it^). & goto :fail )
set "ISCC="
for %%P in ("%ProgramFiles(x86)%\Inno Setup 6\ISCC.exe" "%ProgramFiles%\Inno Setup 6\ISCC.exe" "%LOCALAPPDATA%\Programs\Inno Setup 6\ISCC.exe") do if not defined ISCC if exist "%%~P" set "ISCC=%%~P"
if not defined ISCC for /f "delims=" %%I in ('where ISCC.exe 2^>nul') do if not defined ISCC set "ISCC=%%I"
set "ISDIR=%ROOT%\build\tools\InnoSetup6"
if not defined ISCC if exist "%ISDIR%\ISCC.exe" set "ISCC=%ISDIR%\ISCC.exe"
if defined ISCC goto :have_iscc
rem Not installed: put Inno Setup into build\tools for this project only (official release, checked, no admin rights)
echo       Inno Setup 6 not found - installing it into build\tools (once, no admin rights needed)
if not exist "%CACHE%" mkdir "%CACHE%"
call :fetch "%CACHE%\innosetup-%IS_VER%.exe" "https://github.com/jrsoftware/issrc/releases/download/is-%IS_TAG%/innosetup-%IS_VER%.exe" || goto :fail
call :sha "%CACHE%\innosetup-%IS_VER%.exe" %IS_SHA256% || goto :fail
start "" /wait "%CACHE%\innosetup-%IS_VER%.exe" /VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP- /CURRENTUSER /NOICONS /DIR="%ISDIR%"
rem fallback: install mode for all users (Windows asks for admin approval once)
if not exist "%ISDIR%\ISCC.exe" start "" /wait "%CACHE%\innosetup-%IS_VER%.exe" /VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP- /ALLUSERS /NOICONS /DIR="%ISDIR%"
if not exist "%ISDIR%\ISCC.exe" (
  echo [ERROR] Inno Setup could not be installed automatically. Install it once by hand:
  echo         winget install -e --id JRSoftware.InnoSetup      ^(or https://jrsoftware.org/isdl.php^)
  goto :fail
)
set "ISCC=%ISDIR%\ISCC.exe"
:have_iscc
set "VJ=%ROOT%\backend\version.json"
for /f "usebackq delims=" %%V in (`php -r "echo json_decode(file_get_contents(getenv('VJ')))->version;"`) do set "VER=%%V"
if not defined VER ( echo [ERROR] backend\version.json could not be read. & goto :fail )
echo       PHP, Composer, npm and Inno Setup found. Version %VER%

rem ---- 2. console build ------------------------------------------------------------------
echo [2/8] Building the admin console
pushd "%ROOT%\admin" || goto :fail
if not exist node_modules (
  call npm ci || ( popd & echo [ERROR] npm ci failed. & goto :fail )
)
call npm run build || ( popd & echo [ERROR] The console build failed - see above. & goto :fail )
popd
if not exist "%ROOT%\backend\public\admin\index.html" ( echo [ERROR] backend\public\admin\index.html was not produced. & goto :fail )

rem ---- G1: the full Android app (all-day location), served by each client server at /get-app -------------
rem Build it first in Android Studio: Build - Generate Signed App Bundle / APK - APK - flavour "direct", release.
set "APK=%ROOT%\android\app\build\outputs\apk\direct\release\app-direct-release.apk"
if not exist "%APK%" goto :no_apk
if not exist "%ROOT%\backend\public\downloads" mkdir "%ROOT%\backend\public\downloads"
copy /y "%APK%" "%ROOT%\backend\public\downloads\rovexy.apk" >nul || ( echo [ERROR] Could not copy the app file. & goto :fail )
php -r "preg_match('/versionName = .([0-9.]+)/', file_get_contents($argv[1]), $m); file_put_contents($argv[2], json_encode(['version' => $m[1] ?? '?', 'built' => date('Y-m-d')]));" -- "%ROOT%\android\app\build.gradle.kts" "%ROOT%\backend\public\downloads\rovexy-apk.json"
echo       App file included: %APK%
goto :have_apk
:no_apk
echo [WARN] No direct app file ^(%APK%^). Client servers will show "app file not on this server" at /get-app.
:have_apk
rem ---- 3. clean copy of the backend ------------------------------------------------------
echo [3/8] Copying the backend to build\client-stage
if exist "%STAGE%" rmdir /s /q "%STAGE%"
if exist "%STAGE%" ( echo [ERROR] Could not clear %STAGE% - close any program using it. & goto :fail )
robocopy "%ROOT%\backend" "%STAGE%\backend" /E /XD "%ROOT%\backend\vendor" "%ROOT%\backend\storage" "%ROOT%\backend\tests" node_modules .git /XF .env .phpunit.result.cache *.log /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 ( echo [ERROR] Copying the backend failed. & goto :fail )
robocopy "%ROOT%\deploy\onprem" "%STAGE%\deploy\onprem" /E /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 ( echo [ERROR] Copying deploy\onprem failed. & goto :fail )
for %%D in (app\public framework\cache\data framework\sessions framework\views logs) do mkdir "%STAGE%\backend\storage\%%D" 2>nul

rem Settings for the build commands only (the package itself has no .env)
set "APP_ENV=local"
set "APP_DEBUG=false"
set "DB_CONNECTION=sqlite"
set "DB_DATABASE=:memory:"
set "CACHE_STORE=array"
set "SESSION_DRIVER=array"
set "QUEUE_CONNECTION=sync"
set "BROADCAST_CONNECTION=null"
rem used only to warn when the package would trust this PC's test licence key
set "LICENCE_PRIVATE_KEY=%ROOT%\backend\storage\app\keys\licence-private.pem"

rem ---- 4. production PHP packages --------------------------------------------------------
echo [4/8] Installing PHP packages for production (composer --no-dev)
pushd "%STAGE%\backend" || goto :fail
call composer install --no-dev --optimize-autoloader --no-interaction --no-progress || ( popd & echo [ERROR] composer install failed - see above. & goto :fail )

rem ---- 5. pack + verify ------------------------------------------------------------------
echo [5/8] Packing and checking the package
if not exist "%OUT%" mkdir "%OUT%"
if exist "%OUT%\ROVEXY-OnPrem-%VER%.zip" del /q "%OUT%\ROVEXY-OnPrem-%VER%.zip" "%OUT%\ROVEXY-OnPrem-%VER%.zip.sha256"
php artisan rovexy:build-client --out="%OUT%" --verify || ( popd & echo [ERROR] Packing or the install check failed - see above. & goto :fail )
popd
rmdir /s /q "%STAGE%" 2>nul

rem ---- 6. Windows runtime (downloaded once, then reused from build\cache) ----------------------
echo [6/8] Windows runtime (PHP, Caddy, WinSW, Visual C++ runtime, CA certificates)
if not exist "%CACHE%" mkdir "%CACHE%"
call :fetch "%CACHE%\%PHP_ZIP%" "https://windows.php.net/downloads/releases/%PHP_ZIP%" "https://windows.php.net/downloads/releases/archives/%PHP_ZIP%" || goto :fail
call :sha "%CACHE%\%PHP_ZIP%" %PHP_SHA256% || goto :fail
call :fetch "%CACHE%\caddy_%CADDY_VER%_windows_amd64.zip" "https://github.com/caddyserver/caddy/releases/download/v%CADDY_VER%/caddy_%CADDY_VER%_windows_amd64.zip" || goto :fail
call :sha "%CACHE%\caddy_%CADDY_VER%_windows_amd64.zip" %CADDY_SHA256% || goto :fail
call :fetch "%CACHE%\WinSW-%WINSW_VER%.NET461.exe" "https://github.com/winsw/winsw/releases/download/v%WINSW_VER%/WinSW.NET461.exe" || goto :fail
call :sha "%CACHE%\WinSW-%WINSW_VER%.NET461.exe" %WINSW_SHA256% || goto :fail
call :fetch "%CACHE%\vc_redist.x64.exe" "https://aka.ms/vs/17/release/vc_redist.x64.exe" || goto :fail
powershell -NoProfile -Command "$s = Get-AuthenticodeSignature -LiteralPath '%CACHE%\vc_redist.x64.exe'; if ($s.Status -ne 'Valid' -or $s.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') { exit 1 }"
if errorlevel 1 ( del "%CACHE%\vc_redist.x64.exe" & echo [ERROR] vc_redist.x64.exe is not signed by Microsoft - deleted. Run build-client.bat again. & goto :fail )
call :fetch "%CACHE%\cacert.pem" "https://curl.se/ca/cacert.pem" || goto :fail
findstr /c:"BEGIN CERTIFICATE" "%CACHE%\cacert.pem" >nul || ( del "%CACHE%\cacert.pem" & echo [ERROR] cacert.pem is not a certificate list - deleted. Run build-client.bat again. & goto :fail )

rem ---- 7. installer stage ----------------------------------------------------------------------
echo [7/8] Putting the installer files together in build\setup-stage
if exist "%SSTAGE%" rmdir /s /q "%SSTAGE%"
if exist "%SSTAGE%" ( echo [ERROR] Could not clear %SSTAGE% - close any program using it. & goto :fail )
for %%D in (php caddy winsw installer probe) do mkdir "%SSTAGE%\%%D"
tar -xf "%OUT%\ROVEXY-OnPrem-%VER%.zip" -C "%SSTAGE%" || ( echo [ERROR] Could not unpack the package. & goto :fail )
move "%SSTAGE%\ROVEXY-OnPrem-%VER%\rovexy" "%SSTAGE%\rovexy" >nul || ( echo [ERROR] Unexpected package layout. & goto :fail )
copy /y "%SSTAGE%\ROVEXY-OnPrem-%VER%\activate-licence.bat" "%SSTAGE%\" >nul
copy /y "%SSTAGE%\ROVEXY-OnPrem-%VER%\INSTALL.md" "%SSTAGE%\" >nul
copy /y "%SSTAGE%\ROVEXY-OnPrem-%VER%\CLOUDFLARE-TUNNEL.md" "%SSTAGE%\" >nul
rmdir /s /q "%SSTAGE%\ROVEXY-OnPrem-%VER%"
tar -xf "%CACHE%\%PHP_ZIP%" -C "%SSTAGE%\php" || ( echo [ERROR] Could not unpack PHP. & goto :fail )
if not exist "%SSTAGE%\php\extras\ssl" mkdir "%SSTAGE%\php\extras\ssl"
copy /y "%CACHE%\cacert.pem" "%SSTAGE%\php\extras\ssl\cacert.pem" >nul
tar -xf "%CACHE%\caddy_%CADDY_VER%_windows_amd64.zip" -C "%SSTAGE%\caddy" caddy.exe || ( echo [ERROR] Could not unpack Caddy. & goto :fail )
copy /y "%CACHE%\WinSW-%WINSW_VER%.NET461.exe" "%SSTAGE%\winsw\WinSW.exe" >nul
copy /y "%CACHE%\vc_redist.x64.exe" "%SSTAGE%\" >nul
copy /y "%ROOT%\deploy\windows\configure.php" "%SSTAGE%\installer\" >nul
copy /y "%ROOT%\deploy\windows\php-pool.php" "%SSTAGE%\installer\" >nul
copy /y "%ROOT%\deploy\onprem\env.onprem" "%SSTAGE%\installer\" >nul
copy /y "%ROOT%\brand\rovexy\favicon.ico" "%SSTAGE%\rovexy.ico" >nul
rem the small PHP the wizard uses for its checks before anything is installed
copy /y "%ROOT%\deploy\windows\configure.php" "%SSTAGE%\probe\" >nul
for %%F in (php.exe php8.dll libcrypto-3-x64.dll libssl-3-x64.dll ext\php_pdo_mysql.dll ext\php_openssl.dll ext\php_mbstring.dll) do (
  if not exist "%SSTAGE%\php\%%F" ( echo [ERROR] %%F is missing from %PHP_ZIP%. & goto :fail )
  copy /y "%SSTAGE%\php\%%F" "%SSTAGE%\probe\" >nul
)
"%SSTAGE%\php\php.exe" -n -v >nul 2>&1 || echo [WARN] The bundled php.exe does not start on this PC ^(Visual C++ runtime missing?^). The installer installs that runtime on the client.

rem ---- 8. setup.exe ----------------------------------------------------------------------------
echo [8/8] Compiling ROVEXY-Setup-%VER%.exe (Inno Setup - takes a few minutes)
if exist "%OUT%\ROVEXY-Setup-%VER%.exe" del /q "%OUT%\ROVEXY-Setup-%VER%.exe"
"%ISCC%" /Q "/DAppVersion=%VER%" "/DStage=%SSTAGE%" "/O%OUT%" "%ROOT%\deploy\windows\rovexy-setup.iss" || ( echo [ERROR] Inno Setup could not compile the installer - see above. & goto :fail )
rem G2: code signing, so Windows SmartScreen shows "Ametecs India" instead of "Unknown publisher". Code-signing
rem certificates now live on a USB token or in Windows: set ROVEXY_SIGN_THUMBPRINT to the certificate's thumbprint
rem (certmgr.msc - Personal - Certificates - the certificate - Details - Thumbprint) before running this script.
if not defined ROVEXY_SIGN_THUMBPRINT goto :unsigned
set "SIGNTOOL="
for /f "delims=" %%S in ('where signtool.exe 2^>nul') do if not defined SIGNTOOL set "SIGNTOOL=%%S"
if not defined SIGNTOOL for /f "delims=" %%S in ('dir /b /s "%ProgramFiles(x86)%\Windows Kits\10\bin\signtool.exe" 2^>nul ^| findstr /i "\\x64\\"') do set "SIGNTOOL=%%S"
if not defined SIGNTOOL ( echo [ERROR] signtool.exe was not found. Install the Windows SDK ^(Signing Tools for Desktop Apps^). & goto :fail )
echo       signing ROVEXY-Setup-%VER%.exe
"%SIGNTOOL%" sign /sha1 %ROVEXY_SIGN_THUMBPRINT% /fd sha256 /tr http://timestamp.digicert.com /td sha256 /d "ROVEXY Setup" "%OUT%\ROVEXY-Setup-%VER%.exe" || ( echo [ERROR] Signing failed - is the USB token plugged in? & goto :fail )
goto :signed
:unsigned
echo [WARN] ROVEXY-Setup-%VER%.exe is NOT signed ^(ROVEXY_SIGN_THUMBPRINT is not set^). Windows will warn "Unknown publisher" on client servers.
:signed
php -r "file_put_contents($argv[1] . '.sha256', hash_file('sha256', $argv[1]) . '  ' . basename($argv[1]) . PHP_EOL);" -- "%OUT%\ROVEXY-Setup-%VER%.exe"
rmdir /s /q "%SSTAGE%" 2>nul

echo.
echo  ============================================================================
echo   Installer ready - the same file goes to every client:
echo     %OUT%\ROVEXY-Setup-%VER%.exe
echo     %OUT%\ROVEXY-Setup-%VER%.exe.sha256        (checksum)
echo   Also built: %OUT%\ROVEXY-OnPrem-%VER%.zip   (Linux / manual installs)
echo.
echo   Client: double-click ROVEXY-Setup-%VER%.exe on the server. Put their .lic file
echo   next to it to activate during setup; otherwise the last page shows the Server ID
echo   for Super Admin - Licences - Licence file... See INSTALL.md.
echo  ============================================================================
echo.
if /i not "%~1"=="/quiet" ( explorer /select,"%OUT%\ROVEXY-Setup-%VER%.exe" & pause )
exit /b 0

rem ---- helpers ---------------------------------------------------------------------------------
:fetch
rem :fetch <file> <url> [<second url>]  - downloads once; build\cache keeps it for the next build
if exist "%~1" exit /b 0
echo       downloading %~nx1
curl.exe -fsSL --retry 3 -o "%~1.part" "%~2" && ( move /y "%~1.part" "%~1" >nul & exit /b 0 )
if not "%~3"=="" curl.exe -fsSL --retry 3 -o "%~1.part" "%~3" && ( move /y "%~1.part" "%~1" >nul & exit /b 0 )
del "%~1.part" 2>nul
echo [ERROR] Could not download %~2
echo         Check the internet connection, or download it by hand and save it as %~1
exit /b 1

:sha
rem :sha <file> <expected sha256>
php -r "exit(hash_file('sha256', $argv[1]) === strtolower($argv[2]) ? 0 : 1);" -- "%~1" %2 && exit /b 0
del "%~1"
echo [ERROR] %~nx1 does not have the expected SHA-256 checksum, so it was deleted. Run build-client.bat again;
echo         if it repeats, the download source changed - check the version and SHA-256 at the top of this file.
exit /b 1

:fail
echo.
echo  Build stopped. Nothing was sent anywhere; fix the error above and run build-client.bat again.
if /i not "%~1"=="/quiet" pause
exit /b 1
