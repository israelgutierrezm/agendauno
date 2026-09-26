export interface NegocioReciente {
  slug: string;
  nombre: string;
  logo_url: string | null;
  ciudad: string | null;
  pais: string | null;
  visitado_en: number;
}

const CLAVE = "agendauno.negocios-recientes.v1";
const LIMITE = 5;

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

export function leerNegociosRecientes(store?: Storage): NegocioReciente[] {
  const destino = almacenamiento(store);
  if (destino === null) return [];
  try {
    const guardado = destino.getItem(CLAVE);
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

export function recordarNegocio(
  negocio: Omit<NegocioReciente, "visitado_en">,
  store?: Storage,
): NegocioReciente[] {
  const destino = almacenamiento(store);
  if (destino === null) return [];
  const existentes = leerNegociosRecientes(destino);
  const anterior = existentes.find((item) => item.slug === negocio.slug.trim());
  const actualizado: NegocioReciente = {
    ...negocio,
    slug: negocio.slug.trim(),
    nombre: negocio.nombre.trim(),
    logo_url: negocio.logo_url ?? anterior?.logo_url ?? null,
    ciudad: negocio.ciudad ?? anterior?.ciudad ?? null,
    pais: negocio.pais ?? anterior?.pais ?? null,
    visitado_en: Date.now(),
  };
  const recientes = [
    actualizado,
    ...existentes.filter((item) => item.slug !== actualizado.slug),
  ].slice(0, LIMITE);
  try {
    destino.setItem(CLAVE, JSON.stringify(recientes));
  } catch {
    // El acceso rápido es opcional: la navegación funciona sin almacenamiento.
  }
  return recientes;
}

export function olvidarNegocio(
  slug: string,
  store?: Storage,
): NegocioReciente[] {
  const destino = almacenamiento(store);
  if (destino === null) return [];
  const recientes = leerNegociosRecientes(destino).filter(
    (item) => item.slug !== slug,
  );
  try {
    destino.setItem(CLAVE, JSON.stringify(recientes));
  } catch {
    // Ignora almacenamiento no disponible.
  }
  return recientes;
}
