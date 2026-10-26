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

// Un --dart-define (sin espacios); vacío si no viene o viene vacío en el archivo.
fun definido(clave: String): String = dartDefines[clave].orEmpty().trim()

// La marca blanca (ADR 0111 y 0208): su negocio (el slug), su id, su nombre y, si lo
// trae, el color de su ícono. Sus íconos van en
// configuraciones/marca_blanca/<negocio>/android/res (docs/MOBILE.md).
val negocioMarcaBlanca = definido("NEGOCIO")
val negocioValido = Regex("^[a-z0-9-]+$").matches(negocioMarcaBlanca)
val idMarcaBlanca = definido("ANDROID_ID")
val nombreMarcaBlanca = definido("APP_NOMBRE")
val colorMarca = definido("COLOR_MARCA")
val recursosMarca = file("../../configuraciones/marca_blanca/$negocioMarcaBlanca/android/res")
// COLOR_MARCA se vuelve el fondo del ícono adaptativo: un recurso generado que, como
// los de la marca, reemplaza al de AgendaUno (src/main).
val recursosColorMarca = layout.buildDirectory.dir("generated/marca_blanca/res").get().asFile
val conColorMarca = negocioValido && Regex("^#[0-9a-fA-F]{6}$").matches(colorMarca)

// Un applicationId de Android: segmentos separados por puntos (al menos dos), cada uno
// empieza con letra y sigue con letras, números o «_» (como lo exige Google Play).
val formatoIdAndroid = Regex("^[a-zA-Z][a-zA-Z0-9_]*(\\.[a-zA-Z][a-zA-Z0-9_]*)+$")

// El esquema propio de cada app para volver a ella desde el navegador (el regreso del
// pago: `<esquema>://pagos?ref=…`): `agendauno`, `turnouno` y, en la marca blanca, su
// ANDROID_ID en minúsculas con «-» en lugar de «_» (lo mismo calcula la app en
// AppConfig.esquemaApp). La API solo acepta `^[a-z][a-z0-9.+-]{2,40}$`.
val esquemaMarcaBlanca = idMarcaBlanca.lowercase().replace('_', '-')
val formatoEsquema = Regex("^[a-z][a-z0-9.+-]{2,40}$")

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
            applicationId = idMarcaBlanca.ifEmpty { "com.agendauno.app" }
            manifestPlaceholders["appNombre"] = nombreMarcaBlanca.ifEmpty { "AgendaUno" }
            manifestPlaceholders["esquemaApp"] = esquemaMarcaBlanca.ifEmpty { "agendauno" }
        }
        create("turnouno") {
            dimension = "producto"
            applicationId = "com.turnouno.app"
            manifestPlaceholders["appNombre"] = "TurnoUno"
            manifestPlaceholders["esquemaApp"] = "turnouno"
        }
    }

    // Los íconos y colores de una marca blanca reemplazan a los de AgendaUno:
    // configuraciones/marca_blanca/<negocio>/android/res (con un NEGOCIO que no es un
    // slug no se toma ninguna carpeta: la compilación se detiene abajo).
    if (negocioValido) {
        sourceSets.getByName("agendauno").res.srcDir(recursosMarca)
    }
    if (conColorMarca) {
        File(recursosColorMarca, "values").mkdirs()
        File(recursosColorMarca, "values/color_marca.xml").writeText(
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n" +
                "<!-- Generado de COLOR_MARCA (configuraciones/marca_blanca/" +
                "$negocioMarcaBlanca.json). -->\n" +
                "<resources>\n" +
                "    <color name=\"ic_launcher_background\">$colorMarca</color>\n" +
                "</resources>\n",
        )
        sourceSets.getByName("agendauno").res.srcDir(recursosColorMarca)
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

// La marca blanca es solo de AgendaUno, nunca con el id de una app oficial y siempre
// con lo suyo completo: se detiene antes de compilar una app que no se podría (o no se
// debe) publicar, y dice qué falta.
gradle.taskGraph.whenReady {
    val datosDeMarca =
        listOf("ANDROID_ID", "APP_NOMBRE", "COLOR_MARCA").filter { definido(it).isNotEmpty() }
    if (negocioMarcaBlanca.isEmpty()) {
        if (datosDeMarca.isNotEmpty()) {
            throw GradleException(
                "${datosDeMarca.joinToString()} sin NEGOCIO: una app de marca blanca se " +
                    "compila con su archivo completo (--dart-define-from-file=" +
                    "configuraciones/marca_blanca/<negocio>.json, docs/MOBILE.md).",
            )
        }
        return@whenReady
    }
    fun detener(motivo: String): Nothing =
        throw GradleException(
            "Marca blanca \"$negocioMarcaBlanca\": $motivo (docs/MOBILE.md, «Marca blanca»).",
        )

    if (!negocioValido) {
        detener("NEGOCIO debe ser el slug del negocio: minúsculas, números y guiones")
    }
    // Lo que se pidió compilar (assembleTurnounoRelease…), no todo el grafo.
    if (gradle.startParameter.taskNames.any { it.contains("Turnouno", ignoreCase = true) }) {
        detener("la marca blanca es solo de AgendaUno (ADR 0111): compílala con --flavor agendauno")
    }
    val producto = definido("PRODUCTO")
    if (producto.isNotEmpty() && producto != "agendauno") {
        detener("PRODUCTO=$producto: la marca blanca es solo de AgendaUno; quita PRODUCTO de su archivo")
    }
    if (idMarcaBlanca.isEmpty() ||
        idMarcaBlanca == "com.agendauno.app" ||
        idMarcaBlanca == "com.turnouno.app"
    ) {
        detener("necesita su propio ANDROID_ID en su archivo de configuración (configuraciones/marca_blanca/)")
    }
    if (!formatoIdAndroid.matches(idMarcaBlanca)) {
        detener(
            "ANDROID_ID=$idMarcaBlanca no es un id de Android: segmentos separados por " +
                "puntos (al menos dos, p. ej. mx.estudioluna.app), cada uno empieza con " +
                "letra y sigue con letras, números o «_»",
        )
    }
    if (!formatoEsquema.matches(esquemaMarcaBlanca)) {
        detener(
            "ANDROID_ID=$idMarcaBlanca es muy largo para el esquema de la app " +
                "($esquemaMarcaBlanca: máximo 41 caracteres)",
        )
    }
    if (nombreMarcaBlanca.isEmpty()) {
        detener("falta APP_NOMBRE: su nombre en el teléfono")
    }
    if (colorMarca.isNotEmpty() && !conColorMarca) {
        detener("COLOR_MARCA=$colorMarca no es un color #RRGGBB")
    }
    val colores = File(recursosMarca, "values/colors.xml")
    val fondoEnColores =
        colores.isFile && colores.readText().contains("name=\"ic_launcher_background\"")
    if (conColorMarca && fondoEnColores) {
        detener(
            "el fondo del ícono viene dos veces (COLOR_MARCA y ic_launcher_background en " +
                "values/colors.xml): deja uno",
        )
    }

    // La versión de release: con sus íconos completos (no los de AgendaUno) y su app
    // en Firebase (sin ella, no le llegarían las notificaciones).
    if (allTasks.none { it.name.contains("Release") }) {
        return@whenReady
    }
    fun existe(carpeta: String, nombre: String) =
        listOf("png", "webp").any { File(recursosMarca, "$carpeta/$nombre.$it").isFile }
    val faltan = buildList {
        for (densidad in listOf("mdpi", "hdpi", "xhdpi", "xxhdpi", "xxxhdpi")) {
            for (icono in listOf("ic_launcher", "ic_launcher_foreground", "ic_launcher_monochrome")) {
                if (!existe("mipmap-$densidad", icono)) add("mipmap-$densidad/$icono.png")
            }
            if (!existe("drawable-$densidad", "ic_notificacion")) {
                add("drawable-$densidad/ic_notificacion.png")
            }
        }
        if (!fondoEnColores && !conColorMarca) {
            add("values/colors.xml con el color ic_launcher_background (o COLOR_MARCA en su archivo)")
        }
    }
    if (faltan.isNotEmpty()) {
        detener(
            "faltan sus recursos en configuraciones/marca_blanca/$negocioMarcaBlanca/" +
                "android/res: ${faltan.joinToString()}",
        )
    }
    val firebase = buildList {
        if (definido("FIREBASE_ANDROID_APP_ID").isEmpty() && definido("FIREBASE_APP_ID").isEmpty()) {
            add("FIREBASE_ANDROID_APP_ID")
        }
        if (definido("FIREBASE_ANDROID_API_KEY").isEmpty() && definido("FIREBASE_API_KEY").isEmpty()) {
            add("FIREBASE_API_KEY")
        }
        if (definido("FIREBASE_SENDER_ID").isEmpty()) add("FIREBASE_SENDER_ID")
        if (definido("FIREBASE_PROJECT_ID").isEmpty()) add("FIREBASE_PROJECT_ID")
    }
    if (firebase.isNotEmpty()) {
        detener(
            "faltan los datos de su app en Firebase (${firebase.joinToString()}): sin " +
                "ellos no recibiría notificaciones",
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
