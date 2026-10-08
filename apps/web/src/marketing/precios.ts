/**
 * Precios públicos de la suscripción (ADR 0107), en dólares y sin impuestos. En
 * México se cobran en pesos al tipo de cambio del día del cobro, más IVA.
 * Backend: migración 2026_10_08_000200_tarifas_en_usd. Es una referencia comercial,
 * no calcula ni sustituye la facturación: al publicar otra versión en plataforma,
 * actualizar esta referencia y sus pruebas. Importes en centavos de dólar.
 *
 * - Clases: por alumnos activos al mes (mes vencido). Más de 1,000: cotización.
 * - Citas: por nivel y profesionales contratados, mensual o anual (10 meses). Más de
 *   20 profesionales: cotización.
 */
export const bandasEstudios = [
  { capacidad: "Hasta 40 alumnos activos", subtotal: 2100 },
  { capacidad: "41–60 alumnos activos", subtotal: 3000 },
  { capacidad: "61–80 alumnos activos", subtotal: 3900 },
  { capacidad: "81–100 alumnos activos", subtotal: 4800 },
  { capacidad: "101–150 alumnos activos", subtotal: 6800 },
  { capacidad: "151–200 alumnos activos", subtotal: 8400 },
  { capacidad: "201–300 alumnos activos", subtotal: 11100 },
  { capacidad: "301–500 alumnos activos", subtotal: 16400 },
  { capacidad: "501–1,000 alumnos activos", subtotal: 29700 },
] as const;

/** Más alumnos que esto: cotización. */
export const MAX_ALUMNOS = 1000;

export type NivelCitas = "individual" | "premium" | "pro";

/** Precio al mes de Premium y Pro por profesionales contratados (2 a 20). */
const PREMIUM = [
  24, 28, 33, 37, 41, 45, 50, 54, 58, 62, 67, 71, 75, 79, 84, 88, 92, 96, 101,
];
const PRO = [
  33, 50, 50, 50, 58, 67, 75, 84, 92, 101, 109, 118, 126, 135, 143, 152, 160,
  169, 177,
];

export const preciosCitas: {
  profesionales: number;
  premium: number;
  pro: number;
}[] = PREMIUM.map((premium, i) => ({
  profesionales: i + 2,
  premium: premium * 100,
  pro: (PRO[i] ?? 0) * 100,
}));

/** Individual: un profesional. */
export const PRECIO_INDIVIDUAL = 900;
/** Más profesionales que esto: cotización. */
export const MAX_PROFESIONALES = 20;
/** El anual cuesta estos meses (2 de cortesía). */
export const MESES_ANUAL = 10;

export const nivelesCitas: {
  nivel: NivelCitas;
  nombre: string;
  capacidad: string;
  desde: number;
  funciones: string[];
}[] = [
  {
    nivel: "individual",
    nombre: "Individual",
    capacidad: "1 profesional",
    desde: PRECIO_INDIVIDUAL,
    funciones: [
      "Agenda y citas, con la app",
      "Recordatorios por correo y en la app",
      "Tu página con dirección propia",
      "Cobro al agendar en línea* y en caja",
      "Clientes, reseñas y reportes básicos",
    ],
  },
  {
    nivel: "premium",
    nombre: "Premium",
    capacidad: "Desde 2 profesionales",
    desde: PREMIUM[0]! * 100,
    funciones: [
      "Todo lo de Individual",
      "Equipo, roles y varias sucursales",
      "Cabinas y recursos",
      "Paquetes, membresías y promociones",
      "Mostrador, inventario y comisiones",
      "Documentos y consentimientos",
    ],
  },
  {
    nivel: "pro",
    nombre: "Pro",
    capacidad: "Desde 2 profesionales",
    desde: PRO[0]! * 100,
    funciones: [
      "Todo lo de Premium",
      "Facturación electrónica*",
      "Cobro automático y venta en línea de paquetes*",
      "Formularios, lealtad y mensajes masivos",
      "Integraciones, API y roles propios",
      "Reportes avanzados",
    ],
  },
];

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
