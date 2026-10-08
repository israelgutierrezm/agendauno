<script setup lang="ts">
import { ref, useId } from "vue";
import { useI18n } from "vue-i18n";

import BuscarPersona from "@/components/BuscarPersona.vue";
import { api, mensajeDeError } from "@/lib/api";

/**
 * Agregar a alguien a la clase en el momento (llegó sin reservar): se busca y se
 * reserva con su plan, como cualquier reserva; si ya no hay lugar, entra a la lista
 * de espera. Lo usan el pase de lista y el detalle de la clase.
 */
const props = defineProps<{ base: string; sesionId: string; llena: boolean }>();
const emit = defineEmits<{
  agregado: [nombre: string, enEspera: boolean];
  cerrar: [];
}>();

const { t } = useI18n();
const campo = useId();
const personaId = ref("");
const nombre = ref("");
const guardando = ref(false);
const error = ref<string | null>(null);

function elegir(p: { nombre: string }): void {
  nombre.value = p.nombre;
}

async function agregar(): Promise<void> {
  if (personaId.value === "") {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    // Sin lugar, a la lista de espera (esperar=true) en vez de fallar.
    await api.post(`${props.base}/sesiones/${props.sesionId}/reservas`, {
      persona_id: personaId.value,
      esperar: props.llena,
    });
    emit("agregado", nombre.value, props.llena);
    personaId.value = "";
    nombre.value = "";
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <form class="aac" data-prueba="agregar-a-clase" @submit.prevent="agregar">
    <label class="tu-label" :for="campo">{{
      t("paseLista.agregar.quien")
    }}</label>
    <BuscarPersona
      v-model="personaId"
      :campo-id="campo"
      :buscar-en="`${base}/miembros`"
      :parametros="{ tipo: 'miembro' }"
      :deshabilitado="guardando"
      @elegir="elegir"
    />
    <p v-if="llena" class="text-xs" :style="{ color: 'var(--aviso)' }">
      {{ t("paseLista.agregar.llena") }}
    </p>
    <p
      v-if="error"
      class="text-sm"
      role="alert"
      :style="{ color: 'var(--error)' }"
    >
      {{ error }}
    </p>
    <div class="flex flex-wrap gap-2">
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="guardando || personaId === ''"
      >
        {{
          llena
            ? t("paseLista.agregar.aEspera")
            : t("paseLista.agregar.agregar")
        }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="guardando"
        @click="emit('cerrar')"
      >
        {{ t("comun.cancelar") }}
      </button>
    </div>
  </form>
</template>

<style scoped>
.aac {
  display: grid;
  gap: 0.6rem;
  padding: 0.9rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
}
</style>
