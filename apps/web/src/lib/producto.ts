/// <reference types="vite/client" />
/**
 * Los dos productos de la plataforma (ADR 0108): AgendaUno (negocios de clases,
 * agendauno.mx) y TurnoUno (negocios de citas, turnouno.mx). Un solo código; cada
 * dominio se presenta con su marca.
 *
 * Qué producto se muestra:
 * 1. el del build (`VITE_PRODUCTO`): las landings pre-generadas de cada dominio;
 * 2. el del host (`turnouno.mx`, `barberia.turnouno.mx`…): la aplicación en producción;
 * 3. el del negocio en sesión: en desarrollo (localhost) el panel habla con su marca;
 * 4. en desarrollo, `?producto=turnouno` (se recuerda en la pestaña);
 * 5. AgendaUno.
 *
 * Lo importa `vite.config` (por seoConfig): sin alias `@/` ni vue-i18n.
 */

export type Producto = "agendauno" | "turnouno";

export const PRODUCTOS_LISTA: readonly Producto[] = ["agendauno", "turnouno"];

export interface DatosProducto {
  id: Producto;
  /** Nombre de la marca («AgendaUno»). */
  nombre: string;
  /** Dominio de la marca: sus negocios viven en `{slug}.{dominio}`. */
  dominio: string;
  /** La modalidad de sus negocios (ADR 0104). */
  modalidad: "clases" | "citas";
}

function env(clave: string): string | undefined {
  // `import.meta.env` no existe al cargarlo `vite.config` (Node): de ahí el `?.`.
  const variables = import.meta.env as Record<string, unknown> | undefined;
  const valor = variables?.[clave];
  return typeof valor === "string" && valor.trim() !== ""
    ? valor.trim()
    : undefined;
}

export const PRODUCTOS: Record<Producto, DatosProducto> = {
  agendauno: {
    id: "agendauno",
    nombre: "AgendaUno",
    dominio: (env("VITE_DOMINIO_PUBLICO") ?? "agendauno.mx").toLowerCase(),
    modalidad: "clases",
  },
  turnouno: {
    id: "turnouno",
    // La identidad de TurnoUno aún no es definitiva: su nombre es configurable.
    nombre: env("VITE_TURNOUNO_NOMBRE") ?? "TurnoUno",
    dominio: (env("VITE_DOMINIO_TURNOUNO") ?? "turnouno.mx").toLowerCase(),
    modalidad: "citas",
  },
};

export function esProducto(valor: unknown): valor is Producto {
  return valor === "agendauno" || valor === "turnouno";
}

/** El producto de una modalidad: clases → AgendaUno, citas → TurnoUno. */
export function productoDeModalidad(
  modalidad: string | null | undefined,
): Producto {
  return modalidad === "citas" ? "turnouno" : "agendauno";
}

/** El producto del build de una landing (`VITE_PRODUCTO`), si lo hay. */
export const PRODUCTO_DE_BUILD: Producto | null = (() => {
  const valor = env("VITE_PRODUCTO");
  return esProducto(valor) ? valor : null;
})();

/**
 * El producto de un host: su dominio, `www.` o el subdominio de un negocio (un nivel).
 * `null` fuera de los dominios de los productos (localhost, una IP).
 */
export function productoDelHost(host: string): Producto | null {
  const h = host.toLowerCase().split(":")[0] ?? "";
  for (const id of PRODUCTOS_LISTA) {
    const dominio = PRODUCTOS[id].dominio;
    if (h === dominio) {
      return id;
    }
    if (h.endsWith(`.${dominio}`)) {
      const sub = h.slice(0, -dominio.length - 1);
      if (sub !== "" && !sub.includes(".")) {
        return id;
      }
    }
  }
  return null;
}

const CLAVE_DESARROLLO = "tu.producto";
let deSesion: Producto | null = null;

// Sin tipos del DOM: este archivo también lo carga `vite.config` (Node).
interface Ventana {
  location: { hostname: string; search: string };
  sessionStorage: {
    getItem(clave: string): string | null;
    setItem(clave: string, valor: string): void;
  };
}
function ventana(): Ventana | undefined {
  return (globalThis as { window?: Ventana }).window;
}

/** El producto del negocio en sesión (lo fija la sesión al cargar el negocio). */
export function fijarProductoDeSesion(producto: Producto | null): void {
  deSesion = producto;
}

function deDesarrollo(): Producto | null {
  const w = ventana();
  if (w === undefined) {
    return null;
  }
  const pedido = new URLSearchParams(w.location.search).get("producto");
  try {
    if (esProducto(pedido)) {
      w.sessionStorage.setItem(CLAVE_DESARROLLO, pedido);
      return pedido;
    }
    const guardado = w.sessionStorage.getItem(CLAVE_DESARROLLO);
    return esProducto(guardado) ? guardado : null;
  } catch {
    return esProducto(pedido) ? pedido : null;
  }
}

/** El producto con que se presenta esta página (ver el orden arriba). */
export function productoActual(): Producto {
  if (PRODUCTO_DE_BUILD !== null) {
    return PRODUCTO_DE_BUILD;
  }
  const w = ventana();
  if (w !== undefined) {
    const delHost = productoDelHost(w.location.hostname);
    if (delHost !== null) {
      return delHost;
    }
  }
  return deSesion ?? deDesarrollo() ?? "agendauno";
}

export function datosProducto(
  producto: Producto = productoActual(),
): DatosProducto {
  return PRODUCTOS[producto];
}

/**
 * Un texto con la marca del producto: los textos base están en AgendaUno; en TurnoUno,
 * «AgendaUno» se lee «TurnoUno» y «agendauno.mx», su dominio.
 */
export function conMarca(texto: string, producto: Producto): string {
  if (producto === "agendauno") {
    return texto;
  }
  const { nombre, dominio } = PRODUCTOS[producto];
  return texto
    .replaceAll(PRODUCTOS.agendauno.nombre, nombre)
    .replaceAll(PRODUCTOS.agendauno.dominio, dominio);
}

/** Igual que `conMarca`, en cada texto de un objeto o lista (mensajes, contenidos). */
export function conMarcaProfunda<T>(valor: T, producto: Producto): T {
  if (producto === "agendauno") {
    return valor;
  }
  const recorrer = (v: unknown): unknown => {
    if (typeof v === "string") {
      return conMarca(v, producto);
    }
    if (Array.isArray(v)) {
      return v.map(recorrer);
    }
    if (v !== null && typeof v === "object") {
      return Object.fromEntries(
        Object.entries(v).map(([k, x]) => [k, recorrer(x)]),
      );
    }
    return v;
  };
  return recorrer(valor) as T;
}
