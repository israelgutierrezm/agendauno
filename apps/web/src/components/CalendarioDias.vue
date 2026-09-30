<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from "vue";
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
const tira = ref<HTMLElement | null>(null);
const tiraId = useId();
const puedeRetroceder = ref(false);
const puedeAvanzar = ref(false);
let observador: ResizeObserver | undefined;
let consulta = 0;

function medir(): void {
  const el = tira.value;
  puedeRetroceder.value = el !== null && el.scrollLeft > 1;
  puedeAvanzar.value =
    el !== null && el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
}
function desplazar(direccion: number): void {
  const el = tira.value;
  if (!el) return;
  el.scrollBy?.({
    left: direccion * Math.max(80, el.clientWidth * 0.75),
    behavior: window.matchMedia?.("(prefers-reduced-motion: reduce)").matches
      ? "auto"
      : "smooth",
  });
}
function nombreFecha(f: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(`${f}T12:00:00Z`));
}

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
    await nextTick();
    if (esta !== consulta) return;
    if (!mas) tira.value?.scrollTo?.({ left: 0, behavior: "instant" });
    medir();
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
    // Invalida también una petición pendiente si se deselecciona la sede.
    consulta++;
    dias.value = [];
    cargando.value = false;
    error.value = null;
    puedeRetroceder.value = false;
    puedeAvanzar.value = false;
    void cargar();
  },
  { immediate: true },
);
onMounted(() => {
  if (typeof ResizeObserver !== "undefined" && tira.value) {
    observador = new ResizeObserver(medir);
    observador.observe(tira.value);
  }
});
onBeforeUnmount(() => {
  consulta++;
  observador?.disconnect();
});
</script>

<template>
  <div class="cd-calendario" :aria-busy="cargando">
    <div class="cd-navegacion">
      <button
        type="button"
        class="cd-flecha"
        :disabled="!puedeRetroceder || cargando"
        :aria-controls="tiraId"
        :aria-label="$t('perfilPublico.agendar.diasAnteriores')"
        @click="desplazar(-1)"
      >
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
          aria-hidden="true"
        >
          <path d="m14 6-6 6 6 6" />
        </svg>
      </button>
      <div
        :id="tiraId"
        ref="tira"
        class="cd-dias"
        data-prueba="dias"
        @scroll="medir"
      >
        <template v-if="cargando && dias.length === 0">
          <span
            v-for="n in 8"
            :key="`cargando-${n}`"
            class="cd-dia cd-esqueleto"
            aria-hidden="true"
          >
            <span /><span /><span />
          </span>
        </template>
        <button
          v-for="d in dias"
          :key="d.fecha"
          type="button"
          class="cd-dia"
          :class="{ 'cd-dia--activo': modelValue === d.fecha }"
          :disabled="!d.abierto || cargando"
          :data-fecha="d.fecha"
          :aria-pressed="modelValue === d.fecha"
          :aria-label="
            nombreFecha(d.fecha) +
            (!d.abierto ? `, ${$t('perfilPublico.agendar.diaCerrado')}` : '')
          "
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
      <button
        type="button"
        class="cd-flecha"
        :disabled="!puedeAvanzar || cargando"
        :aria-controls="tiraId"
        :aria-label="$t('perfilPublico.agendar.diasSiguientes')"
        @click="desplazar(1)"
      >
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
          aria-hidden="true"
        >
          <path d="m10 6 6 6-6 6" />
        </svg>
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
      v-else-if="cargando || modelValue === ''"
      class="mt-2 text-sm"
      role="status"
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
.cd-calendario {
  min-width: 0;
}
.cd-navegacion {
  display: grid;
  grid-template-columns: 2rem minmax(0, 1fr) 2rem;
  align-items: center;
  gap: 0.5rem;
}
.cd-flecha {
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2.75rem;
  border: 1px solid var(--borde);
  border-radius: 10px;
  background: var(--superficie);
  color: var(--texto-suave);
  cursor: pointer;
}
.cd-flecha svg {
  width: 18px;
  height: 18px;
}
.cd-flecha:disabled {
  opacity: 0.35;
  cursor: default;
}
.cd-flecha:not(:disabled):hover {
  color: var(--primario);
  border-color: var(--primario);
}
.cd-dias {
  display: flex;
  gap: 0.6rem;
  overflow-x: auto;
  padding: 0.25rem 0.2rem 0.6rem;
  scroll-snap-type: x proximity;
  scrollbar-width: thin;
  scrollbar-color: var(--borde) transparent;
  overscroll-behavior-x: contain;
}
.cd-dia {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 0 0 auto;
  justify-content: center;
  gap: 0.2rem;
  min-width: 5rem;
  min-height: 5.6rem;
  padding: 0.65rem 0.5rem;
  border: 1px solid var(--borde);
  border-radius: 10px;
  background: var(--superficie);
  cursor: pointer;
  scroll-snap-align: start;
  line-height: 1.2;
}
.cd-dia strong {
  font-size: 1.35rem;
  font-weight: 600;
}
.cd-dia-suave {
  font-size: 0.85rem;
  color: var(--texto-suave);
}
.cd-dia--activo {
  border-color: var(--primario);
  background: var(--primario);
  color: var(--primario-contraste);
}
.cd-dia:not(:disabled):hover {
  border-color: var(--primario);
}
.cd-dia:focus-visible,
.cd-flecha:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 2px;
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
.cd-esqueleto {
  pointer-events: none;
  background: var(--fondo);
}
.cd-esqueleto span {
  width: 1.75rem;
  height: 0.5rem;
  margin: 0.25rem 0;
  border-radius: 3px;
  background: var(--borde);
}
.cd-esqueleto span:nth-child(2) {
  width: 1.25rem;
  height: 1.3rem;
}
@media (max-width: 520px) {
  .cd-navegacion {
    gap: 0.25rem;
    grid-template-columns: 1.75rem minmax(0, 1fr) 1.75rem;
  }
  .cd-flecha {
    width: 1.75rem;
  }
  .cd-dia {
    min-width: 4.25rem;
    min-height: 5.25rem;
  }
}
</style>
