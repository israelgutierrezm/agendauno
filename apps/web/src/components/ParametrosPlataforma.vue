<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import ParametrosEditor from "@/components/ParametrosEditor.vue";
import { crearClientePlataforma } from "@/lib/clientePlataforma";
import { erroresPorCampo, mensajeDeError } from "@/lib/api";
import type { Parametro } from "@/lib/parametros";
import { useToastStore } from "@/stores/toast";

/**
 * Parámetros de plataforma (ADR 0042), para el superadmin: el valor que aplica a
 * todos los negocios que no ajustaron el suyo, y los que solo fija la plataforma
 * (vigencia de enlaces, política de cancelación de quien no tiene una). El editor los
 * muestra por categoría, una a la vez, con buscador (ParametrosEditor).
 */
const props = defineProps<{ apiUrl: string; token: string }>();

const { t } = useI18n();
const toast = useToastStore();
const cliente = crearClientePlataforma({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});
const auth = (): { headers: Record<string, string> } => ({
  headers: { Authorization: `Bearer ${props.token}` },
});

const parametros = ref<Parametro[]>([]);
const guardando = ref(false);
const error = ref<string | null>(null);
// Lo rechazado por campo, para mostrarlo junto a cada uno.
const errores = ref<Record<string, string>>({});

async function cargar(): Promise<void> {
  try {
    const { data } = await cliente.get<{ data: Parametro[] }>(
      "/api/v1/plataforma/parametros",
      auth(),
    );
    parametros.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function guardar(valores: Record<string, number | null>): Promise<void> {
  guardando.value = true;
  error.value = null;
  errores.value = {};
  try {
    const { data } = await cliente.put<{ data: Parametro[] }>(
      "/api/v1/plataforma/parametros",
      { valores },
      auth(),
    );
    parametros.value = data.data;
    toast.exito(t("parametrosConfig.guardado"));
  } catch (e) {
    error.value = mensajeDeError(e);
    errores.value = erroresPorCampo(e);
  } finally {
    guardando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <!-- Sin título propio: el de la sección («Parámetros») ya lo nombra. -->
  <div class="tu-card p-5">
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("parametrosConfig.ayudaPlataforma") }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-3" role="alert">
      {{ error }}
    </AvisoTono>
    <ParametrosEditor
      v-if="parametros.length > 0"
      class="mt-4"
      :parametros="parametros"
      modo="plataforma"
      :guardando="guardando"
      :errores="errores"
      @guardar="guardar"
    />
  </div>
</template>
