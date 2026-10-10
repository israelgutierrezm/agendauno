import type {
  NavigationGuardReturn,
  RouteRecordRaw,
  RouteRecordSingleView,
} from "vue-router";

import {
  PRODUCTOS,
  productoDeModalidad,
  productoDelHost,
} from "@/lib/producto";
import { salirA } from "@/lib/tenant";
import { rutaSolucion, soluciones } from "@/marketing/soluciones";
import {
  MODOS,
  NOMBRE_RUTA_MODALIDAD,
  rutaModalidad,
  type Modo,
} from "@/marketing/modalidades";
import {
  MODO_COMERCIAL,
  paginasMarketing,
  type VistaMarketing,
} from "@/marketing/seoConfig";

declare module "vue-router" {
  interface RouteMeta {
    /**
     * Página comercial del producto (portada, /software-para-*; ADR 0108): menú
     * comercial e indexable; en el subdominio de un negocio no se muestra.
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
 * Lleva a la misma ruta en el dominio del otro producto (ADR 0108). En desarrollo (sin
 * dominio de producto), al mismo sitio con la otra marca (`?producto=`).
 */
function alOtroProducto(modo: Modo, ruta: string): NavigationGuardReturn {
  const otro = productoDeModalidad(modo);
  if (typeof window !== "undefined") {
    salirA(
      productoDelHost(window.location.hostname) === null
        ? `${ruta}?producto=${otro}`
        : `https://${PRODUCTOS[otro].dominio}${ruta}`,
    );
  }
  return false;
}

/**
 * `/clases` y `/citas` (ADR 0108): cada dominio es de un producto. La de su modalidad
 * es la portada (`/clases` en agendauno.mx lleva a `/`, con su query); la otra vive en
 * el dominio del otro producto (`/citas` en agendauno.mx lleva a turnouno.mx), igual
 * que sus páginas por giro (que aquí no son el enlace corto de un negocio). Los enlaces
 * por nombre (`modalidad-clases`, `modalidad-citas`) siguen funcionando. Son
 * comerciales: en el subdominio de un negocio no salen de él.
 */
function rutasDeOtrosProductos(): RouteRecordRaw[] {
  const modalidades: RouteRecordRaw[] = MODOS.map((modo) =>
    modo === MODO_COMERCIAL
      ? {
          path: rutaModalidad(modo),
          name: NOMBRE_RUTA_MODALIDAD[modo],
          redirect: (to) => ({ path: "/", query: to.query, hash: to.hash }),
        }
      : {
          path: rutaModalidad(modo),
          name: NOMBRE_RUTA_MODALIDAD[modo],
          component: { render: () => null },
          meta: { marketing: true, modo },
          beforeEnter: () => alOtroProducto(modo, "/"),
        },
  );
  const giros: RouteRecordRaw[] = soluciones
    .filter((s) => s.modo !== MODO_COMERCIAL)
    .map((s) => ({
      path: rutaSolucion(s.slug),
      name: `solucion-${s.slug}`,
      component: { render: () => null },
      meta: { marketing: true, modo: s.modo },
      beforeEnter: () => alOtroProducto(s.modo, rutaSolucion(s.slug)),
    }));
  return [...modalidades, ...giros];
}

/**
 * Las rutas comerciales, iguales (mismo path, nombre, meta y props) en el router de
 * la aplicación y en el del prerender (`entry-marketing.ts`): solo cambia cómo se
 * carga cada vista. Salen de `paginasMarketing`, la lista única de seoConfig.
 */
export function rutasComerciales(
  vistas: Record<VistaMarketing, Vista>,
): RouteRecordRaw[] {
  return [
    ...paginasMarketing.map((pagina) => ({
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
    })),
    ...rutasDeOtrosProductos(),
  ];
}
