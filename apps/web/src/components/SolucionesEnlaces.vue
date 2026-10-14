<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";
import { computed, useId } from "vue";
import { RouterLink } from "vue-router";
import {
  ETIQUETA_MENU,
  MODOS,
  solucionesDe,
  type Modo,
} from "@/marketing/modalidades";
import { soluciones, rutaSolucion } from "@/marketing/soluciones";

/*
| Enlaces a las páginas por giro (/software-para-*).
| - Sin props: todas, en una cuadrícula (como siempre). `excluir` quita la página actual.
| - `modo`: solo los giros de esa modalidad (/clases, /citas y su página por giro).
| - `dosColumnas` (portada): «Clases» y «Citas», cada una con sus giros. El slot `pie`
|   (con su `modo`) va al final de cada columna: el enlace a la modalidad o la nota de
|   salud de citas los pone la página.
*/
const props = defineProps<{
  excluir?: string;
  modo?: Modo;
  dosColumnas?: boolean;
  /** Tarjetas con fotografía para descubrir el producto por tipo de negocio. */
  visual?: boolean;
}>();
const id = useId();

const lista = computed(() =>
  (props.modo ? solucionesDe(props.modo) : soluciones).filter(
    (s) => s.slug !== props.excluir,
  ),
);
const columnas = computed(() =>
  MODOS.map((modo) => ({
    modo,
    titulo: ETIQUETA_MENU[modo],
    soluciones: solucionesDe(modo).filter((s) => s.slug !== props.excluir),
  })),
);
</script>

<template>
  <nav
    v-if="dosColumnas"
    class="soluciones-columnas"
    aria-label="Soluciones por tipo de negocio"
  >
    <div
      v-for="columna in columnas"
      :key="columna.modo"
      class="soluciones-columna"
      :data-modo="columna.modo"
    >
      <h3 :id="`${id}-${columna.modo}`">{{ columna.titulo }}</h3>
      <ul class="soluciones-enlaces" :aria-labelledby="`${id}-${columna.modo}`">
        <li v-for="solucion in columna.soluciones" :key="solucion.slug">
          <RouterLink :to="rutaSolucion(solucion.slug)">
            <span>{{ solucion.nombre }}</span
            ><span aria-hidden="true"
              ><IconoNav
                nombre="flecha"
                :tam="14"
                class="inline align-[-0.15em]"
            /></span>
          </RouterLink>
        </li>
      </ul>
      <slot name="pie" :modo="columna.modo"></slot>
    </div>
  </nav>
  <nav
    v-else
    class="soluciones-enlaces"
    :class="{ 'soluciones-enlaces--visual': visual }"
    :data-modo="modo"
    aria-label="Soluciones por tipo de negocio"
  >
    <RouterLink
      v-for="solucion in lista"
      :key="solucion.slug"
      :to="rutaSolucion(solucion.slug)"
    >
      <img
        v-if="visual"
        :src="`/assets/landing/disciplinas/${solucion.imagen}`"
        alt=""
        width="480"
        height="320"
        loading="lazy"
        decoding="async"
      />
      <span class="solucion-enlace-copy">
        <span>{{ solucion.nombre }}</span>
        <small v-if="visual">{{ solucion.ejemplo }}</small> </span
      ><span aria-hidden="true"
        ><IconoNav nombre="flecha" :tam="14" class="inline align-[-0.15em]"
      /></span>
    </RouterLink>
  </nav>
</template>

<style scoped>
.soluciones-enlaces {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.75rem;
  margin-top: 1.5rem;
}
.soluciones-enlaces a {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1.2rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta, 18px);
  background: var(--superficie);
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--texto);
  text-decoration: none;
  transition:
    transform 0.2s ease,
    box-shadow 0.2s ease;
}
.soluciones-enlaces a:hover {
  transform: translateY(-2px);
  box-shadow: var(--sombra-elevada);
}
.soluciones-enlaces a:active {
  transform: translateY(0);
}
@media (prefers-reduced-motion: reduce) {
  .soluciones-enlaces a {
    transition: box-shadow 0.2s ease;
  }
  .soluciones-enlaces a:hover {
    transform: none;
  }
}
.soluciones-enlaces a:focus-visible {
  outline: 2px solid var(--enlace);
  outline-offset: 3px;
}
.soluciones-enlaces--visual a {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  padding: 0 0 1.1rem;
  gap: 0.9rem 0.5rem;
  overflow: hidden;
}
.soluciones-enlaces--visual img {
  grid-column: 1 / -1;
  width: 100%;
  aspect-ratio: 3 / 2;
  object-fit: cover;
}
.solucion-enlace-copy {
  min-width: 0;
}
.soluciones-enlaces--visual .solucion-enlace-copy {
  padding-left: 1.1rem;
}
.soluciones-enlaces--visual a > span:last-child {
  padding-right: 1.1rem;
}
.solucion-enlace-copy small {
  display: block;
  margin-top: 0.35rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
  font-weight: 400;
}
/* Portada: dos columnas, una por modalidad, con su lista de giros. */
.soluciones-columnas {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 2rem;
  margin-top: 1.5rem;
}
.soluciones-columna {
  min-width: 0;
}
.soluciones-columna h3 {
  font-size: 1rem;
  font-weight: 500;
}
.soluciones-columna .soluciones-enlaces {
  grid-template-columns: minmax(0, 1fr);
  margin: 1rem 0 0;
  padding: 0;
  list-style: none;
}
@media (max-width: 639px) {
  .soluciones-enlaces,
  .soluciones-columnas {
    grid-template-columns: 1fr;
  }
  .soluciones-enlaces--visual {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
  }
  .soluciones-enlaces--visual a {
    font-size: 0.85rem;
  }
  .soluciones-enlaces--visual .solucion-enlace-copy {
    padding-left: 0.8rem;
  }
  .soluciones-enlaces--visual a > span:last-child {
    padding-right: 0.8rem;
  }
}
@media (prefers-reduced-motion: reduce) {
  .soluciones-enlaces--visual a {
    transition: none;
  }
  .soluciones-enlaces--visual a:hover {
    transform: none;
  }
}
</style>
