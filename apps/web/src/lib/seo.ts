import {
  SITE_URL,
  DEFAULT_IMAGE,
  type SeoOptions,
} from "@/marketing/seoConfig";

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
    'meta[name="robots"]',
    "name",
    "robots",
    options.index === false
      ? "noindex,follow"
      : "index,follow,max-image-preview:large",
  );
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
  if (options.index === false) {
    document.head.querySelector('link[rel="canonical"]')?.remove();
    document.head.querySelector('meta[property="og:url"]')?.remove();
  } else upsertCanonical(canonical);

  document.head.querySelector("#agendauno-route-jsonld")?.remove();
  if (options.jsonLd) {
    const script = document.createElement("script");
    script.id = "agendauno-route-jsonld";
    script.type = "application/ld+json";
    script.textContent = JSON.stringify(options.jsonLd);
    document.head.appendChild(script);
  }
}
