/**
 * El sitio público de cada negocio (ADR 0114): su plantilla, sus secciones (orden,
 * visibilidad, título y texto propios) y sus banners. Lo que muestra cada sección sale
 * del sistema; aquí solo su forma. La API valida y normaliza lo que se guarda.
 *
 * Entre las secciones van los bloques propios del negocio (ADR 0146): texto con imagen,
 * galería, preguntas, testimonios, llamado a la acción, video, beneficios y formulario.
 * Se repiten, se ordenan y tienen su estilo; campos fijos, sin HTML ni código.
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

export const BLOQUES = [
  "texto_imagen",
  "galeria",
  "preguntas",
  "testimonios",
  "llamado",
  "video",
  "beneficios",
  "formulario",
] as const;
export type TipoBloque = (typeof BLOQUES)[number];
export type FondoBloque = "normal" | "suave" | "marca" | "oscuro";
export type AlineacionBloque = "izquierda" | "centro";

/** Lo propio de cada tipo de bloque (la API guarda solo lo de su tipo). */
export interface DatosBloque {
  lado?: "izquierda" | "derecha";
  boton_texto?: string | null;
  boton_enlace?: string | null;
  fotos?: { url: string; pie: string | null }[];
  items?: {
    pregunta?: string | null;
    respuesta?: string | null;
    nombre?: string | null;
    titulo?: string | null;
    texto?: string | null;
    foto_url?: string | null;
  }[];
  url?: string | null;
  proveedor?: "youtube" | "vimeo" | null;
  video_id?: string | null;
  formulario_id?: string | null;
}

export interface SeccionSitio {
  tipo: TipoSeccion | TipoBloque;
  // Los bloques propios llevan su id, su estilo y sus datos.
  id?: string;
  // Solo en el borrador: en la página pública ya vienen solo las visibles.
  visible?: boolean;
  titulo: string | null;
  texto: string | null;
  foto_url: string | null;
  estilo?: { fondo: FondoBloque; alineacion: AlineacionBloque };
  datos?: DatosBloque;
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

export function esFija(tipo: TipoSeccion | TipoBloque): boolean {
  return (SECCIONES_FIJAS as string[]).includes(tipo);
}

export function esBloque(tipo: string): tipo is TipoBloque {
  return (BLOQUES as readonly string[]).includes(tipo);
}

/** La clave de una sección en una lista: la de un bloque es su id. */
export function claveSeccion(s: Pick<SeccionSitio, "tipo" | "id">): string {
  return s.id ?? s.tipo;
}

/** Un id para un bloque nuevo, con la forma que la API conserva. */
export function idBloque(): string {
  let id = "";
  while (id.length < 20) {
    id += Math.random().toString(36).slice(2);
  }
  return id.slice(0, 20);
}

/** Un bloque nuevo, vacío y visible, con el estilo de siempre. */
export function nuevoBloque(tipo: TipoBloque): SeccionSitio & {
  id: string;
  visible: boolean;
} {
  const datos: DatosBloque = {
    texto_imagen: {
      lado: "izquierda" as const,
      boton_texto: null,
      boton_enlace: null,
    },
    llamado: { boton_texto: null, boton_enlace: null },
    galeria: { fotos: [] },
    preguntas: { items: [{ pregunta: null, respuesta: null }] },
    testimonios: { items: [{ nombre: null, texto: null, foto_url: null }] },
    beneficios: { items: [{ titulo: null, texto: null }] },
    video: { url: null, proveedor: null, video_id: null },
    formulario: { formulario_id: null },
  }[tipo];
  return {
    id: idBloque(),
    tipo,
    visible: true,
    titulo: null,
    texto: null,
    foto_url: null,
    estilo: { fondo: "normal", alineacion: "izquierda" },
    datos,
  };
}

/** Una copia con otro id (lo de adentro no se comparte). */
export function duplicarBloque<T extends SeccionSitio>(bloque: T): T {
  const copia = JSON.parse(JSON.stringify(bloque)) as T;
  copia.id = idBloque();
  return copia;
}

/**
 * El reproductor de un video (solo YouTube sin cookies o Vimeo sin rastreo), armado con
 * su proveedor y su id: nunca con el enlace que se pegó.
 */
export function urlReproductor(
  proveedor: string | null | undefined,
  id: string | null | undefined,
): string | null {
  if (proveedor === "youtube" && id && /^[A-Za-z0-9_-]{11}$/.test(id)) {
    return `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0`;
  }
  if (proveedor === "vimeo" && id && /^\d{6,12}$/.test(id)) {
    return `https://player.vimeo.com/video/${id}?autoplay=1&dnt=1`;
  }
  return null;
}

/** ¿El enlace es de un video de YouTube o Vimeo? (la API lo confirma al guardar). */
export function esEnlaceDeVideo(url: string): boolean {
  return /^https?:\/\/(www\.|m\.)?(youtube\.com\/(watch\?|shorts\/|live\/|embed\/)|youtu\.be\/|(player\.)?vimeo\.com\/)/i.test(
    url.trim(),
  );
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
export function moverSeccion<T extends { tipo: TipoSeccion | TipoBloque }>(
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
