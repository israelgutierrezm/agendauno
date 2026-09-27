import { soluciones, rutaSolucion } from "./soluciones.ts";

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
export const rutasMarketing = [
  "/",
  ...soluciones.map((s) => rutaSolucion(s.slug)),
];

export function seoParaRuta(path: string): SeoOptions {
  const ruta = path.split(/[?#]/)[0]!.replace(/\/$/, "") || "/";
  if (ruta === "/aviso-de-privacidad") {
    return {
      title: "Aviso de privacidad | AgendaUno",
      description:
        "Información sobre el tratamiento de datos personales y los derechos de privacidad en AgendaUno.",
      index: false,
    };
  }
  const solucion = soluciones.find((s) => rutaSolucion(s.slug) === ruta);
  if (ruta === "/" || solucion) {
    const contenido = solucion
      ? { title: solucion.titulo, description: solucion.descripcion }
      : DEFAULT_SEO;
    return {
      ...contenido,
      path: ruta,
      index: true,
      jsonLd: {
        "@context": "https://schema.org",
        "@graph": [
          {
            "@type": "Organization",
            "@id": `${SITE_URL}/#organization`,
            name: "AgendaUno",
            url: SITE_URL,
            logo: `${SITE_URL}/assets/brand/agendauno/final-v2/logo.png`,
          },
          {
            "@type": "WebPage",
            "@id": `${SITE_URL}${ruta}#webpage`,
            url: `${SITE_URL}${ruta}`,
            name: contenido.title,
            description: contenido.description,
            inLanguage: "es-MX",
            about: { "@id": `${SITE_URL}/#software` },
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
      `<script id="turnouno-route-jsonld" type="application/ld+json">${JSON.stringify(seo.jsonLd).replace(/</g, "\\u003c")}</script>`,
    );
  }
  return tags.join("\n");
}
