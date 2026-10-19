<script setup lang="ts" generic="T">
import { ref } from "vue";

import IconoNav from "@/components/IconoNav.vue";

/**
 * Un carrusel del sitio del negocio (ADR 0167): una fila que se desliza con el dedo,
 * con el teclado o con sus flechas, de a casi una pantalla. No se mueve solo: nada
 * cambia sin que quien lo ve lo pida (y sin animación si prefiere menos movimiento).
 */
defineProps<{ items: T[]; etiqueta: string }>();
defineSlots<{ default(props: { item: T; indice: number }): unknown }>();

const pista = ref<HTMLElement | null>(null);

function mover(sentido: 1 | -1): void {
  const el = pista.value;
  if (!el) {
    return;
  }
  const menos =
    typeof window.matchMedia === "function" &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  el.scrollBy({
    left: sentido * el.clientWidth * 0.85,
    behavior: menos ? "auto" : "smooth",
  });
}
</script>

<template>
  <div
    class="cs"
    role="region"
    aria-roledescription="carrusel"
    :aria-label="etiqueta"
    data-prueba="carrusel"
  >
    <ul ref="pista" class="cs-pista" tabindex="0">
      <li v-for="(item, indice) in items" :key="indice" class="cs-elemento">
        <slot :item="item" :indice="indice" />
      </li>
    </ul>
    <div v-if="items.length > 1" class="cs-flechas">
      <button
        type="button"
        class="tu-icono-btn"
        :aria-label="$t('sitioWeb.publico.anterior')"
        data-prueba="carrusel-anterior"
        @click="mover(-1)"
      >
        <IconoNav nombre="atras" :tam="16" />
      </button>
      <button
        type="button"
        class="tu-icono-btn"
        :aria-label="$t('sitioWeb.publico.siguiente')"
        data-prueba="carrusel-siguiente"
        @click="mover(1)"
      >
        <IconoNav nombre="flecha" :tam="16" />
      </button>
    </div>
  </div>
</template>

<style scoped>
.cs-pista {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: min(85%, 18rem);
  gap: 1rem;
  margin: 0;
  padding: 0 0 0.5rem;
  list-style: none;
  overflow-x: auto;
  overscroll-behavior-x: contain;
  scroll-snap-type: x mandatory;
}
.cs-pista:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 4px;
}
.cs-elemento {
  scroll-snap-align: start;
  min-width: 0;
}
.cs-flechas {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.75rem;
}
</style>
