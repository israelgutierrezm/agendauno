import type { RouteRecordRaw, RouteRecordSingleView } from "vue-router";

import type { Modo } from "@/marketing/modalidades";
import { paginasMarketing, type VistaMarketing } from "@/marketing/seoConfig";

declare module "vue-router" {
  interface RouteMeta {
    /**
     * Página comercial de AgendaUno (portada, /clases, /citas, /software-para-*):
     * menú comercial e indexable; en el subdominio de un negocio no se muestra.
     */
    marketing?: boolean;
    /** La modalidad de la página comercial (`null` en la portada). */
    modo?: Modo | null;
    /**
     * El giro del registro de la página comercial: solo en las páginas por giro de
     * un solo giro (`perfilDeSolucion`); `null` en las demás.
     */
    giro?: string | null;
  }
}

type Vista = RouteRecordSingleView["component"];

/**
 * Las rutas comerciales, iguales (mismo path, nombre, meta y props) en el router de
 * la aplicación y en el del prerender (`entry-marketing.ts`): solo cambia cómo se
 * carga cada vista. Salen de `paginasMarketing`, la lista única de seoConfig.
 */
export function rutasComerciales(
  vistas: Record<VistaMarketing, Vista>,
): RouteRecordRaw[] {
  return paginasMarketing.map((pagina) => ({
    path: pagina.path,
    name: pagina.name,
    component: vistas[pagina.vista],
    meta: { marketing: true, modo: pagina.modo, giro: pagina.giro ?? null },
    props:
      pagina.vista === "modalidad"
        ? { modo: pagina.modo }
        : pagina.vista === "solucion"
          ? { slug: pagina.slug }
          : false,
  }));
}
