<script setup lang="ts">
import { ref } from "vue";

import { api, mensajeDeError } from "@/lib/api";

/**
 * "Esta y las siguientes" (fase 2, punto 2.5): cambia los días, la hora, la duración
 * o el profesional de una clase recurrente desde esta fecha (días: ADR 0045). Primero muestra qué pasará
 * (cuántas fechas se mueven y cuáles se conservan y por qué) y luego se aplica. El
 * historial no se toca y lo cambiado a mano se respeta.
 */
interface Resultado {
  aplicado: boolean;
  movidas: number;
  conservadas: { fecha: string; motivo: string }[];
  quitadas?: number;
  creadas?: number;
  omitidas?: { fecha: string; motivo: string }[];
}

const props = defineProps<{
  base: string;
  serieId: string;
  /** Fecha de la serie de esta sesión (desde cuándo cambia). */
  fecha: string;
  zona: string;
  iniciaEn: string;
  duracion: number;
  profesionales: { id: string; nombre: string }[];
  profesionalId: string | null;
  /** Días de la serie (ISO: 1 = lunes … 7 = domingo). */
  dias: number[];
}>();

const emit = defineEmits<{ hecho: []; cerrar: [] }>();

const hora = ref(
  new Intl.DateTimeFormat("es-MX", {
    timeZone: props.zona,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(props.iniciaEn)),
);
const duracion = ref(props.duracion);
const profesional = ref(props.profesionalId ?? "");
const dias = ref<number[]>([...props.dias].sort((a, b) => a - b));
const INICIALES = ["L", "M", "M", "J", "V", "S", "D"];
function alternarDia(n: number): void {
  const siguen = dias.value.includes(n)
    ? dias.value.filter((d) => d !== n)
    : [...dias.value, n].sort((a, b) => a - b);
  // Al menos un día: para quitar la clase se cancela la serie.
  if (siguen.length > 0) {
    dias.value = siguen;
    vista.value = null;
  }
}
const vista = ref<Resultado | null>(null);
const guardando = ref(false);
const error = ref<string | null>(null);

function fechaLarga(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
  }).format(new Date(`${iso}T12:00:00`));
}

async function enviar(previsualizar: boolean): Promise<void> {
  guardando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: Resultado }>(
      `${props.base}/plantillas-horario/${props.serieId}/cambiar`,
      {
        desde: props.fecha,
        hora_local: hora.value,
        duracion_minutos: duracion.value,
        instructor_id: profesional.value,
        ...(dias.value.length > 0 ? { dias_semana: dias.value } : {}),
        previsualizar,
      },
    );
    if (previsualizar) {
      vista.value = data.data;
    } else {
      emit("hecho");
    }
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
    :aria-label="$t('cambiarSerie.titulo')"
    @submit.prevent="enviar(vista === null)"
  >
    <p :style="{ color: 'var(--texto-suave)' }">
      {{ $t("cambiarSerie.desde", { fecha: fechaLarga(fecha) }) }}
    </p>
    <div v-if="props.dias.length > 0">
      <span class="tu-label">{{ $t("cambiarSerie.dias") }}</span>
      <div class="mt-1 flex gap-1">
        <button
          v-for="(inicial, i) in INICIALES"
          :key="i"
          type="button"
          class="h-8 w-8 rounded-full text-xs font-semibold"
          :aria-pressed="dias.includes(i + 1)"
          :aria-label="$t('cambiarSerie.nombresDias').split(',')[i]"
          :style="
            dias.includes(i + 1)
              ? { background: 'var(--primario)', color: '#fff' }
              : { background: 'var(--superficie-2)', color: 'var(--texto)' }
          "
          @click="alternarDia(i + 1)"
        >
          {{ inicial }}
        </button>
      </div>
    </div>
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="tu-label" for="cs-hora">{{
          $t("cambiarSerie.hora")
        }}</label>
        <input
          id="cs-hora"
          v-model="hora"
          type="time"
          step="300"
          class="tu-input w-auto"
          required
          @input="vista = null"
        />
      </div>
      <div>
        <label class="tu-label" for="cs-dur">{{
          $t("cambiarSerie.duracion")
        }}</label>
        <input
          id="cs-dur"
          v-model.number="duracion"
          type="number"
          min="5"
          step="5"
          class="tu-input w-24"
          required
          @input="vista = null"
        />
      </div>
      <div v-if="profesionales.length > 0">
        <label class="tu-label" for="cs-pro">{{
          $t("cambiarSerie.profesional")
        }}</label>
        <select
          id="cs-pro"
          v-model="profesional"
          class="tu-input w-auto"
          @change="vista = null"
        >
          <option value="">{{ $t("cambiarSerie.sinAsignar") }}</option>
          <option v-for="p in profesionales" :key="p.id" :value="p.id">
            {{ p.nombre }}
          </option>
        </select>
      </div>
    </div>

    <!-- Qué pasará, antes de aplicar -->
    <div v-if="vista" role="status">
      <p>{{ $t("cambiarSerie.movidas", { n: vista.movidas }) }}</p>
      <p v-if="(vista.quitadas ?? 0) > 0">
        {{ $t("cambiarSerie.quitadas", { n: vista.quitadas }) }}
      </p>
      <p v-if="(vista.creadas ?? 0) > 0">
        {{ $t("cambiarSerie.creadas", { n: vista.creadas }) }}
      </p>
      <template v-if="(vista.omitidas ?? []).length > 0">
        <p class="mt-1" style="color: var(--aviso)">
          {{ $t("cambiarSerie.omitidas", { n: vista.omitidas?.length }) }}
        </p>
        <ul class="mt-1" :style="{ color: 'var(--texto-suave)' }">
          <li v-for="o in vista.omitidas" :key="o.fecha">
            {{ fechaLarga(o.fecha) }} · {{ o.motivo }}
          </li>
        </ul>
      </template>
      <template v-if="vista.conservadas.length > 0">
        <p class="mt-1" style="color: var(--aviso)">
          {{ $t("cambiarSerie.conservadas", { n: vista.conservadas.length }) }}
        </p>
        <ul class="mt-1" :style="{ color: 'var(--texto-suave)' }">
          <li v-for="c in vista.conservadas" :key="c.fecha">
            {{ fechaLarga(c.fecha) }} · {{ c.motivo }}
          </li>
        </ul>
      </template>
    </div>
    <p v-if="error" style="color: var(--error)">{{ error }}</p>

    <div class="flex flex-wrap gap-2">
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="guardando"
      >
        {{
          vista === null
            ? $t("cambiarSerie.revisar")
            : $t("cambiarSerie.aplicar")
        }}
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
