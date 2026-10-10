/**
 * Precios públicos de la suscripción (ADR 0107). La fuente es lo que publica el
 * superadmin: la landing los lee de `GET /api/v1/precios` (`preciosPublicos.ts`). Lo
 * de aquí es solo el respaldo: lo que muestra el HTML pre-generado (buscadores) y la
 * página mientras llega la respuesta. Debe coincidir con la tarifa vigente
 * (migración 2026_10_08_000200_tarifas_en_usd). Importes en centavos de dólar.
 */
export type NivelCitas = "individual" | "premium" | "pro";

export interface Banda {
  hasta: number | null;
  monto_minor: number;
}

export interface PreciosPublicos {
  clases: { moneda: string; dias_prueba: number; bandas: Banda[] };
  citas: {
    moneda: string;
    dias_prueba: number;
    meses_anual: number;
    niveles: Partial<Record<NivelCitas, Record<string, number>>> | null;
    funciones: Partial<Record<string, string>> | null;
  };
  ventas: { correo: string | null; whatsapp: string | null };
  timbres: { moneda: string; precio_minor: number; paquetes: number[] };
  /**
   * ¿Qué producto recibe registros de negocios? (ADR 0108). El superadmin puede cerrar
   * el de un producto: mientras, su landing junta interesados (prelanzamiento).
   */
  registro: { agendauno: boolean; turnouno: boolean };
}

const PREMIUM = [
  24, 28, 33, 37, 41, 45, 50, 54, 58, 62, 67, 71, 75, 79, 84, 88, 92, 96, 101,
];
const PRO = [
  33, 50, 50, 50, 58, 67, 75, 84, 92, 101, 109, 118, 126, 135, 143, 152, 160,
  169, 177,
];
const porProfesionales = (precios: number[]): Record<string, number> =>
  Object.fromEntries(precios.map((usd, i) => [String(i + 2), usd * 100]));

/** El respaldo: la tarifa publicada al escribir esto. */
export const PRECIOS_POR_OMISION: PreciosPublicos = {
  clases: {
    moneda: "USD",
    dias_prueba: 30,
    bandas: [
      { hasta: 40, monto_minor: 2100 },
      { hasta: 60, monto_minor: 3000 },
      { hasta: 80, monto_minor: 3900 },
      { hasta: 100, monto_minor: 4800 },
      { hasta: 150, monto_minor: 6800 },
      { hasta: 200, monto_minor: 8400 },
      { hasta: 300, monto_minor: 11100 },
      { hasta: 500, monto_minor: 16400 },
      { hasta: 1000, monto_minor: 29700 },
      { hasta: null, monto_minor: 29700 },
    ],
  },
  citas: {
    moneda: "USD",
    dias_prueba: 30,
    meses_anual: 10,
    niveles: {
      individual: { "1": 900 },
      premium: porProfesionales(PREMIUM),
      pro: porProfesionales(PRO),
    },
    funciones: null,
  },
  ventas: { correo: "ventas@agendauno.mx", whatsapp: null },
  timbres: {
    moneda: "MXN",
    precio_minor: 180,
    paquetes: [50, 100, 200, 350, 500],
  },
  // Los dos productos reciben registros (TurnoUno se lanzó). Debe coincidir con
  // `registro.abierto_*` del API: es lo que dice el HTML pre-generado.
  registro: { agendauno: true, turnouno: true },
};

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

/** «$90» (pesos). */
export function pesos(centavos: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: "MXN",
    minimumFractionDigits: centavos % 100 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(centavos / 100);
}
