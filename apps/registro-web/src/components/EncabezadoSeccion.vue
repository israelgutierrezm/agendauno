<script setup lang="ts">
/**
 * Encabezado de una pantalla del panel: el único título de la página (con su total,
 * si aplica) y las acciones a la derecha. Sin ícono ni texto explicativo: la
 * pantalla se explica sola.
 */
defineProps<{
  titulo: string;
  total?: number | string;
}>();
</script>

<template>
  <div class="flex items-center justify-between gap-4 flex-wrap">
    <h1
      class="text-2xl font-semibold tracking-tight leading-tight min-w-0 break-words"
    >
      {{ titulo }}
      <span
        v-if="total !== undefined"
        class="ml-1 font-normal tabular-nums"
        :style="{ color: 'var(--texto-suave)' }"
        >{{ total }}</span
      >
    </h1>
    <div
      v-if="$slots.acciones"
      class="flex w-full max-w-full flex-wrap items-center gap-2 sm:w-auto"
    >
      <slot name="acciones" />
    </div>
  </div>
</template>
