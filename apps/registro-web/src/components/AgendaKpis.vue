<script setup lang="ts">
/**
 * Tira de indicadores de la agenda: cada tarjeta con un icono de color, el valor
 * grande y su etiqueta. Los valores los calcula la vista (según la modalidad).
 */
defineProps<{
  tarjetas: {
    clave: string;
    valor: string;
    etiqueta: string;
    icono: string; // path SVG (trazo)
    fondo: string;
    tinta: string;
  }[];
}>();
</script>

<template>
  <ul class="kp" role="list">
    <li v-for="k in tarjetas" :key="k.clave" class="kp-tarjeta tu-card">
      <span
        class="kp-icono"
        :style="{ background: k.fondo, color: k.tinta }"
        aria-hidden="true"
      >
        <svg
          width="19"
          height="19"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
        >
          <path :d="k.icono" />
        </svg>
      </span>
      <span class="min-w-0">
        <span class="kp-valor">{{ k.valor }}</span>
        <span class="kp-etiqueta">{{ k.etiqueta }}</span>
      </span>
    </li>
  </ul>
</template>

<style scoped>
.kp {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}
.kp-tarjeta {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.8rem 1rem;
}
.kp-icono {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.75rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.kp-valor {
  display: block;
  font-size: 1.35rem;
  font-weight: 800;
  letter-spacing: -0.02em;
  line-height: 1.1;
}
.kp-etiqueta {
  display: block;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--texto-suave);
}
</style>
