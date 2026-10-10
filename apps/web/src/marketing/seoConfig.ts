import { soluciones, rutaSolucion } from "./soluciones.ts";
import { MODALIDADES, perfilDeSolucion, type Modo } from "./modalidades.ts";
import { PRECIOS_POR_OMISION } from "./precios.ts";
import {
  PRODUCTOS,
  PRODUCTO_DE_BUILD,
  conMarca,
  productoActual,
  type Producto,
} from "../lib/producto.ts";

/**
 * El producto de las páginas comerciales (ADR 0108): el del build de la landing
 * (`VITE_PRODUCTO`) o, en la aplicación, el del dominio. Cada dominio publica solo
 * las páginas de su producto: agendauno.mx las de clases, turnouno.mx las de citas.
 */
export const PRODUCTO_COMERCIAL: Producto =
  PRODUCTO_DE_BUILD ??
  ("window" in globalThis ? productoActual() : "agendauno");
const MARCA = PRODUCTOS[PRODUCTO_COMERCIAL];
/** La modalidad de los negocios del producto: la de su portada y sus páginas. */
export const MODO_COMERCIAL: Modo = MARCA.modalidad;

export const SITE_URL = `https://${MARCA.dominio}`;
// TurnoUno aún no tiene imagen de marca: su portada usa la foto de su página.
export const DEFAULT_IMAGE =
  PRODUCTO_COMERCIAL === "agendauno"
    ? `${SITE_URL}/assets/brand/agendauno/final-v2/open-graph.png`
    : `${SITE_URL}${MODALIDADES[MODO_COMERCIAL].seo.imagen}`;
export const DEFAULT_IMAGE_ALT =
  PRODUCTO_COMERCIAL === "agendauno"
    ? "Logotipo de AgendaUno"
    : MODALIDADES[MODO_COMERCIAL].seo.imagenAlt;

/** Los textos de SEO están en AgendaUno: en TurnoUno se leen con su marca. */
function marcar(texto: string): string {
  return conMarca(texto, PRODUCTO_COMERCIAL);
}

/**
 * ¿El producto recibe registros? (ADR 0108). El HTML pre-generado usa el respaldo de
 * `precios.ts`; la aplicación puede pasar lo que publicó el superadmin.
 */
export const REGISTRO_ABIERTO_POR_OMISION: boolean =
  PRECIOS_POR_OMISION.registro[PRODUCTO_COMERCIAL];

/**
 * La frase con que cierra la descripción de una página comercial: la prueba gratis si
 * el producto recibe registros; si el superadmin lo cerró (prelanzamiento), la lista
 * de interesados. Nunca ofrece una prueba que todavía no se puede empezar.
 */
export function cierreSeo(registroAbierto: boolean): string {
  return registroAbierto
    ? `Prueba AgendaUno gratis durante ${PRECIOS_POR_OMISION[MODO_COMERCIAL].dias_prueba} días, sin tarjeta.`
    : "AgendaUno abre pronto: déjanos tus datos y te avisamos.";
}
function conCierre(descripcion: string, registroAbierto: boolean): string {
  return `${descripcion} ${cierreSeo(registroAbierto)}`;
}

export interface SeoOptions {
  title: string;
  description: string;
  path?: string;
  image?: string;
  /** Texto alternativo de la imagen (`og:image:alt`). */
  imageAlt?: string;
  type?: "website" | "profile";
  index?: boolean;
  jsonLd?: Record<string, unknown> | null;
}
export const DEFAULT_SEO = {
  title: marcar(MODALIDADES[MODO_COMERCIAL].seo.title),
  description: marcar(
    conCierre(
      MODALIDADES[MODO_COMERCIAL].seo.description,
      REGISTRO_ABIERTO_POR_OMISION,
    ),
  ),
} as const;

/** Qué vista monta cada página comercial (el router elige el componente). */
export type VistaMarketing = "modalidad" | "solucion";

export interface PaginaMarketing {
  path: string;
  /** Nombre de la ruta: el mismo en el router y en el prerender. */
  name: string;
  vista: VistaMarketing;
  /** La modalidad de la página: la de /clases y /citas, y la de cada giro. */
  modo: Modo | null;
  /** Solo en las páginas por giro: su slug en `soluciones.ts`. */
  slug?: string;
  /**
   * Solo en las páginas por giro: el giro del registro que llevan sus «Probar gratis»
   * (el del menú también, `?giro=`), o `null` si la página junta varios giros.
   */
  giro?: string | null;
}

/**
 * LA lista de páginas comerciales del producto: el router, el prerender, el sitemap,
 * el SEO, la analítica y las revisiones de `scripts/check-marketing*.mjs` salen de
 * aquí. La portada es la página de la modalidad del producto; las páginas por giro,
 * solo las de esa modalidad.
 */
export const paginasMarketing: readonly PaginaMarketing[] = [
  { path: "/", name: "inicio", vista: "modalidad", modo: MODO_COMERCIAL },
  ...soluciones
    .filter((s) => s.modo === MODO_COMERCIAL)
    .map((s): PaginaMarketing => ({
      path: rutaSolucion(s.slug),
      name: `solucion-${s.slug}`,
      vista: "solucion",
      modo: s.modo,
      slug: s.slug,
      giro: perfilDeSolucion(s.slug),
    })),
];
export const rutasMarketing: string[] = paginasMarketing.map((p) => p.path);

function organizacionYSoftware(
  registroAbierto: boolean,
): Record<string, unknown>[] {
  return [
    {
      "@type": "Organization",
      "@id": `${SITE_URL}/#organization`,
      name: MARCA.nombre,
      url: SITE_URL,
      ...(PRODUCTO_COMERCIAL === "agendauno"
        ? { logo: `${SITE_URL}/assets/brand/agendauno/final-v2/logo.png` }
        : {}),
    },
    {
      "@type": "SoftwareApplication",
      "@id": `${SITE_URL}/#software`,
      name: MARCA.nombre,
      url: SITE_URL,
      applicationCategory: "BusinessApplication",
      operatingSystem: "Web",
      description: conCierre(
        MODALIDADES[MODO_COMERCIAL].seo.description,
        registroAbierto,
      ),
      publisher: { "@id": `${SITE_URL}/#organization` },
    },
  ];
}

/** Miga de pan de una página comercial: Inicio → … → la página (`@id` `#breadcrumb`). */
function migaDePan(
  url: string,
  pasos: readonly { name: string; item: string }[],
): Record<string, unknown> {
  return {
    "@type": "BreadcrumbList",
    "@id": `${url}#breadcrumb`,
    itemListElement: [{ name: "Inicio", item: `${SITE_URL}/` }, ...pasos].map(
      (paso, i) => ({ "@type": "ListItem", position: i + 1, ...paso }),
    ),
  };
}

/** La portada del producto: su WebPage (sin Offer, FAQPage ni hreflang). */
function seoDeModalidad(modo: Modo, registroAbierto: boolean): SeoOptions {
  const { seo } = MODALIDADES[modo];
  const ruta = "/";
  const url = `${SITE_URL}/`;
  const image = `${SITE_URL}${seo.imagen}`;
  const description = conCierre(seo.description, registroAbierto);
  return {
    title: seo.title,
    description,
    path: ruta,
    image,
    imageAlt: seo.imagenAlt,
    index: true,
    jsonLd: {
      "@context": "https://schema.org",
      "@graph": [
        ...organizacionYSoftware(registroAbierto),
        {
          "@type": "WebPage",
          "@id": `${url}#webpage`,
          url,
          name: seo.title,
          description,
          inLanguage: "es-MX",
          primaryImageOfPage: image,
          about: { "@id": `${SITE_URL}/#software` },
        },
      ],
    },
  };
}

/**
 * El SEO de una ruta, con la marca del producto (ADR 0108). `registroAbierto`: si el
 * producto recibe registros (por omisión, el respaldo de `precios.ts`, el mismo del
 * HTML pre-generado); decide la frase de cierre de las páginas comerciales.
 */
export function seoParaRuta(
  path: string,
  { registroAbierto = REGISTRO_ABIERTO_POR_OMISION } = {},
): SeoOptions {
  const seo = seoBase(path, registroAbierto);
  return {
    ...seo,
    title: marcar(seo.title),
    description: marcar(seo.description),
    ...(seo.imageAlt === undefined ? {} : { imageAlt: marcar(seo.imageAlt) }),
    jsonLd: seo.jsonLd
      ? (JSON.parse(marcar(JSON.stringify(seo.jsonLd))) as Record<
          string,
          unknown
        >)
      : seo.jsonLd,
  };
}

function seoBase(path: string, registroAbierto: boolean): SeoOptions {
  const ruta = path.split(/[?#]/)[0]!.replace(/\/$/, "") || "/";
  if (ruta === "/terminos") {
    return {
      title: "Términos y condiciones | AgendaUno",
      description:
        "Las condiciones para usar AgendaUno en tu negocio: el servicio, la prueba, el cobro y la cancelación.",
      index: false,
    };
  }
  if (ruta === "/aviso-de-privacidad") {
    return {
      title: "Aviso de privacidad | AgendaUno",
      description:
        "Información sobre el tratamiento de datos personales y los derechos de privacidad en AgendaUno.",
      index: false,
    };
  }
  if (ruta === "/") {
    return seoDeModalidad(MODO_COMERCIAL, registroAbierto);
  }
  const solucion = soluciones.find(
    (s) => rutaSolucion(s.slug) === ruta && s.modo === MODO_COMERCIAL,
  );
  if (solucion) {
    const contenido = {
      title: solucion.titulo,
      description: conCierre(solucion.descripcion, registroAbierto),
    };
    const url = `${SITE_URL}${ruta}`;
    // Cada página por giro, con la foto de su giro (la de su encabezado).
    const image = `${SITE_URL}/assets/landing/disciplinas/${solucion.imagen}`;
    // La página por giro cuelga de la portada del producto (Inicio / giro).
    const miga = migaDePan(url, [{ name: solucion.nombre, item: url }]);
    return {
      ...contenido,
      path: ruta,
      image,
      imageAlt: solucion.alt,
      index: true,
      jsonLd: {
        "@context": "https://schema.org",
        "@graph": [
          ...organizacionYSoftware(registroAbierto),
          {
            "@type": "WebPage",
            "@id": `${url}#webpage`,
            url,
            name: contenido.title,
            description: contenido.description,
            inLanguage: "es-MX",
            primaryImageOfPage: image,
            about: { "@id": `${SITE_URL}/#software` },
            breadcrumb: { "@id": `${url}#breadcrumb` },
          },
          miga,
        ],
      },
    };
  }
  const titulos: Record<string, string> = {
    // Sin registro abierto, /registro es la lista de interesados.
    "/registro": registroAbierto
      ? "Crea tu negocio gratis | AgendaUno"
      : "Quiero que me avisen | AgendaUno",
    "/entrar": "Iniciar sesión | AgendaUno",
    "/negocios": "Encuentra tu negocio | AgendaUno",
    "/activar": "Activa tu cuenta | AgendaUno",
  };
  return {
    title: titulos[ruta] ?? "AgendaUno",
    description: "Accede a tu negocio y gestiona tu operación en AgendaUno.",
    index: false,
  };
}

function escapeHtml(value: string): string {
  return value.replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ]!,
  );
}

/** El texto alternativo de la imagen de la página (o el de la imagen por omisión). */
export function altDeImagen(seo: SeoOptions): string {
  if (seo.image === undefined) {
    return marcar(DEFAULT_IMAGE_ALT);
  }
  return seo.imageAlt ?? MARCA.nombre;
}

/** Misma metadata para el HTML estático y la navegación de Vue. */
export function renderSeoHead(seo: SeoOptions): string {
  const tags = [
    `<title>${escapeHtml(seo.title)}</title>`,
    `<meta name="description" content="${escapeHtml(seo.description)}">`,
    `<meta name="robots" content="${seo.index === false ? "noindex,follow" : "index,follow,max-image-preview:large"}">`,
    '<meta property="og:locale" content="es_MX">',
    `<meta property="og:site_name" content="${escapeHtml(MARCA.nombre)}">`,
    `<meta property="og:type" content="${seo.type ?? "website"}">`,
    `<meta property="og:title" content="${escapeHtml(seo.title)}">`,
    `<meta property="og:description" content="${escapeHtml(seo.description)}">`,
    `<meta property="og:image" content="${escapeHtml(seo.image ?? DEFAULT_IMAGE)}">`,
    `<meta property="og:image:alt" content="${escapeHtml(altDeImagen(seo))}">`,
    '<meta name="twitter:card" content="summary_large_image">',
    `<meta name="twitter:title" content="${escapeHtml(seo.title)}">`,
    `<meta name="twitter:description" content="${escapeHtml(seo.description)}">`,
    `<meta name="twitter:image" content="${escapeHtml(seo.image ?? DEFAULT_IMAGE)}">`,
    `<meta name="twitter:image:alt" content="${escapeHtml(altDeImagen(seo))}">`,
  ];
  if (seo.index !== false) {
    const url = escapeHtml(new URL(seo.path ?? "/", SITE_URL).href);
    tags.push(
      `<link rel="canonical" href="${url}">`,
      `<meta property="og:url" content="${url}">`,
    );
  }
  if (seo.jsonLd) {
    tags.push(
      `<script id="agendauno-route-jsonld" type="application/ld+json">${JSON.stringify(seo.jsonLd).replace(/</g, "\\u003c")}</script>`,
    );
  }
  return tags.join("\n");
}
