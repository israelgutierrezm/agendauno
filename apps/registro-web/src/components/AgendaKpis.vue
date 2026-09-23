<script setup lang="ts">
/**
 * Indicadores de la agenda en una sola franja: la etiqueta y el valor, sin íconos
 * ni colores (el color queda para lo que pide atención). Los valores los calcula
 * la vista según la modalidad.
 */
defineProps<{
  tarjetas: {
    clave: string;
    valor: string;
    etiqueta: string;
  }[];
}>();
</script>

<template>
  <dl class="kp tu-card">
    <div v-for="k in tarjetas" :key="k.clave" class="min-w-0">
      <dt class="kp-etiqueta">{{ k.etiqueta }}</dt>
      <dd class="kp-valor">{{ k.valor }}</dd>
    </div>
  </dl>
</template>

<style scoped>
.kp {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(8.5rem, 1fr));
  gap: 1rem;
  margin: 0;
  padding: 0.9rem 1.25rem;
}
.kp-etiqueta {
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.kp-valor {
  margin: 0.15rem 0 0;
  font-size: 1.25rem;
  font-weight: 600;
  line-height: 1.2;
  font-variant-numeric: tabular-nums;
}
</style>
