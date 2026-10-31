import type { useI18n } from "vue-i18n";

import { api } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";

type Traductor = ReturnType<typeof useI18n>["t"];

// Al cambiar la zona: cuántas fechas conservan su hora y si quedó en la cola.
export interface Reanclaje {
  sesiones: number;
  reservas: number;
  en_cola: boolean;
}

interface SedeGuardada {
  meta?: { reanclaje?: Reanclaje };
}

/** No confirmó el cambio de zona: nada cambió y el formulario sigue abierto. */
export class CambioDeZonaCancelado extends Error {}

/**
 * Guarda una sede que ya existe. Si cambia su zona y tiene fechas por venir (ADR 0230),
 * el servidor no cambia nada y responde cuántas son: se pregunta y solo con el sí se
 * confirma. Devuelve lo que se re-ancló; sin el sí, lanza {@link CambioDeZonaCancelado}.
 */
export async function guardarSede(
  url: string,
  cuerpo: Record<string, unknown>,
  t: Traductor,
): Promise<Reanclaje | undefined> {
  try {
    const { data } = await api.put<SedeGuardada>(url, cuerpo);
    return data.meta?.reanclaje;
  } catch (e) {
    const cambio = (
      e as {
        response?: {
          data?: {
            code?: string;
            meta?: { sesiones: number; reservas: number };
          };
        };
      }
    ).response?.data;
    if (cambio?.code !== "TIMEZONE_CHANGE_UNCONFIRMED" || !cambio.meta) {
      throw e;
    }
    const { sesiones, reservas } = cambio.meta;
    const seguir = await confirmar(
      t("operacion.sedes.confirmarZona", {
        fechas: t("operacion.sedes.zonaFechas", { n: sesiones }, sesiones),
        personas: t("operacion.sedes.zonaPersonas", { n: reservas }, reservas),
      }),
      { aceptar: t("operacion.sedes.cambiarZona") },
    );
    if (!seguir) {
      throw new CambioDeZonaCancelado();
    }
    const { data } = await api.put<SedeGuardada>(url, {
      ...cuerpo,
      confirmar_zona: true,
    });
    return data.meta?.reanclaje;
  }
}
