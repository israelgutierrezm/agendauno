import { api } from "@/lib/api";

/**
 * Reserva con Google (ADR 0198): quien llega desde Google trae un `rwg_token` en la
 * dirección. Se guarda 30 días por negocio en este navegador y, cuando reserva, se le
 * avisa a Google (por el servidor) y se olvida. Solo el token: ningún dato de la persona.
 */
const DIAS = 30;
const clave = (slug: string): string => `rwg:${slug.toLowerCase()}`;

export function capturarRwgToken(slug: string | null, token: unknown): void {
  if (
    !slug ||
    typeof token !== "string" ||
    token === "" ||
    token.length > 1000
  ) {
    return;
  }
  try {
    localStorage.setItem(
      clave(slug),
      JSON.stringify({ token, hasta: Date.now() + DIAS * 86_400_000 }),
    );
  } catch {
    // Sin almacenamiento (ventana privada): no se mide.
  }
}

/** Tras una reserva en ese negocio: avisa a Google si se llegó desde ahí. */
export function avisarConversionGoogle(slug: string): void {
  let guardado: { token?: string; hasta?: number } | null = null;
  try {
    guardado = JSON.parse(localStorage.getItem(clave(slug)) ?? "null");
    localStorage.removeItem(clave(slug));
  } catch {
    return;
  }
  if (
    !guardado?.token ||
    typeof guardado.hasta !== "number" ||
    guardado.hasta < Date.now()
  ) {
    return;
  }
  void api
    .post(`/api/v1/app/${slug}/reserva-con-google/conversion`, {
      rwg_token: guardado.token,
    })
    .catch(() => undefined);
}
