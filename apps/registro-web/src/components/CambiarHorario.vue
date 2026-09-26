<script setup lang="ts">
import { ref } from "vue";

import { api, mensajeDeError } from "@/lib/api";

/**
 * Cambiar el horario de una cita (y, si se quiere, de profesional) o de una clase
 * completa (fase 2, punto 2.1). El servidor revalida que el nuevo horario esté libre;
 * si no, no cambia nada y dice por qué. Va en línea, dentro del detalle.
 */
const props = defineProps<{
  /** Endpoint de reprogramar (`…/reservas/{id}/reprogramar` o `…/sesiones/{id}/reprogramar`). */
  url: string;
  zona: string;
  /** Hora actual (ISO), para proponer el mismo día y hora. */
  iniciaEn: string;
  /** Solo en citas: con quién (vacío = el mismo). */
  profesionales?: { id: string; nombre: string }[];
  profesionalId?: string | null;
}>();

const emit = defineEmits<{
  hecho: [datos: { antes: string; ahora: string }];
  cerrar: [];
}>();

function local(iso: string, parte: "fecha" | "hora"): string {
  const d = new Date(iso);
  return parte === "fecha"
    ? new Intl.DateTimeFormat("en-CA", { timeZone: props.zona }).format(d)
    : new Intl.DateTimeFormat("es-MX", {
        timeZone: props.zona,
        hour: "2-digit",
        minute: "2-digit",
        hour12: false,
      }).format(d);
}

const fecha = ref(local(props.iniciaEn, "fecha"));
const hora = ref(local(props.iniciaEn, "hora"));
const profesional = ref(props.profesionalId ?? "");
const guardando = ref(false);
const error = ref<string | null>(null);

async function guardar(): Promise<void> {
  guardando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: { antes: string; ahora: string } }>(
      props.url,
      {
        inicia_en_local: `${fecha.value} ${hora.value}:00`,
        ...(props.profesionales && profesional.value !== ""
          ? { instructor_id: profesional.value }
          : {}),
      },
    );
    emit("hecho", data.data);
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
    :aria-label="$t('reprogramar.titulo')"
    @submit.prevent="guardar"
  >
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="tu-label" for="rp-fecha">{{
          $t("reprogramar.fecha")
        }}</label>
        <input
          id="rp-fecha"
          v-model="fecha"
          type="date"
          class="tu-input w-auto"
          required
        />
      </div>
      <div>
        <label class="tu-label" for="rp-hora">{{
          $t("reprogramar.hora")
        }}</label>
        <input
          id="rp-hora"
          v-model="hora"
          type="time"
          step="300"
          class="tu-input w-auto"
          required
        />
      </div>
      <div v-if="profesionales && profesionales.length > 0">
        <label class="tu-label" for="rp-pro">{{
          $t("reprogramar.profesional")
        }}</label>
        <select id="rp-pro" v-model="profesional" class="tu-input w-auto">
          <option v-for="p in profesionales" :key="p.id" :value="p.id">
            {{ p.nombre }}
          </option>
        </select>
      </div>
    </div>
    <p :style="{ color: 'var(--texto-suave)' }">
      {{ $t("reprogramar.ayuda") }}
    </p>
    <p v-if="error" style="color: var(--error)">{{ error }}</p>
    <div class="flex flex-wrap gap-2">
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="guardando"
      >
        {{ $t("reprogramar.guardar") }}
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
