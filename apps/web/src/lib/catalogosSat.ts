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
