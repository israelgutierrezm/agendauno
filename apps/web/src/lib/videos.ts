/**
 * Biblioteca de videos del negocio (ADR 0184). Solo se guardan enlaces: YouTube y Vimeo
 * se muestran dentro de la página con su reproductor; cualquier otro sitio se abre
 * aparte. Nunca se inserta código que haya escrito alguien.
 */
export interface Video {
  id: string;
  titulo: string;
  descripcion: string | null;
  url: string | null;
  duracion_minutos: number | null;
  categoria: string | null;
  publicado: boolean;
  orden: number;
  planes: { id: string; nombre: string }[];
  /** Para el cliente: si su plan lo incluye (si no, no llega el enlace). */
  disponible?: boolean;
}

/** El reproductor de un enlace de YouTube o Vimeo; null si es de otro sitio. */
export function reproductorDe(url: string | null | undefined): string | null {
  if (!url) {
    return null;
  }
  let u: URL;
  try {
    u = new URL(url);
  } catch {
    return null;
  }
  if (u.protocol !== "https:") {
    return null;
  }
  const host = u.hostname.replace(/^www\./, "").replace(/^m\./, "");
  const valido = (id: string | null | undefined): id is string =>
    typeof id === "string" && /^[\w-]{6,20}$/.test(id);
  if (host === "youtube.com") {
    const id = u.pathname.startsWith("/shorts/")
      ? u.pathname.split("/")[2]
      : u.searchParams.get("v");
    return valido(id) ? `https://www.youtube-nocookie.com/embed/${id}` : null;
  }
  if (host === "youtu.be") {
    const id = u.pathname.slice(1);
    return valido(id) ? `https://www.youtube-nocookie.com/embed/${id}` : null;
  }
  if (host === "vimeo.com") {
    const id = u.pathname.split("/").filter(Boolean)[0];
    return id && /^\d{4,12}$/.test(id)
      ? `https://player.vimeo.com/video/${id}`
      : null;
  }
  return null;
}

/** Agrupa por categoría, en el orden en que llegan («» = sin categoría, al final). */
export function porCategoria(videos: Video[]): [string, Video[]][] {
  const grupos = new Map<string, Video[]>();
  for (const v of videos) {
    const clave = v.categoria?.trim() ?? "";
    grupos.set(clave, [...(grupos.get(clave) ?? []), v]);
  }
  return [...grupos.entries()].sort(([a], [b]) =>
    a === "" ? 1 : b === "" ? -1 : 0,
  );
}
