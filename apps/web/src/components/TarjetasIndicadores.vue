<script setup lang="ts">
import { computed } from "vue";

import IconoNav from "@/components/IconoNav.vue";

/**
 * Indicadores (Agenda, Recepción, listados): una franja con la etiqueta, un ícono
 * pequeño y neutro, y el valor. Sin color de adorno: solo lo que pide atención
 * (`aviso`) va en el color de aviso y la tendencia, en verde o rojo. `tono` y
 * `decoracion` se conservan por compatibilidad, pero ya no pintan nada.
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
    // Tableros operativos: libera altura antes de la agenda si caben las cifras.
    compacta?: boolean;
  }>(),
  { decoracion: "icono", compacta: false },
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
      :class="{
        'ti-medio-pares': columnasMedio === 2,
        'ti-compacta': compacta,
      }"
      :style="{
        '--ti-n': String(columnas),
        '--ti-n-medio': String(columnasMedio),
      }"
    >
      <div
        v-for="k in tarjetas"
        :key="k.clave"
        class="ti-celda"
        :data-prueba="`indicador-${k.clave}`"
      >
        <dt class="ti-etiqueta">
          <IconoNav
            v-if="k.icono"
            class="ti-icono"
            :nombre="k.icono"
            :tam="15"
          />
          {{ k.etiqueta }}
        </dt>
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
    </dl>
  </div>
</template>

<style scoped>
/* Una sola franja, al estilo de los tableros de producto: celdas separadas por una
   línea fina, sin cuadros de color ni íconos de adorno. Se acomoda al ancho que
   tiene (no al de la ventana): de dos en dos y compactas si es angosto (el teléfono),
   de dos en dos (o las 3) en mediano y en una fila si cabe. */
.ti-envoltura {
  container-type: inline-size;
}
.ti {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1px;
  margin: 0;
  overflow: hidden;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--borde);
}
/* Angosto: con un número impar, la última ocupa la fila completa. */
.ti-celda:last-child:nth-child(odd) {
  grid-column: span 2;
}
@container (min-width: 34rem) {
  .ti {
    grid-template-columns: repeat(var(--ti-n-medio), minmax(0, 1fr));
  }
  /* De dos en dos con un número impar: la última ocupa la fila completa. Con
     tres columnas (3 o menos indicadores) caben todas en una fila. */
  .ti-celda:last-child:nth-child(odd) {
    grid-column: auto;
  }
  .ti-medio-pares > .ti-celda:last-child:nth-child(odd) {
    grid-column: span 2;
  }
}
@container (min-width: 60rem) {
  .ti {
    grid-template-columns: repeat(var(--ti-n), minmax(0, 1fr));
  }
  .ti-medio-pares > .ti-celda:last-child:nth-child(odd) {
    grid-column: auto;
  }
}
.ti-celda {
  min-width: 0;
  padding: 0.6rem 0.8rem 0.65rem;
  background: var(--superficie);
}
@container (min-width: 48rem) {
  .ti-compacta {
    grid-template-columns: repeat(var(--ti-n), minmax(0, 1fr));
  }
  .ti-compacta.ti-medio-pares > .ti-celda:last-child:nth-child(odd) {
    grid-column: auto;
  }
}
@container (min-width: 34rem) {
  .ti-celda {
    padding: 0.95rem 1.15rem 1rem;
  }
}
.ti-etiqueta {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.ti-icono {
  flex-shrink: 0;
  opacity: 0.85;
}
.ti-valor {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.25rem 0.6rem;
  margin: 0.2rem 0 0;
  font-size: 1.15rem;
  font-weight: 600;
  line-height: 1.2;
  letter-spacing: -0.01em;
  font-variant-numeric: tabular-nums;
}
@container (min-width: 34rem) {
  .ti-valor {
    margin-top: 0.35rem;
    font-size: 1.45rem;
  }
}
.ti-tendencia {
  display: inline-flex;
  align-items: center;
  gap: 0.15rem;
  font-size: 0.75rem;
  font-weight: 500;
  letter-spacing: 0;
}
.ti-buena {
  color: var(--exito-texto, var(--exito));
}
.ti-mala {
  color: var(--error);
}
.ti-neutra {
  color: var(--texto-suave);
}
</style>
