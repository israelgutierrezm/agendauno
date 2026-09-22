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
