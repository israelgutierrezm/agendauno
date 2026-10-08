/**
 * Modelo comercial (ADR 0107): el plan de un negocio de citas y lo que la API manda
 * de él en `GET /renta`. Los precios vienen en minor y en la moneda de la tarifa
 * (USD); a México se le cobra en pesos al tipo de cambio del día del cobro.
 */
export const NIVELES_PLAN = ["individual", "premium", "pro"] as const;
export type NivelPlan = (typeof NIVELES_PLAN)[number];

export interface TipoCambio {
  valor: string;
  fecha: string;
  fuente: string;
}

export interface PlanCitas {
  nivel: NivelPlan;
  profesionales: number;
  periodicidad: "mensual" | "anual";
  elegido: boolean;
  en_prueba: boolean;
  cubierto_hasta: string | null;
  siguiente: {
    nivel: NivelPlan;
    profesionales: number;
    periodicidad: "mensual" | "anual";
  } | null;
  profesionales_actuales: number;
  limite_profesionales: number | null;
  max_profesionales: number;
  meses_anual: number;
  moneda_tarifa: string;
  moneda_cobro: string;
  iva_porcentaje: number;
  tipo_cambio: TipoCambio | null;
  /** Precio mensual por nivel y profesionales (`{"premium": {"2": 2400}}`). */
  precios: Partial<Record<NivelPlan, Record<string, number>>>;
}

/** El precio mensual de un nivel con esos profesionales (null: no se vende). */
export function precioPlan(
  plan: Pick<PlanCitas, "precios">,
  nivel: NivelPlan,
  profesionales: number,
): number | null {
  const precio = plan.precios[nivel]?.[String(profesionales)];
  return typeof precio === "number" ? precio : null;
}

/** Las funciones que no tiene el plan del negocio (las manda `/yo`). */
export type FuncionPlan =
  | "equipo"
  | "sucursales"
  | "recursos"
  | "paquetes"
  | "inventario"
  | "comisiones"
  | "promociones"
  | "documentos"
  | "facturacion"
  | "cobro_automatico"
  | "venta_en_linea"
  | "formularios"
  | "lealtad"
  | "mensajes"
  | "integraciones"
  | "roles_propios"
  | "reportes_avanzados";
