import { computed, type ComputedRef } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter, type RouteLocationRaw } from "vue-router";

import { ubicacion, type Vista } from "@/lib/menu";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * A dónde lleva «Volver» y con qué texto. Toda pantalla que tenga un «Volver» (fichas,
 * importadores) lo pinta igual: `<RouterLink :to="regreso.destino.value"
 * class="tu-volver"><IconoNav nombre="atras" :tam="16" />{{ regreso.etiqueta.value }}`
 * (como FichaInstructorView), sin fijar el destino a mano.
 */
export interface Regreso {
  destino: ComputedRef<RouteLocationRaw>;
  etiqueta: ComputedRef<string>;
}

/**
 * «Volver» de una ficha: regresa a la pantalla del panel de donde se llegó (la
 * bandeja de respuestas, Recepción, Expedientes…) para no perder ese contexto. Si se
 * entró directo (un enlace, sin historial) o se viene de la lista de siempre, vuelve
 * a esa lista con su texto de siempre.
 */
export function useRegreso(lista: {
  name: string;
  etiqueta: () => string;
}): Regreso {
  const route = useRoute();
  const router = useRouter();
  const { t } = useI18n();
  const sesion = useSesionTenantStore();

  const origen = computed<{ ruta: string; vista: Vista } | null>(() => {
    // El historial no es reactivo: se recalcula al cambiar de ruta.
    void route.fullPath;
    const atras = (router.options.history.state as { back?: unknown } | null)
      ?.back;
    if (typeof atras !== "string") {
      return null;
    }
    const r = router.resolve(atras);
    if (
      r.name === undefined ||
      r.name === route.name ||
      r.name === lista.name
    ) {
      return null;
    }
    // Solo pantallas del menú (no fichas ni importaciones, que son de paso).
    const u = ubicacion(r);
    if (u === null || u.vista.ruta !== r.name) {
      return null;
    }
    return { ruta: r.fullPath, vista: u.vista };
  });

  const destino = computed<RouteLocationRaw>(() =>
    origen.value !== null ? origen.value.ruta : { name: lista.name },
  );
  const etiqueta = computed(() => {
    if (origen.value === null) {
      return lista.etiqueta();
    }
    const x = origen.value.vista;
    // Quien trabaja solo la ve con su `etiquetaSolo` («Tu agenda y sucursales»).
    const etiqueta =
      x.etiquetaSolo !== undefined && sesion.conEquipo === false
        ? x.etiquetaSolo
        : x.etiqueta;
    const nombre =
      x.termino !== undefined
        ? plural(sesion.terminologia[x.termino])
        : t(etiqueta);
    return t("comun.volverA", {
      destino: nombre.charAt(0).toLowerCase() + nombre.slice(1),
    });
  });

  return { destino, etiqueta };
}
