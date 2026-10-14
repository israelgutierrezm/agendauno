<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, type RouteLocationRaw } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";

/**
 * Indicadores (Agenda, Recepción, listados): una franja con la etiqueta, un ícono
 * pequeño y el valor. Las tarjetas amplias usan un acento suave; la variante
 * compacta conserva su franja para no restar espacio a la agenda.
 * El aviso y la tendencia mantienen sus colores semánticos.
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
  // A dónde lleva (M08): el listado ya filtrado; toda la tarjeta es el enlace.
  to?: RouteLocationRaw;
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
        :class="
          !compacta
            ? `tu-tono-${k.aviso ? 'naranja' : (k.tono ?? 'azul')}`
            : undefined
        "
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
          <RouterLink
            v-if="k.to"
            :to="k.to"
            class="ti-enlace"
            :aria-label="`${k.etiqueta}: ${k.valor}`"
            :style="k.aviso ? { color: 'var(--aviso)' } : undefined"
            >{{ k.valor }}</RouterLink
          >
          <span
            v-else
            :style="k.aviso ? { color: 'var(--aviso)' } : undefined"
            >{{ k.valor }}</span
          >
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
/* Compacta (tableros operativos): menos alto, para que se vea lo de abajo. */
@container (min-width: 34rem) {
  .ti-compacta .ti-celda {
    padding: 0.6rem 0.95rem 0.65rem;
  }
  .ti-compacta .ti-valor {
    margin-top: 0.15rem;
    font-size: 1.2rem;
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
/* Con destino (M08): toda la tarjeta lleva al listado filtrado. */
.ti-celda {
  position: relative;
}
.ti-enlace {
  color: inherit;
  text-decoration: none;
}
.ti-enlace::after {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: inherit;
}
.ti-celda:has(.ti-enlace):hover {
  background: var(--fondo);
}
.ti-enlace:focus-visible {
  outline: none;
}
.ti-celda:has(.ti-enlace:focus-visible) {
  outline: 2px solid var(--primario);
  outline-offset: -2px;
}
</style>

<style scoped>
.ti:not(.ti-compacta) {
  gap: 0.85rem;
  border: 0;
  border-radius: 0;
  overflow: visible;
  background: transparent;
}
.ti:not(.ti-compacta) .ti-celda {
  padding: 1.15rem;
  border: 1px solid
    color-mix(in srgb, var(--tono, var(--primario)) 16%, var(--borde));
  border-radius: var(--radio-tarjeta, 1rem);
  background: linear-gradient(
    135deg,
    color-mix(in srgb, var(--tono, var(--primario)) 7%, var(--superficie)),
    var(--superficie) 85%
  );
  box-shadow: var(--sombra-tarjeta);
}
.ti:not(.ti-compacta) .ti-icono {
  box-sizing: content-box;
  padding: 0.45rem;
  border-radius: 0.65rem;
  background: color-mix(
    in srgb,
    var(--tono, var(--primario)) 12%,
    var(--superficie)
  );
  color: var(--texto);
  opacity: 1;
}
.ti:not(.ti-compacta) .ti-valor {
  margin-top: 0.8rem;
  font-size: clamp(1.45rem, 2.3vw, 2rem);
  font-weight: 500;
}
/* Con destino: se eleva con una sombra del tono de la tarjeta (el borde no cambia). */
.ti:not(.ti-compacta) .ti-celda:has(.ti-enlace) {
  transition:
    transform 0.2s ease,
    box-shadow 0.2s ease;
}
.ti:not(.ti-compacta) .ti-celda:has(.ti-enlace):hover {
  transform: translateY(-2px);
  box-shadow:
    var(--sombra-elevada),
    0 12px 24px -14px
      color-mix(in srgb, var(--tono, var(--primario)) 45%, transparent);
}
.ti:not(.ti-compacta) .ti-celda:has(.ti-enlace):active {
  transform: translateY(0);
}
@media (prefers-reduced-motion: reduce) {
  .ti:not(.ti-compacta) .ti-celda:has(.ti-enlace) {
    transition: box-shadow 0.2s ease;
  }
  .ti:not(.ti-compacta) .ti-celda:has(.ti-enlace):hover {
    transform: none;
  }
}
.ti-celda:has(.ti-enlace:focus-visible) {
  outline-offset: 2px;
}
</style>
