<script setup lang="ts">
/**
 * Miniatura de una app de calendario (Google Calendar, Apple Calendar u Outlook)
 * para reconocerla de un vistazo junto a su nombre: formas propias y sencillas con
 * sus colores, no el logotipo oficial. Es decorativa: el nombre va en el texto.
 */
withDefaults(
  defineProps<{ marca: "google" | "apple" | "outlook"; tam?: number }>(),
  { tam: 20 },
);
</script>

<template>
  <svg
    :width="tam"
    :height="tam"
    viewBox="0 0 24 24"
    aria-hidden="true"
    class="lc"
    :data-marca="marca"
  >
    <!-- Google Calendar: hoja blanca con franjas de color y el «31». -->
    <template v-if="marca === 'google'">
      <rect x="2.5" y="2.5" width="19" height="19" rx="3.5" fill="#fff" />
      <path
        d="M6 2.5h12a3.5 3.5 0 0 1 3.5 3.5v1.5h-19V6A3.5 3.5 0 0 1 6 2.5z"
        fill="#4285F4"
      />
      <path d="M21.5 7.5v8.5H17V7.5z" fill="#FBBC04" />
      <path d="M2.5 16H7v5.5H6A3.5 3.5 0 0 1 2.5 18z" fill="#34A853" />
      <path d="M17 21.5V16h4.5z" fill="#EA4335" />
      <text
        x="11.75"
        y="17"
        text-anchor="middle"
        font-size="7.5"
        font-weight="700"
        fill="#1A73E8"
        font-family="Arial, sans-serif"
      >
        31
      </text>
      <rect
        x="2.5"
        y="2.5"
        width="19"
        height="19"
        rx="3.5"
        fill="none"
        stroke="#DADCE0"
        stroke-width="0.8"
      />
    </template>
    <!-- Apple Calendar: hoja blanca con el día de la semana en rojo y la fecha. -->
    <template v-else-if="marca === 'apple'">
      <rect
        x="2.5"
        y="2.5"
        width="19"
        height="19"
        rx="4.5"
        fill="#fff"
        stroke="#DADCE0"
        stroke-width="0.8"
      />
      <text
        x="12"
        y="9"
        text-anchor="middle"
        font-size="4.6"
        font-weight="600"
        fill="#FF3B30"
        font-family="Arial, sans-serif"
      >
        LUN
      </text>
      <text
        x="12"
        y="18.6"
        text-anchor="middle"
        font-size="9.5"
        font-weight="400"
        fill="#1C1C1E"
        font-family="Arial, sans-serif"
      >
        17
      </text>
    </template>
    <!-- Outlook: cuadro azul con la «O» y un sobre detrás. -->
    <template v-else>
      <rect x="8" y="4" width="13.5" height="16" rx="2" fill="#28A8EA" />
      <path
        d="M8 9.5l6.75 4.25L21.5 9.5V18a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2z"
        fill="#0078D4"
      />
      <rect x="2.5" y="6.5" width="11" height="11" rx="1.8" fill="#0F5BAA" />
      <ellipse
        cx="8"
        cy="12"
        rx="2.9"
        ry="3.3"
        fill="none"
        stroke="#fff"
        stroke-width="1.6"
      />
    </template>
  </svg>
</template>

<style scoped>
.lc {
  flex-shrink: 0;
  display: inline-block;
}
</style>
