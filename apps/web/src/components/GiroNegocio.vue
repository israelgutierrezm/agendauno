<script setup lang="ts">
import { computed, ref, watch } from "vue";

import { mensajeDeError } from "@/lib/api";
import { useCambiosPendientes } from "@/lib/cambiosPendientes";
import { girosDe } from "@/lib/modalidad";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Tipo de negocio (giro) en Configuración: solo los de su modalidad (ADR 0104). Otro
 * giro cambia cómo se llaman las cosas; pasar de clases a citas (o al revés) lo hace
 * AgendaUno y el servidor lo niega (MODALITY_LOCKED), con su mensaje.
 */
const emit = defineEmits<{ guardado: [perfil: string] }>();

const sesion = useSesionTenantStore();

const actual = computed(() => sesion.estudio?.perfil ?? "");
const elegido = ref(actual.value);
watch(actual, (perfil) => (elegido.value = perfil));

// Un giro guardado de otra modalidad (si AgendaUno la cambió) se sigue mostrando.
const giros = computed(() => {
  const lista = girosDe(sesion.modalidad);
  return actual.value === "" || lista.includes(actual.value)
    ? lista
    : [actual.value, ...lista];
});

const guardando = ref(false);
const listo = ref(false);
const error = ref<string | null>(null);
const hayCambios = computed(() => elegido.value !== actual.value);
useCambiosPendientes(() => hayCambios.value);

async function enviar(): Promise<void> {
  guardando.value = true;
  listo.value = false;
  error.value = null;
  try {
    await sesion.cambiarGiro(elegido.value);
    listo.value = true;
    emit("guardado", elegido.value);
  } catch (e) {
    error.value = mensajeDeError(e);
    elegido.value = actual.value;
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <div class="tu-card p-6" data-prueba="giro-negocio">
    <h2 class="font-medium text-lg">
      {{ $t("modalidadNegocio.giro.titulo") }}
    </h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t(`modalidadNegocio.giro.ayuda.${sesion.modalidad}`) }}
    </p>

    <form
      class="mt-4 flex flex-wrap items-center gap-3"
      @submit.prevent="enviar"
    >
      <label class="sr-only" for="giro-negocio">{{
        $t("modalidadNegocio.giro.etiqueta")
      }}</label>
      <select
        id="giro-negocio"
        v-model="elegido"
        class="tu-input w-auto"
        data-prueba="giro"
      >
        <option v-for="g in giros" :key="g" :value="g">
          {{ $t(`registro.perfiles.${g}`) }}
        </option>
      </select>
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="guardando || !hayCambios"
      >
        {{ $t("modalidadNegocio.giro.guardar") }}
      </button>
      <span
        v-if="listo"
        class="text-sm"
        role="status"
        :style="{ color: 'var(--exito)' }"
        >{{ $t("modalidadNegocio.giro.guardado") }}</span
      >
    </form>
    <p
      v-if="error"
      class="mt-3 text-sm"
      role="alert"
      style="color: var(--error)"
      data-prueba="giro-error"
    >
      {{ error }}
    </p>
  </div>
</template>
