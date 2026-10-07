import axios, { type InternalAxiosRequestConfig } from "axios";

import { i18n } from "@/i18n";
import { getCorrelationId } from "@/lib/correlationId";
import { conTerminosActuales } from "@/lib/terminologia";

// Una API colgada no deja la pantalla esperando sin fin: a los 30 s la petición
// falla como «sin conexión». Subir o descargar archivos tiene más margen.
export const TIEMPO_LIMITE_MS = 30_000;
const TIEMPO_LIMITE_ARCHIVOS_MS = 300_000;

/**
 * Cliente HTTP del flujo multi-tenant (registro/directorio/login por estudio).
 *
 * A diferencia de la SPA de administracion legacy (cookie de Sanctum), aqui la
 * identidad es TENANT-LOCAL: no hay login global. Tras iniciar sesion en un
 * estudio se guarda un bearer token que se envia en cada peticion. Cada peticion
 * lleva un `X-Correlation-ID` para trazabilidad.
 */
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? "http://localhost:8000",
  timeout: TIEMPO_LIMITE_MS,
  headers: {
    Accept: "application/json",
  },
});

let bearer: string | null = null;
let negocioDelBearer: string | null = null;

/**
 * Fija (o limpia) el bearer tenant-local y el negocio al que pertenece. Solo viaja a
 * las rutas de ESE negocio (`/api/v1/app/{slug}/…`): nunca a las de otro negocio (sus
 * páginas públicas) ni a la plataforma, que manda su propia credencial.
 */
export function fijarBearer(
  token: string | null,
  slug: string | null = null,
): void {
  bearer = token;
  negocioDelBearer = token === null ? null : slug;
}

/** ¿La petición va a las rutas de este negocio? */
export function esDelNegocio(url: string | undefined, slug: string): boolean {
  const ruta = (url ?? "").replace(/^https?:\/\/[^/]+/, "");
  const base = `/api/v1/app/${slug}`;
  return (
    ruta === base || ruta.startsWith(`${base}/`) || ruta.startsWith(`${base}?`)
  );
}

api.interceptors.request.use((config) => {
  config.headers.set("X-Correlation-ID", getCorrelationId());

  if (
    bearer !== null &&
    negocioDelBearer !== null &&
    !config.headers.has("Authorization") &&
    esDelNegocio(config.url, negocioDelBearer)
  ) {
    config.headers.set("Authorization", `Bearer ${bearer}`);
  }

  // Una foto, un documento o un reporte por una red lenta tarda más que 30 s.
  if (
    config.timeout === TIEMPO_LIMITE_MS &&
    (config.data instanceof FormData || config.responseType === "blob")
  ) {
    config.timeout = TIEMPO_LIMITE_ARCHIVOS_MS;
  }

  return config;
});

// Rutas del negocio para entrar, salir o activar la cuenta: su respuesta no dice
// nada de la sesión guardada.
const RUTAS_DE_ACCESO = new Set([
  "/login",
  "/logout",
  "/auth/google",
  "/activar",
  "/reenviar-activacion",
  "/recuperar-contrasena",
  "/restablecer-contrasena",
  "/confirmar-correo",
]);

/**
 * ¿La petición se mandó con el token de la sesión ACTUAL a una ruta que lo usa? Una
 * petición con su propia credencial (la plataforma), a otro negocio o con un token
 * anterior (se volvió a entrar mientras viajaba) no habla de esta sesión.
 */
function usoLaSesion(config: InternalAxiosRequestConfig | undefined): boolean {
  if (config === undefined || bearer === null || negocioDelBearer === null) {
    return false;
  }
  if (
    !esDelNegocio(config.url, negocioDelBearer) ||
    config.headers.get("Authorization") !== `Bearer ${bearer}`
  ) {
    return false;
  }
  const ruta = (config.url ?? "")
    .replace(/^https?:\/\/[^/]+/, "")
    .split("?")[0]
    .slice(`/api/v1/app/${negocioDelBearer}`.length);
  return !RUTAS_DE_ACCESO.has(ruta);
}

let alPerder: (() => void) | null = null;

/**
 * Quién atiende una sesión que el servidor ya no reconoce (la limpia y lleva a
 * «Entrar»). Lo registra main.ts: así este cliente no depende del store ni del router.
 */
export function alPerderSesion(manejador: (() => void) | null): void {
  alPerder = manejador;
}

// Un 401 con el token de la sesión: lo revocaron (baja, cambio de contraseña, cerró
// sesión en otro lado). Se avisa una sola vez: al limpiarla, el token deja de viajar.
api.interceptors.response.use(undefined, (error: unknown) => {
  if (
    axios.isAxiosError(error) &&
    error.response?.status === 401 &&
    usoLaSesion(error.config)
  ) {
    alPerder?.();
  }
  return Promise.reject(error);
});

/** Por qué no se pudo hablar con la API sin que eso diga nada de la sesión. */
export type FallaPasajera = "sin-conexion" | "mantenimiento" | "servidor";

/**
 * ¿El error dice que la sesión guardada ya no sirve? 401: el token no existe o fue
 * revocado. 404: el negocio ya no existe o no opera (ResolverEstudio).
 */
export function esSesionInvalida(e: unknown): boolean {
  const estado = axios.isAxiosError(e) ? e.response?.status : undefined;
  return estado === 401 || estado === 404;
}

/**
 * Clasifica un error que NO invalida la sesión: sin respuesta (red caída o tiempo
 * agotado), mantenimiento (503, `artisan down` al publicar) u otro tropiezo del
 * servidor (429, 5xx…). `null` si el error invalida la sesión.
 */
export function fallaPasajera(e: unknown): FallaPasajera | null {
  if (esSesionInvalida(e)) {
    return null;
  }
  if (!axios.isAxiosError(e)) {
    return "servidor";
  }
  if (e.response === undefined) {
    return "sin-conexion";
  }
  return e.response.status === 503 ? "mantenimiento" : "servidor";
}

/**
 * Extrae un mensaje legible del contrato de error de la API
 * `{code, message, meta:{errors}}`. Prefiere el primer error de campo (más
 * específico) cuando la respuesta es de validación; si no, usa `message`.
 */
export function mensajeDeError(
  e: unknown,
  porDefecto = "Ocurrió un error inesperado.",
): string {
  if (axios.isAxiosError(e)) {
    // Sin respuesta (sin red, servidor caído o tiempo agotado): no es un error del dato.
    if (e.response === undefined && e.code !== "ERR_CANCELED") {
      return i18n.global.t("comun.sinRespuesta");
    }
    const data = e.response?.data as
      | { message?: string; meta?: { errors?: Record<string, string[]> } }
      | undefined;

    const errores = data?.meta?.errors;
    if (errores) {
      const primero = Object.values(errores)[0]?.[0];
      if (typeof primero === "string" && primero !== "") {
        return conTerminosActuales(primero);
      }
    }

    // En la terminología del negocio: "Esta clase…" se lee "Esta cita…".
    return conTerminosActuales(data?.message ?? porDefecto);
  }

  return porDefecto;
}
