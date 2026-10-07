<script setup lang="ts">
/*
| Collage del hero de las páginas comerciales: tres fotos de negocios (la del centro,
| más alta, se pide primero) y un aviso flotante («Reserva confirmada»…). Solo
| presentación: sin estado ni APIs del navegador, se prerenderiza tal cual. Las
| animaciones se apagan con `prefers-reduced-motion`.
*/
export interface FotoCollage {
  clave: string;
  src: string;
  alt: string;
  /** Nombre del negocio sobre la foto. */
  etiqueta: string;
  /** `object-position` si el sujeto no está centrado. */
  posicion?: string;
}

defineProps<{
  fotos: readonly FotoCollage[];
  aviso: { titulo: string; detalle: string };
}>();
</script>

<template>
  <figure class="tu-hero-visual reveal">
    <div class="tu-hero-collage">
      <div
        v-for="(foto, i) in fotos"
        :key="foto.clave"
        class="tu-hero-foto"
        :class="`tu-hero-foto--${i + 1}`"
      >
        <img
          :src="foto.src"
          :alt="foto.alt"
          width="1122"
          height="1402"
          :style="foto.posicion ? { objectPosition: foto.posicion } : undefined"
          :fetchpriority="i === 1 ? 'high' : undefined"
          decoding="async"
        />
        <span>{{ foto.etiqueta }}</span>
      </div>
      <div class="tu-hero-reserva">
        <span class="tu-hero-reserva-check" aria-hidden="true">
          <svg
            viewBox="0 0 24 24"
            width="16"
            height="16"
            fill="none"
            stroke="currentColor"
            stroke-width="2.6"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path d="m5 12.5 4 4 10-10" />
          </svg>
        </span>
        <span>
          <strong>{{ aviso.titulo }}</strong>
          <small>{{ aviso.detalle }}</small>
        </span>
      </div>
    </div>
  </figure>
</template>

<style scoped>
.tu-hero-visual {
  min-width: 0;
  margin: 0;
  transform-origin: 50% 100%;
}
.tu-hero-collage {
  position: relative;
  display: grid;
  height: clamp(32rem, 54vw, 41rem);
  grid-template-columns: 0.9fr 1.08fr 0.9fr;
  align-items: center;
  gap: 0.65rem;
  overflow: hidden;
  padding: 1rem;
  border-radius: var(--radio-panel, 28px);
  background:
    radial-gradient(circle at 84% 18%, rgb(79 127 144 / 16%), transparent 32%),
    linear-gradient(145deg, #e9edf1 0%, #eef4f5 52%, #e3edef 100%);
}
.tu-hero-foto {
  position: relative;
  height: 82%;
  overflow: hidden;
  border: 1px solid rgb(255 255 255 / 72%);
  border-radius: 1.5rem;
  background: #fff;
  box-shadow: 0 1.2rem 3.5rem rgb(36 51 70 / 13%);
}
.tu-hero-foto--2 {
  height: 96%;
}
.tu-hero-foto--3 {
  height: 76%;
}
.tu-hero-foto img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
  transition: transform 1.1s cubic-bezier(0.22, 1, 0.36, 1);
}
.tu-hero-foto:hover img {
  transform: scale(1.045);
}
.tu-hero-foto > span {
  position: absolute;
  right: 0.55rem;
  bottom: 0.55rem;
  left: 0.55rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem 0.65rem;
  border: 1px solid rgb(255 255 255 / 48%);
  border-radius: 8px;
  background: rgb(14 22 32 / 58%);
  color: #fff;
  font-size: 0.72rem;
  font-weight: 600;
  backdrop-filter: blur(14px);
}
.tu-hero-reserva {
  position: absolute;
  z-index: 3;
  right: 1.4rem;
  bottom: 1.4rem;
  display: flex;
  max-width: 17rem;
  align-items: center;
  gap: 0.75rem;
  padding: 0.8rem 1rem;
  border: 1px solid rgb(255 255 255 / 72%);
  border-radius: 1.15rem;
  background: rgb(255 255 255 / 88%);
  color: #17212e;
  text-align: left;
  box-shadow: 0 1rem 2.8rem rgb(38 51 67 / 18%);
  backdrop-filter: blur(18px);
  animation: tu-reserva-flota 4.6s ease-in-out infinite;
}
.tu-hero-reserva-check {
  display: grid;
  width: 2.15rem;
  height: 2.15rem;
  flex: 0 0 auto;
  place-content: center;
  border-radius: 50%;
  background: #198754;
  color: #fff;
}
.tu-hero-reserva strong,
.tu-hero-reserva small {
  display: block;
}
.tu-hero-reserva strong {
  font-size: 0.84rem;
  font-weight: 600;
}
.tu-hero-reserva small {
  margin-top: 0.1rem;
  color: #5f6975;
  font-size: 0.7rem;
}
.tu-hero-foto--1 {
  animation: tu-foto-flota 7s ease-in-out infinite alternate;
}
.tu-hero-foto--3 {
  animation: tu-foto-flota 8s -3s ease-in-out infinite alternate-reverse;
}
@keyframes tu-foto-flota {
  to {
    transform: translateY(-0.65rem);
  }
}
@keyframes tu-reserva-flota {
  50% {
    transform: translateY(-0.45rem);
  }
}
@media (prefers-reduced-motion: reduce) {
  .tu-hero-foto--1,
  .tu-hero-foto--3,
  .tu-hero-reserva {
    animation: none;
  }
  .tu-hero-foto img {
    transition: none;
  }
  .tu-hero-foto:hover img {
    transform: none;
  }
}
@media (max-width: 1023px) {
  .tu-hero-visual {
    width: min(100%, 48rem);
    margin-inline: auto;
  }
  .tu-hero-collage {
    height: min(41rem, 72vw);
  }
}
@media (max-width: 639px) {
  .tu-hero-collage {
    height: 27rem;
    gap: 0.4rem;
    padding: 0.6rem;
    border-radius: 1.5rem;
  }
  .tu-hero-foto {
    border-radius: 1.1rem;
  }
  .tu-hero-foto > span {
    right: 0.3rem;
    bottom: 0.3rem;
    left: 0.3rem;
    padding: 0.38rem 0.3rem;
    font-size: 0.6rem;
  }
  .tu-hero-reserva {
    right: 0.85rem;
    bottom: 0.85rem;
    max-width: 13.5rem;
    padding: 0.62rem 0.7rem;
  }
}
</style>
