# Building the apps

**No APK or Windows installer was produced in this session.** The build environment could not reach Google Maven, Gradle, Maven Central or GitHub. Use the steps below on a developer PC, or after network access has been allowed.

## Android APK

Prerequisites:

- Android Studio (latest stable) or the command-line tools
- Android SDK Platform 36 and Build-Tools
- JDK 17. The settings file includes the Foojay toolchain resolver, so Gradle can fetch it.
- Network access to `dl.google.com`, `maven.google.com`, `repo.maven.apache.org`, `services.gradle.org` and `plugins.gradle.org`

```bash
cd android
# 1. Update gradle/libs.versions.toml to current stable versions (they were chosen offline).
./gradlew :core:test                 # pure-Kotlin logic tests
./gradlew :app:assembleDebug         # → app/build/outputs/apk/debug/app-debug.apk
```

### Release signing

The keystore is never committed.

```bash
keytool -genkeypair -v -keystore ammp-release.keystore -alias ammp -keyalg RSA -keysize 4096 -validity 9125
```

Next, add a `signingConfigs { release { … } }` block that reads from `keystore.properties`, which is git-ignored. Then:

```bash
./gradlew :app:assembleRelease
```

Upload the signed APK as a **private app** in managed Google Play for each enterprise (see ENROLMENT.md).

### FCM (optional)

1. Add `id("com.google.gms.google-services")` to the app plugins.
2. Place the deployment's `google-services.json` in `app/`.
3. Set `PUSH_DRIVER=fcm` with `FCM_PROJECT_ID` and the service account on the server.

Without FCM, the app still works through WebSocket events and the 15-minute periodic sync.

### Quick logic check without the Android SDK

This is what was run here:

```bash
cd android/core
kotlinc src/main/kotlin src/test/kotlin -cp junit4.jar:hamcrest-core.jar -d build/core-test.jar
java -cp build/core-test.jar:junit4.jar:hamcrest-core.jar:kotlin-stdlib.jar org.junit.runner.JUnitCore ametecs.managedmobile.core.CoreTest
../../tools/interop-check.sh          # Kotlin-signed request verified by the PHP server code
```

## Windows desktop installer

Prerequisites: Windows 10/11 x64, Node 22, and network access to GitHub (Electron and NSIS binaries).

```powershell
cd desktop
npm ci
npm test                 # security-policy tests
npm run dist:win         # → desktop\release\Ametecs Managed Mobile Admin Setup <version>.exe
```

- **Code signing:** set `CSC_LINK` (a .pfx path or base64) and `CSC_KEY_PASSWORD` before `dist:win`. Without them, the installer is unsigned and SmartScreen will warn.
- **First run:** the app asks for the company server URL. It must be `https://`. It is validated against `<url>/up`, then the console at `<url>/admin` loads.
- **Change server later:** File → Change server.
- **Export folder:** File → Choose export folder sets where recordings and CSV downloads are saved.
- **Development:** `AMMP_DESKTOP_DEV=1 npm start` allows `http://localhost` and enables DevTools.

## Admin console (web build served by the server)

```bash
cd admin && npm ci && npm test && npm run build    # outputs to backend/public/admin
```
