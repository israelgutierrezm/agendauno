import axios from "axios";

import { getCorrelationId } from "@/lib/correlationId";

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
  headers: {
    Accept: "application/json",
  },
});

let bearer: string | null = null;

/** Fija (o limpia) el bearer tenant-local para las siguientes peticiones. */
export function fijarBearer(token: string | null): void {
  bearer = token;
}

api.interceptors.request.use((config) => {
  config.headers.set("X-Correlation-ID", getCorrelationId());

  if (bearer !== null) {
    config.headers.set("Authorization", `Bearer ${bearer}`);
  }

  return config;
});

/**
 * Extrae un mensaje legible del contrato de error de la API
 * `{code, message, meta:{errors}}`. Prefiere el primer error de campo (más
 * específico) cuando la respuesta es de validación; si no, usa `message`.
 */
export function mensajeDeError(
  e: unknown,
  porDefecto = "Ocurrio un error inesperado.",
): string {
  if (axios.isAxiosError(e)) {
    const data = e.response?.data as
      | { message?: string; meta?: { errors?: Record<string, string[]> } }
      | undefined;

    const errores = data?.meta?.errors;
    if (errores) {
      const primero = Object.values(errores)[0]?.[0];
      if (typeof primero === "string" && primero !== "") {
        return primero;
      }
    }

    return data?.message ?? porDefecto;
  }

  return porDefecto;
}
