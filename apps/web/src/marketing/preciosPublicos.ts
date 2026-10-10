import axios from "axios";
import { onMounted, reactive } from "vue";

import { PRECIOS_POR_OMISION, type PreciosPublicos } from "@/marketing/precios";

/**
 * Los precios que publica el superadmin (ADR 0107), para la landing: se piden una vez
 * a `GET /api/v1/precios` y se comparten entre las secciones. Mientras llegan (y en el
 * HTML pre-generado) rige el respaldo de `precios.ts`. Si la API no responde, se queda
 * el respaldo.
 */
export const preciosPublicos = reactive<{
  datos: PreciosPublicos;
  cargados: boolean;
}>({ datos: PRECIOS_POR_OMISION, cargados: false });

let pedido: Promise<void> | null = null;

/** Cambia los precios mostrados (lo que respondió la API o una prueba). */
export function aplicarPreciosPublicos(datos: Partial<PreciosPublicos>): void {
  const base = PRECIOS_POR_OMISION;
  preciosPublicos.datos = {
    clases: { ...base.clases, ...datos.clases },
    citas: { ...base.citas, ...datos.citas },
    ventas: { ...base.ventas, ...datos.ventas },
    timbres: { ...base.timbres, ...datos.timbres },
    registro: { ...base.registro, ...datos.registro },
  };
  preciosPublicos.cargados = true;
}

/** Pide los precios a la API (una sola vez; en las pruebas, nunca). */
export function cargarPreciosPublicos(): Promise<void> {
  if (typeof window === "undefined" || import.meta.env.MODE === "test") {
    return Promise.resolve();
  }
  pedido ??= axios
    .get<{ data: PreciosPublicos }>(
      `${import.meta.env.VITE_API_URL ?? "http://localhost:8000"}/api/v1/precios`,
      { timeout: 8000, headers: { Accept: "application/json" } },
    )
    .then(({ data }) => aplicarPreciosPublicos(data.data))
    .catch(() => {
      // Sin respuesta: se queda el respaldo y se vuelve a intentar en otra página.
      pedido = null;
    });
  return pedido;
}

/** Para una sección de la landing: los precios vigentes (se piden al montarla). */
export function usePreciosPublicos(): typeof preciosPublicos {
  onMounted(() => void cargarPreciosPublicos());
  return preciosPublicos;
}
