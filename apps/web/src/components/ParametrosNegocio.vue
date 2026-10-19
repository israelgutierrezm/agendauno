<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import ParametrosEditor from "@/components/ParametrosEditor.vue";
import { api, erroresPorCampo, mensajeDeError } from "@/lib/api";
import type { Parametro } from "@/lib/parametros";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Límites y tiempos del negocio (ADR 0042): tiempo para pagar, recordatorios, gracias
 * de cobro… Lo que el administrador no ajusta usa el valor de la plataforma.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const parametros = ref<Parametro[]>([]);
const guardando = ref(false);
const error = ref<string | null>(null);
// Lo rechazado por campo, para mostrarlo junto a cada uno.
const errores = ref<Record<string, string>>({});

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Parametro[] }>(
      `${base.value}/parametros`,
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
    const { data } = await api.put<{ data: Parametro[] }>(
      `${base.value}/parametros`,
      { valores },
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
  <div class="mt-5 tu-card p-5">
    <h2 class="font-medium">{{ $t("parametrosConfig.tituloNegocio") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("parametrosConfig.ayudaNegocio") }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-3" role="alert">
      {{ error }}
    </AvisoTono>
    <ParametrosEditor
      v-if="parametros.length > 0"
      class="mt-4"
      :parametros="parametros"
      modo="negocio"
      :guardando="guardando"
      :errores="errores"
      @guardar="guardar"
    />
  </div>
</template>
