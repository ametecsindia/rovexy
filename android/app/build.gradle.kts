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
        versionCode = 1
        versionName = "0.1.0"
        // Optional white-label default server (managed configuration from Android Management overrides it).
        buildConfigField("String", "DEFAULT_SERVER_URL", "\"\"")
        buildConfigField("String", "PRODUCT_NAME", "\"Ametecs Managed Mobile\"")
    }
    buildFeatures { compose = true; buildConfig = true }
    buildTypes {
        release {
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            buildConfigField("Boolean", "ALLOW_LOCAL_HTTP", "false")
            // Signing: configure via keystore.properties (never committed). See docs/BUILD.md.
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

// ponytail: dev-only mirror of the Kotlin sources to "Smart caller/_src" so the remote assistant can read them
// (its file bridge can't reach paths this deep). Harmless; delete this block whenever.
copy { from("src/main/kotlin/ametecs/managedmobile"); into(rootProject.file("../_src")) }
