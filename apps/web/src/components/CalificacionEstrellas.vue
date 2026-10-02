<script setup lang="ts">
/**
 * Calificación de 1 a 5 en estrellas (llenas hasta el valor, tenues el resto). Solo
 * se ve: para lectores de pantalla dice «4 de 5».
 */
withDefaults(defineProps<{ valor: number; tam?: number }>(), { tam: 14 });
</script>

<template>
  <span
    class="inline-flex items-center gap-0.5"
    role="img"
    :aria-label="$t('listadosVisual.resenas.deCinco', { n: valor })"
  >
    <svg
      v-for="n in 5"
      :key="n"
      :width="tam"
      :height="tam"
      viewBox="0 0 24 24"
      aria-hidden="true"
      :style="{
        color: n <= Math.round(valor) ? 'var(--aviso)' : 'var(--borde)',
      }"
    >
      <path
        fill="currentColor"
        d="M12 3.2l2.6 5.5 6 .8-4.4 4.2 1.1 6-5.3-2.9-5.3 2.9 1.1-6L3.4 9.5l6-.8z"
      />
    </svg>
  </span>
</template>
