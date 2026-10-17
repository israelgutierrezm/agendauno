import { useI18n } from "vue-i18n";

/**
 * Los avisos del negocio (los eventos que disparan un mensaje automático, p. ej.
 * «reserva.recordatorio_24h») con su nombre en palabras y el área donde se listan.
 * La llave técnica nunca se muestra: un evento nuevo sin texto se lee legible
 * («Pago anulado») mientras le ponen su nombre.
 */
export const AREAS_EVENTO = [
  "reservas",
  "pagos",
  "cuenta",
  "prospectos",
  "otros",
] as const;
export type AreaEvento = (typeof AREAS_EVENTO)[number];

// Por la primera parte de la llave («pago.anulado» → pago).
const AREA_POR_PREFIJO: Record<string, AreaEvento> = {
  reserva: "reservas",
  asistencia: "reservas",
  acceso: "reservas",
  orden: "pagos",
  pago: "pagos",
  cobro: "pagos",
  membresia: "pagos",
  factura: "pagos",
  pago_automatico: "pagos",
  cuenta: "cuenta",
  privacidad: "cuenta",
  resena: "cuenta",
  prospecto: "prospectos",
};

export function areaDeEvento(clave: string): AreaEvento {
  return AREA_POR_PREFIJO[clave.split(".")[0] ?? ""] ?? "otros";
}

/** «pago.anulado» → «Pago anulado». */
export function eventoLegible(clave: string): string {
  const texto = clave.replace(/[._]+/g, " ").trim();
  return texto.charAt(0).toUpperCase() + texto.slice(1);
}

/** Los eventos agrupados por área, en el orden de las áreas y del servidor. */
export function eventosPorArea(
  claves: string[],
): { area: AreaEvento; eventos: string[] }[] {
  return AREAS_EVENTO.map((area) => ({
    area,
    eventos: claves.filter((c) => areaDeEvento(c) === area),
  })).filter((g) => g.eventos.length > 0);
}

export function useEventosNegocio() {
  const { t, te } = useI18n();
  function nombreEvento(clave: string): string {
    const llave = `comunicacionPantalla.eventos.${clave}`;
    return te(llave) ? t(llave) : eventoLegible(clave);
  }
  return { nombreEvento };
}
