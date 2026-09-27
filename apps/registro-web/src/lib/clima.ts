import { ref } from "vue";

import { api } from "@/lib/api";

/**
 * El clima de los Inicios (alumno: GET /mi/clima; equipo: GET /clima): el
 * pronóstico para la próxima clase o cita en su sede (`pronostico`) o el de ahora
 * (`ahora`; `aproximado` si salió de la IP). Se pide aparte y sin esperar: si no
 * llega, la tarjeta simplemente no lo muestra.
 */
export interface Clima {
  tipo: "pronostico" | "ahora";
  lugar: string;
  aproximado: boolean;
  temperatura: number;
  condicion: string;
  icono: string;
  es_de_dia: boolean;
  lluvia: number | null;
  // De qué es el pronóstico (clase o cita), para nombrarlo igual que la tarjeta.
  sesion_tipo?: "clase" | "cita" | null;
}

export function useClima(url: () => string) {
  const clima = ref<Clima | null>(null);

  async function cargar(): Promise<void> {
    try {
      const { data } = await api.get<{ data: Clima | null }>(url());
      clima.value =
        data.data && typeof data.data.temperatura === "number"
          ? data.data
          : null;
    } catch {
      clima.value = null;
    }
  }

  return { clima, cargar };
}

type Traducir = (clave: string, valores?: Record<string, unknown>) => string;

/** "Pronóstico para tu cita en Roma Norte", "Ahora cerca de Guadalajara"… */
export function lugarDelClima(
  c: Clima | null,
  t: Traducir,
  tipoReserva?: "clase" | "cita" | null,
): string {
  if (!c) {
    return "";
  }
  if (c.tipo === "pronostico") {
    return (c.sesion_tipo ?? tipoReserva) === "cita"
      ? t("portal.inicio.clima.pronosticoCita", { lugar: c.lugar })
      : t("portal.inicio.clima.pronostico", { lugar: c.lugar });
  }
  if (c.lugar === "") {
    return "";
  }
  return c.aproximado
    ? t("portal.inicio.clima.ahoraCerca", { lugar: c.lugar })
    : t("portal.inicio.clima.ahora", { lugar: c.lugar });
}
