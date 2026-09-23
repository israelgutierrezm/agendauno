<script setup lang="ts">
import { computed, ref, watch } from "vue";

/**
 * Foto de una persona o, si no tiene (o no carga), su inicial en un círculo de
 * tinte suave (azul muy claro con la letra en el tono fuerte).
 */
const props = withDefaults(
  defineProps<{
    nombre: string | null | undefined;
    foto?: string | null;
    tam?: "sm" | "md" | "lg" | "xl";
  }>(),
  { tam: "md", foto: null },
);

const inicial = computed(() =>
  (props.nombre ?? "").trim().charAt(0).toUpperCase(),
);

// Si la imagen falla, se vuelve a la inicial (y se reintenta si cambia la foto).
const fallo = ref(false);
watch(
  () => props.foto,
  () => {
    fallo.value = false;
  },
);

const clases: Record<"sm" | "md" | "lg" | "xl", string> = {
  sm: "h-7 w-7 text-xs",
  md: "h-8 w-8 text-xs",
  lg: "h-14 w-14 text-xl",
  xl: "h-20 w-20 text-2xl",
};
</script>

<template>
  <img
    v-if="foto && !fallo"
    :src="foto"
    alt=""
    class="rounded-full object-cover shrink-0"
    :class="clases[tam]"
    @error="fallo = true"
  />
  <span
    v-else
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
