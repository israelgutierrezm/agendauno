/**
 * Tipos y formato de los destinos de medición (GA4 y Meta, ADR 0143), compartidos por
 * el superadmin (los del SaaS) e Integraciones (los de cada negocio).
 */

export type ProveedorMedicion = "ga4" | "meta";

export interface DestinoMedicion {
  producto?: string;
  proveedor: ProveedorMedicion;
  identificador: string | null;
  secreto_configurado: boolean;
  codigo_prueba: string | null;
  activo: boolean;
  activo_desde: string | null;
  aviso_confirmado_en: string | null;
  estado: "apagado" | "activo" | "con_error";
  ultimo_envio_en: string | null;
  ultimo_error: string | null;
  ultimo_error_en: string | null;
  eventos_extra?: string[];
}

export interface EnvioMedicion {
  id: string;
  producto?: string | null;
  proveedor: ProveedorMedicion | null;
  evento: string;
  nombre: string;
  negocio?: string | null;
  ocurrido_en: string;
  estado: "pendiente" | "enviado" | "error" | "omitido";
  motivo: string | null;
  intentos: number;
  proximo_intento_en: string | null;
  enviado_en: string | null;
  ultimo_error: string | null;
}

/** «12 oct, 14:05» en la zona del navegador. */
export function formatoFechaHora(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(iso));
}
