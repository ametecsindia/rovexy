// Pure Kotlin/JVM logic shared by the app; unit-tested without an Android device.
plugins { alias(libs.plugins.kotlin.jvm) }
// ponytail: target 17 on whatever JDK runs Gradle (Studio's JBR) — no toolchain download (foojay unreachable).
java { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
kotlin { compilerOptions { jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17) } }
dependencies { testImplementation(libs.junit) }
