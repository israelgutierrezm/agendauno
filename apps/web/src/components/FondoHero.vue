<template>
  <!-- Luz continua, sin círculos ni objetos flotantes detrás del contenido. -->
  <div class="fondo-hero" aria-hidden="true" />
</template>

<style scoped>
.fondo-hero {
  position: absolute;
  inset: 0;
  z-index: 0;
  pointer-events: none;
  background: linear-gradient(
    118deg,
    var(--fondo) 12%,
    color-mix(in srgb, var(--primario) 4%, var(--fondo)) 62%,
    color-mix(in srgb, var(--marketing-rosa) 5%, var(--fondo))
  );
}
</style>
