<script setup lang="ts">
import IconoClima from "@/components/IconoClima.vue";
import type { Clima } from "@/lib/clima";

/**
 * La tarjeta grande de los Inicios (alumno, instructor y negocio): a la izquierda
 * una etiqueta corta en mayúsculas y lo que toca (slot), a la derecha la foto del
 * giro con el clima encima. En el teléfono, lo que toca va primero y la foto debajo,
 * más baja: la foto adorna, no empuja la información. Siempre se muestra: cada
 * Inicio decide qué decir cuando no hay nada agendado.
 */
defineProps<{
  etiqueta: string;
  foto: string;
  clima: Clima | null;
  climaLugar: string;
  // "En curso" y similares resaltan la etiqueta con el color de marca.
  etiquetaViva?: boolean;
}>();
</script>

<template>
  <article class="tp tu-card">
    <div class="tp-texto">
      <p class="tp-etiqueta" :class="{ 'tp-viva': etiquetaViva }">
        {{ etiqueta }}
      </p>
      <slot />
    </div>

    <div class="tp-foto">
      <img :src="foto" alt="" loading="lazy" />
      <div v-if="clima" class="tp-clima" role="status">
        <IconoClima :icono="clima.icono" :de-dia="clima.es_de_dia" :tam="30" />
        <div class="min-w-0">
          <p class="leading-tight">
            <span class="text-xl font-semibold">{{ clima.temperatura }}°</span>
            <span class="ml-1.5 text-sm">{{ clima.condicion }}</span>
            <span
              v-if="clima.lluvia !== null && clima.lluvia >= 20"
              class="ml-1.5 text-sm opacity-85"
              >·
              {{ $t("portal.inicio.clima.lluvia", { n: clima.lluvia }) }}</span
            >
          </p>
          <p v-if="climaLugar" class="mt-0.5 truncate text-xs opacity-85">
            {{ climaLugar }}
          </p>
        </div>
      </div>
    </div>
  </article>
</template>

<style scoped>
/* Texto a la izquierda, foto del giro a la derecha (debajo y más baja en el
   teléfono), con el clima sobre la foto. */
.tp {
  display: grid;
  overflow: hidden;
  padding: 0;
}
.tp-texto {
  order: 1;
  padding: 1.5rem;
}
.tp-foto {
  position: relative;
  order: 2;
  min-height: 7rem;
  background: var(--superficie-2);
}
.tp-foto img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}
@media (min-width: 768px) {
  .tp {
    grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
  }
  .tp-texto {
    order: 1;
    padding: 2.25rem 2rem;
  }
  .tp-foto {
    order: 2;
    min-height: 16rem;
  }
}
.tp-etiqueta {
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--primario);
}
.tp-viva::before {
  content: "";
  display: inline-block;
  width: 0.45rem;
  height: 0.45rem;
  margin-right: 0.4rem;
  border-radius: 9999px;
  background: currentColor;
  vertical-align: 0.1em;
}
/* Sobre una foto cualquiera: fondo oscuro translúcido y texto blanco. */
.tp-clima {
  position: absolute;
  left: 0.75rem;
  right: 0.75rem;
  bottom: 0.75rem;
  display: flex;
  max-width: max-content;
  align-items: center;
  gap: 0.65rem;
  padding: 0.55rem 0.85rem;
  border-radius: 0.8rem;
  color: #fff;
  background: rgb(15 23 42 / 0.58);
  backdrop-filter: blur(6px);
}
</style>
