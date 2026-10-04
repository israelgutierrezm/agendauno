/**
 * Planes y paquetes (ADR 0050): lo que el negocio vende para reservar. La vigencia
 * se calcula igual que en la API (`VigenciaProducto`), para mostrar "hasta cuándo"
 * mientras se edita; el último día cuenta.
 */
export type TipoPlan =
  | "paquete"
  | "membresia"
  | "sesion_individual"
  | "add_on"
  | "pase_dia"
  | "taller";
export type TipoVigencia = "dias" | "meses" | "fin_de_mes";

export interface Plan {
  id: string;
  nombre: string;
  tipo: TipoPlan;
  precio_minor: number;
  moneda: string;
  ilimitado: boolean;
  creditos_incluidos: number | null;
  vigencia_tipo: TipoVigencia | null;
  vigencia_cantidad: number | null;
  vence_si_compra_hoy?: string | null;
  politica_reset: string;
  unidades_por_ciclo: number | null;
  politica_rollover: string;
  rollover_max: number | null;
  sucursal_id: string | null;
  sucursales?: { id: string; nombre: string }[];
  todas_sucursales?: boolean;
  archivado: boolean;
  ofertas: { id: string; nombre: string }[];
}

/** 1000 unidades = 1 clase. */
export const UNIDADES_POR_CLASE = 1000;

/** Sección de la pantalla en que va cada tipo. */
export function seccionDe(tipo: TipoPlan): string {
  return tipo === "pase_dia" || tipo === "taller" ? "otro" : tipo;
}
export const SECCIONES = [
  "paquete",
  "membresia",
  "sesion_individual",
  "add_on",
  "otro",
] as const;

function isoDe(d: Date): string {
  const p = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

/** Último día (AAAA-MM-DD) en que se puede usar lo comprado en `compra`. */
export function vigenteHasta(
  tipo: TipoVigencia,
  cantidad: number,
  compra: Date,
): string {
  const d = new Date(compra.getFullYear(), compra.getMonth(), compra.getDate());
  if (tipo === "dias") {
    return isoDe(
      new Date(d.getFullYear(), d.getMonth(), d.getDate() + cantidad),
    );
  }
  if (tipo === "meses") {
    // Sin desbordar: del 31 de enero, 1 mes → último día de febrero.
    const destino = new Date(d.getFullYear(), d.getMonth() + cantidad, 1);
    const ultimo = new Date(
      destino.getFullYear(),
      destino.getMonth() + 1,
      0,
    ).getDate();
    return isoDe(
      new Date(
        destino.getFullYear(),
        destino.getMonth(),
        Math.min(d.getDate(), ultimo),
      ),
    );
  }
  // Hasta el último día del mes de compra (1) o de los siguientes.
  return isoDe(new Date(d.getFullYear(), d.getMonth() + cantidad, 0));
}

/** "14 de noviembre" a partir de "2026-11-14". */
export function fechaLarga(iso: string): string {
  const [a, m, d] = iso.split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
  }).format(new Date(a, m - 1, d));
}

/** "14 oct" a partir de "2026-10-14". */
export function fechaCorta(iso: string | null): string {
  if (!iso) {
    return "—";
  }
  const [a, m, d] = iso.slice(0, 10).split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
  }).format(new Date(a, m - 1, d));
}

export function clasesDe(unidades: number | null): number {
  return Math.round((unidades ?? 0) / UNIDADES_POR_CLASE);
}

export function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
