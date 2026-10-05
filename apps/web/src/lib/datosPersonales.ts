/**
 * Fecha de nacimiento y género de una persona (opcionales). La lista de géneros es
 * breve e incluyente y coincide con `GeneroPersona` del API.
 */
export const GENEROS = [
  "mujer",
  "hombre",
  "no_binario",
  "otro",
  "prefiero_no_decir",
] as const;
export type Genero = (typeof GENEROS)[number];

/** Años cumplidos a una fecha (AAAA-MM-DD); null si no hay fecha. */
export function edadDe(
  nacimiento: string | null | undefined,
  hoy: Date = new Date(),
): number | null {
  if (!nacimiento) {
    return null;
  }
  const [a, m, d] = nacimiento.slice(0, 10).split("-").map(Number);
  let edad = hoy.getFullYear() - a;
  if (
    hoy.getMonth() + 1 < m ||
    (hoy.getMonth() + 1 === m && hoy.getDate() < d)
  ) {
    edad -= 1;
  }
  return edad;
}

/** «14 mar 1994» (fecha de calendario, sin zona). */
export function fechaNacimientoTexto(nacimiento: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(`${nacimiento.slice(0, 10)}T12:00:00`));
}

/** El día de hoy (local) como AAAA-MM-DD: tope del campo de fecha. */
export function hoyIso(hoy: Date = new Date()): string {
  const dos = (n: number) => String(n).padStart(2, "0");
  return `${hoy.getFullYear()}-${dos(hoy.getMonth() + 1)}-${dos(hoy.getDate())}`;
}
