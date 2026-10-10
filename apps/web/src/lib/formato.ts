/**
 * Dinero con la moneda y el país del negocio (ADR 0099): cómo se muestra un monto y
 * cómo se lee un precio escrito a mano. El idioma de los números sale del país del
 * negocio (`sesion.pais`), no del navegador de quien mira: en Colombia «25.000» son
 * veinticinco mil; en México, «1,250.50» son mil doscientos cincuenta con cincuenta.
 */

/** Países de habla hispana: sus números se escriben como en `es-<PAÍS>`. */
const HISPANOHABLANTES = new Set([
  "AR",
  "BO",
  "CL",
  "CO",
  "CR",
  "CU",
  "DO",
  "EC",
  "ES",
  "GQ",
  "GT",
  "HN",
  "MX",
  "NI",
  "PA",
  "PE",
  "PR",
  "PY",
  "SV",
  "UY",
  "VE",
]);

/** Otros países con su propio idioma de números. */
const OTROS_IDIOMAS: Record<string, string> = {
  US: "en-US",
  CA: "en-CA",
  BR: "pt-BR",
  PT: "pt-PT",
};

/** Sin país (o uno sin idioma propio aquí), como en México. */
export const LOCALE_POR_OMISION = "es-MX";

/** El idioma con que se escriben los números en un país (ISO 3166-1, «CO»). */
export function localeDe(pais?: string | null): string {
  const codigo = (pais ?? "").trim().toUpperCase();
  if (HISPANOHABLANTES.has(codigo)) {
    return `es-${codigo}`;
  }
  return OTROS_IDIOMAS[codigo] ?? LOCALE_POR_OMISION;
}

// Crear un Intl.NumberFormat cuesta; las listas largas lo piden muchas veces.
const formatos = new Map<string, Intl.NumberFormat>();
function formato(
  locale: string,
  opciones: Intl.NumberFormatOptions,
): Intl.NumberFormat {
  const clave = `${locale}|${JSON.stringify(opciones)}`;
  let f = formatos.get(clave);
  if (f === undefined) {
    f = new Intl.NumberFormat(locale, opciones);
    formatos.set(clave, f);
  }
  return f;
}

/**
 * Un monto en centavos con su moneda (ISO 4217), escrito como en el país del negocio:
 * 125050 MXN en México → «$1,250.50»; en Colombia, COP → «$ 1.250,50».
 */
export function dinero(
  minor: number,
  moneda: string,
  pais?: string | null,
  opciones: Intl.NumberFormatOptions = {},
): string {
  const locale = localeDe(pais);
  // Toda moneda de la plataforma lleva dos decimales (los montos van en centavos):
  // sin fijarlos, cada versión de los datos de Intl decide (p. ej. COP sin centavos).
  const maximo = opciones.maximumFractionDigits ?? 2;
  try {
    return formato(locale, {
      minimumFractionDigits: Math.min(2, maximo),
      maximumFractionDigits: maximo,
      ...opciones,
      style: "currency",
      currency: moneda,
    }).format(minor / 100);
  } catch {
    // Un código que el navegador no conoce: el número y el código, sin romper.
    return `${moneda} ${formato(locale, { minimumFractionDigits: 2 }).format(minor / 100)}`;
  }
}

/**
 * El símbolo de la moneda como se ve en el país del negocio, para ponerlo junto a un
 * campo de precio: «$» (pesos en México), «€» (euros en España), «S/» (soles en Perú).
 * Si el país no le da símbolo propio, su código («USD» en México).
 */
export function simboloMoneda(moneda: string, pais?: string | null): string {
  try {
    return (
      formato(localeDe(pais), { style: "currency", currency: moneda })
        .formatToParts(0)
        .find((p) => p.type === "currency")?.value ?? moneda
    );
  } catch {
    return moneda;
  }
}

/**
 * Los separadores de miles y de decimales del país. Se piden a un número de siete
 * cifras: algunos idiomas (es-ES) no separan los miles de uno de cuatro.
 */
function separadores(pais?: string | null): {
  miles: string | null;
  decimal: string;
} {
  const partes = formato(localeDe(pais), {}).formatToParts(1234567.5);
  const miles = partes.find((p) => p.type === "group")?.value ?? null;
  return {
    // Un espacio (duro o angosto, como en Costa Rica) se escribe como espacio.
    miles: miles !== null && /\s/.test(miles) ? " " : miles,
    decimal: partes.find((p) => p.type === "decimal")?.value ?? ".",
  };
}

/**
 * Un precio escrito a mano, en centavos, leído como lo escribe la gente del país del
 * negocio: «25.000» en Colombia = 2500000; «12,50» en España = 1250; «1,250.50» en
 * México = 125050. Acepta el símbolo o el código de la moneda alrededor («$250»,
 * «12,50 €») y espacios como separador de miles.
 *
 * Devuelve null si no se puede leer o si se presta a confusión (antes que guardar un
 * precio mil veces mayor o menor): más de dos decimales («1.250» en México), miles mal
 * agrupados («12,50» en México), negativos o letras entre los números.
 */
export function aMinor(texto: string, pais?: string | null): number | null {
  const { miles, decimal } = separadores(pais);
  const limpio = texto
    .replace(/\s+/g, " ")
    .trim()
    .replace(/^[^\d.,-]+/, "")
    .replace(/[^\d.,-]+$/, "")
    .trim();
  if (limpio === "" || /[^\d., ]/.test(limpio)) {
    return null;
  }
  const partes = limpio.split(decimal);
  if (partes.length > 2) {
    return null;
  }
  const entero = partes[0] ?? "";
  const fraccion = partes[1] ?? "";
  if (!/^\d{0,2}$/.test(fraccion)) {
    return null;
  }
  // Los miles: el separador del país o un espacio; si el del país es un espacio,
  // también el signo que no es el decimal («1.234,50» en Costa Rica).
  const otro = decimal === "," ? "." : ",";
  const deMiles = new Set([" ", miles ?? " "]);
  if (miles === " " || miles === null) {
    deMiles.add(otro);
  }
  let piezas = [entero];
  for (const s of deMiles) {
    piezas = piezas.flatMap((x) => x.split(s));
  }
  if (piezas.length > 1) {
    const [primera, ...resto] = piezas;
    if (
      !/^\d{1,3}$/.test(primera ?? "") ||
      resto.some((p) => !/^\d{3}$/.test(p))
    ) {
      return null;
    }
  } else if (!/^\d*$/.test(entero)) {
    return null;
  }
  const digitos = piezas.join("");
  if (digitos === "" && fraccion === "") {
    return null;
  }
  const minor = Number(digitos || "0") * 100 + Number(fraccion.padEnd(2, "0"));
  return Number.isSafeInteger(minor) ? minor : null;
}

/**
 * Un precio en centavos como se escribe en un campo del país del negocio, sin
 * separador de miles, para que `aMinor` lo lea igual: 1250 en España → «12,50».
 */
export function aTexto(minor: number, pais?: string | null): string {
  return formato(localeDe(pais), {
    useGrouping: false,
    minimumFractionDigits: minor % 100 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(minor / 100);
}

/** Cómo se escribe un precio en el país del negocio, como ejemplo: «1,250.50». */
export function ejemploPrecio(pais?: string | null): string {
  return formato(localeDe(pais), { minimumFractionDigits: 2 }).format(1250.5);
}
