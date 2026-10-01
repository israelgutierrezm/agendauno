<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";

/**
 * Indicadores de la agenda, uno por tarjeta: el ícono en un cuadro de su color, la
 * etiqueta y el valor, y el mismo ícono grande y tenue de fondo. Los valores los
 * calcula la vista según la modalidad.
 */
export type TonoKpi = "azul" | "verde" | "naranja" | "morado" | "rosa";

defineProps<{
  tarjetas: {
    clave: string;
    valor: string;
    etiqueta: string;
    icono?: string;
    tono?: TonoKpi;
  }[];
}>();
</script>

<template>
  <dl class="kp" :style="{ '--kp-n': String(Math.max(tarjetas.length, 1)) }">
    <div
      v-for="k in tarjetas"
      :key="k.clave"
      class="kp-tarjeta tu-card"
      :class="`kp-${k.tono ?? 'azul'}`"
    >
      <span class="kp-icono" aria-hidden="true">
        <IconoNav :nombre="k.icono ?? 'punto'" :tam="22" />
      </span>
      <div class="min-w-0">
        <dt class="kp-etiqueta">{{ k.etiqueta }}</dt>
        <dd class="kp-valor">{{ k.valor }}</dd>
      </div>
      <span class="kp-fondo" aria-hidden="true">
        <IconoNav :nombre="k.icono ?? 'punto'" :tam="72" />
      </span>
    </div>
  </dl>
</template>

<style scoped>
/* Uno por fila en el teléfono, de dos en dos en mediano y todos en una fila en
   pantalla grande (sin que quede uno solo abajo). */
.kp {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.85rem;
  margin: 0;
}
@media (min-width: 640px) {
  .kp {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (min-width: 1200px) {
  .kp {
    grid-template-columns: repeat(var(--kp-n), minmax(0, 1fr));
  }
}
.kp-tarjeta {
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 1rem 1.1rem;
}
.kp-azul {
  --kp-tono: #3b82f6;
}
.kp-verde {
  --kp-tono: #16a34a;
}
.kp-naranja {
  --kp-tono: #f97316;
}
.kp-morado {
  --kp-tono: #8b5cf6;
}
.kp-rosa {
  --kp-tono: #ec4899;
}
.kp-icono {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.85rem;
  height: 2.85rem;
  flex-shrink: 0;
  border-radius: 0.8rem;
  background: color-mix(in srgb, var(--kp-tono) 13%, var(--superficie));
  color: var(--kp-tono);
}
.kp-etiqueta {
  font-size: 0.78rem;
  color: var(--texto-suave);
}
.kp-valor {
  margin: 0.1rem 0 0;
  font-size: 1.35rem;
  font-weight: 700;
  line-height: 1.2;
  font-variant-numeric: tabular-nums;
}
.kp-fondo {
  position: absolute;
  right: -0.5rem;
  bottom: -1rem;
  color: var(--kp-tono);
  opacity: 0.1;
  pointer-events: none;
}
</style>
