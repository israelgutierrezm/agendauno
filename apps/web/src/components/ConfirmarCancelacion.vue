<script setup lang="ts">
import { onMounted, ref, watch } from "vue";

import { api, mensajeDeError } from "@/lib/api";

/**
 * Confirmación de una cancelación con su efecto a la vista (fase 1, punto 1.4):
 * antes de cancelar se lee la vista previa del API ("Se devolverá 1 crédito", "Se
 * cobrará 1 crédito…") y, en recepción, se elige quién cancela: el negocio (sin
 * penalizar) o el cliente que lo pidió (aplica su política). Va en línea, debajo de
 * lo que se cancela.
 */
interface Efecto {
  cancelable: boolean;
  mensaje: string;
}

const props = defineProps<{
  /** Endpoint de la vista previa (`…/cancelacion`). */
  url: string;
  /** Recepción: preguntar quién cancela. */
  conQuien?: boolean;
  ocupado?: boolean;
}>();

const emit = defineEmits<{
  confirmar: [por: "cliente" | "negocio" | null];
  cerrar: [];
}>();

const por = ref<"cliente" | "negocio">("negocio");
const efecto = ref<Efecto | null>(null);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{ data: Efecto }>(props.url, {
      params: props.conQuien ? { por: por.value } : {},
    });
    efecto.value = data.data;
  } catch (e) {
    efecto.value = null;
    error.value = mensajeDeError(e);
  }
}

watch(por, cargar);
onMounted(cargar);
</script>

<template>
  <div
    class="mt-2 space-y-3 rounded-lg border p-3 text-sm"
    :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
    role="group"
    :aria-label="$t('cancelacion.titulo')"
  >
    <div v-if="conQuien" class="tu-segmentado" role="group">
      <button
        type="button"
        :aria-pressed="por === 'negocio'"
        @click="por = 'negocio'"
      >
        {{ $t("cancelacion.porNegocio") }}
      </button>
      <button
        type="button"
        :aria-pressed="por === 'cliente'"
        @click="por = 'cliente'"
      >
        {{ $t("cancelacion.porCliente") }}
      </button>
    </div>

    <p v-if="efecto" role="status">{{ efecto.mensaje }}</p>
    <p v-else-if="!error" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("cancelacion.calculando") }}
    </p>
    <p v-if="error" style="color: var(--error)">{{ error }}</p>

    <div class="flex flex-wrap gap-2">
      <button
        v-if="efecto?.cancelable"
        type="button"
        class="tu-btn tu-btn-primario"
        :disabled="ocupado"
        @click="emit('confirmar', conQuien ? por : null)"
      >
        {{ $t("cancelacion.confirmar") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="ocupado"
        @click="emit('cerrar')"
      >
        {{ $t("cancelacion.volver") }}
      </button>
    </div>
  </div>
</template>
