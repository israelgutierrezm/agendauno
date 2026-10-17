/**
 * Casos de los pilotos para la landing (evidencia, no promesas). Solo negocios reales
 * que autorizaron aparecer, con lo que de verdad pasó:
 * - qué problema tenían y qué recorrido resolvieron con el producto;
 * - qué cambió en su operación, dicho en hechos (sin cifras de ahorro o crecimiento
 *   que no se puedan respaldar);
 * - una imagen real del producto con sus datos (o con datos de demostración, dicho
 *   así en el texto alternativo).
 * Vacío, la sección no aparece. Ver docs/ACEPTACION-V1.md (pilotos) y
 * docs/PLAN-MEJORAS-CAPTACION.md (C01.6).
 */
// Relativo y con extensión: lo importa el pre-generado de la landing.
import type { Modo } from "./modalidades.ts";

export interface CasoPiloto {
  /** Nombre con que el negocio autorizó aparecer. */
  negocio: string;
  /** Giro y ciudad («Estudio de pilates · Guadalajara»). */
  giro: string;
  problema: string;
  /** El recorrido que resolvieron (de la reserva al cobro, la lista de espera…). */
  recorrido: string;
  /** Qué cambió en su operación, en hechos. */
  cambio: string;
  imagen: { src: string; alt: string };
  /** Cómo y cuándo autorizó aparecer (no se muestra; queda para revisión). */
  autorizacion: string;
}

export const CASOS: Record<Modo, readonly CasoPiloto[]> = {
  clases: [],
  citas: [],
};
