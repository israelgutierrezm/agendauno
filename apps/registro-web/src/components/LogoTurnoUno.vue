<script setup lang="ts">
withDefaults(defineProps<{ tam?: number }>(), { tam: 64 });
</script>

<template>
  <!-- Isotipo TurnoUno: "u" + punto + sonrisa. SVG transparente (sin recuadro),
       escalable y a prueba de modo claro/oscuro. -->
  <svg
    :width="tam"
    :height="tam"
    viewBox="0 0 100 100"
    fill="none"
    class="shrink-0"
    aria-hidden="true"
  >
    <path
      d="M30 26 V50 a20 20 0 0 0 40 0 V26"
      stroke="#1a7fff"
      stroke-width="14"
      stroke-linecap="round"
      fill="none"
    />
    <circle cx="80" cy="30" r="8" fill="#35c2f9" />
    <path
      d="M30 80 q20 15 40 0"
      stroke="#35c2f9"
      stroke-width="9"
      stroke-linecap="round"
      fill="none"
    />
  </svg>
</template>
