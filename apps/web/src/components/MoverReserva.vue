<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import AvisoTono from "@/components/AvisoTono.vue";
import { api, mensajeDeError } from "@/lib/api";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Mover a un alumno a otra fecha de la MISMA clase (fase 2, punto 2.1): conserva su
 * crédito apartado. Ofrece las próximas fechas programadas de la clase con lugar.
 */
interface SesionLibre {
  id: string;
  oferta_id: string | null;
  inicia_en: string;
  zona_horaria: string;
  capacidad: number | null;
  ocupados: number;
  estado: string;
}

const props = defineProps<{
  base: string;
  reservaId: string;
  sesionId: string;
  ofertaId: string | null;
}>();

const emit = defineEmits<{ hecho: []; cerrar: [] }>();

const sesiones = ref<SesionLibre[]>([]);
const destino = ref("");
const guardando = ref(false);
const error = ref<string | null>(null);

const opciones = computed(() =>
  sesiones.value.filter(
    (s) =>
      s.id !== props.sesionId &&
      s.oferta_id === props.ofertaId &&
      s.estado === "programada" &&
      new Date(s.inicia_en).getTime() > Date.now() &&
      (s.capacidad === null || s.ocupados < s.capacidad),
  ),
);

function cuando(s: SesionLibre): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: s.zona_horaria,
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(s.inicia_en));
}

const sesion = useSesionTenantStore();

onMounted(async () => {
  // Desde hoy en el negocio (no el día UTC: de noche ya sería mañana) y solo las
  // fechas de esta clase, para que no se queden fuera por el tope de la agenda.
  const zona = sesion.zonaHoraria || "America/Mexico_City";
  const desde = hoyEnNegocio(zona);
  const hasta = hoyEnNegocio(zona, new Date(Date.now() + 30 * 86_400_000));
  try {
    const { data } = await api.get<{ data: SesionLibre[] }>(
      `${props.base}/sesiones`,
      {
        params: {
          desde,
          hasta,
          ...(props.ofertaId ? { oferta_id: props.ofertaId } : {}),
        },
      },
    );
    sesiones.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
});

async function mover(): Promise<void> {
  if (destino.value === "") {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    await api.post(`${props.base}/reservas/${props.reservaId}/reprogramar`, {
      sesion_id: destino.value,
    });
    emit("hecho");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <form
    class="mt-2 space-y-3 rounded-lg border p-3 text-sm"
    :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
    :aria-label="$t('reprogramar.moverTitulo')"
    @submit.prevent="mover"
  >
    <p
      v-if="opciones.length === 0 && !error"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("reprogramar.sinFechas") }}
    </p>
    <div v-else-if="opciones.length > 0">
      <label class="tu-label" :for="`mv-${reservaId}`">{{
        $t("reprogramar.otraFecha")
      }}</label>
      <select
        :id="`mv-${reservaId}`"
        v-model="destino"
        class="tu-input"
        required
      >
        <option value="" disabled>{{ $t("reprogramar.elegir") }}</option>
        <option v-for="s in opciones" :key="s.id" :value="s.id">
          {{ cuando(s) }}
        </option>
      </select>
    </div>
    <AvisoTono v-if="error" tono="peligro" role="alert">{{ error }}</AvisoTono>
    <div class="flex flex-wrap gap-2">
      <button
        v-if="opciones.length > 0"
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="guardando || destino === ''"
      >
        {{ $t("reprogramar.mover") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="guardando"
        @click="emit('cerrar')"
      >
        {{ $t("reprogramar.volver") }}
      </button>
    </div>
  </form>
</template>
