import java.io.FileInputStream
import java.util.Base64
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

// Los --dart-define de la compilación (Flutter los pasa en base64, separados por
// comas; también los de --dart-define-from-file). Una app de marca blanca (ADR 0111)
// toma de su archivo de configuración su id (`ANDROID_ID`) y su nombre (`APP_NOMBRE`).
val dartDefines: Map<String, String> =
    (project.findProperty("dart-defines") as String?)
        ?.split(",")
        ?.mapNotNull { codificado: String ->
            runCatching { String(Base64.getDecoder().decode(codificado)) }.getOrNull()
        }
        ?.mapNotNull { par: String ->
            par.split("=", limit = 2).takeIf { it.size == 2 }?.let { it[0] to it[1] }
        }
        ?.toMap()
        ?: emptyMap()
val negocioMarcaBlanca = dartDefines["NEGOCIO"].orEmpty()

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
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    // Las dos apps oficiales desde el mismo código (ADR 0111): cada sabor es otra app
    // en las tiendas (su id, su nombre y su ícono; los recursos de src/turnouno
    // reemplazan a los de src/main). `flutter build appbundle --flavor turnouno`.
    flavorDimensions += "producto"
    productFlavors {
        create("agendauno") {
            dimension = "producto"
            // Una app de marca blanca de AgendaUno lleva su propio id y su nombre.
            applicationId = dartDefines["ANDROID_ID"] ?: "com.agendauno.app"
            manifestPlaceholders["appNombre"] = dartDefines["APP_NOMBRE"] ?: "AgendaUno"
        }
        create("turnouno") {
            dimension = "producto"
            applicationId = "com.turnouno.app"
            manifestPlaceholders["appNombre"] = "TurnoUno"
        }
    }

    // Los íconos y colores de una marca blanca (si los tiene) reemplazan a los de
    // AgendaUno: configuraciones/marca_blanca/<negocio>/android/res.
    if (negocioMarcaBlanca.isNotEmpty()) {
        sourceSets.getByName("agendauno").res.srcDir(
            "../../configuraciones/marca_blanca/$negocioMarcaBlanca/android/res",
        )
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

// La marca blanca es solo de AgendaUno y nunca con el id de una app oficial: se
// detiene antes de compilar una app que no se podría (o no se debe) publicar.
gradle.taskGraph.whenReady {
    if (negocioMarcaBlanca.isEmpty()) {
        return@whenReady
    }
    // Lo que se pidió compilar (assembleTurnounoRelease…), no todo el grafo.
    if (gradle.startParameter.taskNames.any { it.contains("Turnouno", ignoreCase = true) }) {
        throw GradleException(
            "La marca blanca es solo de AgendaUno (ADR 0111): compílala con " +
                "--flavor agendauno.",
        )
    }
    val id = dartDefines["ANDROID_ID"].orEmpty()
    if (id.isEmpty() || id == "com.agendauno.app" || id == "com.turnouno.app") {
        throw GradleException(
            "Una app de marca blanca necesita su propio ANDROID_ID en su archivo de " +
                "configuración (configuraciones/marca_blanca/, docs/MOBILE.md).",
        )
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
