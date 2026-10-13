<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";
/**
 * Encabezado de una pantalla del panel: el único título de la página (con su total,
 * si aplica), una línea opcional de qué se hace ahí y las acciones a la derecha.
 */
defineProps<{
  titulo: string;
  total?: number | string;
  subtitulo?: string;
  icono?: string;
}>();
</script>

<template>
  <div class="tu-encabezado flex items-center justify-between gap-4 flex-wrap">
    <div class="flex min-w-0 items-center gap-3">
      <span v-if="icono" class="es-icono" aria-hidden="true"
        ><IconoNav :nombre="icono" :tam="23"
      /></span>
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
.es-icono {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 3rem;
  height: 3rem;
  border: 1px solid var(--borde);
  border-radius: 0.85rem;
  background: var(--primario-suave);
  color: var(--primario-fuerte);
}
.es-total {
  display: inline-block;
  padding: 0.2rem 0.6rem;
  border-radius: 0.5rem;
  background: var(--superficie-2);
  font-size: 0.75em;
  vertical-align: middle;
}
</style>
