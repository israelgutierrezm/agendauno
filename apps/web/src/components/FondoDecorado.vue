<script setup lang="ts">
/**
 * Adorno de fondo para una cabecera (la tarjeta de Mi perfil), como la banda
 * decorada de Acadion: aros concéntricos y burbujas tenues en vez de un bloque de
 * color. Todo sale de `--acento` sobre la superficie, así que toma el color del tema
 * (claro u oscuro) sin ajustes por tema. Las figuras van en píxeles y solo su
 * posición en porcentaje: el aro sigue siendo un círculo sea cual sea el ancho.
 * Se tiende debajo del contenido del contenedor (que debe ser `position: relative`).
 */
const aros = [
  { x: 92, y: 8, d: 120, o: 0.3 },
  { x: 92, y: 8, d: 190, o: 0.18 },
  { x: 92, y: 8, d: 264, o: 0.1 },
  { x: 92, y: 8, d: 340, o: 0.05 },
  { x: 4, y: 104, d: 96, o: 0.18 },
  { x: 4, y: 104, d: 156, o: 0.09 },
];

// Burbujas sueltas: rompen la simetría de los dos juegos de aros.
const burbujas = [
  { x: 64, y: 22, d: 8, o: 0.22 },
  { x: 76, y: 72, d: 6, o: 0.26 },
  { x: 54, y: 84, d: 5, o: 0.18 },
  { x: 84, y: 46, d: 4, o: 0.24 },
];
</script>

<template>
  <div class="fd" aria-hidden="true">
    <!-- Dos rectas largas y muy tenues: dan dirección al conjunto. -->
    <svg class="fd-lineas" viewBox="0 0 100 100" preserveAspectRatio="none">
      <line
        x1="38"
        y1="100"
        x2="100"
        y2="30"
        stroke="currentColor"
        stroke-width="1"
        stroke-opacity="0.1"
        vector-effect="non-scaling-stroke"
      />
      <line
        x1="52"
        y1="112"
        x2="100"
        y2="56"
        stroke="currentColor"
        stroke-width="1"
        stroke-opacity="0.07"
        vector-effect="non-scaling-stroke"
      />
    </svg>
    <span
      v-for="(a, i) in aros"
      :key="`aro-${i}`"
      class="fd-aro"
      :style="{
        left: `${a.x}%`,
        top: `${a.y}%`,
        width: `${a.d}px`,
        height: `${a.d}px`,
        marginLeft: `${-a.d / 2}px`,
        marginTop: `${-a.d / 2}px`,
        opacity: a.o,
      }"
    />
    <span
      v-for="(b, i) in burbujas"
      :key="`burbuja-${i}`"
      class="fd-burbuja"
      :style="{
        left: `${b.x}%`,
        top: `${b.y}%`,
        width: `${b.d}px`,
        height: `${b.d}px`,
        marginLeft: `${-b.d / 2}px`,
        marginTop: `${-b.d / 2}px`,
        opacity: b.o,
      }"
    />
  </div>
</template>

<style scoped>
.fd {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
  border-radius: inherit;
  color: var(--acento);
}
.fd-lineas {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}
.fd-aro,
.fd-burbuja {
  position: absolute;
  border-radius: 9999px;
}
.fd-aro {
  border: 1px solid currentColor;
}
.fd-burbuja {
  background: currentColor;
}
</style>
