<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import { mensajeDeError } from "@/lib/api";
import type {
  DatosTerminologia,
  TerminoEditable,
  TerminosNegocio,
} from "@/lib/terminologia";

/**
 * Cómo se llaman las cosas en el negocio (ADR 0049): qué se reserva, quién lo toma y
 * quién lo imparte. Parte de lo de su giro; se elige otro de cada lista. Lo usan el
 * administrador (Configuración) y el superadmin (ficha del negocio): cada uno pasa
 * cómo cargar y guardar.
 */
type Termino = TerminoEditable;

// Una prop booleana ausente vale false: por defecto, sí puede cambiarla.
const props = withDefaults(
  defineProps<{
    cargar: () => Promise<DatosTerminologia>;
    guardar: (
      valores: Record<Termino, string | null>,
    ) => Promise<DatosTerminologia>;
    puedeGestionar?: boolean;
  }>(),
  { puedeGestionar: true },
);

const emit = defineEmits<{ guardado: [vigente: TerminosNegocio] }>();

const TERMINOS: Termino[] = ["sesion", "miembro", "instructor"];

const datos = ref<DatosTerminologia | null>(null);
const elegidos = ref<Record<Termino, string>>({
  sesion: "",
  miembro: "",
  instructor: "",
});
const guardando = ref(false);
const listo = ref(false);
const error = ref<string | null>(null);

function tomar(d: DatosTerminologia): void {
  datos.value = d;
  elegidos.value = {
    sesion: d.propia.sesion ?? "",
    miembro: d.propia.miembro ?? "",
    instructor: d.propia.instructor ?? "",
  };
}

const hayCambios = computed(() =>
  TERMINOS.some((t) => elegidos.value[t] !== (datos.value?.propia[t] ?? "")),
);

async function enviar(): Promise<void> {
  guardando.value = true;
  listo.value = false;
  error.value = null;
  try {
    const d = await props.guardar({
      sesion: elegidos.value.sesion || null,
      miembro: elegidos.value.miembro || null,
      instructor: elegidos.value.instructor || null,
    });
    tomar(d);
    listo.value = true;
    emit("guardado", d.vigente);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

onMounted(async () => {
  try {
    tomar(await props.cargar());
  } catch (e) {
    error.value = mensajeDeError(e);
  }
});
</script>

<template>
  <div class="tu-card p-6">
    <h2 class="font-light text-lg">{{ $t("terminologiaNegocio.titulo") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("terminologiaNegocio.ayuda") }}
    </p>

    <form v-if="datos" class="mt-4 space-y-3" @submit.prevent="enviar">
      <div
        v-for="t in TERMINOS"
        :key="t"
        class="flex flex-wrap items-center justify-between gap-3 text-sm"
      >
        <label :for="`term-${t}`" class="min-w-0 flex-1">
          <span class="block font-medium">{{
            $t(`terminologiaNegocio.terminos.${t}`)
          }}</span>
        </label>
        <select
          :id="`term-${t}`"
          v-model="elegidos[t]"
          class="tu-input w-auto"
          :disabled="!puedeGestionar"
        >
          <option value="">
            {{
              $t("terminologiaNegocio.delGiro", {
                termino: datos.del_perfil[t],
              })
            }}
          </option>
          <option v-for="o in datos.opciones[t]" :key="o" :value="o">
            {{ o }}
          </option>
        </select>
      </div>

      <div v-if="puedeGestionar" class="flex flex-wrap items-center gap-3">
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="guardando || !hayCambios"
        >
          {{ $t("terminologiaNegocio.guardar") }}
        </button>
        <span
          v-if="listo"
          class="text-sm"
          role="status"
          :style="{ color: 'var(--exito)' }"
          >{{ $t("terminologiaNegocio.guardado") }}</span
        >
      </div>
    </form>
    <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
  </div>
</template>
