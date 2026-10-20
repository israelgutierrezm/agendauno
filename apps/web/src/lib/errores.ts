import { isAxiosError } from "axios";
import type { App } from "vue";

import { api } from "@/lib/api";
import { getCorrelationId } from "@/lib/correlationId";

/**
 * Reporta al monitoreo de la plataforma (ADR 0080) los errores de la web que nadie
 * atrapó: los de Vue, los de JavaScript y las promesas rechazadas sin manejar.
 *
 * No reporta los errores de la API (ya se registran allá), los de otros orígenes
 * (extensiones del navegador) ni ruido conocido del navegador. Cada error se manda
 * una sola vez por página, y como mucho 10. El servidor tacha lo sensible.
 *
 * También lo que bloquea la CSP (ADR 0174): para una página de la plataforma eso es
 * algo que se rompió (un servicio que no está en la lista), no un ataque. Sin lo que
 * inyectan las extensiones del navegador.
 */
const MAXIMO_POR_PAGINA = 10;
const RUIDO = [/ResizeObserver loop/i, /^Script error\.?$/i];

interface Contexto {
  ruta: () => string | null;
  estudio: () => string | null;
}

interface ErrorDescrito {
  tipo: string;
  mensaje: string;
  lugar?: string;
  traza?: string;
}

let contexto: Contexto = { ruta: () => null, estudio: () => null };
const vistos = new Set<string>();
let enviados = 0;

/** Solo para las pruebas: empieza de cero. */
export function reiniciarReporteDeErrores(): void {
  vistos.clear();
  enviados = 0;
}

/** El primer lugar de nuestro código en la traza, sin el dominio. */
function lugarDe(traza: string | undefined): string | undefined {
  const origen = window.location.origin;
  for (const linea of traza?.split("\n") ?? []) {
    const encontrado = /(https?:\/\/[^\s)]+):(\d+):(\d+)/.exec(linea);
    if (encontrado !== null && encontrado[1].startsWith(origen)) {
      const archivo = encontrado[1].slice(origen.length).replace(/^\//, "");
      return `${archivo}:${encontrado[2]}:${encontrado[3]}`;
    }
  }
  return undefined;
}

export function describirError(error: unknown): ErrorDescrito | null {
  if (error instanceof Error) {
    return {
      tipo: error.name || "Error",
      mensaje: error.message || String(error),
      lugar: lugarDe(error.stack),
      traza: error.stack?.slice(0, 8000),
    };
  }
  if (typeof error === "string" && error.trim() !== "") {
    return { tipo: "Error", mensaje: error };
  }
  return null;
}

function esRuido(error: unknown, archivo?: string): boolean {
  if (isAxiosError(error)) {
    return true;
  }
  if (
    archivo !== undefined &&
    archivo !== "" &&
    !archivo.startsWith(window.location.origin)
  ) {
    return true;
  }
  const mensaje = error instanceof Error ? error.message : String(error);
  return RUIDO.some((r) => r.test(mensaje));
}

export function reportarError(error: unknown, archivo?: string): void {
  if (enviados >= MAXIMO_POR_PAGINA || esRuido(error, archivo)) {
    return;
  }
  const descrito = describirError(error);
  if (descrito === null) {
    return;
  }
  const clave = `${descrito.tipo}|${descrito.mensaje}|${descrito.lugar ?? ""}`;
  if (vistos.has(clave)) {
    return;
  }
  vistos.add(clave);
  enviados++;

  const cuerpo = {
    origen: "web",
    ...descrito,
    ruta: contexto.ruta(),
    estudio: contexto.estudio(),
    version: (import.meta.env.VITE_APP_VERSION as string | undefined) ?? "dev",
  };
  void fetch(`${api.defaults.baseURL ?? ""}/api/v1/errores`, {
    method: "POST",
    keepalive: true,
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "X-Correlation-ID": getCorrelationId(),
    },
    body: JSON.stringify(cuerpo),
  }).catch(() => undefined);
}

const EXTENSIONES = /^(chrome|moz|safari|ms-browser)-extension:/i;

/** Lo que bloqueó la CSP, sin la query ni el resto de la dirección. */
function bloqueado(uri: string): string {
  if (uri === "" || !uri.includes(":")) {
    return uri || "inline";
  }
  try {
    const url = new URL(uri);
    return url.origin === "null" ? url.protocol : url.origin + url.pathname;
  } catch {
    return uri.split(/[?#]/)[0] ?? uri;
  }
}

/** Una violación de la CSP como error para el monitoreo; null si es ruido. */
export function errorDeCsp(evento: {
  blockedURI: string;
  effectiveDirective: string;
  sourceFile: string;
}): Error | null {
  if (
    EXTENSIONES.test(evento.blockedURI) ||
    EXTENSIONES.test(evento.sourceFile)
  ) {
    return null;
  }
  const error = new Error(
    `Bloqueado ${bloqueado(evento.blockedURI)} por ${evento.effectiveDirective}`,
  );
  error.name = "CSP";
  // El lugar no es nuestro código: no se manda una traza que no dice nada.
  error.stack = "";
  return error;
}

export function instalarReporteDeErrores(app: App, datos: Contexto): void {
  contexto = datos;
  app.config.errorHandler = (error) => {
    reportarError(error);
    console.error(error);
  };
  window.addEventListener("error", (evento) => {
    reportarError(evento.error ?? evento.message, evento.filename);
  });
  window.addEventListener("unhandledrejection", (evento) => {
    reportarError(evento.reason);
  });
  document.addEventListener("securitypolicyviolation", (evento) => {
    const error = errorDeCsp(evento);
    if (error !== null) {
      reportarError(error);
    }
  });
}
