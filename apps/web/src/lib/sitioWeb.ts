/**
 * El sitio público de cada negocio (ADR 0114): su plantilla, sus secciones (orden,
 * visibilidad, título y texto propios) y sus banners. Lo que muestra cada sección sale
 * del sistema; aquí solo su forma. La API valida y normaliza lo que se guarda.
 */

export type TipoSeccion =
  | "inicio"
  | "promociones"
  | "nosotros"
  | "agenda"
  | "servicios"
  | "horario"
  | "precios"
  | "equipo"
  | "resenas"
  | "sucursales"
  | "contacto";

export type PlantillaSitio = "esencial" | "portada" | "compacta";

export interface SeccionSitio {
  tipo: TipoSeccion;
  // Solo en el borrador: en la página pública ya vienen solo las visibles.
  visible?: boolean;
  titulo: string | null;
  texto: string | null;
  foto_url: string | null;
}

export interface BannerSitio {
  id?: string;
  titulo: string;
  texto: string | null;
  enlace_texto: string | null;
  enlace_url: string | null;
  // Solo en el borrador: el público ve los vigentes hoy.
  desde?: string | null;
  hasta?: string | null;
  foto_url: string | null;
}

export interface SitioPublico {
  plantilla: PlantillaSitio;
  secciones: SeccionSitio[];
  banners: BannerSitio[];
}

/** La portada y el contacto no se mueven ni se ocultan. */
export const SECCIONES_FIJAS: TipoSeccion[] = ["inicio", "contacto"];

export function esFija(tipo: TipoSeccion): boolean {
  return SECCIONES_FIJAS.includes(tipo);
}

/**
 * La página de siempre (plantilla esencial, todo visible): la que se ve si el API no
 * manda sitio. Un negocio de citas no tiene horario de clases.
 */
export function sitioPorDefecto(esCitas: boolean): SitioPublico {
  const tipos: TipoSeccion[] = [
    "inicio",
    "promociones",
    "nosotros",
    "agenda",
    "servicios",
    "horario",
    "precios",
    "equipo",
    "resenas",
    "sucursales",
    "contacto",
  ];
  return {
    plantilla: "esencial",
    secciones: tipos
      .filter((t) => !(esCitas && t === "horario"))
      .map((tipo) => ({ tipo, titulo: null, texto: null, foto_url: null })),
    banners: [],
  };
}

/**
 * Mueve una sección un lugar (`-1` sube, `1` baja) sin pasar de la portada ni del
 * contacto, que se quedan en su lugar. Devuelve una lista nueva.
 */
export function moverSeccion<T extends { tipo: TipoSeccion }>(
  secciones: T[],
  indice: number,
  paso: -1 | 1,
): T[] {
  const destino = indice + paso;
  const lista = [...secciones];
  if (
    destino < 0 ||
    destino >= lista.length ||
    esFija(lista[indice].tipo) ||
    esFija(lista[destino].tipo)
  ) {
    return lista;
  }
  [lista[indice], lista[destino]] = [lista[destino], lista[indice]];
  return lista;
}

/**
 * El texto que se lee sobre un color (`#rrggbb`): blanco sobre colores oscuros y casi
 * negro sobre claros (luminancia relativa de WCAG).
 */
export function colorDeContraste(hex: string): string {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
  if (!m) {
    return "#ffffff";
  }
  const [r, g, b] = [0, 2, 4].map((i) => {
    const c = parseInt(m[1].slice(i, i + 2), 16) / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  const luminancia = 0.2126 * r + 0.7152 * g + 0.0722 * b;
  // Contraste con blanco vs. con casi negro (#111): el mayor.
  const conBlanco = 1.05 / (luminancia + 0.05);
  const conNegro = (luminancia + 0.05) / 0.0559;
  return conBlanco >= conNegro ? "#ffffff" : "#111111";
}
