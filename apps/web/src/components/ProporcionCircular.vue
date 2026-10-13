<script setup lang="ts">
import { computed } from "vue";
const props = defineProps<{ valor: number; total: number; etiqueta: string }>();
// Es una proporción de los datos recibidos, nunca una tendencia ni una meta.
const porcentaje = computed(() =>
  props.total > 0 &&
  Number.isFinite(props.valor) &&
  Number.isFinite(props.total)
    ? Math.max(0, Math.min(100, Math.round((props.valor / props.total) * 100)))
    : null,
);
</script>
<template>
  <div
    class="pc"
    role="img"
    :aria-label="`${etiqueta}: ${porcentaje === null ? '—' : porcentaje + '%'}`"
  >
    <svg viewBox="0 0 120 120" aria-hidden="true">
      <circle cx="60" cy="60" r="49" class="pc-base" />
      <circle
        v-if="porcentaje !== null"
        cx="60"
        cy="60"
        r="49"
        class="pc-valor"
        pathLength="100"
        :stroke-dasharray="`${porcentaje} 100`"
        transform="rotate(-90 60 60)"
      />
    </svg>
    <span aria-hidden="true">{{
      porcentaje === null ? "—" : porcentaje + "%"
    }}</span>
  </div>
</template>
<style scoped>
.pc {
  position: relative;
  width: 8.5rem;
  aspect-ratio: 1;
  flex-shrink: 0;
}
.pc svg {
  width: 100%;
  height: 100%;
  overflow: visible;
}
.pc circle {
  fill: none;
  stroke-width: 7;
}
.pc-base {
  stroke: color-mix(in srgb, var(--primario) 12%, var(--superficie));
}
.pc-valor {
  stroke: var(--primario);
  stroke-linecap: round;
}
.pc span {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  font-size: 1.8rem;
  font-weight: 600;
  letter-spacing: -0.04em;
  font-variant-numeric: tabular-nums;
  color: var(--texto);
}
</style>
