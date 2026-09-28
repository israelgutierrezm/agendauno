/**
 * Un parámetro configurable (ADR 0042) tal como lo entrega el API, para el negocio
 * (con `plataforma`: lo que aplica si no pone el suyo) o para el superadmin (con
 * `defecto`: el valor inicial). `valor` null = no ajustado.
 */
export interface Parametro {
  clave: string;
  grupo: string;
  etiqueta: string;
  ayuda: string;
  tipo: "entero" | "si_no";
  minimo: number;
  maximo: number;
  unidad: string;
  /** Si trae valores, solo se acepta uno de ellos (p. ej. IVA 16 u 8). */
  opciones?: number[];
  valor: number | null;
  plataforma?: number;
  defecto?: number;
}
