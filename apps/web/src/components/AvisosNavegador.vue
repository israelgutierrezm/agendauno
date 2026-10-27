<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import { mensajeDeError } from "@/lib/api";
import {
  activarPushWeb,
  desactivarPushWeb,
  estadoPushWeb,
  type EstadoPushWeb,
} from "@/lib/pushWeb";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * «Avisos en este navegador» (push en la web, ADR 0225): los mismos avisos que llegan a
 * la app (confirmaciones, recordatorios, cambios), en este navegador. Si el navegador no
 * puede o la plataforma no los ofrece, no se muestra (`disponible`).
 */
const emit = defineEmits<{ disponible: [valor: boolean] }>();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const estado = ref<EstadoPushWeb | null>(null);
const ocupado = ref(false);
const error = ref<string | null>(null);

async function cambiar(activar: boolean): Promise<void> {
  if (ocupado.value) return;
  ocupado.value = true;
  error.value = null;
  try {
    estado.value = activar
      ? await activarPushWeb(base.value)
      : await desactivarPushWeb(base.value);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}

onMounted(async () => {
  try {
    estado.value = await estadoPushWeb(base.value);
  } catch {
    estado.value = "no_disponible";
  }
  emit(
    "disponible",
    estado.value !== "no_soportado" && estado.value !== "no_disponible",
  );
});
</script>

<template>
  <div
    v-if="
      estado === 'activo' || estado === 'inactivo' || estado === 'bloqueado'
    "
    class="grid gap-1"
    data-prueba="avisos-navegador"
  >
    <p v-if="estado === 'bloqueado'" class="text-sm">
      {{ $t("avisosNavegador.bloqueado") }}
    </p>
    <div v-else>
      <button
        v-if="estado === 'inactivo'"
        type="button"
        class="tu-btn tu-btn-secundario"
        :disabled="ocupado"
        data-prueba="activar-avisos"
        @click="cambiar(true)"
      >
        {{ $t("avisosNavegador.activar") }}
      </button>
      <template v-else>
        <span class="mr-2 text-sm" data-prueba="avisos-activos">{{
          $t("avisosNavegador.activos")
        }}</span>
        <button
          type="button"
          class="tu-btn tu-btn-discreto tu-btn-chico"
          :disabled="ocupado"
          data-prueba="desactivar-avisos"
          @click="cambiar(false)"
        >
          {{ $t("avisosNavegador.desactivar") }}
        </button>
      </template>
    </div>
    <p
      v-if="error"
      class="text-sm"
      :style="{ color: 'var(--error)' }"
      role="alert"
    >
      {{ error }}
    </p>
  </div>
</template>
