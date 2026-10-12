import type { Ambito } from "@/lib/atribucion";

/**
 * Las preferencias de medición de quien visita (ADR 0143), por ámbito: cada producto y
 * cada negocio piden y guardan las suyas.
 *
 * - Analítica: Google Analytics 4 (cómo se usa la página).
 * - Publicidad: Meta y las señales de anuncios de Google (medir y mejorar campañas).
 * - Sin elegir, nada opcional se carga ni se manda (por omisión, todo apagado).
 * - Se pueden cambiar o retirar cuando se quiera; el cambio se avisa al servidor para
 *   que se aplique a lo que ya se registró con este navegador.
 *
 * Recordar la elección es necesario para respetarla: se guarda en este navegador
 * (`localStorage`), junto con un id de visitante aleatorio que no identifica a nadie.
 */

export interface Preferencias {
  analitica: boolean;
  publicidad: boolean;
  /** Cuándo se eligió (ISO 8601). */
  en: string;
  version: number;
}

/** Sube si cambia lo que se pide: las preferencias anteriores ya no valen. */
export const VERSION_PREFERENCIAS = 1;

const CLAVE = "tu.preferencias.";
const CLAVE_VISITANTE = "tu.visitante.";
export const EVENTO_PREFERENCIAS = "tu:preferencias-medicion";

function almacen(): Storage | null {
  try {
    return typeof window === "undefined" ? null : window.localStorage;
  } catch {
    return null;
  }
}

export function leerPreferencias(ambito: Ambito): Preferencias | null {
  try {
    const crudo = almacen()?.getItem(CLAVE + ambito);
    if (crudo === null || crudo === undefined) return null;
    const p = JSON.parse(crudo) as Partial<Preferencias>;
    if (p.version !== VERSION_PREFERENCIAS || typeof p.en !== "string") {
      return null;
    }
    return {
      analitica: p.analitica === true,
      publicidad: p.publicidad === true,
      en: p.en,
      version: p.version,
    };
  } catch {
    return null;
  }
}

/** El id de visitante del ámbito, si ya autorizó algo alguna vez. */
export function visitanteDe(ambito: Ambito): string | null {
  try {
    const v = almacen()?.getItem(CLAVE_VISITANTE + ambito) ?? null;
    return v !== null && /^[a-f0-9]{32}$/.test(v) ? v : null;
  } catch {
    return null;
  }
}

function nuevoVisitante(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
}

/**
 * Guarda lo que eligió. Con alguna autorización se crea su id de visitante (si no lo
 * tenía); al retirarlas se conserva para poder avisar el cambio al servidor.
 */
export function guardarPreferencias(
  ambito: Ambito,
  eleccion: { analitica: boolean; publicidad: boolean },
  ahora = new Date(),
): { preferencias: Preferencias; visitante: string | null } {
  const preferencias: Preferencias = {
    analitica: eleccion.analitica,
    publicidad: eleccion.publicidad,
    en: ahora.toISOString(),
    version: VERSION_PREFERENCIAS,
  };
  let visitante = visitanteDe(ambito);
  try {
    const s = almacen();
    s?.setItem(CLAVE + ambito, JSON.stringify(preferencias));
    if (visitante === null && (eleccion.analitica || eleccion.publicidad)) {
      visitante = nuevoVisitante();
      s?.setItem(CLAVE_VISITANTE + ambito, visitante);
    }
  } catch {
    // Sin almacenamiento: vale para esta página y se vuelve a preguntar.
  }
  if (typeof window !== "undefined") {
    window.dispatchEvent(
      new CustomEvent(EVENTO_PREFERENCIAS, {
        detail: { ambito, preferencias },
      }),
    );
  }
  return { preferencias, visitante };
}
