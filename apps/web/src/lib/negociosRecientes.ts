import { productoActual, type Producto } from "@/lib/producto";

/**
 * Los negocios que se abrieron en este dispositivo, para entrar de nuevo con un toque.
 *
 * Cada producto guarda su propia lista (ADR 0136): en TurnoUno solo aparecen negocios
 * de TurnoUno y en AgendaUno solo los de AgendaUno, aunque en desarrollo compartan el
 * mismo origen. Para quien entra, cada producto es un sitio aparte.
 */
export interface NegocioReciente {
  slug: string;
  nombre: string;
  logo_url: string | null;
  ciudad: string | null;
  pais: string | null;
  visitado_en: number;
}

// La v1 no decía de qué producto era cada negocio: ya no se lee.
const CLAVE = "negocios-recientes.v2";
const LIMITE = 5;

function clave(producto: Producto): string {
  return `${producto}.${CLAVE}`;
}

function almacenamiento(predeterminado?: Storage): Storage | null {
  if (predeterminado !== undefined) return predeterminado;
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

function esNegocio(valor: unknown): valor is NegocioReciente {
  if (typeof valor !== "object" || valor === null) return false;
  const negocio = valor as Partial<NegocioReciente>;
  return (
    typeof negocio.slug === "string" &&
    negocio.slug.trim() !== "" &&
    typeof negocio.nombre === "string" &&
    negocio.nombre.trim() !== "" &&
    (negocio.logo_url === null || typeof negocio.logo_url === "string") &&
    (negocio.ciudad === null || typeof negocio.ciudad === "string") &&
    (negocio.pais === null || typeof negocio.pais === "string") &&
    typeof negocio.visitado_en === "number"
  );
}

/** Los recientes de un producto (por omisión, el de esta página). */
export function leerNegociosRecientes(
  store?: Storage,
  producto: Producto = productoActual(),
): NegocioReciente[] {
  const destino = almacenamiento(store);
  if (destino === null) return [];
  try {
    const guardado = destino.getItem(clave(producto));
    if (guardado === null) return [];
    const datos: unknown = JSON.parse(guardado);
    return Array.isArray(datos)
      ? datos
          .filter(esNegocio)
          .sort((a, b) => b.visitado_en - a.visitado_en)
          .slice(0, LIMITE)
      : [];
  } catch {
    return [];
  }
}

/**
 * Lo recuerda en la lista de SU producto (`producto`; si no se sabe, el de esta
 * página). Devuelve la lista del producto de esta página.
 */
export function recordarNegocio(
  negocio: Omit<NegocioReciente, "visitado_en"> & { producto?: Producto },
  store?: Storage,
): NegocioReciente[] {
  const destino = almacenamiento(store);
  if (destino === null) return [];
  const { producto: suyo, ...datos } = negocio;
  const producto = suyo ?? productoActual();
  const existentes = leerNegociosRecientes(destino, producto);
  const anterior = existentes.find((item) => item.slug === datos.slug.trim());
  const actualizado: NegocioReciente = {
    ...datos,
    slug: datos.slug.trim(),
    nombre: datos.nombre.trim(),
    logo_url: datos.logo_url ?? anterior?.logo_url ?? null,
    ciudad: datos.ciudad ?? anterior?.ciudad ?? null,
    pais: datos.pais ?? anterior?.pais ?? null,
    visitado_en: Date.now(),
  };
  const recientes = [
    actualizado,
    ...existentes.filter((item) => item.slug !== actualizado.slug),
  ].slice(0, LIMITE);
  try {
    destino.setItem(clave(producto), JSON.stringify(recientes));
  } catch {
    // El acceso rápido es opcional: la navegación funciona sin almacenamiento.
  }
  return leerNegociosRecientes(destino);
}

export function olvidarNegocio(
  slug: string,
  store?: Storage,
  producto: Producto = productoActual(),
): NegocioReciente[] {
  const destino = almacenamiento(store);
  if (destino === null) return [];
  const recientes = leerNegociosRecientes(destino, producto).filter(
    (item) => item.slug !== slug,
  );
  try {
    destino.setItem(clave(producto), JSON.stringify(recientes));
  } catch {
    // Ignora almacenamiento no disponible.
  }
  return recientes;
}
