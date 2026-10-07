/**
 * Países y ladas (ADR 0103): todos los códigos ISO 3166-1 alfa-2 con su lada
 * internacional (UIT), solo dígitos y sin «+». Es la misma lista que valida el API
 * (`CatalogoPaises`). Los nombres salen del navegador, en español
 * (`Intl.DisplayNames`), y en los selectores van primero los más usados.
 *
 * Los celulares que se capturan con lada viajan como «+<lada> <número>» (p. ej.
 * «+57 3001234567»); los que se guardaron sin «+» se leen con la lada del negocio.
 */
export const LADAS: Readonly<Record<string, string>> = {
  AD: "376",
  AE: "971",
  AF: "93",
  AG: "1",
  AI: "1",
  AL: "355",
  AM: "374",
  AO: "244",
  AQ: "672",
  AR: "54",
  AS: "1",
  AT: "43",
  AU: "61",
  AW: "297",
  AX: "358",
  AZ: "994",
  BA: "387",
  BB: "1",
  BD: "880",
  BE: "32",
  BF: "226",
  BG: "359",
  BH: "973",
  BI: "257",
  BJ: "229",
  BL: "590",
  BM: "1",
  BN: "673",
  BO: "591",
  BQ: "599",
  BR: "55",
  BS: "1",
  BT: "975",
  BV: "47",
  BW: "267",
  BY: "375",
  BZ: "501",
  CA: "1",
  CC: "61",
  CD: "243",
  CF: "236",
  CG: "242",
  CH: "41",
  CI: "225",
  CK: "682",
  CL: "56",
  CM: "237",
  CN: "86",
  CO: "57",
  CR: "506",
  CU: "53",
  CV: "238",
  CW: "599",
  CX: "61",
  CY: "357",
  CZ: "420",
  DE: "49",
  DJ: "253",
  DK: "45",
  DM: "1",
  DO: "1",
  DZ: "213",
  EC: "593",
  EE: "372",
  EG: "20",
  EH: "212",
  ER: "291",
  ES: "34",
  ET: "251",
  FI: "358",
  FJ: "679",
  FK: "500",
  FM: "691",
  FO: "298",
  FR: "33",
  GA: "241",
  GB: "44",
  GD: "1",
  GE: "995",
  GF: "594",
  GG: "44",
  GH: "233",
  GI: "350",
  GL: "299",
  GM: "220",
  GN: "224",
  GP: "590",
  GQ: "240",
  GR: "30",
  GS: "500",
  GT: "502",
  GU: "1",
  GW: "245",
  GY: "592",
  HK: "852",
  HM: "672",
  HN: "504",
  HR: "385",
  HT: "509",
  HU: "36",
  ID: "62",
  IE: "353",
  IL: "972",
  IM: "44",
  IN: "91",
  IO: "246",
  IQ: "964",
  IR: "98",
  IS: "354",
  IT: "39",
  JE: "44",
  JM: "1",
  JO: "962",
  JP: "81",
  KE: "254",
  KG: "996",
  KH: "855",
  KI: "686",
  KM: "269",
  KN: "1",
  KP: "850",
  KR: "82",
  KW: "965",
  KY: "1",
  KZ: "7",
  LA: "856",
  LB: "961",
  LC: "1",
  LI: "423",
  LK: "94",
  LR: "231",
  LS: "266",
  LT: "370",
  LU: "352",
  LV: "371",
  LY: "218",
  MA: "212",
  MC: "377",
  MD: "373",
  ME: "382",
  MF: "590",
  MG: "261",
  MH: "692",
  MK: "389",
  ML: "223",
  MM: "95",
  MN: "976",
  MO: "853",
  MP: "1",
  MQ: "596",
  MR: "222",
  MS: "1",
  MT: "356",
  MU: "230",
  MV: "960",
  MW: "265",
  MX: "52",
  MY: "60",
  MZ: "258",
  NA: "264",
  NC: "687",
  NE: "227",
  NF: "672",
  NG: "234",
  NI: "505",
  NL: "31",
  NO: "47",
  NP: "977",
  NR: "674",
  NU: "683",
  NZ: "64",
  OM: "968",
  PA: "507",
  PE: "51",
  PF: "689",
  PG: "675",
  PH: "63",
  PK: "92",
  PL: "48",
  PM: "508",
  PN: "64",
  PR: "1",
  PS: "970",
  PT: "351",
  PW: "680",
  PY: "595",
  QA: "974",
  RE: "262",
  RO: "40",
  RS: "381",
  RU: "7",
  RW: "250",
  SA: "966",
  SB: "677",
  SC: "248",
  SD: "249",
  SE: "46",
  SG: "65",
  SH: "290",
  SI: "386",
  SJ: "47",
  SK: "421",
  SL: "232",
  SM: "378",
  SN: "221",
  SO: "252",
  SR: "597",
  SS: "211",
  ST: "239",
  SV: "503",
  SX: "1",
  SY: "963",
  SZ: "268",
  TC: "1",
  TD: "235",
  TF: "262",
  TG: "228",
  TH: "66",
  TJ: "992",
  TK: "690",
  TL: "670",
  TM: "993",
  TN: "216",
  TO: "676",
  TR: "90",
  TT: "1",
  TV: "688",
  TW: "886",
  TZ: "255",
  UA: "380",
  UG: "256",
  UM: "1",
  US: "1",
  UY: "598",
  UZ: "998",
  VA: "39",
  VC: "1",
  VE: "58",
  VG: "1",
  VI: "1",
  VN: "84",
  VU: "678",
  WF: "681",
  WS: "685",
  XK: "383",
  YE: "967",
  YT: "262",
  ZA: "27",
  ZM: "260",
  ZW: "263",
};

/** El país de quien no dijo otro. */
export const PAIS_POR_OMISION = "MX";

/** Los más usados: van primero en los selectores, en este orden. */
export const PAISES_FRECUENTES = [
  "MX",
  "US",
  "CA",
  "CO",
  "AR",
  "CL",
  "PE",
  "ES",
  "EC",
  "GT",
  "CR",
  "DO",
  "PA",
  "UY",
] as const;

export interface Pais {
  /** ISO 3166-1 alfa-2, en mayúsculas («MX»). */
  codigo: string;
  /** Solo dígitos, sin «+» («52»). */
  lada: string;
  /** En español («México»). */
  nombre: string;
}

let nombres: Intl.DisplayNames | null | undefined;

/** «MX» → «México». Sin `Intl.DisplayNames`, el código. */
export function nombrePais(codigo: string): string {
  if (nombres === undefined) {
    try {
      nombres = new Intl.DisplayNames(["es"], { type: "region" });
    } catch {
      nombres = null;
    }
  }
  try {
    return nombres?.of(codigo) ?? codigo;
  } catch {
    return codigo;
  }
}

/** ¿Es un país de la lista? (acepta minúsculas). */
export function esPais(codigo: string | null | undefined): boolean {
  return typeof codigo === "string" && codigo.toUpperCase() in LADAS;
}

/** La lada de un país («CO» → «57»), o null si no está en la lista. */
export function ladaDe(codigo: string | null | undefined): string | null {
  return codigo ? (LADAS[codigo.toUpperCase()] ?? null) : null;
}

let ordenados: Pais[] | null = null;

/** Todos los países: los frecuentes primero y luego los demás por nombre. */
export function paisesOrdenados(): Pais[] {
  if (ordenados === null) {
    const pais = (codigo: string): Pais => ({
      codigo,
      lada: LADAS[codigo],
      nombre: nombrePais(codigo),
    });
    const frecuentes = new Set<string>(PAISES_FRECUENTES);
    const resto = Object.keys(LADAS)
      .filter((c) => !frecuentes.has(c))
      .map(pais)
      .sort((a, b) => a.nombre.localeCompare(b.nombre, "es"));
    ordenados = [...PAISES_FRECUENTES.map(pais), ...resto];
  }
  return ordenados;
}

/** ¿Va entre los frecuentes? */
export function esFrecuente(codigo: string): boolean {
  return (PAISES_FRECUENTES as readonly string[]).includes(codigo);
}

/**
 * El país de una lada. Varias comparten lada (+1: Estados Unidos, Canadá, Puerto
 * Rico…): se prefiere `preferido` si es de esa lada, luego el frecuente y luego el
 * primero por nombre.
 */
export function paisDeLada(
  lada: string,
  preferido?: string | null,
): string | null {
  const digitos = lada.replace(/\D/g, "");
  if (preferido && ladaDe(preferido) === digitos) {
    return preferido.toUpperCase();
  }
  return paisesOrdenados().find((p) => p.lada === digitos)?.codigo ?? null;
}

/**
 * Separa un teléfono en lada y número (solo dígitos).
 * - «+57 300 123 4567» → { lada: "57", numero: "3001234567" }
 * - «+573001234567» (sin espacio) → la lada más larga de la lista que coincida.
 * - Sin «+» («5512345678») → la lada `ladaPorOmision` (la del negocio).
 */
export function separarTelefono(
  valor: string | null | undefined,
  ladaPorOmision: string,
): { lada: string; numero: string } {
  const texto = (valor ?? "").trim();
  if (!texto.startsWith("+")) {
    return { lada: ladaPorOmision, numero: texto.replace(/\D/g, "") };
  }
  return (
    separarInternacional(texto) ?? {
      lada: ladaPorOmision,
      numero: texto.replace(/\D/g, ""),
    }
  );
}

/**
 * Un teléfono con «+»: su lada (de la lista) y el número. Null si no empieza con «+»
 * o aún no se reconoce una lada («+», «+5»). Las ladas no son prefijo una de otra, así
 * que sin espacio («+573001234567») la primera que coincide es la buena.
 */
export function separarInternacional(
  valor: string,
): { lada: string; numero: string } | null {
  const texto = valor.trim();
  if (!texto.startsWith("+")) {
    return null;
  }
  const conEspacio = /^\+\s*(\d{1,4})[\s\-.()]+(.*)$/.exec(texto);
  if (conEspacio && esLada(conEspacio[1])) {
    return {
      lada: conEspacio[1],
      numero: conEspacio[2].replace(/\D/g, ""),
    };
  }
  const digitos = texto.replace(/\D/g, "");
  for (let largo = 4; largo >= 1; largo--) {
    const lada = digitos.slice(0, largo);
    if (esLada(lada)) {
      return { lada, numero: digitos.slice(largo) };
    }
  }
  return null;
}

/** «57» + «300 123 4567» → «+57 3001234567». Sin número, vacío. */
export function unirTelefono(lada: string, numero: string): string {
  const digitos = numero.replace(/\D/g, "");
  return digitos === "" ? "" : `+${lada.replace(/\D/g, "")} ${digitos}`;
}

let ladasConocidas: Set<string> | null = null;
function esLada(lada: string): boolean {
  ladasConocidas ??= new Set(Object.values(LADAS));
  return ladasConocidas.has(lada);
}
