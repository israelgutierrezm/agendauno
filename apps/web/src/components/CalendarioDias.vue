<script setup lang="ts">
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";

/**
 * Calendario de días para agendar una cita (ADR 0065): una tira desde hoy, en la
 * zona de la sede. Los días que ya pasaron, en que nadie atiende o que el negocio
 * cerró no se pueden elegir. Se abre en el primero con atención. Lo usan la página
 * pública (`/citas/dias`) y la cuenta del cliente (`/mi/citas/dias`).
 */
const props = defineProps<{
  // Ruta del API que da los días (pública o de la cuenta).
  ruta: string;
  sucursalId: string;
  // Con alguien de preferencia, solo sus días.
  instructorId?: string | null;
  zona: string;
  modelValue: string;
}>();
const emit = defineEmits<{ "update:modelValue": [string] }>();

interface Dia {
  fecha: string;
  abierto: boolean;
}

const { t } = useI18n();
const DIAS_POR_TANDA = 14;
const dias = ref<Dia[]>([]);
const cargando = ref(false);
const error = ref<string | null>(null);
let consulta = 0;

function hoySede(): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: props.zona,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}
function diaSiguiente(f: string): string {
  const d = new Date(`${f}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + 1);
  return d.toISOString().slice(0, 10);
}

async function cargar(mas = false): Promise<void> {
  if (props.sucursalId === "") return;
  const esta = ++consulta;
  const ultimo = dias.value[dias.value.length - 1];
  const desde = mas && ultimo ? diaSiguiente(ultimo.fecha) : hoySede();
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Dia[] }>(props.ruta, {
      params: {
        sucursal_id: props.sucursalId,
        desde,
        dias: DIAS_POR_TANDA,
        ...(props.instructorId ? { instructor_id: props.instructorId } : {}),
      },
    });
    if (esta !== consulta) return;
    dias.value = mas ? [...dias.value, ...data.data] : data.data;
    // Sin día elegido (o el elegido ya no tiene atención): el primero que sí.
    if (
      !mas &&
      !dias.value.some((d) => d.fecha === props.modelValue && d.abierto)
    ) {
      emit("update:modelValue", dias.value.find((d) => d.abierto)?.fecha ?? "");
    }
  } catch (e) {
    if (esta === consulta) error.value = mensajeDeError(e);
  } finally {
    if (esta === consulta) cargando.value = false;
  }
}

function etiqueta(f: string): { semana: string; numero: string; mes: string } {
  const d = new Date(`${f}T12:00:00Z`);
  const hoy = hoySede();
  const corto = (op: Intl.DateTimeFormatOptions): string =>
    new Intl.DateTimeFormat("es-MX", { ...op, timeZone: "UTC" })
      .format(d)
      .replace(".", "");
  return {
    semana:
      f === hoy
        ? t("perfilPublico.agendar.hoy")
        : f === diaSiguiente(hoy)
          ? t("perfilPublico.agendar.manana")
          : corto({ weekday: "short" }),
    numero: String(d.getUTCDate()),
    mes: corto({ month: "short" }),
  };
}

// Otra sede u otra persona: otros días.
watch(
  () => [props.sucursalId, props.instructorId ?? ""],
  () => {
    dias.value = [];
    void cargar();
  },
  { immediate: true },
);
</script>

<template>
  <div>
    <div class="cd-dias" data-prueba="dias">
      <button
        v-for="d in dias"
        :key="d.fecha"
        type="button"
        class="cd-dia"
        :class="{ 'cd-dia--activo': modelValue === d.fecha }"
        :disabled="!d.abierto"
        :data-fecha="d.fecha"
        :aria-pressed="modelValue === d.fecha"
        @click="emit('update:modelValue', d.fecha)"
      >
        <span class="cd-dia-suave">{{ etiqueta(d.fecha).semana }}</span>
        <strong>{{ etiqueta(d.fecha).numero }}</strong>
        <span class="cd-dia-suave">{{ etiqueta(d.fecha).mes }}</span>
      </button>
      <button
        v-if="dias.length > 0"
        type="button"
        class="cd-dia cd-dia-mas"
        :disabled="cargando"
        data-prueba="mas-fechas"
        @click="cargar(true)"
      >
        {{ $t("perfilPublico.agendar.masFechas") }}
      </button>
    </div>
    <p
      v-if="error"
      class="mt-2 text-sm"
      role="alert"
      style="color: var(--error)"
    >
      {{ error }}
    </p>
    <p
      v-else-if="modelValue === ''"
      class="mt-2 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
      data-prueba="sin-dias"
    >
      {{
        cargando
          ? $t("reservar.calculando")
          : dias.length > 0
            ? $t("perfilPublico.agendar.sinDias")
            : $t("reservar.sinHorario")
      }}
    </p>
  </div>
</template>

<style scoped>
.cd-dias {
  display: flex;
  gap: 0.5rem;
  overflow-x: auto;
  padding-bottom: 0.25rem;
  scroll-snap-type: x proximity;
}
.cd-dia {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 0 0 auto;
  min-width: 3.6rem;
  padding: 0.45rem 0.4rem;
  border: 1px solid var(--borde);
  border-radius: 12px;
  scroll-snap-align: start;
  line-height: 1.2;
}
.cd-dia strong {
  font-size: 1.05rem;
  font-weight: 600;
}
.cd-dia-suave {
  font-size: 0.72rem;
  color: var(--texto-suave);
}
.cd-dia--activo {
  border-color: var(--primario);
  background: var(--primario);
  color: var(--primario-contraste);
}
.cd-dia--activo .cd-dia-suave {
  color: inherit;
}
.cd-dia:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
.cd-dia-mas {
  justify-content: center;
  font-size: 0.8rem;
  color: var(--enlace);
}
</style>
