<script setup lang="ts">
import { computed } from "vue";

import { dinero } from "@/lib/formato";

/**
 * Qué incluye un servicio tipo paquete y, si sale más barato, cuánto costaría por
 * separado (ADR 0063). En un servicio simple no muestra nada.
 */
const props = defineProps<{
  incluye?: string[] | null;
  precioMinor?: number | null;
  porSeparadoMinor?: number | null;
  moneda?: string | null;
  /** El país del negocio: cómo se escriben sus precios. */
  pais?: string | null;
}>();

const lista = computed(() => props.incluye ?? []);
// Solo se compara si de verdad sale más barato que por separado.
const porSeparado = computed(() =>
  props.porSeparadoMinor != null &&
  props.precioMinor != null &&
  props.porSeparadoMinor > props.precioMinor
    ? dinero(props.porSeparadoMinor, props.moneda ?? "MXN", props.pais)
    : null,
);
</script>

<template>
  <span
    v-if="lista.length > 0"
    class="block text-sm"
    :style="{ color: 'var(--texto-suave)' }"
    data-prueba="incluye"
  >
    {{ $t("perfilPublico.incluye.lista", { lista: lista.join(" · ") }) }}
    <span v-if="porSeparado" class="block">
      {{ $t("perfilPublico.incluye.porSeparado") }}
      <s>{{ porSeparado }}</s>
    </span>
  </span>
</template>
