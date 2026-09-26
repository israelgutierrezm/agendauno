<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import { api, mensajeDeError } from "@/lib/api";

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

onMounted(async () => {
  const hoy = new Date();
  const hasta = new Date(hoy.getTime() + 30 * 86_400_000);
  const iso = (d: Date): string => d.toISOString().slice(0, 10);
  try {
    const { data } = await api.get<{ data: SesionLibre[] }>(
      `${props.base}/sesiones`,
      { params: { desde: iso(hoy), hasta: iso(hasta) } },
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
    <p v-if="error" style="color: var(--error)">{{ error }}</p>
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
