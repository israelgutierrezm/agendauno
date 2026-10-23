<script setup lang="ts">
/**
 * Encabezado de una pantalla del panel: el único título de la página (con su total,
 * si aplica), una línea opcional de qué se hace ahí y las acciones a la derecha. Sin
 * ícono: el menú ya dice en qué sección se está.
 */
defineProps<{
  titulo: string;
  total?: number | string;
  subtitulo?: string;
}>();
</script>

<template>
  <div class="tu-encabezado flex items-center justify-between gap-4 flex-wrap">
    <!-- El título cede espacio (su texto baja de renglón) para que las acciones se
         queden a la derecha; con poco ancho, las acciones pasan abajo. -->
    <div class="flex min-w-0 flex-1 basis-64 items-center gap-3">
      <div class="min-w-0">
        <h1
          class="text-2xl font-semibold tracking-tight leading-tight min-w-0 break-words"
        >
          {{ titulo }}
          <span
            v-if="total !== undefined"
            class="es-total ml-2 font-normal tabular-nums"
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
    </div>
    <div
      v-if="$slots.acciones"
      class="flex w-full max-w-full flex-wrap items-center gap-2 sm:w-auto"
    >
      <slot name="acciones" />
    </div>
  </div>
</template>

<style scoped>
.es-total {
  display: inline-block;
  padding: 0.2rem 0.6rem;
  border-radius: 0.5rem;
  background: var(--superficie-2);
  font-size: 0.75em;
  vertical-align: middle;
}
</style>
