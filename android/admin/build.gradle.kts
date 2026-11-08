// ROVEXY Admin: the company admin console (the same React console as /admin in the browser) in a phone app.
// Every console feature appears here automatically after a server deploy; only this thin shell is built in Android Studio.
plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
}

android {
    namespace = "ametecs.rovexy.admin"
    compileSdk = 36

    defaultConfig {
        applicationId = "in.ametecs.rovexy.admin"
        minSdk = 29          // Android 10+: saves files to Downloads without a storage permission
        targetSdk = 36
        versionCode = 4        // +1 on every Play upload
        versionName = "1.0.3"
        buildConfigField("String", "DEFAULT_SERVER_URL", "\"https://rovexy.app\"")
    }
    buildFeatures { buildConfig = true }
    // Same upload key as the caller app (see app/build.gradle.kts); without it the release build is simply unsigned.
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
            buildConfigField("Boolean", "ALLOW_LOCAL_HTTP", "true")   // PC on the same Wi-Fi running `php artisan serve`
        }
    }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
}
kotlin { compilerOptions { jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17) } }

dependencies {
    implementation(project(":core"))     // ServerUrl: same address rules as the caller app
    implementation(libs.activity)
}
