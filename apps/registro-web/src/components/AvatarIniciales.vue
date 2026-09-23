<script setup lang="ts">
import { computed } from "vue";

/**
 * Inicial de una persona en un círculo de tinte suave (azul muy claro con la letra
 * en el tono fuerte): se distingue sin competir con las acciones.
 */
const props = withDefaults(
  defineProps<{
    nombre: string | null | undefined;
    tam?: "sm" | "md" | "lg";
  }>(),
  { tam: "md" },
);

const inicial = computed(() =>
  (props.nombre ?? "").trim().charAt(0).toUpperCase(),
);

const clases: Record<"sm" | "md" | "lg", string> = {
  sm: "h-7 w-7 text-xs",
  md: "h-8 w-8 text-xs",
  lg: "h-14 w-14 text-xl",
};
</script>

<template>
  <span
    class="rounded-full inline-flex items-center justify-center font-semibold shrink-0"
    :class="clases[tam]"
    :style="{
      background: 'var(--primario-suave)',
      color: 'var(--primario-fuerte)',
    }"
    aria-hidden="true"
    >{{ inicial || "?" }}</span
  >
</template>
