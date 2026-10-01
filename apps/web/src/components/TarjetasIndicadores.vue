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

/** Cómo va contra el periodo anterior: «↑ 12 %», en verde si es bueno. */
export interface Tendencia {
  direccion: "sube" | "baja" | "igual";
  texto: string;
  // true = bueno (verde), false = malo (rojo), null = neutro.
  buena: boolean | null;
  titulo?: string;
}

export interface Indicador {
  clave: string;
  valor: string;
  etiqueta: string;
  icono?: string;
  tono?: Tono;
  aviso?: boolean;
  tendencia?: Tendencia;
}

const props = withDefaults(
  defineProps<{
    tarjetas: Indicador[];
    // De fondo: el ícono grande y tenue, o unas barras.
    decoracion?: "icono" | "barras";
  }>(),
  { decoracion: "icono" },
);

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
  <div class="ti-envoltura">
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
        :class="[
          `tu-tono-${k.tono ?? 'azul'}`,
          { 'ti-con-barras': decoracion === 'barras' },
        ]"
        :data-prueba="`indicador-${k.clave}`"
      >
        <span class="tu-icono-tono ti-icono" aria-hidden="true">
          <IconoNav :nombre="k.icono ?? 'punto'" :tam="22" />
        </span>
        <div class="min-w-0">
          <dt class="ti-etiqueta">{{ k.etiqueta }}</dt>
          <dd class="ti-valor">
            <span :style="k.aviso ? { color: 'var(--aviso)' } : undefined">{{
              k.valor
            }}</span>
            <span
              v-if="k.tendencia"
              class="ti-tendencia"
              :class="
                k.tendencia.buena === true
                  ? 'ti-buena'
                  : k.tendencia.buena === false
                    ? 'ti-mala'
                    : 'ti-neutra'
              "
              :title="k.tendencia.titulo"
              data-prueba="tendencia"
            >
              <IconoNav
                v-if="k.tendencia.direccion !== 'igual'"
                :nombre="k.tendencia.direccion === 'sube' ? 'arriba' : 'abajo'"
                :tam="12"
              />
              {{ k.tendencia.texto }}
            </span>
          </dd>
        </div>
        <span v-if="decoracion === 'icono'" class="ti-fondo" aria-hidden="true">
          <IconoNav :nombre="k.icono ?? 'punto'" :tam="72" />
        </span>
        <span v-else class="ti-barras" aria-hidden="true">
          <span
            v-for="(h, i) in [38, 62, 48, 86]"
            :key="i"
            :style="{ height: `${h}%` }"
          ></span>
        </span>
      </div>
    </dl>
  </div>
</template>

<style scoped>
/* Según el ancho que tiene (no el de la ventana, que incluye el menú): uno por fila
   si es angosto, de dos en dos (o los 3) en mediano y en una fila si cabe. */
.ti-envoltura {
  container-type: inline-size;
}
.ti {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.85rem;
  margin: 0;
}
@container (min-width: 34rem) {
  .ti {
    grid-template-columns: repeat(var(--ti-n-medio), minmax(0, 1fr));
  }
}
@container (min-width: 60rem) {
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
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem 0.6rem;
  margin: 0.1rem 0 0;
  font-size: 1.35rem;
  font-weight: 700;
  line-height: 1.2;
  font-variant-numeric: tabular-nums;
}
.ti-tendencia {
  display: inline-flex;
  align-items: center;
  gap: 0.15rem;
  padding: 0.1rem 0.4rem;
  border-radius: 0.4rem;
  font-size: 0.72rem;
  font-weight: 600;
}
.ti-buena {
  background: color-mix(in srgb, var(--exito) 12%, var(--superficie));
  color: var(--exito-texto, var(--exito));
}
.ti-mala {
  background: color-mix(in srgb, var(--error) 10%, var(--superficie));
  color: var(--error);
}
.ti-neutra {
  background: var(--superficie-2);
  color: var(--texto-suave);
}
/* Las barras van a la derecha: el texto no les pasa por encima. */
.ti-con-barras {
  padding-right: 2.9rem;
}
.ti-barras {
  position: absolute;
  right: 1rem;
  bottom: 1rem;
  display: flex;
  align-items: flex-end;
  gap: 0.2rem;
  height: 1.7rem;
  pointer-events: none;
}
.ti-barras > span {
  width: 0.35rem;
  border-radius: 0.15rem;
  background: var(--tono);
  opacity: 0.25;
}
.ti-barras > span:last-child {
  opacity: 0.55;
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
