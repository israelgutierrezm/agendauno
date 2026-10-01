<script setup lang="ts">
import { computed } from "vue";

import IconoNav from "@/components/IconoNav.vue";

/**
 * Indicadores en tarjetas (Agenda, Recepción, Inicio): el ícono en un cuadro de su
 * color, la etiqueta y el valor, y el mismo ícono grande y tenue de fondo. Un valor
 * que pide atención (`aviso`) va en el color de aviso. Los colores son los de
 * `.tu-tono-*` (style.css).
 */
export type Tono = "azul" | "verde" | "naranja" | "morado" | "rosa" | "cielo";

export interface Indicador {
  clave: string;
  valor: string;
  etiqueta: string;
  icono?: string;
  tono?: Tono;
  aviso?: boolean;
}

const props = defineProps<{ tarjetas: Indicador[] }>();

// En pantalla grande, todos en una fila hasta 5; con 6, dos filas de 3. En mediano,
// de dos en dos, salvo que sean 3 o menos (caben en una fila).
const columnas = computed(() => {
  const n = Math.max(props.tarjetas.length, 1);
  return n <= 5 ? n : Math.ceil(n / 2);
});
const columnasMedio = computed(() =>
  Math.min(
    props.tarjetas.length <= 3 ? 3 : 2,
    Math.max(props.tarjetas.length, 1),
  ),
);
</script>

<template>
  <dl
    class="ti"
    :style="{
      '--ti-n': String(columnas),
      '--ti-n-medio': String(columnasMedio),
    }"
  >
    <div
      v-for="k in tarjetas"
      :key="k.clave"
      class="ti-tarjeta tu-card"
      :class="`tu-tono-${k.tono ?? 'azul'}`"
      :data-prueba="`indicador-${k.clave}`"
    >
      <span class="tu-icono-tono ti-icono" aria-hidden="true">
        <IconoNav :nombre="k.icono ?? 'punto'" :tam="22" />
      </span>
      <div class="min-w-0">
        <dt class="ti-etiqueta">{{ k.etiqueta }}</dt>
        <dd
          class="ti-valor"
          :style="k.aviso ? { color: 'var(--aviso)' } : undefined"
        >
          {{ k.valor }}
        </dd>
      </div>
      <span class="ti-fondo" aria-hidden="true">
        <IconoNav :nombre="k.icono ?? 'punto'" :tam="72" />
      </span>
    </div>
  </dl>
</template>

<style scoped>
/* Uno por fila en el teléfono, de dos en dos en mediano y en una fila en pantalla
   grande (sin que quede uno solo abajo). */
.ti {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.85rem;
  margin: 0;
}
@media (min-width: 640px) {
  .ti {
    grid-template-columns: repeat(var(--ti-n-medio), minmax(0, 1fr));
  }
}
@media (min-width: 1200px) {
  .ti {
    grid-template-columns: repeat(var(--ti-n), minmax(0, 1fr));
  }
}
.ti-tarjeta {
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 1rem 1.1rem;
}
.ti-icono {
  width: 2.85rem;
  height: 2.85rem;
}
.ti-etiqueta {
  font-size: 0.78rem;
  color: var(--texto-suave);
}
.ti-valor {
  margin: 0.1rem 0 0;
  font-size: 1.35rem;
  font-weight: 700;
  line-height: 1.2;
  font-variant-numeric: tabular-nums;
}
.ti-fondo {
  position: absolute;
  right: -0.5rem;
  bottom: -1rem;
  color: var(--tono);
  opacity: 0.1;
  pointer-events: none;
}
</style>
