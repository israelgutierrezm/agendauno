package com.agendauno.app

import android.app.Activity
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.OpenableColumns
import androidx.core.content.FileProvider
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File

class MainActivity : FlutterActivity() {
    /** La respuesta pendiente de «elegir un PDF» (una a la vez). */
    private var pdfPendiente: MethodChannel.Result? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        crearCanalDeAvisos()
    }

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        // Canal `agendauno/archivos` (lib/core/archivos/archivos_nativos.dart): abrir con
        // el visor del teléfono un documento que la app bajó con su sesión (un PDF) y
        // elegir un PDF del teléfono para subirlo. Apple Wallet es solo del iPhone.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "agendauno/archivos")
            .setMethodCallHandler { llamada, resultado ->
                when (llamada.method) {
                    "abrir" -> resultado.success(
                        abrir(
                            llamada.argument<ByteArray>("bytes"),
                            llamada.argument<String>("nombre"),
                            llamada.argument<String>("mime"),
                        ),
                    )
                    "elegirPdf" -> elegirPdf(resultado)
                    else -> resultado.notImplemented()
                }
            }
    }

    /**
     * El canal propio de las notificaciones (ADR 0025): con él Android las muestra con
     * su nombre en Ajustes y con prioridad alta; FCM lo usa por el `meta-data`
     * `default_notification_channel_id` del manifiesto. Crearlo otra vez no cambia
     * nada (Android conserva lo que eligió la persona).
     */
    private fun crearCanalDeAvisos() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return
        }
        val canal = NotificationChannel(
            getString(R.string.canal_avisos_id),
            getString(R.string.canal_avisos_nombre),
            NotificationManager.IMPORTANCE_HIGH,
        ).apply {
            description = getString(R.string.canal_avisos_descripcion)
        }
        getSystemService(NotificationManager::class.java)?.createNotificationChannel(canal)
    }

    /**
     * Lo guarda en la caché de la app y lo abre con la app que el teléfono tenga para
     * ese tipo, con permiso de solo lectura (ArchivosProvider). false si no hay con qué.
     */
    private fun abrir(bytes: ByteArray?, nombre: String?, mime: String?): Boolean {
        if (bytes == null) {
            return false
        }
        val carpeta = File(cacheDir, "compartidos").apply { mkdirs() }
        // Solo el nombre (sin rutas): nunca se escribe fuera de la carpeta.
        val limpio = File(nombre ?: "").name.ifBlank { "documento" }
        val archivo = File(carpeta, limpio)
        archivo.writeBytes(bytes)
        val uri = FileProvider.getUriForFile(this, "$packageName.archivos", archivo)
        val intento = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, mime ?: contentResolver.getType(uri) ?: "application/octet-stream")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        return try {
            startActivity(intento)
            true
        } catch (e: ActivityNotFoundException) {
            false
        }
    }

    /**
     * El selector de documentos del sistema, solo PDF (no pide permisos: el sistema
     * da acceso solo al que se elige). Responde `{bytes, nombre}` o null si se canceló;
     * uno de más de [MAXIMO_PDF] bytes no se lee (`{demasiado: true}`).
     */
    private fun elegirPdf(resultado: MethodChannel.Result) {
        if (pdfPendiente != null) {
            resultado.error("ocupado", "Ya hay un PDF por elegir.", null)
            return
        }
        val intento = Intent(Intent.ACTION_OPEN_DOCUMENT).apply {
            addCategory(Intent.CATEGORY_OPENABLE)
            type = "application/pdf"
        }
        try {
            pdfPendiente = resultado
            @Suppress("DEPRECATION")
            startActivityForResult(intento, CODIGO_PDF)
        } catch (e: ActivityNotFoundException) {
            pdfPendiente = null
            resultado.success(null)
        }
    }

    @Deprecated("FlutterActivity aún entrega aquí el resultado del selector")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        @Suppress("DEPRECATION")
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode != CODIGO_PDF) {
            return
        }
        val resultado = pdfPendiente ?: return
        pdfPendiente = null
        val uri = data?.data
        if (resultCode != Activity.RESULT_OK || uri == null) {
            resultado.success(null)
            return
        }
        try {
            val nombre = nombreDe(uri) ?: "documento.pdf"
            val bytes = contentResolver.openInputStream(uri)?.use { entrada ->
                entrada.readNBytesHasta(MAXIMO_PDF + 1)
            }
            when {
                bytes == null -> resultado.success(null)
                bytes.size > MAXIMO_PDF -> resultado.success(mapOf("demasiado" to true))
                else -> resultado.success(mapOf("bytes" to bytes, "nombre" to nombre))
            }
        } catch (e: Exception) {
            resultado.error("lectura", "No se pudo leer el PDF.", null)
        }
    }

    /** El nombre con que el sistema muestra el documento elegido. */
    private fun nombreDe(uri: Uri): String? =
        contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)
            ?.use { cursor ->
                if (cursor.moveToFirst()) cursor.getString(0) else null
            }

    /** Lee hasta [limite] bytes (sin cargar en memoria uno enorme). */
    private fun java.io.InputStream.readNBytesHasta(limite: Int): ByteArray {
        val salida = java.io.ByteArrayOutputStream()
        val bloque = ByteArray(16 * 1024)
        while (salida.size() < limite) {
            val leidos = read(bloque, 0, minOf(bloque.size, limite - salida.size()))
            if (leidos < 0) {
                break
            }
            salida.write(bloque, 0, leidos)
        }
        return salida.toByteArray()
    }

    private companion object {
        const val CODIGO_PDF = 4207

        /** El tope del servidor para un documento (8 MB). */
        const val MAXIMO_PDF = 8 * 1024 * 1024
    }
}
