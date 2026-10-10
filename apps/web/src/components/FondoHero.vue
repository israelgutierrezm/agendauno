<script setup lang="ts">
/**
 * Fondo decorativo de los hero comerciales (portada, /clases y /citas): círculos
 * suaves en el rosa y el azul de la marca detrás del texto y las fotos. Solo adorno:
 * no lo leen los lectores de pantalla y no recibe clics.
 */
</script>

<template>
  <div class="fondo-hero" aria-hidden="true">
    <span class="fh-forma fh-rosa" />
    <span class="fh-forma fh-azul" />
    <span class="fh-forma fh-azul-izquierda" />
    <span class="fh-forma fh-rosa-abajo" />
  </div>
</template>

<style scoped>
.fondo-hero {
  position: absolute;
  inset: 0;
  z-index: 0;
  overflow: hidden;
  pointer-events: none;
}
.fh-forma {
  position: absolute;
  border-radius: 50%;
}
/* Detrás de las fotos: el círculo rosa grande, con su borde definido. */
.fh-rosa {
  top: -14rem;
  right: 6%;
  width: clamp(26rem, 52vw, 48rem);
  aspect-ratio: 1;
  background: radial-gradient(
    circle at 32% 34%,
    color-mix(in srgb, var(--marketing-rosa) 17%, transparent),
    color-mix(in srgb, var(--marketing-rosa) 7%, transparent) 72%
  );
}
.fh-azul {
  top: 4rem;
  right: -18rem;
  width: clamp(28rem, 58vw, 54rem);
  aspect-ratio: 1;
  background: radial-gradient(
    circle at 40% 40%,
    color-mix(in srgb, var(--primario) 9%, transparent),
    color-mix(in srgb, var(--primario) 4%, transparent) 70%
  );
}
.fh-azul-izquierda {
  top: 32%;
  left: -16rem;
  width: clamp(18rem, 32vw, 30rem);
  aspect-ratio: 1;
  background: radial-gradient(
    circle at 65% 45%,
    color-mix(in srgb, var(--primario) 8%, transparent),
    color-mix(in srgb, var(--primario) 3%, transparent) 72%
  );
}
.fh-rosa-abajo {
  right: -9rem;
  bottom: -11rem;
  width: clamp(14rem, 24vw, 24rem);
  aspect-ratio: 1;
  background: color-mix(in srgb, var(--marketing-rosa) 9%, transparent);
}
/* En oscuro, más tenues: que no compitan con el texto. */
.dark .fondo-hero {
  opacity: 0.55;
}
/* En el teléfono, el rosa queda arriba del texto y los demás se apartan. */
@media (max-width: 767px) {
  .fh-rosa {
    top: -10rem;
    right: -8rem;
  }
  .fh-azul {
    top: auto;
    bottom: -12rem;
    right: -14rem;
  }
  .fh-rosa-abajo {
    display: none;
  }
}
</style>
