import {
  PRODUCTOS,
  PRODUCTOS_LISTA,
  type Producto,
  productoActual,
} from "@/lib/producto";
import { slugDeContexto } from "@/lib/tenant";

/**
 * Atribución de campañas (ADR 0142): de dónde llegó quien visita, guardada aparte por
 * ámbito: cada producto (la promoción del SaaS) y cada negocio (la promoción de sus
 * clases, planes o servicios). Nada de un negocio se mezcla con otro ni con la
 * plataforma.
 *
 * Reglas propias (no son las de Google ni las de Meta):
 * - Un «toque» es una llegada con campaña: `utm_source` o `utm_campaign`, un clic de
 *   anuncio (`gclid`, `fbclid`) o un enlace desde otro sitio (no el propio ni una
 *   pasarela de pago). Entrar directo o navegar dentro no cuenta.
 * - Primer origen: el primer toque del ámbito. Último toque: el más reciente.
 * - Se capturan en cada navegación, antes de cualquier redirección, no solo en la
 *   primera lectura de la pestaña.
 * - Aquí se guardan, como máximo, MAXIMO_DIAS_* (higiene); la ventana que vale la
 *   decide el servidor con sus parámetros al recibir una solicitud, una compra o una
 *   cita. Sin un toque vigente, el registro queda «sin atribución».
 * - Por ahora, solo en la pestaña (sessionStorage). Los identificadores de clic no
 *   salen del navegador sin la autorización de publicidad.
 */

export type Ambito = `plataforma:${Producto}` | `negocio:${string}`;

export interface Toque {
  fuente?: string;
  medio?: string;
  campana?: string;
  contenido?: string;
  termino?: string;
  /** Sitio (solo el host) desde el que llegó. */
  referente?: string;
  gclid?: string;
  fbclid?: string;
  /** Cuándo llegó (ISO 8601). */
  en: string;
}

export interface Atribucion {
  primer_toque: Toque | null;
  ultimo_toque: Toque | null;
}

/** Lo que viaja al servidor con una solicitud, una compra, una cita o un registro. */
export type AtribucionParaEnviar = Atribucion;

/** Topes de lo que se guarda en el navegador; el servidor aplica su propia ventana. */
export const MAXIMO_DIAS_PRIMER_TOQUE = 365;
export const MAXIMO_DIAS_ULTIMO_TOQUE = 90;

const DIA_MS = 86_400_000;
const CLAVE = "tu.atribucion.";

const UTM: ReadonlyArray<[string, keyof Toque]> = [
  ["utm_source", "fuente"],
  ["utm_medium", "medio"],
  ["utm_campaign", "campana"],
  ["utm_content", "contenido"],
  ["utm_term", "termino"],
];

// Pasarelas de pago: volver de pagar no es llegar desde una campaña.
const SITIOS_DE_PAGO = [
  "stripe.com",
  "mercadopago.com",
  "mercadopago.com.mx",
  "mercadolibre.com",
  "openpay.mx",
];

/**
 * Un valor de campaña que se puede guardar: texto corto y común. Se descartan los que
 * parecen datos personales (un correo, un teléfono, un número largo) o traen
 * caracteres raros: así un enlace mal armado no mete datos de nadie a la medición.
 */
export function valorDeCampana(valor: unknown): string | null {
  if (typeof valor !== "string") return null;
  const limpio = valor.trim();
  if (limpio === "" || limpio.length > 100) return null;
  if (limpio.includes("@")) return null;
  if (/\d{7,}/.test(limpio.replace(/[\s().+-]/g, ""))) return null;
  if (!/^[\p{L}\p{N} ._\-+:/|()]+$/u.test(limpio)) return null;
  return limpio;
}

/** Un identificador de clic tal como llegó (no se modifica: Meta distingue mayúsculas). */
export function identificadorDeClic(valor: unknown): string | null {
  return typeof valor === "string" && /^[A-Za-z0-9_-]{8,500}$/.test(valor)
    ? valor
    : null;
}

function esSitioPropio(host: string): boolean {
  if (host === "localhost" || host.endsWith(".localhost")) return true;
  return PRODUCTOS_LISTA.some((id) => {
    const dominio = PRODUCTOS[id].dominio;
    return host === dominio || host.endsWith(`.${dominio}`);
  });
}

/** El host de la página anterior si es otro sitio (ni el propio ni una pasarela). */
export function referenteExterno(
  referrer: string,
  hostActual: string,
): string | null {
  if (referrer === "") return null;
  let host: string;
  try {
    host = new URL(referrer).hostname.toLowerCase();
  } catch {
    return null;
  }
  if (host === "" || host === hostActual.toLowerCase() || esSitioPropio(host)) {
    return null;
  }
  if (SITIOS_DE_PAGO.some((s) => host === s || host.endsWith(`.${s}`))) {
    return null;
  }
  return host.slice(0, 120);
}

/** El toque de una llegada, o `null` si no trae campaña (directo o navegación interna). */
export function toqueDeLlegada(
  query: URLSearchParams,
  referente: string | null,
  ahora: Date,
): Toque | null {
  const toque: Toque = { en: ahora.toISOString() };
  for (const [parametro, campo] of UTM) {
    const valor = valorDeCampana(query.get(parametro));
    if (valor !== null)
      (toque as unknown as Record<string, string>)[campo] = valor;
  }
  const gclid = identificadorDeClic(query.get("gclid"));
  const fbclid = identificadorDeClic(query.get("fbclid"));
  if (gclid !== null) toque.gclid = gclid;
  if (fbclid !== null) toque.fbclid = fbclid;
  if (referente !== null) toque.referente = referente;

  const conCampana =
    toque.fuente !== undefined ||
    toque.campana !== undefined ||
    gclid !== null ||
    fbclid !== null;
  if (!conCampana && referente === null) return null;
  if (!conCampana) {
    // Solo el sitio de donde viene: sin parámetros de campaña que se cuelen.
    return { referente: toque.referente, en: toque.en };
  }
  return toque;
}

function almacen(): Storage | null {
  try {
    return typeof window === "undefined" ? null : window.sessionStorage;
  } catch {
    return null;
  }
}

function vigente(toque: Toque | null, dias: number, ahora: Date): Toque | null {
  if (toque === null || typeof toque.en !== "string") return null;
  const en = Date.parse(toque.en);
  if (Number.isNaN(en) || en > ahora.getTime() + 60_000) return null;
  return ahora.getTime() - en <= dias * DIA_MS ? toque : null;
}

/** Lo guardado para el ámbito, sin lo que ya pasó de los topes. */
export function leerAtribucion(ambito: Ambito, ahora = new Date()): Atribucion {
  const vacio: Atribucion = { primer_toque: null, ultimo_toque: null };
  try {
    const crudo = almacen()?.getItem(CLAVE + ambito);
    if (crudo === null || crudo === undefined) return vacio;
    const guardado = JSON.parse(crudo) as Partial<Atribucion>;
    return {
      primer_toque: vigente(
        guardado.primer_toque ?? null,
        MAXIMO_DIAS_PRIMER_TOQUE,
        ahora,
      ),
      ultimo_toque: vigente(
        guardado.ultimo_toque ?? null,
        MAXIMO_DIAS_ULTIMO_TOQUE,
        ahora,
      ),
    };
  } catch {
    return vacio;
  }
}

function mismoToque(a: Toque | null, b: Toque): boolean {
  if (a === null) return false;
  // Sin la fecha (JSON.stringify omite lo indefinido).
  const sinFecha = (t: Toque) => JSON.stringify({ ...t, en: undefined });
  return sinFecha(a) === sinFecha(b);
}

/**
 * Registra una llegada en el ámbito. El primer origen se queda; el último toque se
 * reemplaza con cada llegada con campaña (la misma de nuevo, p. ej. al recargar, no
 * cambia su fecha).
 */
export function capturarAtribucion(
  ambito: Ambito,
  query: URLSearchParams,
  referrer: string,
  hostActual: string,
  ahora = new Date(),
): Atribucion {
  const actual = leerAtribucion(ambito, ahora);
  const toque = toqueDeLlegada(
    query,
    referenteExterno(referrer, hostActual),
    ahora,
  );
  if (toque === null || mismoToque(actual.ultimo_toque, toque)) {
    return actual;
  }
  const nueva: Atribucion = {
    primer_toque: actual.primer_toque ?? toque,
    ultimo_toque: toque,
  };
  try {
    almacen()?.setItem(CLAVE + ambito, JSON.stringify(nueva));
  } catch {
    // Sin almacenamiento: la medición es opcional.
  }
  return nueva;
}

/** Quita los identificadores de clic (sin autorización de publicidad no salen). */
function sinClics(toque: Toque | null): Toque | null {
  if (toque === null) return null;
  const copia: Toque = { ...toque };
  delete copia.gclid;
  delete copia.fbclid;
  return copia;
}

/** Lo que se manda al servidor junto con el registro que se crea. */
export function atribucionParaEnviar(
  ambito: Ambito,
  ahora = new Date(),
): AtribucionParaEnviar | null {
  const { primer_toque, ultimo_toque } = leerAtribucion(ambito, ahora);
  if (primer_toque === null && ultimo_toque === null) return null;
  return {
    primer_toque: sinClics(primer_toque),
    ultimo_toque: sinClics(ultimo_toque),
  };
}

/** Para agregar a un registro: `{ atribucion }`, o nada si no hay campaña. */
export function conAtribucion(ambito: Ambito): {
  atribucion?: AtribucionParaEnviar;
} {
  const atribucion = atribucionParaEnviar(ambito);
  return atribucion !== null ? { atribucion } : {};
}

interface RutaParaAmbito {
  name?: unknown;
  params: Record<string, unknown>;
  query: Record<string, unknown>;
  meta: Record<string, unknown>;
}

/**
 * El ámbito de una ruta: el negocio (por su slug en la ruta, su subdominio o el
 * `?estudio=` de desarrollo) o el producto en sus páginas comerciales y su registro.
 * Fuera de eso (panel, superadmin), ninguno.
 */
export function ambitoDeRuta(
  ruta: RutaParaAmbito,
  host: string = typeof window === "undefined" ? "" : window.location.hostname,
): Ambito | null {
  const deRuta = typeof ruta.params.slug === "string" ? ruta.params.slug : "";
  const estudio =
    typeof ruta.query.estudio === "string" ? ruta.query.estudio : "";
  const slug =
    deRuta !== ""
      ? deRuta
      : slugDeContexto(
          host,
          estudio !== "" ? `?estudio=${encodeURIComponent(estudio)}` : "",
        );
  if (slug !== null && /^[a-z0-9-]{1,63}$/i.test(slug)) {
    return `negocio:${slug.toLowerCase()}`;
  }
  if (ruta.meta.marketing === true || ruta.name === "registro") {
    return `plataforma:${productoActual()}`;
  }
  return null;
}

// El ámbito de la pantalla actual: lo fija el router al navegar.
let ambitoActual: Ambito | null = null;

export function fijarAmbitoActual(ambito: Ambito | null): void {
  ambitoActual = ambito;
}

/** El ámbito de la pantalla actual (sin router, el del producto). */
export function ambitoEnUso(): Ambito {
  return ambitoActual ?? `plataforma:${productoActual()}`;
}
