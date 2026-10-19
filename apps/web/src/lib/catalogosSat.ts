import type { OpcionBuscable } from "@/lib/buscable";

/**
 * Catálogos del SAT para la facturación (CFDI 4.0). La clave es lo que se guarda y se
 * manda al timbrar; la descripción es la oficial del catálogo, para elegir sin
 * saberse las claves de memoria.
 */

/** A quién le corresponde un régimen: persona física, moral o ambas. */
export type TipoPersona = "fisica" | "moral";

export interface RegimenFiscal {
  clave: string;
  descripcion: string;
  personas: TipoPersona[];
  // Cómo lo conoce la gente (RESICO, RIF…), para encontrarlo al buscar.
  alias?: string;
}

/** c_RegimenFiscal. */
export const REGIMENES_FISCALES: RegimenFiscal[] = [
  {
    clave: "601",
    descripcion: "General de Ley Personas Morales",
    personas: ["moral"],
  },
  {
    clave: "603",
    descripcion: "Personas Morales con Fines no Lucrativos",
    personas: ["moral"],
    alias: "asociación civil AC donataria",
  },
  {
    clave: "605",
    descripcion: "Sueldos y Salarios e Ingresos Asimilados a Salarios",
    personas: ["fisica"],
    alias: "nómina",
  },
  { clave: "606", descripcion: "Arrendamiento", personas: ["fisica"] },
  {
    clave: "607",
    descripcion: "Régimen de Enajenación o Adquisición de Bienes",
    personas: ["fisica"],
  },
  { clave: "608", descripcion: "Demás ingresos", personas: ["fisica"] },
  {
    clave: "610",
    descripcion:
      "Residentes en el Extranjero sin Establecimiento Permanente en México",
    personas: ["fisica", "moral"],
  },
  {
    clave: "611",
    descripcion: "Ingresos por Dividendos (socios y accionistas)",
    personas: ["fisica"],
  },
  {
    clave: "612",
    descripcion:
      "Personas Físicas con Actividades Empresariales y Profesionales",
    personas: ["fisica"],
    alias: "honorarios",
  },
  { clave: "614", descripcion: "Ingresos por intereses", personas: ["fisica"] },
  {
    clave: "615",
    descripcion: "Régimen de los ingresos por obtención de premios",
    personas: ["fisica"],
  },
  {
    clave: "616",
    descripcion: "Sin obligaciones fiscales",
    personas: ["fisica"],
  },
  {
    clave: "620",
    descripcion:
      "Sociedades Cooperativas de Producción que optan por diferir sus ingresos",
    personas: ["moral"],
  },
  {
    clave: "621",
    descripcion: "Incorporación Fiscal",
    personas: ["fisica"],
    alias: "RIF",
  },
  {
    clave: "622",
    descripcion: "Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras",
    personas: ["moral"],
  },
  {
    clave: "623",
    descripcion: "Opcional para Grupos de Sociedades",
    personas: ["moral"],
  },
  { clave: "624", descripcion: "Coordinados", personas: ["moral"] },
  {
    clave: "625",
    descripcion:
      "Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas",
    personas: ["fisica"],
    alias: "plataformas digitales",
  },
  {
    clave: "626",
    descripcion: "Régimen Simplificado de Confianza",
    personas: ["fisica", "moral"],
    alias: "RESICO",
  },
];

/**
 * Persona física o moral según el RFC: 12 caracteres es moral y 13 física. Mientras
 * no tenga una forma válida, no se sabe (null).
 */
export function tipoPersonaDeRfc(rfc: string): TipoPersona | null {
  const limpio = rfc.trim().toUpperCase();
  if (!/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/.test(limpio)) {
    return null;
  }
  return limpio.length === 12 ? "moral" : "fisica";
}

export function regimenFiscal(clave: string): RegimenFiscal | undefined {
  return REGIMENES_FISCALES.find((r) => r.clave === clave);
}

/** ¿El régimen le corresponde a ese tipo de persona? (sin tipo conocido, sí) */
export function regimenCorresponde(
  clave: string,
  tipo: TipoPersona | null,
): boolean {
  const regimen = regimenFiscal(clave);
  return (
    tipo === null || regimen === undefined || regimen.personas.includes(tipo)
  );
}

/**
 * Las opciones del selector: «626 · Régimen Simplificado de Confianza». Con el tipo
 * de persona del RFC, primero los que le corresponden y después los demás.
 */
export function opcionesRegimen(
  tipo: TipoPersona | null,
  textos: {
    persona: (personas: TipoPersona[]) => string;
    paraTuRfc: string;
    otros: string;
  },
): OpcionBuscable[] {
  const opcion = (r: RegimenFiscal, grupo?: string): OpcionBuscable => ({
    valor: r.clave,
    etiqueta: `${r.clave} · ${r.descripcion}`,
    detalle: textos.persona(r.personas),
    busqueda: r.alias,
    grupo,
  });
  if (tipo === null) {
    return REGIMENES_FISCALES.map((r) => opcion(r));
  }
  const suyos = REGIMENES_FISCALES.filter((r) => r.personas.includes(tipo));
  const otros = REGIMENES_FISCALES.filter((r) => !r.personas.includes(tipo));
  return [
    ...suyos.map((r) => opcion(r, textos.paraTuRfc)),
    ...otros.map((r) => opcion(r, textos.otros)),
  ];
}

/** Un uso del CFDI (c_UsoCFDI) y a quién le corresponde. */
export interface UsoCfdi {
  clave: string;
  descripcion: string;
  personas: TipoPersona[];
}

const AMBAS: TipoPersona[] = ["fisica", "moral"];
const FISICA: TipoPersona[] = ["fisica"];

/** c_UsoCFDI. */
export const USOS_CFDI: UsoCfdi[] = [
  { clave: "G01", descripcion: "Adquisición de mercancías", personas: AMBAS },
  {
    clave: "G02",
    descripcion: "Devoluciones, descuentos o bonificaciones",
    personas: AMBAS,
  },
  { clave: "G03", descripcion: "Gastos en general", personas: AMBAS },
  { clave: "I01", descripcion: "Construcciones", personas: AMBAS },
  {
    clave: "I02",
    descripcion: "Mobiliario y equipo de oficina por inversiones",
    personas: AMBAS,
  },
  { clave: "I03", descripcion: "Equipo de transporte", personas: AMBAS },
  {
    clave: "I04",
    descripcion: "Equipo de cómputo y accesorios",
    personas: AMBAS,
  },
  {
    clave: "I05",
    descripcion: "Dados, troqueles, moldes, matrices y herramental",
    personas: AMBAS,
  },
  { clave: "I06", descripcion: "Comunicaciones telefónicas", personas: AMBAS },
  { clave: "I07", descripcion: "Comunicaciones satelitales", personas: AMBAS },
  { clave: "I08", descripcion: "Otra maquinaria y equipo", personas: AMBAS },
  {
    clave: "D01",
    descripcion: "Honorarios médicos, dentales y gastos hospitalarios",
    personas: FISICA,
  },
  {
    clave: "D02",
    descripcion: "Gastos médicos por incapacidad o discapacidad",
    personas: FISICA,
  },
  { clave: "D03", descripcion: "Gastos funerales", personas: FISICA },
  { clave: "D04", descripcion: "Donativos", personas: FISICA },
  {
    clave: "D05",
    descripcion:
      "Intereses reales efectivamente pagados por créditos hipotecarios (casa habitación)",
    personas: FISICA,
  },
  {
    clave: "D06",
    descripcion: "Aportaciones voluntarias al SAR",
    personas: FISICA,
  },
  {
    clave: "D07",
    descripcion: "Primas por seguros de gastos médicos",
    personas: FISICA,
  },
  {
    clave: "D08",
    descripcion: "Gastos de transportación escolar obligatoria",
    personas: FISICA,
  },
  {
    clave: "D09",
    descripcion:
      "Depósitos en cuentas para el ahorro, primas que tengan como base planes de pensiones",
    personas: FISICA,
  },
  {
    clave: "D10",
    descripcion: "Pagos por servicios educativos (colegiaturas)",
    personas: FISICA,
  },
  { clave: "S01", descripcion: "Sin efectos fiscales", personas: AMBAS },
  { clave: "CP01", descripcion: "Pagos", personas: AMBAS },
  { clave: "CN01", descripcion: "Nómina", personas: FISICA },
];

/** c_FormaPago. */
export const FORMAS_PAGO: { clave: string; descripcion: string }[] = [
  { clave: "01", descripcion: "Efectivo" },
  { clave: "02", descripcion: "Cheque nominativo" },
  { clave: "03", descripcion: "Transferencia electrónica de fondos" },
  { clave: "04", descripcion: "Tarjeta de crédito" },
  { clave: "05", descripcion: "Monedero electrónico" },
  { clave: "06", descripcion: "Dinero electrónico" },
  { clave: "08", descripcion: "Vales de despensa" },
  { clave: "12", descripcion: "Dación en pago" },
  { clave: "13", descripcion: "Pago por subrogación" },
  { clave: "14", descripcion: "Pago por consignación" },
  { clave: "15", descripcion: "Condonación" },
  { clave: "17", descripcion: "Compensación" },
  { clave: "23", descripcion: "Novación" },
  { clave: "24", descripcion: "Confusión" },
  { clave: "25", descripcion: "Remisión de deuda" },
  { clave: "26", descripcion: "Prescripción o caducidad" },
  { clave: "27", descripcion: "A satisfacción del acreedor" },
  { clave: "28", descripcion: "Tarjeta de débito" },
  { clave: "29", descripcion: "Tarjeta de servicios" },
  { clave: "30", descripcion: "Aplicación de anticipos" },
  { clave: "31", descripcion: "Intermediario pagos" },
  { clave: "99", descripcion: "Por definir" },
];

/** ¿Ese uso del CFDI le corresponde a ese tipo de persona? (sin tipo conocido, sí) */
export function usoCfdiCorresponde(
  clave: string,
  tipo: TipoPersona | null,
): boolean {
  const uso = USOS_CFDI.find((u) => u.clave === clave);
  return tipo === null || uso === undefined || uso.personas.includes(tipo);
}

/**
 * Los usos del CFDI para elegir: con el tipo de persona del receptor, primero los
 * que le corresponden.
 */
export function opcionesUsoCfdi(
  tipo: TipoPersona | null,
  textos: { paraElRfc: string; otros: string },
): OpcionBuscable[] {
  const opcion = (u: UsoCfdi, grupo?: string): OpcionBuscable => ({
    valor: u.clave,
    etiqueta: `${u.clave} · ${u.descripcion}`,
    grupo,
  });
  if (tipo === null) {
    return USOS_CFDI.map((u) => opcion(u));
  }
  return [
    ...USOS_CFDI.filter((u) => u.personas.includes(tipo)).map((u) =>
      opcion(u, textos.paraElRfc),
    ),
    ...USOS_CFDI.filter((u) => !u.personas.includes(tipo)).map((u) =>
      opcion(u, textos.otros),
    ),
  ];
}

/** Las formas de pago para elegir («03 · Transferencia electrónica de fondos»). */
export function opcionesFormaPago(): OpcionBuscable[] {
  return FORMAS_PAGO.map((f) => ({
    valor: f.clave,
    etiqueta: `${f.clave} · ${f.descripcion}`,
  }));
}
