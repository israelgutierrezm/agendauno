<script setup lang="ts">
import { RouterLink } from "vue-router";

/**
 * Botón para ir a una carga masiva por CSV. Va en verde «Excel» con un ícono de
 * hoja de cálculo para que se distinga claramente de las acciones normales.
 */
defineProps<{ ruta: string; texto: string }>();
</script>

<template>
  <RouterLink
    :to="{ name: ruta }"
    class="tu-btn tu-btn-importar shrink-0 inline-flex items-center gap-2"
  >
    <svg
      class="h-4 w-4 shrink-0"
      fill="none"
      viewBox="0 0 24 24"
      stroke-width="1.8"
      stroke="currentColor"
      aria-hidden="true"
    >
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        d="M3.75 3.75h16.5v16.5H3.75zM3.75 9h16.5M3.75 14.25h16.5M9 3.75v16.5M14.25 3.75v16.5"
      />
    </svg>
    {{ texto }}
  </RouterLink>
</template>

<style scoped>
/* Verde «Excel»: color de marca fijo, legible en claro y oscuro. */
.tu-btn-importar {
  background: #1d6f42;
  border-color: #1d6f42;
  color: #fff;
}
.tu-btn-importar:hover {
  background: #185c37;
  border-color: #185c37;
}
</style>
