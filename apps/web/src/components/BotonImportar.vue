<script setup lang="ts">
import { RouterLink } from "vue-router";

/**
 * Botón para ir a una carga masiva por CSV. Es una acción secundaria (la principal de
 * la pantalla es crear: «Nueva clase», «Agregar», «Invitar»): con borde, y el ícono
 * de hoja de cálculo en verde «Excel» para reconocerlo de un vistazo.
 */
defineProps<{ ruta: string; texto: string }>();
</script>

<template>
  <RouterLink
    :to="{ name: ruta }"
    class="tu-btn tu-btn-fantasma shrink-0 inline-flex items-center gap-2"
    data-prueba="boton-importar"
  >
    <svg
      class="bi-icono h-4 w-4 shrink-0"
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
/* El verde «Excel», solo en el ícono (se aclara en temas oscuros). */
.bi-icono {
  color: color-mix(in srgb, #1d6f42, var(--texto) 25%);
}
</style>
