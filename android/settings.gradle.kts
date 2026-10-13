pluginManagement {
    repositories { google(); mavenCentral(); gradlePluginPortal() }
}
plugins {
    // Lets Gradle provision the JDK 17 toolchain on machines that only have a newer JDK (e.g. Android Studio's JBR 21).
    id("org.gradle.toolchains.foojay-resolver-convention") version "1.0.0"
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories { google(); mavenCentral() }
}
rootProject.name = "AmetecsManagedMobile"
include(":core", ":app")
