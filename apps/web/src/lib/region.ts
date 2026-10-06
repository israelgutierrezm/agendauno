/**
 * Zonas horarias que se ofrecen al elegir la del negocio o la de una sucursal: las de
 * México primero (la del centro, por omisión) y algunas de la región. El nombre de
 * cada una está en `region.zonas` (i18n).
 */
export const ZONA_POR_OMISION = "America/Mexico_City";

export const ZONAS_HORARIAS = [
  "America/Mexico_City",
  "America/Cancun",
  "America/Merida",
  "America/Monterrey",
  "America/Chihuahua",
  "America/Mazatlan",
  "America/Hermosillo",
  "America/Tijuana",
  "America/Guatemala",
  "America/Costa_Rica",
  "America/Santo_Domingo",
  "America/Bogota",
  "America/Lima",
  "America/Santiago",
  "America/Argentina/Buenos_Aires",
  "America/New_York",
  "America/Los_Angeles",
  "Europe/Madrid",
] as const;

/** Las zonas a ofrecer: las de la lista y, si ya tiene otra, también esa. */
export function zonasConActual(actual: string | null | undefined): string[] {
  const lista: string[] = [...ZONAS_HORARIAS];
  if (actual && !lista.includes(actual)) {
    lista.push(actual);
  }
  return lista;
}

/** «America/Mexico_City» → clave i18n segura (los «/» no van en claves). */
export function claveZona(zona: string): string {
  return zona.replace(/\//g, "__");
}
