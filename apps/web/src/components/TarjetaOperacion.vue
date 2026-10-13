<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, type RouteLocationRaw } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import IlustracionAcceso, {
  type Ilustracion,
} from "@/components/IlustracionAcceso.vue";
import { imagenAcceso } from "@/lib/imagenesAcceso";

export type { Ilustracion };

/**
 * Acceso grande de un Inicio (negocio, alumno o instructor): el ícono en su cuadro
 * de color, el título, su dato o qué se hace ahí y un botón con flecha, sobre un
 * fondo suave del mismo color con una ilustración a la derecha. Lleva a una pantalla
 * (`to`) o hace algo al tocarla (`tocar`, p. ej. mostrar el pase). El dato va en
 * ámbar cuando pide atención. Si existe su imagen (`imagen`, la primera que haya de
 * las candidatas en `src/assets/accesos/`), va en lugar de la ilustración.
 */
const props = defineProps<{
  to?: RouteLocationRaw;
  icono: string;
  titulo: string;
  texto: string;
  tono: string;
  ilustracion: Ilustracion;
  atencion?: boolean;
  imagen?: string | string[];
}>();
const emit = defineEmits<{ tocar: [] }>();
const foto = computed(() => imagenAcceso(props.imagen));
</script>

<template>
  <component
    :is="props.to ? RouterLink : 'button'"
    :to="props.to"
    :type="props.to ? undefined : 'button'"
    class="to-tarjeta tu-card"
    :class="`tu-tono-${tono}`"
    @click="props.to ? undefined : emit('tocar')"
  >
    <span class="to-fondo" aria-hidden="true"></span>
    <img
      v-if="foto"
      :src="foto"
      alt=""
      class="to-dibujo to-foto"
      loading="lazy"
      decoding="async"
    />
    <IlustracionAcceso v-else :nombre="ilustracion" class="to-dibujo" />

    <span class="to-contenido">
      <span class="tu-icono-tono to-icono" aria-hidden="true">
        <IconoNav :nombre="icono" :tam="24" />
      </span>
      <span class="to-titulo">{{ titulo }}</span>
      <span
        class="to-texto"
        :style="
          atencion ? { color: 'var(--aviso)', fontWeight: 600 } : undefined
        "
        >{{ texto }}</span
      >
      <span class="to-boton" aria-hidden="true">
        <IconoNav nombre="flecha" :tam="20" />
      </span>
    </span>
  </component>
</template>

<style scoped>
.to-tarjeta {
  position: relative;
  display: block;
  width: 100%;
  height: 100%;
  min-height: 13.5rem;
  overflow: hidden;
  padding: 1.35rem;
  color: inherit;
  text-align: left;
  text-decoration: none;
  cursor: pointer;
  background: linear-gradient(
    120deg,
    color-mix(in srgb, var(--tono) 9%, var(--superficie)) 0%,
    color-mix(in srgb, var(--tono) 18%, var(--superficie)) 100%
  );
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease,
    transform 0.15s ease;
}
.to-tarjeta:hover {
  border-color: color-mix(in srgb, var(--tono) 45%, var(--borde));
  transform: translateY(-2px);
}
.to-tarjeta:focus-visible {
  outline: 2px solid var(--tono);
  outline-offset: 3px;
}
.to-fondo {
  position: absolute;
  top: -4rem;
  right: -4rem;
  width: 13rem;
  height: 13rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--tono) 12%, transparent);
}
.to-dibujo {
  position: absolute;
  right: 0.35rem;
  bottom: 0.35rem;
  width: 36%;
  max-width: 13rem;
  height: 76%;
  pointer-events: none;
}
.to-foto {
  object-fit: contain;
  object-position: right bottom;
}
.to-contenido {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  height: 100%;
  min-height: 11rem;
  max-width: 62%;
}
.to-icono {
  width: 3.1rem;
  height: 3.1rem;
  border-radius: 0.9rem;
}
.to-titulo {
  margin-top: 1rem;
  font-size: 1.2rem;
  font-weight: 600;
  line-height: 1.2;
}
.to-texto {
  margin-top: 0.4rem;
  font-size: 0.88rem;
  line-height: 1.4;
  color: var(--texto-suave);
}
.to-boton {
  margin-top: auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.8rem;
  background: var(--superficie);
  color: var(--texto);
  border: 1px solid color-mix(in srgb, var(--tono) 15%, var(--borde));
  box-shadow: 0 3px 8px color-mix(in srgb, var(--texto) 6%, transparent);
}
@media (prefers-reduced-motion: reduce) {
  .to-tarjeta {
    transition: none;
  }
  .to-tarjeta:hover {
    transform: none;
  }
}
</style>
