import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";

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
  /** Qué nivel abre cada función (lo fija el superadmin en la tarifa). */
  funciones?: Partial<Record<string, string>>;
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

/**
 * Lo que todos los planes de citas incluyen (no depende del nivel). Solo lo que existe
 * hoy: los recordatorios van por correo (la app aún no está publicada) y la página de
 * cada negocio vive en el dominio de su producto (ADR 0108: citas → TurnoUno).
 */
export const FUNCIONES_BASE = [
  "Agenda y citas",
  "Recordatorios por correo",
  `Tu página en tunegocio.${PRODUCTOS[productoDeModalidad("citas")].dominio}`,
  "Cobro al agendar en línea* y en caja",
  "Clientes, reseñas y reportes básicos",
] as const;

/**
 * El nombre de cada función que depende del nivel (ADR 0107), en el orden en que se
 * presenta. Las que solo operan en México llevan «*».
 */
export const ETIQUETAS_FUNCION: Record<FuncionPlan, string> = {
  equipo: "Equipo y roles",
  sucursales: "Varias sucursales",
  recursos: "Cabinas y recursos",
  paquetes: "Paquetes y membresías",
  promociones: "Promociones",
  inventario: "Mostrador e inventario",
  comisiones: "Comisiones y nómina",
  documentos: "Documentos y consentimientos",
  facturacion: "Facturación electrónica*",
  cobro_automatico: "Cobro automático de membresías*",
  venta_en_linea: "Venta en línea de paquetes*",
  formularios: "Formularios personalizables",
  lealtad: "Programa de lealtad",
  mensajes: "Mensajes masivos por correo",
  integraciones: "Integraciones y API",
  roles_propios: "Roles propios",
  reportes_avanzados: "Reportes avanzados",
};

/**
 * El nivel que abre cada función si la tarifa no dice otro: el mismo reparto de
 * siempre del servidor (`FuncionesPlan::NIVEL_MINIMO`).
 */
export const NIVEL_POR_OMISION: Record<FuncionPlan, NivelPlan> = {
  equipo: "premium",
  sucursales: "premium",
  recursos: "premium",
  paquetes: "premium",
  promociones: "premium",
  inventario: "premium",
  comisiones: "premium",
  documentos: "premium",
  facturacion: "pro",
  cobro_automatico: "pro",
  venta_en_linea: "pro",
  formularios: "pro",
  lealtad: "pro",
  mensajes: "pro",
  integraciones: "pro",
  roles_propios: "pro",
  reportes_avanzados: "pro",
};

/**
 * Qué incluye cada nivel según el reparto vigente (`funcion → nivel que la abre`,
 * lo fija el superadmin en la tarifa): Individual lo de todos más lo que abre;
 * Premium y Pro, «todo lo del anterior» más lo suyo. Sin «*» si `conAsterisco` es
 * falso (dentro del panel, donde ya se sabe el país).
 */
export function funcionesPorNivel(
  mapa: Partial<Record<string, string>>,
  conAsterisco = true,
): Record<NivelPlan, string[]> {
  const limpia = (texto: string) =>
    conAsterisco ? texto : texto.replace("*", "");
  const de = (nivel: NivelPlan) =>
    (Object.keys(ETIQUETAS_FUNCION) as FuncionPlan[])
      .filter((f) => (mapa[f] ?? NIVEL_POR_OMISION[f]) === nivel)
      .map((f) => limpia(ETIQUETAS_FUNCION[f]));
  return {
    individual: [...FUNCIONES_BASE.map(limpia), ...de("individual")],
    premium: ["Todo lo de Individual", ...de("premium")],
    pro: ["Todo lo de Premium", ...de("pro")],
  };
}
