<script setup lang="ts">
import { computed } from "vue";

import LogoAgendaUno from "@/components/LogoAgendaUno.vue";
import { PRODUCTOS, type Producto, productoActual } from "@/lib/producto";

/**
 * El logo del producto (ADR 0108). AgendaUno usa su logo oficial. TurnoUno aún no
 * tiene logotipo definitivo: se muestra su nombre en texto (`VITE_TURNOUNO_NOMBRE`)
 * con los colores de la plataforma, configurables con `--turnouno-marca` y
 * `--turnouno-acento`.
 */
const props = withDefaults(
  defineProps<{
    producto?: Producto;
    ancho?: number;
    variante?: "horizontal" | "horizontal-slogan" | "vertical" | "isotipo";
  }>(),
  { producto: undefined, ancho: 150, variante: "horizontal" },
);

const actual = computed(() => props.producto ?? productoActual());
const nombre = computed(() => PRODUCTOS[actual.value].nombre);
// «Turno» + «Uno»: la segunda parte de la marca en el color de acento.
const partes = computed(() => {
  const n = nombre.value;
  const corte = n.search(/[A-Z][a-z]*$/);
  return corte > 0 ? [n.slice(0, corte), n.slice(corte)] : [n, ""];
});
</script>

<template>
  <LogoAgendaUno
    v-if="actual === 'agendauno'"
    :variante="variante"
    :ancho="ancho"
  />
  <span
    v-else
    class="producto-logo-texto"
    :style="{
      fontSize: `${Math.round(ancho / (variante === 'isotipo' ? 3.6 : 6.4))}px`,
    }"
    :aria-label="nombre"
    role="img"
    data-prueba="logo-texto"
    ><span aria-hidden="true"
      >{{ partes[0]
      }}<span class="producto-logo-acento">{{ partes[1] }}</span></span
    ></span
  >
</template>

<style scoped>
.producto-logo-texto {
  display: inline-flex;
  align-items: baseline;
  font-weight: 600;
  letter-spacing: -0.02em;
  line-height: 1;
  color: var(--turnouno-marca, var(--texto, #031b4e));
  white-space: nowrap;
}
.producto-logo-acento {
  color: var(--turnouno-acento, var(--acento, #006df7));
}
</style>
