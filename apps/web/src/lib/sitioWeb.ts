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

/** Lo que el sitio no muestra de cada módulo (ADR 0167), por su id público. */
export interface OcultosSitio {
  servicios: string[];
  productos: string[];
  equipo: string[];
}

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
  // Cómo se muestra la galería o los testimonios (ADR 0167, Plus).
  presentacion?: string | null;
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
  // Cómo se muestra una sección del sistema (ADR 0167, Plus); null = como siempre.
  presentacion?: string | null;
}

/**
 * Cómo se puede mostrar cada sección o bloque (ADR 0167, Plus), como en el API: el
 * equipo en tarjetas, mosaico o carrusel; reseñas, galería y testimonios en carrusel
 * (la galería también en mosaico).
 */
export const PRESENTACIONES: Partial<
  Record<TipoSeccion | TipoBloque, string[]>
> = {
  equipo: ["tarjetas", "mosaico", "carrusel"],
  resenas: ["carrusel"],
  galeria: ["mosaico", "carrusel"],
  testimonios: ["carrusel"],
};

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

/** Cómo aparece en buscadores y al compartirse (vacío: lo de siempre). */
export interface SeoSitio {
  titulo: string | null;
  descripcion: string | null;
  imagen_url: string | null;
}

/**
 * Una página propia (ADR 0146, fase 2): su dirección (`/p/{slug}`), su título, si va
 * en el menú, su SEO y sus secciones (las del sistema, una vez; bloques propios).
 */
export interface PaginaSitio {
  id?: string;
  slug: string;
  titulo: string;
  en_menu: boolean;
  seo: SeoSitio;
  secciones: SeccionSitio[];
}

export type TipografiaSitio =
  | "predeterminada"
  | "moderna"
  | "clasica"
  | "elegante"
  | "amigable"
  | "sistema";
export type EsquinasSitio = "rectas" | "suaves" | "redondas";
export type TemaSitio = "automatico" | "claro" | "oscuro";

/** El estilo de todo el sitio (ADR 0146, fase 3). */
export interface EstiloSitio {
  // `#rrggbb`; null: el color de su marca.
  color: string | null;
  tipografia: TipografiaSitio;
  esquinas: EsquinasSitio;
  tema: TemaSitio;
}

/** El botón principal del encabezado (p. ej. «Agendar» hacia `#agenda`). */
export interface EncabezadoSitio {
  boton_texto: string | null;
  boton_enlace: string | null;
}

export interface SitioPublico {
  plantilla: PlantillaSitio;
  secciones: SeccionSitio[];
  banners: BannerSitio[];
  // Fases 2 y 3 (ADR 0146); un API anterior no los manda.
  seo?: SeoSitio;
  paginas?: PaginaSitio[];
  pie?: { texto: string | null };
  estilo?: EstiloSitio;
  encabezado?: EncabezadoSitio;
}

/**
 * La familia de cada tipografía del sitio (null: la de siempre). Las de Google Fonts se
 * cargan solo si el sitio las usa.
 */
export const FAMILIAS_SITIO: Record<TipografiaSitio, string | null> = {
  predeterminada: null,
  moderna: "Poppins",
  clasica: "Lora",
  elegante: "Playfair Display",
  amigable: "Nunito",
  sistema: "Sistema",
};

/** Los radios de cada estilo de esquinas (las suaves son las de siempre). */
export const RADIOS_SITIO: Record<EsquinasSitio, Record<string, string>> = {
  rectas: {
    "--radio-boton": "4px",
    "--radio-tarjeta": "6px",
    "--radio-imagen": "6px",
    "--radio-panel": "8px",
  },
  suaves: {},
  redondas: {
    "--radio-boton": "999px",
    "--radio-tarjeta": "24px",
    "--radio-imagen": "28px",
    "--radio-panel": "32px",
  },
};

/** La dirección de una página a partir de su título: «Preguntas frecuentes» → preguntas-frecuentes. */
export function direccionDe(titulo: string, maximo = 40): string {
  return titulo
    .normalize("NFD")
    .replace(/\p{Diacritic}/gu, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, maximo)
    .replace(/-+$/g, "");
}

/** Una dirección que no usa otra página (agrega un número si hace falta). */
export function direccionLibre(base: string, usadas: string[]): string {
  const limpia = base || "pagina";
  let direccion = limpia;
  for (let n = 2; usadas.includes(direccion); n++) {
    direccion = `${limpia.slice(0, 37)}-${n}`;
  }
  return direccion;
}

/** Una página nueva, en el menú y vacía. */
export function nuevaPagina(titulo: string, usadas: string[]): PaginaSitio {
  return {
    id: idBloque(),
    slug: direccionLibre(direccionDe(titulo), usadas),
    titulo,
    en_menu: true,
    seo: { titulo: null, descripcion: null, imagen_url: null },
    secciones: [],
  };
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
    seo: { titulo: null, descripcion: null, imagen_url: null },
    paginas: [],
    pie: { texto: null },
    estilo: {
      color: null,
      tipografia: "predeterminada",
      esquinas: "suaves",
      tema: "automatico",
    },
    encabezado: { boton_texto: null, boton_enlace: null },
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

/*
 * Editor visual (ADR 0146, fase 3): el editor y su vista previa (en un marco del mismo
 * origen) se hablan con mensajes. El editor manda el borrador sin guardar y la vista
 * previa avisa cuándo está lista, si el borrador se pudo mostrar y qué sección se quiere
 * editar. Solo se aceptan mensajes del mismo origen y de la ventana esperada.
 */
export const FUENTE_EDITOR_SITIO = "agendauno-sitio";

export type MensajeEditorSitio =
  | { tipo: "lista" }
  | { tipo: "borrador"; borrador: unknown }
  | { tipo: "estado"; valido: boolean }
  | { tipo: "editar"; pagina: string | null; seccion: string }
  | { tipo: "enfocar"; seccion: string };

/** Manda un mensaje a la otra ventana del editor, solo a este origen. */
export function avisarEditorSitio(
  destino: Window | null | undefined,
  mensaje: MensajeEditorSitio,
): void {
  destino?.postMessage(
    { fuente: FUENTE_EDITOR_SITIO, ...mensaje },
    window.location.origin,
  );
}

/** El mensaje del editor, si viene de este origen y de la ventana esperada. */
export function mensajeEditorSitio(
  evento: MessageEvent,
  de: Window | null | undefined,
): MensajeEditorSitio | null {
  const datos = evento.data as { fuente?: unknown; tipo?: unknown } | null;
  if (
    !de ||
    evento.origin !== window.location.origin ||
    evento.source !== de ||
    typeof datos !== "object" ||
    datos === null ||
    datos.fuente !== FUENTE_EDITOR_SITIO ||
    typeof datos.tipo !== "string"
  ) {
    return null;
  }
  return datos as MensajeEditorSitio;
}

/** La ventana del editor, si esta página se ve dentro de él (en un marco). */
export function ventanaDelEditor(): Window | null {
  return window.parent !== window ? window.parent : null;
}
