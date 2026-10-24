<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import AvisoTono from "@/components/AvisoTono.vue";
import { erroresPorCampo, mensajeDeError } from "@/lib/api";
import { useCambiosPendientes } from "@/lib/cambiosPendientes";
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
    // La página ya lo nombra en su encabezado (Configuración): sin título propio.
    integrado?: boolean;
  }>(),
  { puedeGestionar: true, integrado: false },
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
// Lo rechazado por término, junto a su campo.
const errores = ref<Record<string, string>>({});

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
useCambiosPendientes(() => hayCambios.value);

async function enviar(): Promise<void> {
  guardando.value = true;
  listo.value = false;
  error.value = null;
  errores.value = {};
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
    errores.value = erroresPorCampo(e);
    // Si no es de un término, el aviso general.
    if (!TERMINOS.some((t) => errores.value[t])) {
      error.value = mensajeDeError(e);
    }
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
    <h2 v-if="!integrado" class="font-medium text-lg">
      {{ $t("terminologiaNegocio.titulo") }}
    </h2>
    <p
      class="text-sm"
      :class="{ 'mt-1': !integrado }"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("terminologiaNegocio.ayuda") }}
    </p>

    <form v-if="datos" class="mt-4 space-y-3" @submit.prevent="enviar">
      <div
        v-for="t in TERMINOS"
        :key="t"
        class="grid items-center gap-2 text-sm sm:grid-cols-[minmax(0,1fr)_minmax(0,20rem)] sm:gap-4"
      >
        <label :for="`term-${t}`" class="min-w-0">
          <span class="block font-medium">{{
            $t(`terminologiaNegocio.terminos.${t}`)
          }}</span>
        </label>
        <select
          :id="`term-${t}`"
          v-model="elegidos[t]"
          class="tu-input w-full"
          :disabled="!puedeGestionar"
          :aria-invalid="errores[t] ? 'true' : undefined"
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
        <p
          v-if="errores[t]"
          class="text-xs sm:col-start-2"
          role="alert"
          :style="{ color: 'var(--error)' }"
          :data-prueba="`error-${t}`"
        >
          {{ errores[t] }}
        </p>
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
    <AvisoTono v-if="error" tono="peligro" class="mt-3" role="alert">
      {{ error }}
    </AvisoTono>
  </div>
</template>
