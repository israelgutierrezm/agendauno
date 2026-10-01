<script setup lang="ts">
/**
 * Encabezado de una pantalla del panel: el único título de la página (con su total,
 * si aplica), una línea opcional de qué se hace ahí y las acciones a la derecha.
 */
defineProps<{
  titulo: string;
  total?: number | string;
  subtitulo?: string;
}>();
</script>

<template>
  <div class="flex items-center justify-between gap-4 flex-wrap">
    <div class="min-w-0">
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
      <p
        v-if="subtitulo"
        class="mt-1 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ subtitulo }}
      </p>
    </div>
    <div
      v-if="$slots.acciones"
      class="flex w-full max-w-full flex-wrap items-center gap-2 sm:w-auto"
    >
      <slot name="acciones" />
    </div>
  </div>
</template>
