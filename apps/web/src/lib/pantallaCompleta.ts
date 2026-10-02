import { computed, onBeforeUnmount, onMounted, ref } from "vue";

// Solo presentación: no cambia permisos, filtros ni preferencias guardadas.
export const agendaAmpliada = ref(false);

export function usePantallaCompleta(esAgenda = false) {
  const nativa = ref(false);
  const disponible = ref(false);
  const pendiente = ref(false);
  let propia = false;
  let desmontado = false;

  function sincronizar() {
    const antes = nativa.value;
    nativa.value = Boolean(document.fullscreenElement);
    disponible.value = Boolean(
      typeof document.documentElement.requestFullscreen === "function" &&
      typeof document.exitFullscreen === "function" &&
      document.fullscreenEnabled !== false,
    );
    if (esAgenda && antes && !nativa.value) {
      agendaAmpliada.value = false;
      propia = false;
    }
  }

  async function cerrarAgenda() {
    agendaAmpliada.value = false;
    if (propia && document.fullscreenElement) await document.exitFullscreen();
    propia = false;
  }

  async function alternar(): Promise<boolean> {
    if (pendiente.value) return true;
    pendiente.value = true;
    try {
      if (esAgenda && agendaAmpliada.value) {
        await cerrarAgenda();
      } else if (!esAgenda && document.fullscreenElement) {
        await document.exitFullscreen();
      } else {
        if (esAgenda) agendaAmpliada.value = true;
        if (!document.fullscreenElement) {
          if (!disponible.value) return false;
          // El documento completo mantiene visibles los diálogos teletransportados
          // a body (cobros, detalles y nueva cita), también al ampliar la agenda.
          await document.documentElement.requestFullscreen();
          propia = esAgenda;
          if (desmontado && propia) await cerrarAgenda();
        }
      }
      return true;
    } catch {
      // Si el navegador lo rechaza, la agenda conserva el modo sin distracciones.
      return false;
    } finally {
      sincronizar();
      pendiente.value = false;
    }
  }

  function teclado(evento: KeyboardEvent) {
    if (
      esAgenda &&
      agendaAmpliada.value &&
      evento.key === "Escape" &&
      !evento.defaultPrevented &&
      !document.querySelector('[aria-modal="true"]')
    ) {
      void cerrarAgenda().catch(() => undefined);
    }
  }

  onMounted(() => {
    sincronizar();
    document.addEventListener("fullscreenchange", sincronizar);
    window.addEventListener("keydown", teclado);
  });
  onBeforeUnmount(() => {
    desmontado = true;
    document.removeEventListener("fullscreenchange", sincronizar);
    window.removeEventListener("keydown", teclado);
    if (esAgenda) void cerrarAgenda().catch(() => undefined);
  });

  return {
    activa: computed(() => (esAgenda ? agendaAmpliada.value : nativa.value)),
    disponible,
    pendiente,
    alternar,
  };
}
