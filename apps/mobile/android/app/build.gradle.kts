import java.io.FileInputStream
import java.util.Properties

// Firma de release: android/key.properties (no se sube al repositorio; ver
// android/key.properties.example y docs/MOBILE.md). Sin él, la versión de release NO
// se compila (antes se firmaba en silencio con la llave de depuración, que Google Play
// rechaza); debug, `flutter test` y `flutter analyze` no lo necesitan.
val archivoFirma = rootProject.file("key.properties")
val firma = Properties().apply {
    if (archivoFirma.exists()) {
        FileInputStream(archivoFirma).use { load(it) }
    }
}

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.agendauno.app"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.agendauno.app"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (archivoFirma.exists()) {
            create("release") {
                keyAlias = firma.getProperty("keyAlias")
                keyPassword = firma.getProperty("keyPassword")
                storeFile = file(firma.getProperty("storeFile"))
                storePassword = firma.getProperty("storePassword")
            }
        }
    }

    buildTypes {
        release {
            if (archivoFirma.exists()) {
                signingConfig = signingConfigs.getByName("release")
            }
        }
    }
}

// Solo al compilar la versión de release (assembleRelease, bundleRelease…): sin la
// llave de subida se detiene con un mensaje claro en lugar de firmar con la de
// depuración.
gradle.taskGraph.whenReady {
    if (!archivoFirma.exists() && allTasks.any { it.name.contains("Release") }) {
        throw GradleException(
            "Falta android/key.properties: la versión de release no se firma con la " +
                "llave de depuración. Cópialo de android/key.properties.example con los " +
                "datos de la llave de subida de Google Play (docs/MOBILE.md, «Firma de " +
                "release»).",
        )
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
