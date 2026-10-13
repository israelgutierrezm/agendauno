<script setup lang="ts">
/**
 * El distintivo de cada plataforma de bienestar en Integraciones y Reportes: su color y
 * una marca simple (no su logotipo oficial). Wellhub, una flor en su rosa sobre rosa
 * pálido; TotalPass, sus iniciales en blanco sobre negro.
 */
withDefaults(
  defineProps<{
    proveedor: string;
    /** Lado del distintivo, en píxeles. */
    tam?: number;
  }>(),
  { tam: 44 },
);

// Seis pétalos alrededor del centro.
const PETALOS = [0, 60, 120, 180, 240, 300];
</script>

<template>
  <span
    class="logo-plataforma"
    :class="`logo-${proveedor}`"
    :style="{ width: `${tam}px`, height: `${tam}px` }"
    aria-hidden="true"
  >
    <svg
      v-if="proveedor === 'wellhub'"
      viewBox="0 0 24 24"
      :width="tam * 0.62"
      :height="tam * 0.62"
    >
      <ellipse
        v-for="g in PETALOS"
        :key="g"
        cx="12"
        cy="6.6"
        rx="2.6"
        ry="4.6"
        :transform="`rotate(${g} 12 12)`"
        fill="currentColor"
      />
      <circle cx="12" cy="12" r="2.3" fill="#fff" />
    </svg>
    <span v-else-if="proveedor === 'totalpass'" class="logo-iniciales">TP</span>
    <span v-else class="logo-iniciales">{{
      proveedor.slice(0, 2).toUpperCase()
    }}</span>
  </span>
</template>

<style scoped>
.logo-plataforma {
  display: inline-grid;
  flex-shrink: 0;
  place-items: center;
  border-radius: 0.7rem;
  background: var(--superficie-2);
  color: var(--texto);
}
.logo-wellhub {
  background: #fde8ee;
  color: #e5336a;
}
.logo-totalpass {
  background: #111111;
  color: #ffffff;
}
.dark .logo-wellhub {
  background: color-mix(in srgb, #e5336a 18%, var(--superficie));
}
.logo-iniciales {
  font-size: 0.95rem;
  font-weight: 800;
  font-style: italic;
  letter-spacing: -0.04em;
}
</style>
