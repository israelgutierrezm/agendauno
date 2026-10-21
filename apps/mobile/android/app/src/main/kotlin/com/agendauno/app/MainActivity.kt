package com.agendauno.app

import android.content.ActivityNotFoundException
import android.content.Intent
import androidx.core.content.FileProvider
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel
import java.io.File

class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        // Canal `agendauno/archivos` (lib/core/archivos/archivos_nativos.dart): abrir con
        // el visor del teléfono un documento que la app bajó con su sesión (un PDF).
        // Apple Wallet es solo del iPhone.
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
                    else -> resultado.notImplemented()
                }
            }
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
}
