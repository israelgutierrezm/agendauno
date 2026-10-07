import axios from "axios";

import { PERFILES_POR_MODO } from "@/marketing/modalidades";

/**
 * Modalidad del negocio (ADR 0104): cada negocio es solo de clases o solo de citas,
 * nunca de ambas. La guarda el servidor (`estudios.modalidad`) y la manda con sus
 * `capacidades` en la sesión y en el escaparate: la web no la deduce del giro, de los
 * pasos de la configuración inicial ni de las ofertas.
 */

/** Cómo atiende el negocio: clases con cupo o citas 1 a 1 con un profesional. */
export type ModalidadServicio = "clases" | "citas";

/** Lo que el negocio ofrece, según el servidor. */
export interface Capacidades {
  clases: boolean;
  citas: boolean;
}

/** Lo que el servidor dice de un negocio (sesión o escaparate). */
export interface DatosModalidad {
  modalidad?: ModalidadServicio | null;
  capacidades?: Capacidades | null;
  // Respaldo mientras una respuesta aún no trae `modalidad`.
  perfil_config?: { modalidad?: ModalidadServicio } | null;
}

export function capacidadesDe(modalidad: ModalidadServicio): Capacidades {
  return { clases: modalidad === "clases", citas: modalidad === "citas" };
}

/** La modalidad que dice el servidor; sin dato, clases. */
export function modalidadDe(
  datos: DatosModalidad | null | undefined,
): ModalidadServicio {
  return datos?.modalidad ?? datos?.perfil_config?.modalidad ?? "clases";
}

/** Las capacidades que dice el servidor; sin ellas, las de su modalidad. */
export function capacidadesDeNegocio(
  datos: DatosModalidad | null | undefined,
): Capacidades {
  return datos?.capacidades ?? capacidadesDe(modalidadDe(datos));
}

/**
 * Giros (perfil de negocio) de cada modalidad: al registrarse, el giro da la
 * modalidad inicial; después solo se cambia a otro giro de la misma (cambiar de
 * modalidad lo hace AgendaUno). Es la misma lista que `ModalidadServicio::perfiles()`
 * del servidor, que la manda en GET /onboarding (`perfiles`) pero aún no en la
 * sesión: la configuración inicial usa la del servidor y Configuración, esta. Vive en
 * `marketing/modalidades.ts` (sin dependencias) para que la parte comercial la use
 * sin traer axios.
 */
export const GIROS_POR_MODALIDAD: Record<ModalidadServicio, readonly string[]> =
  PERFILES_POR_MODO;

/** Los giros a los que puede pasar un negocio de esta modalidad. */
export function girosDe(modalidad: ModalidadServicio): readonly string[] {
  return GIROS_POR_MODALIDAD[modalidad];
}

/**
 * ¿El servidor negó una función de la otra modalidad (403 MODALITY_NOT_AVAILABLE)?
 * P. ej. agendar cita en un negocio de clases.
 */
export function esDeOtraModalidad(e: unknown): boolean {
  if (!axios.isAxiosError(e)) {
    return false;
  }
  const data = e.response?.data as { code?: string } | undefined;
  return data?.code === "MODALITY_NOT_AVAILABLE";
}

/** Errores del servidor sobre la modalidad (ADR 0104). */
export const ERRORES_MODALIDAD = new Set([
  "MODALITY_LOCKED",
  "MODALITY_NOT_AVAILABLE",
  "MODALITY_IN_USE",
]);
