package com.agendauno.app

import android.appwidget.AppWidgetManager
import android.content.Context
import android.content.SharedPreferences
import android.view.View
import android.widget.RemoteViews
import es.antonborri.home_widget.HomeWidgetLaunchIntent
import es.antonborri.home_widget.HomeWidgetProvider
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject

/**
 * El widget de la pantalla de inicio del cliente (lib/features/widget_inicio): su
 * próxima reserva o cita (qué, cuándo y dónde) y su saldo o plan. La app guarda los
 * textos con home_widget; aquí solo se dibujan. De las próximas que guardó muestra la
 * primera que aún no termina: aunque la app no se abra, no se queda con una que ya
 * pasó (el sistema lo vuelve a dibujar cada 30 minutos). Al tocarlo, abre la app.
 *
 * Las claves son las de `ClavesWidget` (lib/features/widget_inicio/data/datos_widget.dart).
 */
class WidgetProximo : HomeWidgetProvider() {
    override fun onUpdate(
        context: Context,
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
        widgetData: SharedPreferences,
    ) {
        for (id in appWidgetIds) {
            appWidgetManager.updateAppWidget(
                id,
                dibujar(context, widgetData, System.currentTimeMillis()),
            )
        }
    }

    private fun dibujar(context: Context, datos: SharedPreferences, ahora: Long): RemoteViews {
        val vista = RemoteViews(context.packageName, R.layout.widget_proximo)
        vista.setOnClickPendingIntent(
            R.id.widget_raiz,
            HomeWidgetLaunchIntent.getActivity(context, MainActivity::class.java),
        )

        val negocio = datos.getString(NEGOCIO, null)
        poner(vista, R.id.widget_negocio, negocio)
        if (negocio == null) {
            // Sin sesión de cliente: solo invita a abrir la app.
            vista.setTextViewText(R.id.widget_etiqueta, context.getString(R.string.widget_proximo_etiqueta))
            vista.setTextViewText(R.id.widget_titulo, context.getString(R.string.widget_proximo_sin_sesion))
            poner(vista, R.id.widget_cuando, null)
            vista.setViewVisibility(R.id.widget_aviso, View.GONE)
            poner(vista, R.id.widget_plan, null)
            return vista
        }

        val proxima = siguiente(datos.getString(PROXIMAS, null), ahora)
        if (proxima != null) {
            vista.setTextViewText(R.id.widget_etiqueta, proxima.optString("etiqueta"))
            vista.setTextViewText(R.id.widget_titulo, proxima.optString("titulo"))
            poner(
                vista,
                R.id.widget_cuando,
                listOfNotNull(texto(proxima, "cuando"), texto(proxima, "donde")).joinToString(" · "),
            )
            val aviso = texto(proxima, "aviso")
            vista.setViewVisibility(R.id.widget_aviso, if (aviso == null) View.GONE else View.VISIBLE)
            vista.setTextViewText(R.id.widget_aviso_texto, aviso ?: "")
        } else {
            vista.setTextViewText(R.id.widget_etiqueta, context.getString(R.string.widget_proximo_etiqueta))
            vista.setTextViewText(
                R.id.widget_titulo,
                datos.getString(VACIO, null) ?: context.getString(R.string.widget_proximo_vacio),
            )
            poner(vista, R.id.widget_cuando, datos.getString(ACCION, null))
            vista.setViewVisibility(R.id.widget_aviso, View.GONE)
        }

        val plan = datos.getString(PLAN, null)
        val planTitulo = datos.getString(PLAN_TITULO, null)
        poner(
            vista,
            R.id.widget_plan,
            plan?.let { if (planTitulo == null) it else "$planTitulo · $it" },
        )
        return vista
    }

    /** La primera que aún no termina (vienen de la más cercana a la más lejana). */
    private fun siguiente(json: String?, ahora: Long): JSONObject? {
        if (json.isNullOrBlank()) {
            return null
        }
        return try {
            val lista = JSONArray(json)
            (0 until lista.length())
                .mapNotNull { lista.optJSONObject(it) }
                .firstOrNull { it.optLong("termina", 0L) > ahora }
        } catch (e: JSONException) {
            null
        }
    }

    /** Un texto del JSON, o null si no viene (o viene vacío). */
    private fun texto(objeto: JSONObject, clave: String): String? =
        if (objeto.isNull(clave)) null else objeto.optString(clave).ifBlank { null }

    /** Pone el texto o, sin texto, oculta la vista. */
    private fun poner(vista: RemoteViews, id: Int, valor: String?) {
        if (valor.isNullOrBlank()) {
            vista.setViewVisibility(id, View.GONE)
        } else {
            vista.setViewVisibility(id, View.VISIBLE)
            vista.setTextViewText(id, valor)
        }
    }

    private companion object {
        const val NEGOCIO = "au_negocio"
        const val PROXIMAS = "au_proximas"
        const val PLAN_TITULO = "au_plan_titulo"
        const val PLAN = "au_plan"
        const val VACIO = "au_vacio"
        const val ACCION = "au_accion"
    }
}
