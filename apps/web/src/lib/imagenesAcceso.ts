/**
 * Imágenes de las tarjetas de acceso (ver «Imágenes para las tarjetas de acceso»):
 * se dejan en `src/assets/accesos/` con su nombre (p. ej. `acceso-agenda.webp`) y
 * Vite las incluye solas. Mientras no existan, la tarjeta usa su ilustración.
 */
const ARCHIVOS = import.meta.glob<string>("../assets/accesos/*.{webp,png}", {
  eager: true,
  query: "?url",
  import: "default",
});

/** Nombre sin extensión → URL publicada. */
const DISPONIBLES: Record<string, string> = Object.fromEntries(
  Object.entries(ARCHIVOS).map(([ruta, url]) => [
    ruta.replace(/^.*\//, "").replace(/\.(webp|png)$/, ""),
    url,
  ]),
);

/**
 * La primera imagen que exista de las candidatas, en orden (p. ej. la de su giro y
 * luego la general). Null si ninguna: se usa la ilustración.
 */
export function imagenAcceso(
  candidatas: string | string[] | undefined,
  disponibles: Record<string, string> = DISPONIBLES,
): string | null {
  for (const nombre of Array.isArray(candidatas)
    ? candidatas
    : candidatas
      ? [candidatas]
      : []) {
    if (disponibles[nombre] !== undefined) {
      return disponibles[nombre];
    }
  }
  return null;
}
