<script setup lang="ts">
import axios from "axios";
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import ParametrosEditor from "@/components/ParametrosEditor.vue";
import { mensajeDeError } from "@/lib/api";
import type { Parametro } from "@/lib/parametros";
import { useToastStore } from "@/stores/toast";

/**
 * Parámetros de plataforma (ADR 0042), para el superadmin: el valor que aplica a
 * todos los negocios que no ajustaron el suyo, y los que solo fija la plataforma
 * (vigencia de enlaces, política de cancelación de quien no tiene una).
 */
const props = defineProps<{ apiUrl: string; token: string }>();

const { t } = useI18n();
const toast = useToastStore();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});
const auth = (): { headers: Record<string, string> } => ({
  headers: { Authorization: `Bearer ${props.token}` },
});

const parametros = ref<Parametro[]>([]);
const guardando = ref(false);
const error = ref<string | null>(null);

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
  } finally {
    guardando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <div class="tu-card p-5">
    <h2 class="font-light text-lg">
      {{ $t("parametrosConfig.tituloPlataforma") }}
    </h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("parametrosConfig.ayudaPlataforma") }}
    </p>
    <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <ParametrosEditor
      v-if="parametros.length > 0"
      class="mt-4"
      :parametros="parametros"
      modo="plataforma"
      :guardando="guardando"
      @guardar="guardar"
    />
  </div>
</template>
