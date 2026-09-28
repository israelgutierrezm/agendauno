import { computed, ref } from "vue";

import type { SesionAgenda } from "@/lib/agenda";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Lo que imparte quien da clases o atiende citas (su portal): sus sesiones de un
 * rango de fechas, pedidas con su propio id para que un dueño o recepcionista que
 * también imparte vea solo las suyas. El servidor ya acota al instructor.
 */
export type ClaseMia = SesionAgenda & { sucursal?: string | null };

/** Fecha local AAAA-MM-DD. */
export function isoLocal(d: Date): string {
  const p = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

/** Fecha local de un instante en la zona de la sede. */
export function fechaEnZona(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: zona,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date(iso));
}

export function useMisClases() {
  const sesion = useSesionTenantStore();
  const base = computed(() => `/api/v1/app/${sesion.slug}`);
  const clases = ref<ClaseMia[]>([]);
  const cargando = ref(false);
  const error = ref<string | null>(null);
  let pedido = 0;

  /** Sus sesiones programadas entre dos fechas locales (ambas incluidas). */
  async function cargar(desde: string, hasta: string): Promise<void> {
    const mio = ++pedido;
    cargando.value = clases.value.length === 0;
    error.value = null;
    try {
      const { data } = await api.get<{ data: ClaseMia[] }>(
        `${base.value}/sesiones`,
        {
          params: {
            desde,
            hasta,
            instructor_id: sesion.usuario?.ulid,
          },
        },
      );
      if (mio !== pedido) {
        return; // Llegó tarde: ya se pidió otro rango.
      }
      // El servidor ensancha un día por lado (zonas): se recorta a las fechas locales.
      clases.value = data.data
        .filter((s) => s.estado === "programada")
        .filter((s) => {
          const f = fechaEnZona(s.inicia_en, s.zona_horaria);
          return f >= desde && f <= hasta;
        })
        .sort((a, b) => a.inicia_en.localeCompare(b.inicia_en));
    } catch (e) {
      if (mio === pedido) {
        error.value = mensajeDeError(e);
      }
    } finally {
      if (mio === pedido) {
        cargando.value = false;
      }
    }
  }

  return { base, clases, cargando, error, cargar };
}
