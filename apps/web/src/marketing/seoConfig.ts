import { soluciones, rutaSolucion } from "./soluciones.ts";
import {
  MODALIDADES,
  MODOS,
  NOMBRE_RUTA_MODALIDAD,
  perfilDeSolucion,
  rutaModalidad,
  type Modo,
} from "./modalidades.ts";

export const SITE_URL = "https://agendauno.mx";
export const DEFAULT_IMAGE = `${SITE_URL}/assets/brand/agendauno/final-v2/open-graph.png`;
export interface SeoOptions {
  title: string;
  description: string;
  path?: string;
  image?: string;
  type?: "website" | "profile";
  index?: boolean;
  jsonLd?: Record<string, unknown> | null;
}
export const DEFAULT_SEO = {
  title: "Software de reservas para clases y citas | AgendaUno",
  description:
    "Organiza clases, citas por profesional, membresías y cobros en una agenda visual. Prueba AgendaUno gratis durante 30 días, sin tarjeta.",
} as const;

/** Qué vista monta cada página comercial (el router elige el componente). */
export type VistaMarketing = "landing" | "modalidad" | "solucion";

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
 * LA lista de páginas comerciales: el router, el prerender, el sitemap, el SEO, la
 * analítica y las revisiones de `scripts/check-marketing*.mjs` salen de aquí.
 */
export const paginasMarketing: readonly PaginaMarketing[] = [
  { path: "/", name: "inicio", vista: "landing", modo: null },
  ...MODOS.map((modo): PaginaMarketing => ({
    path: rutaModalidad(modo),
    name: NOMBRE_RUTA_MODALIDAD[modo],
    vista: "modalidad",
    modo,
  })),
  ...soluciones.map((s): PaginaMarketing => ({
    path: rutaSolucion(s.slug),
    name: `solucion-${s.slug}`,
    vista: "solucion",
    modo: s.modo,
    slug: s.slug,
    giro: perfilDeSolucion(s.slug),
  })),
];
export const rutasMarketing: string[] = paginasMarketing.map((p) => p.path);

function organizacionYSoftware(): Record<string, unknown>[] {
  return [
    {
      "@type": "Organization",
      "@id": `${SITE_URL}/#organization`,
      name: "AgendaUno",
      url: SITE_URL,
      logo: `${SITE_URL}/assets/brand/agendauno/final-v2/logo.png`,
    },
    {
      "@type": "SoftwareApplication",
      "@id": `${SITE_URL}/#software`,
      name: "AgendaUno",
      url: SITE_URL,
      applicationCategory: "BusinessApplication",
      operatingSystem: "Web",
      description: DEFAULT_SEO.description,
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

/** /clases y /citas: su WebPage y su miga de pan (sin Offer, FAQPage ni hreflang). */
function seoDeModalidad(modo: Modo): SeoOptions {
  const { ruta, seo } = MODALIDADES[modo];
  const url = `${SITE_URL}${ruta}`;
  const image = `${SITE_URL}${seo.imagen}`;
  return {
    title: seo.title,
    description: seo.description,
    path: ruta,
    image,
    index: true,
    jsonLd: {
      "@context": "https://schema.org",
      "@graph": [
        ...organizacionYSoftware(),
        {
          "@type": "WebPage",
          "@id": `${url}#webpage`,
          url,
          name: seo.title,
          description: seo.description,
          inLanguage: "es-MX",
          primaryImageOfPage: image,
          about: { "@id": `${SITE_URL}/#software` },
          breadcrumb: { "@id": `${url}#breadcrumb` },
        },
        migaDePan(url, [{ name: seo.miga, item: url }]),
      ],
    },
  };
}

export function seoParaRuta(path: string): SeoOptions {
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
  const modalidad = MODOS.find((m) => rutaModalidad(m) === ruta);
  if (modalidad !== undefined) {
    return seoDeModalidad(modalidad);
  }
  const solucion = soluciones.find((s) => rutaSolucion(s.slug) === ruta);
  if (ruta === "/" || solucion) {
    const contenido = solucion
      ? { title: solucion.titulo, description: solucion.descripcion }
      : DEFAULT_SEO;
    const url = `${SITE_URL}${ruta}`;
    // La página por giro cuelga de su modalidad, como su miga visible
    // (AgendaUno / Clases|Citas / giro).
    const miga = solucion
      ? migaDePan(url, [
          {
            name: MODALIDADES[solucion.modo].seo.miga,
            item: `${SITE_URL}${rutaModalidad(solucion.modo)}`,
          },
          { name: solucion.nombre, item: url },
        ])
      : null;
    return {
      ...contenido,
      path: ruta,
      index: true,
      jsonLd: {
        "@context": "https://schema.org",
        "@graph": [
          ...organizacionYSoftware(),
          {
            "@type": "WebPage",
            "@id": `${url}#webpage`,
            url,
            name: contenido.title,
            description: contenido.description,
            inLanguage: "es-MX",
            about: { "@id": `${SITE_URL}/#software` },
            ...(miga ? { breadcrumb: { "@id": `${url}#breadcrumb` } } : {}),
          },
          ...(miga ? [miga] : []),
        ],
      },
    };
  }
  const titulos: Record<string, string> = {
    "/registro": "Crea tu negocio gratis | AgendaUno",
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

/** Misma metadata para el HTML estático y la navegación de Vue. */
export function renderSeoHead(seo: SeoOptions): string {
  const tags = [
    `<title>${escapeHtml(seo.title)}</title>`,
    `<meta name="description" content="${escapeHtml(seo.description)}">`,
    `<meta name="robots" content="${seo.index === false ? "noindex,follow" : "index,follow,max-image-preview:large"}">`,
    '<meta property="og:locale" content="es_MX">',
    '<meta property="og:site_name" content="AgendaUno">',
    `<meta property="og:type" content="${seo.type ?? "website"}">`,
    `<meta property="og:title" content="${escapeHtml(seo.title)}">`,
    `<meta property="og:description" content="${escapeHtml(seo.description)}">`,
    `<meta property="og:image" content="${escapeHtml(seo.image ?? DEFAULT_IMAGE)}">`,
    '<meta name="twitter:card" content="summary_large_image">',
    `<meta name="twitter:title" content="${escapeHtml(seo.title)}">`,
    `<meta name="twitter:description" content="${escapeHtml(seo.description)}">`,
    `<meta name="twitter:image" content="${escapeHtml(seo.image ?? DEFAULT_IMAGE)}">`,
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
