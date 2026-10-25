/**
 * Precios públicos de la suscripción (ADR 0107). La fuente es lo que publica el
 * superadmin: la landing los lee de `GET /api/v1/precios` (`preciosPublicos.ts`) y la
 * contratación, de la misma tarifa vigente. El respaldo es lo que muestra el HTML
 * pre-generado (buscadores) y la página mientras llega la respuesta:
 * - al pre-generar con `PRECIOS_API_URL`, lo publicado en ese momento
 *   (`scripts/build-marketing.mjs` lo pasa en `VITE_PRECIOS_PUBLICADOS`);
 * - si no, `precios-respaldo.json`, que una prueba del API compara con la tarifa que
 *   siembran las migraciones (PreciosRespaldoCoherentesTest).
 * Importes en centavos de dólar.
 */
// Relativo y con extensión: lo importa `vite.config` (por seoConfig), sin alias `@/`.
import type { Producto } from "../lib/producto.ts";
import respaldo from "./precios-respaldo.json" with { type: "json" };

export type NivelCitas = "individual" | "premium" | "pro";

export interface Banda {
  hasta: number | null;
  monto_minor: number;
}

export interface PreciosPublicos {
  clases: {
    moneda: string;
    /** Desde cuándo rige la tarifa (AAAA-MM-DD): la misma que ve la contratación. */
    vigente_desde?: string | null;
    /** IVA en México sobre la suscripción. */
    iva_porcentaje?: number;
    dias_prueba: number;
    bandas: Banda[];
  };
  citas: {
    moneda: string;
    vigente_desde?: string | null;
    iva_porcentaje?: number;
    dias_prueba: number;
    meses_anual: number;
    niveles: Partial<Record<NivelCitas, Record<string, number>>> | null;
    funciones: Partial<Record<string, string>> | null;
  };
  ventas: {
    correo: string | null;
    whatsapp: string | null;
    /** El correo con que cotiza cada marca: el suyo o el general (ADR 0108). */
    correo_por_producto?: Partial<Record<Producto, string | null>>;
  };
  timbres: { moneda: string; precio_minor: number; paquetes: number[] };
  /**
   * ¿Qué producto recibe registros de negocios? (ADR 0108). El superadmin puede cerrar
   * el de un producto: mientras, su landing junta interesados (prelanzamiento).
   */
  registro: { agendauno: boolean; turnouno: boolean };
  /**
   * El último tipo de cambio FIX conocido (pesos por dólar, en diezmilésimas), para
   * mostrar el equivalente en pesos con IVA en México. Null sin uno.
   */
  tipo_cambio?: TipoCambio | null;
  /** Lo que se cobra aparte, con precio publicado (ADR 0188), en centavos de peso. */
  servicios?: {
    arranque_minor: number;
    arranque_migracion_minor: number;
    app_marca_alta_minor: number;
    app_marca_mensual_minor: number;
    moneda: string;
  };
  referidos?: { meses_gratis: number };
}

export interface TipoCambio {
  diezmilesimas: number;
  /** AAAA-MM-DD */
  fecha: string;
}

/** Lo publicado al pre-generar, si se pidió (en la aplicación y en las pruebas, no). */
function publicadosAlGenerar(): Partial<PreciosPublicos> | null {
  // Sin `import.meta.env` cuando lo importa `vite.config` (Node).
  const json = (import.meta as { env?: Record<string, string | undefined> }).env
    ?.VITE_PRECIOS_PUBLICADOS;
  if (!json) return null;
  try {
    return JSON.parse(json) as Partial<PreciosPublicos>;
  } catch {
    return null;
  }
}

/**
 * El respaldo: lo publicado al pre-generar o, sin eso, `precios-respaldo.json` (la
 * tarifa que siembran las migraciones; los dos productos reciben registros).
 */
export const PRECIOS_POR_OMISION: PreciosPublicos = (() => {
  const base = respaldo as PreciosPublicos;
  const publicados = publicadosAlGenerar();
  return publicados === null
    ? base
    : {
        clases: { ...base.clases, ...publicados.clases },
        citas: { ...base.citas, ...publicados.citas },
        ventas: { ...base.ventas, ...publicados.ventas },
        timbres: { ...base.timbres, ...publicados.timbres },
        registro: { ...base.registro, ...publicados.registro },
        tipo_cambio: publicados.tipo_cambio ?? null,
        // Lo que se cobra aparte y los referidos: también lo publicado, para que el
        // HTML pre-generado no diga los precios viejos.
        servicios: publicados.servicios ?? base.servicios,
        referidos: publicados.referidos ?? base.referidos,
      };
})();

const numero = (n: number) => n.toLocaleString("es-MX");

/**
 * Los rangos de alumnos con precio (sin el techo): «Hasta 40 alumnos activos»,
 * «41–60 alumnos activos»… El último rango con tope es desde donde se cotiza.
 */
export function rangosClases(
  bandas: Banda[],
): { capacidad: string; subtotal: number }[] {
  const conTope = bandas.filter(
    (b): b is { hasta: number; monto_minor: number } => b.hasta !== null,
  );
  return conTope.map((b, i) => ({
    capacidad:
      i === 0
        ? `Hasta ${numero(b.hasta)} alumnos activos`
        : `${numero(conTope[i - 1]!.hasta + 1)}–${numero(b.hasta)} alumnos activos`,
    subtotal: b.monto_minor,
  }));
}

/** Más alumnos que esto: cotización (el último rango con tope). */
export function maxAlumnos(bandas: Banda[]): number | null {
  const topes = bandas
    .map((b) => b.hasta)
    .filter((h): h is number => h !== null);
  return topes.length > 0 ? Math.max(...topes) : null;
}

/** Premium y Pro por profesionales, en orden. */
export function tablaCitas(
  niveles: PreciosPublicos["citas"]["niveles"],
): { profesionales: number; premium: number; pro: number }[] {
  const premium = niveles?.premium ?? {};
  const pro = niveles?.pro ?? {};
  return Object.keys(premium)
    .map(Number)
    .sort((a, b) => a - b)
    .map((n) => ({
      profesionales: n,
      premium: premium[String(n)] ?? 0,
      pro: pro[String(n)] ?? 0,
    }));
}

/** El precio de entrada de cada nivel (Individual con 1; Premium y Pro con el mínimo). */
export function desdeCitas(
  niveles: PreciosPublicos["citas"]["niveles"],
): Record<NivelCitas, number> {
  const tabla = tablaCitas(niveles);
  return {
    individual: niveles?.individual?.["1"] ?? 0,
    premium: tabla[0]?.premium ?? 0,
    pro: tabla[0]?.pro ?? 0,
  };
}

/** «$21» (dólares, sin decimales si son enteros). */
export function dolares(centavos: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "USD",
    currencyDisplay: "narrowSymbol",
    minimumFractionDigits: centavos % 100 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(centavos / 100);
}

/**
 * Un importe en dólares (centavos) en pesos con IVA (centavos), al tipo de cambio dado:
 * lo que se pagaría en México (el cargo usa el FIX del día en que se emite).
 */
export function pesosConIva(
  centavosUsd: number,
  ivaPorcentaje: number,
  tipo: TipoCambio,
): number {
  const conIva = centavosUsd + Math.round((centavosUsd * ivaPorcentaje) / 100);
  return Math.round((conIva * tipo.diezmilesimas) / 10000);
}

/** «$1,234» (pesos, redondeado a pesos enteros: es un equivalente, no el cargo). */
export function pesosRedondos(centavos: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
    maximumFractionDigits: 0,
  }).format(Math.round(centavos / 100));
}

/** «20 de octubre de 2026» (sin moverlo de zona). */
export function fechaDelTipo(fecha: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(`${fecha.slice(0, 10)}T12:00:00Z`));
}

/** «$90» (pesos). */
export function pesos(centavos: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
    minimumFractionDigits: centavos % 100 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(centavos / 100);
}
