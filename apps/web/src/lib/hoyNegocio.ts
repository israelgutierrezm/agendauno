import { fechaLocal } from "@/lib/agenda";

/**
 * «Hoy» en el negocio (AAAA-MM-DD), con su zona horaria (ADR 0099), no con la del
 * navegador de quien mira: a las 23:30 en Monterrey, alguien en Madrid ya está en
 * mañana, pero el negocio sigue en hoy. Lo usan los controles de «Hoy», los días por
 * omisión de reportes y cortes, y lo que se pide al servidor «de hoy».
 */
export function hoyEnNegocio(zona: string, ahora: Date = new Date()): string {
  return fechaLocal(ahora.toISOString(), zona);
}

/** El primer día del mes en curso del negocio (AAAA-MM-01). */
export function inicioDeMesEnNegocio(
  zona: string,
  ahora: Date = new Date(),
): string {
  return `${hoyEnNegocio(zona, ahora).slice(0, 8)}01`;
}

/**
 * «Hoy» del negocio como fecha local a mediodía, para los calendarios que cuentan
 * días con `Date` (semanas, meses): mediodía evita brincos por cambio de horario.
 */
export function hoyComoFecha(zona: string, ahora: Date = new Date()): Date {
  return new Date(`${hoyEnNegocio(zona, ahora)}T12:00:00`);
}
