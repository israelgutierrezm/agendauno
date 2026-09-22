interface SeoOptions {
  title: string;
  description: string;
  path?: string;
  image?: string;
  type?: "website" | "profile";
  jsonLd?: Record<string, unknown> | null;
}

const SITE_URL = "https://agendauno.mx";
const DEFAULT_IMAGE = `${SITE_URL}/assets/landing/turnouno-calendar.webp`;

function upsertMeta(
  selector: string,
  attribute: "name" | "property",
  key: string,
  content: string,
): void {
  let element = document.head.querySelector<HTMLMetaElement>(selector);
  if (element === null) {
    element = document.createElement("meta");
    element.setAttribute(attribute, key);
    document.head.appendChild(element);
  }
  element.content = content;
}

function upsertCanonical(url: string): void {
  let element = document.head.querySelector<HTMLLinkElement>(
    'link[rel="canonical"]',
  );
  if (element === null) {
    element = document.createElement("link");
    element.rel = "canonical";
    document.head.appendChild(element);
  }
  element.href = url;
}

export function updateSeo(options: SeoOptions): void {
  const canonical = new URL(options.path ?? "/", SITE_URL).toString();
  const image = options.image ?? DEFAULT_IMAGE;

  document.title = options.title;
  upsertMeta(
    'meta[name="description"]',
    "name",
    "description",
    options.description,
  );
  upsertMeta(
    'meta[property="og:title"]',
    "property",
    "og:title",
    options.title,
  );
  upsertMeta(
    'meta[property="og:description"]',
    "property",
    "og:description",
    options.description,
  );
  upsertMeta(
    'meta[property="og:type"]',
    "property",
    "og:type",
    options.type ?? "website",
  );
  upsertMeta('meta[property="og:url"]', "property", "og:url", canonical);
  upsertMeta('meta[property="og:image"]', "property", "og:image", image);
  upsertMeta(
    'meta[name="twitter:card"]',
    "name",
    "twitter:card",
    "summary_large_image",
  );
  upsertMeta(
    'meta[name="twitter:title"]',
    "name",
    "twitter:title",
    options.title,
  );
  upsertMeta(
    'meta[name="twitter:description"]',
    "name",
    "twitter:description",
    options.description,
  );
  upsertMeta('meta[name="twitter:image"]', "name", "twitter:image", image);
  upsertCanonical(canonical);

  document.head.querySelector("#turnouno-route-jsonld")?.remove();
  if (options.jsonLd) {
    const script = document.createElement("script");
    script.id = "turnouno-route-jsonld";
    script.type = "application/ld+json";
    script.textContent = JSON.stringify(options.jsonLd);
    document.head.appendChild(script);
  }
}

export const DEFAULT_SEO = {
  title: "AgendaUno | Software para estudios y academias",
  description:
    "Gestiona agenda, reservas, membresías, cobros y asistencia desde un solo lugar. Prueba AgendaUno gratis durante 14 días, sin tarjeta.",
} as const;
