/**
 * Resolución del estudio (tenant) a partir del DOMINIO. En producción cada estudio
 * vive en su subdominio `{slug}.agendauno.mx`; el backend ya resuelve el tenant por
 * subdominio, así que aquí solo derivamos el slug del host para adaptar la SPA
 * (aterrizaje directo al estudio, login pre-fijado, QR con la URL corta).
 *
 * En desarrollo (localhost) se admiten dos formas equivalentes: `{slug}.localhost`
 * y el parámetro `?estudio={slug}` (fallback cómodo cuando no hay subdominios).
 */

// Dominio público de la app; el mismo que usa el QR/enlace del estudio.
export const DOMINIO_PUBLICO =
  (import.meta.env.VITE_DOMINIO_PUBLICO as string | undefined) ??
  "agendauno.mx";

// Subdominios que NO son un estudio (marketing/infra).
const RESERVADOS = new Set(["", "www", "app", "api", "admin", "staging"]);

/**
 * Slug del estudio derivado del subdominio (`{slug}.agendauno.mx` en prod o
 * `{slug}.localhost` en dev), o `null` si estamos en el dominio raíz o en un
 * subdominio reservado. Solo un nivel de subdominio (sin puntos internos).
 */
export function slugDeSubdominio(
  host: string = window.location.hostname,
): string | null {
  const h = host.toLowerCase().split(":")[0];

  const sufijos = [".localhost", `.${DOMINIO_PUBLICO.toLowerCase()}`];
  for (const sufijo of sufijos) {
    if (h.endsWith(sufijo)) {
      const sub = h.slice(0, -sufijo.length);
      if (sub !== "" && !sub.includes(".") && !RESERVADOS.has(sub)) {
        return sub;
      }
      return null;
    }
  }
  return null;
}

/**
 * Slug del estudio en contexto: el subdominio, o el fallback dev `?estudio={slug}`.
 * `null` cuando estamos en el dominio raíz sin estudio en contexto.
 */
export function slugDeContexto(
  host: string = window.location.hostname,
  search: string = window.location.search,
): string | null {
  const sub = slugDeSubdominio(host);
  if (sub !== null) {
    return sub;
  }
  const q = new URLSearchParams(search).get("estudio");
  return q !== null && q.trim() !== "" ? q.trim() : null;
}

/** ¿La SPA se está sirviendo desde el subdominio real de un estudio? */
export function enSubdominioDeEstudio(host?: string): boolean {
  return slugDeSubdominio(host) !== null;
}

/** URL pública corta del estudio en forma de subdominio: `{slug}.agendauno.mx`. */
export function urlPublicaEstudio(slug: string): string {
  return `${slug}.${DOMINIO_PUBLICO}`;
}

/**
 * Dirección canónica de una página del negocio: vive en su subdominio (en el dominio
 * principal, esas rutas redirigen ahí). `ruta` es la de dentro del subdominio.
 */
export function urlCanonicaEstudio(slug: string, ruta = "/"): string {
  return `https://${urlPublicaEstudio(slug)}${ruta}`;
}

/**
 * ¿Es el dominio principal de producción (`agendauno.mx` o `www.agendauno.mx`)? En
 * desarrollo (localhost) y en el subdominio de un negocio, no.
 */
export function esDominioPrincipal(
  host: string = window.location.hostname,
): boolean {
  const h = host.toLowerCase().split(":")[0];
  const dominio = DOMINIO_PUBLICO.toLowerCase();
  return h === dominio || h === `www.${dominio}`;
}

/**
 * Rutas del dominio principal que llevan el slug de un negocio, y a qué ruta de su
 * subdominio equivalen. `/agendar/:slug` no está: la generan el API y los correos.
 * La página del negocio conserva `/estudio/{slug}` (existe en el subdominio): así
 * no se brinca la página de un negocio de citas ni se pierde el ancla (`#precios`).
 */
const RUTAS_CON_SLUG_EN_SUBDOMINIO: Record<string, (slug: string) => string> = {
  "estudio-corto": () => "/",
  "estudio-publico": (slug) => `/estudio/${slug}`,
  "enlaces-estudio": () => "/enlaces",
  "aviso-negocio": (slug) => `/estudio/${slug}/aviso-de-privacidad`,
};

// Un slug que sirve como subdominio: letras, números y guiones, sin puntos.
const SLUG_DE_SUBDOMINIO = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

/**
 * Los negocios viven solo en su subdominio: en el dominio principal de producción, el
 * enlace corto (`/{slug}`), su página (`/estudio/{slug}`) y sus enlaces
 * (`/{slug}/enlaces`) van a `https://{slug}.agendauno.mx` con el resto de la ruta, la
 * query y el ancla. `null` si no aplica: otra ruta, desarrollo (localhost), el
 * subdominio de un negocio o un slug que no puede ser subdominio.
 */
export function urlEnSubdominioDelNegocio(
  ruta: {
    name?: unknown;
    params: Record<string, unknown>;
    fullPath: string;
  },
  host: string = window.location.hostname,
): string | null {
  const resto = RUTAS_CON_SLUG_EN_SUBDOMINIO[String(ruta.name ?? "")];
  if (resto === undefined || !esDominioPrincipal(host)) {
    return null;
  }
  const slug = String(ruta.params.slug ?? "").toLowerCase();
  if (!SLUG_DE_SUBDOMINIO.test(slug) || RESERVADOS.has(slug)) {
    return null;
  }
  const queryYAncla = ruta.fullPath.replace(/^[^?#]*/, "");
  return `https://${slug}.${DOMINIO_PUBLICO}${resto(slug)}${queryYAncla}`;
}

/** Sale de la SPA a otra dirección (p. ej. el subdominio del negocio). */
export function salirA(url: string): void {
  window.location.replace(url);
}

type Ubicacion = Pick<Location, "protocol" | "hostname" | "port">;

/**
 * Origen del dominio raíz, sin el subdominio del negocio y con el mismo protocolo y
 * puerto: `https://agendauno.mx` desde `https://barberia.agendauno.mx`, o
 * `http://localhost:5175` desde `http://barberia.localhost:5175`. Fuera de un
 * subdominio de negocio, el origen actual.
 */
export function origenDominioRaiz(
  ubicacion: Ubicacion = window.location,
): string {
  const host = ubicacion.hostname.toLowerCase();
  const sub = slugDeSubdominio(host);
  const raiz = sub === null ? host : host.slice(sub.length + 1);
  const puerto = ubicacion.port !== "" ? `:${ubicacion.port}` : "";
  return `${ubicacion.protocol}//${raiz}${puerto}`;
}

/**
 * «Entrar» de ese negocio en el dominio raíz (`/entrar?estudio={slug}`), con la ruta
 * interna a la que volver después. Lo que solo funciona en el dominio raíz (Google)
 * se manda ahí desde el subdominio.
 */
export function urlEntrarEnDominioRaiz(
  slug: string,
  volver: string | null = null,
  ubicacion: Ubicacion = window.location,
): string {
  const query = new URLSearchParams({ estudio: slug });
  if (volver !== null && /^\/(?![/\\])/.test(volver)) {
    query.set("volver", volver);
  }
  return `${origenDominioRaiz(ubicacion)}/entrar?${query.toString()}`;
}
