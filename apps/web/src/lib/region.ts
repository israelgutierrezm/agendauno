import { i18n } from "@/i18n";
import type { OpcionBuscable } from "@/lib/buscable";
import {
  esFrecuente,
  esPais,
  nombrePais,
  PAIS_POR_OMISION,
  paisesOrdenados,
} from "@/lib/ladas";
import { ZONAS_POR_PAIS } from "@/lib/zonasPorPais";

/**
 * País y zonas horarias del negocio y de sus sucursales (ADR 0099 y 0103). Se ofrecen
 * TODAS las zonas, con las del país del negocio primero y buscador donde se elige.
 * Las de México y algunas de la región tienen nombre propio en `region.zonas` (i18n);
 * las demás se nombran «País (Ciudad)».
 */
export const ZONA_POR_OMISION = "America/Mexico_City";

/** Las de siempre: primero dentro de su país y respaldo sin la lista del navegador. */
export const ZONAS_FRECUENTES = [
  "America/Mexico_City",
  "America/Cancun",
  "America/Merida",
  "America/Monterrey",
  "America/Chihuahua",
  "America/Mazatlan",
  "America/Hermosillo",
  "America/Tijuana",
  "America/Guatemala",
  "America/Costa_Rica",
  "America/Santo_Domingo",
  "America/Bogota",
  "America/Lima",
  "America/Santiago",
  "America/Argentina/Buenos_Aires",
  "America/New_York",
  "America/Los_Angeles",
  "Europe/Madrid",
] as const;

/**
 * Nombres que da el navegador (CLDR) y que no son los canónicos de IANA: el API valida
 * con los de IANA, así que se traducen antes de ofrecerlos o mandarlos.
 */
const ALIAS_IANA: Readonly<Record<string, string>> = {
  "Africa/Asmera": "Africa/Asmara",
  "America/Buenos_Aires": "America/Argentina/Buenos_Aires",
  "America/Catamarca": "America/Argentina/Catamarca",
  "America/Coral_Harbour": "America/Atikokan",
  "America/Cordoba": "America/Argentina/Cordoba",
  "America/Godthab": "America/Nuuk",
  "America/Indianapolis": "America/Indiana/Indianapolis",
  "America/Jujuy": "America/Argentina/Jujuy",
  "America/Louisville": "America/Kentucky/Louisville",
  "America/Mendoza": "America/Argentina/Mendoza",
  "Asia/Calcutta": "Asia/Kolkata",
  "Asia/Katmandu": "Asia/Kathmandu",
  "Asia/Rangoon": "Asia/Yangon",
  "Asia/Saigon": "Asia/Ho_Chi_Minh",
  "Atlantic/Faeroe": "Atlantic/Faroe",
  "Europe/Kiev": "Europe/Kyiv",
  "Pacific/Enderbury": "Pacific/Kanton",
  "Pacific/Ponape": "Pacific/Pohnpei",
  "Pacific/Truk": "Pacific/Chuuk",
};

/** «America/Buenos_Aires» (como lo da el navegador) → «America/Argentina/Buenos_Aires». */
export function normalizarZona(zona: string): string {
  return ALIAS_IANA[zona] ?? zona;
}

let paisPorZona: Map<string, string> | null = null;

/** El país de una zona («America/Bogota» → «CO»), o null si no se sabe. */
export function paisDeZona(zona: string): string | null {
  if (paisPorZona === null) {
    paisPorZona = new Map();
    for (const [pais, zonas] of Object.entries(ZONAS_POR_PAIS)) {
      for (const z of zonas.split(" ")) {
        paisPorZona.set(z, pais);
      }
    }
  }
  return paisPorZona.get(normalizarZona(zona)) ?? null;
}

/**
 * La zona principal (la más poblada) de los países con varias y ninguna entre las
 * frecuentes: va primero entre las del país y es la que se propone si el navegador no
 * dice otra del país (si no, sería la primera por nombre, p. ej. «Antarctica/Macquarie»
 * en Australia).
 */
const ZONA_PRINCIPAL: Readonly<Record<string, string>> = {
  AU: "Australia/Sydney",
  BR: "America/Sao_Paulo",
  CA: "America/Toronto",
  CY: "Asia/Nicosia",
  GL: "America/Nuuk",
  KI: "Pacific/Tarawa",
  MN: "Asia/Ulaanbaatar",
  PF: "Pacific/Tahiti",
  PG: "Pacific/Port_Moresby",
  PT: "Europe/Lisbon",
  RU: "Europe/Moscow",
  UZ: "Asia/Tashkent",
};

/**
 * Las zonas de un país: la principal y las frecuentes primero, en su orden; luego las
 * demás.
 */
export function zonasDelPais(pais: string | null | undefined): string[] {
  const codigo = (pais ?? "").toUpperCase();
  const zonas = (ZONAS_POR_PAIS[codigo] ?? "").split(" ").filter(Boolean);
  const principal = ZONA_PRINCIPAL[codigo];
  const primero: string[] = [
    ...(principal && zonas.includes(principal) ? [principal] : []),
    ...ZONAS_FRECUENTES.filter((z) => zonas.includes(z)),
  ];
  return [...primero, ...zonas.filter((z) => !primero.includes(z))];
}

let todas: string[] | null = null;

/**
 * Todas las zonas: las que conoce el navegador (`Intl.supportedValuesOf`), con su
 * nombre de IANA. Sin esa función, las de la base de zonas por país. Las frecuentes,
 * primero.
 */
export function todasLasZonas(): string[] {
  if (todas === null) {
    let delNavegador: string[] = [];
    try {
      delNavegador =
        typeof Intl.supportedValuesOf === "function"
          ? Intl.supportedValuesOf("timeZone").map(normalizarZona)
          : [];
    } catch {
      delNavegador = [];
    }
    const fuente =
      delNavegador.length > 0
        ? delNavegador
        : Object.values(ZONAS_POR_PAIS).flatMap((z) => z.split(" "));
    todas = [...new Set<string>([...ZONAS_FRECUENTES, ...fuente.sort()])];
  }
  return todas;
}

/** Todas las zonas (se conserva el nombre: la configuración inicial lo usa). */
export const ZONAS_HORARIAS: readonly string[] = todasLasZonas();

/** Las zonas a ofrecer: todas y, si ya tiene otra (vieja o rara), también esa. */
export function zonasConActual(actual: string | null | undefined): string[] {
  const lista = todasLasZonas();
  return actual && !lista.includes(actual) ? [...lista, actual] : [...lista];
}

/** «America/Mexico_City» → clave i18n segura (los «/» no van en claves). */
export function claveZona(zona: string): string {
  return zona.replace(/\//g, "__");
}

/** «America/Argentina/Buenos_Aires» → «Buenos Aires». */
function ciudadDeZona(zona: string): string {
  return (zona.split("/").pop() ?? zona).replace(/_/g, " ");
}

/**
 * Cómo se llama una zona: su nombre propio si lo tiene (`region.zonas`) o «País
 * (Ciudad)»; sin país conocido, la ciudad.
 */
export function etiquetaZona(zona: string): string {
  const clave = `region.zonas.${claveZona(zona)}`;
  if (i18n.global.te(clave)) {
    return i18n.global.t(clave);
  }
  const pais = paisDeZona(zona);
  const ciudad = ciudadDeZona(zona);
  return pais ? `${nombrePais(pais)} (${ciudad})` : ciudad;
}

// Un formato por zona: armar cientos cada vez que se abre una lista es lento.
const formatos = new Map<string, Intl.DateTimeFormat | null>();

/** Su diferencia con UTC en ese momento («UTC-6»), o vacío si no se sabe. */
export function desfaseZona(zona: string, fecha: Date = new Date()): string {
  if (!formatos.has(zona)) {
    try {
      formatos.set(
        zona,
        new Intl.DateTimeFormat("en-US", {
          timeZone: zona,
          timeZoneName: "shortOffset",
        }),
      );
    } catch {
      formatos.set(zona, null);
    }
  }
  const parte = formatos
    .get(zona)
    ?.formatToParts(fecha)
    .find((p) => p.type === "timeZoneName");
  return parte ? parte.value.replace("GMT", "UTC") : "";
}

/**
 * Las zonas para un selector con buscador: las del país del negocio primero (con su
 * grupo) y luego todas por nombre. Con `actual` fuera de la lista, también esa.
 */
export function opcionesZona(
  pais: string | null | undefined,
  actual?: string | null,
): OpcionBuscable[] {
  const t = i18n.global.t;
  const delPais = zonasDelPais(pais);
  const opcion = (zona: string, grupo: string): OpcionBuscable => ({
    valor: zona,
    etiqueta: etiquetaZona(zona),
    detalle: desfaseZona(zona),
    grupo,
    busqueda: `${zona} ${ciudadDeZona(zona)}`,
  });
  const grupoPais = t("region.buscador.zonasDe", {
    pais: nombrePais((pais ?? PAIS_POR_OMISION).toUpperCase()),
  });
  const grupoTodas = t("region.buscador.todasLasZonas");
  const resto = zonasConActual(actual)
    .filter((z) => !delPais.includes(z))
    .map((z) => opcion(z, grupoTodas))
    .sort((a, b) => a.etiqueta.localeCompare(b.etiqueta, "es"));
  return [...delPais.map((z) => opcion(z, grupoPais)), ...resto];
}

/** Los países para un selector con buscador: los frecuentes primero. */
export function opcionesPais(): OpcionBuscable[] {
  const t = i18n.global.t;
  return paisesOrdenados().map((p) => ({
    valor: p.codigo,
    etiqueta: p.nombre,
    detalle: `+${p.lada}`,
    grupo: esFrecuente(p.codigo)
      ? t("region.buscador.frecuentes")
      : t("region.buscador.todosLosPaises"),
    busqueda: p.codigo,
  }));
}

/**
 * La zona horaria del navegador con su nombre de IANA, o null si no la da o es UTC
 * (navegadores que la ocultan).
 */
export function zonaDelNavegador(): string | null {
  try {
    const zona = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (!zona || zona === "UTC" || zona.startsWith("Etc/")) {
      return null;
    }
    return normalizarZona(zona);
  } catch {
    return null;
  }
}

/**
 * El país que se propone al registrar un negocio: el de la zona horaria del navegador
 * (dice dónde está quien registra) y, sin ella, la región de su idioma («es-CO»). Si
 * no, México.
 */
export function paisSugerido(): string {
  const zona = zonaDelNavegador();
  const deZona = zona ? paisDeZona(zona) : null;
  if (deZona && esPais(deZona)) {
    return deZona;
  }
  const idiomas =
    typeof navigator === "undefined"
      ? []
      : navigator.languages?.length
        ? navigator.languages
        : [navigator.language];
  for (const idioma of idiomas) {
    const region = /^[a-z]{2,3}[-_]([a-z]{2})(?:[-_]|$)/i.exec(idioma ?? "");
    if (region && esPais(region[1])) {
      return region[1].toUpperCase();
    }
  }
  return PAIS_POR_OMISION;
}

/**
 * La zona con que nace un negocio de `pais`: la del navegador si es de ese país; si
 * no, la primera del país (la única, la principal o la frecuente: la del centro de
 * México, Nueva York, Madrid…). Nunca la de otro país: se corrige después en «País,
 * moneda y zona horaria». Null si el país no tiene ninguna (el API pone la suya).
 */
export function zonaSugerida(pais: string): string | null {
  const navegador = zonaDelNavegador();
  if (navegador && paisDeZona(navegador) === pais.toUpperCase()) {
    return navegador;
  }
  return zonasDelPais(pais)[0] ?? null;
}
