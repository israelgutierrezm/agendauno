/**
 * La caja de cada sucursal (ADR 0175): sus turnos, conteos y movimientos, como los
 * devuelve el API. La usan la operación del día (CajaView) y los cierres (Cobros ›
 * Cierres de caja, CierresCajaView).
 */
export type Metodo = "efectivo" | "tarjeta" | "transferencia" | "ventanilla";
export const METODOS: Metodo[] = [
  "efectivo",
  "tarjeta",
  "transferencia",
  "ventanilla",
];

export interface Movimiento {
  id: string;
  tipo: string;
  metodo: Metodo;
  monto_minor: number;
  motivo: string | null;
  quien: string | null;
  fecha: string | null;
}
export interface Conteo {
  metodo: Metodo;
  esperado_minor: number;
  contado_minor: number;
  diferencia_minor: number;
}
export interface Turno {
  id: string;
  sucursal: { id: string | null; nombre: string | null };
  estado: "abierta" | "contada" | "cerrada";
  moneda: string;
  fondo_minor: number;
  abierta_por: string | null;
  abierta_en: string;
  cerrada_por: string | null;
  cerrada_en: string | null;
  diferencia_minor: number | null;
  motivo_diferencia: string | null;
  requiere_revision: boolean;
  revisada_por: string | null;
  revisada_en: string | null;
  nota_revision: string | null;
  esperado: Record<Metodo, number> | null;
  conteos: Conteo[];
  movimientos: Movimiento[];
}
