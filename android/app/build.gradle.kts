plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
}
// Firebase push turns on automatically when android/app/google-services.json is present (downloaded from the
// Firebase console). Without it the app still builds and falls back to polling.
if (file("google-services.json").exists()) apply(plugin = "com.google.gms.google-services")

android {
    namespace = "ametecs.managedmobile"
    compileSdk = 36

    defaultConfig {
        applicationId = "in.ametecs.managedmobile"
        minSdk = 28          // Android 9+: required for reliable Keystore EC keys and managed-device APIs used here
        targetSdk = 36
        versionCode = 4        // +1 on every Play upload
        versionName = "1.0.3"
        // Default cloud server; managed configuration from Android Management overrides it, on-prem users type theirs.
        buildConfigField("String", "DEFAULT_SERVER_URL", "\"https://rovexy.app\"")
        buildConfigField("String", "PRODUCT_NAME", "\"ROVEXY\"")
    }
    buildFeatures { compose = true; buildConfig = true }
    // play  = Google Play (.aab): no call log / background location (Play policy); talk time comes from the recording file.
    // direct = on-prem / sideloaded APK: call log for talk time, as before.
    flavorDimensions += "dist"
    productFlavors {
        create("play") { dimension = "dist"; buildConfigField("Boolean", "PLAY", "true") }
        create("direct") { dimension = "dist"; buildConfigField("Boolean", "PLAY", "false") }
    }
    // Upload key: passwords live in %USERPROFILE%\.gradle\gradle.properties (ROVEXY_STORE_FILE, ROVEXY_STORE_PASSWORD,
    // ROVEXY_KEY_ALIAS, ROVEXY_KEY_PASSWORD) — never in this file. Without them the release build is simply unsigned.
    val keyFile = providers.gradleProperty("ROVEXY_STORE_FILE").orNull
    signingConfigs {
        if (keyFile != null) create("upload") {
            storeFile = file(keyFile)
            storePassword = providers.gradleProperty("ROVEXY_STORE_PASSWORD").get()
            keyAlias = providers.gradleProperty("ROVEXY_KEY_ALIAS").get()
            keyPassword = providers.gradleProperty("ROVEXY_KEY_PASSWORD").get()
        }
    }
    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            buildConfigField("Boolean", "ALLOW_LOCAL_HTTP", "false")
            signingConfigs.findByName("upload")?.let { signingConfig = it }
        }
        debug {
            buildConfigField("Boolean", "ALLOW_LOCAL_HTTP", "true")   // emulator → http://10.0.2.2 only
        }
    }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
}
kotlin { compilerOptions { jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17) } }  // ponytail: no toolchain download

dependencies {
    implementation(project(":core"))
    implementation(platform(libs.compose.bom))
    implementation(libs.compose.ui)
    implementation(libs.compose.material3)
    implementation(libs.compose.tooling.preview)
    implementation(libs.activity.compose)
    implementation("androidx.fragment:fragment:1.8.6")   // lint InvalidFragmentVersionForActivityResult: old transitive fragment
    implementation(libs.lifecycle.runtime)
    implementation(libs.lifecycle.viewmodel)
    implementation(libs.lifecycle.process)
    implementation(libs.navigation.compose)
    implementation(libs.okhttp)
    implementation(libs.serialization.json)
    implementation(libs.work.runtime)
    implementation(libs.play.location)
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)
    implementation(libs.webrtc)
    testImplementation(libs.junit)
}
