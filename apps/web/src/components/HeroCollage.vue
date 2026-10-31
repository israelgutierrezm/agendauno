<script setup lang="ts">
import { ref } from "vue";

/*
| Collage interactivo del hero de las páginas comerciales: tres fotos de negocios
| (la del centro se pide primero), etiquetas de giro y un aviso flotante en vivo.
| Al pasar el cursor o tocar cualquier tarjeta, pasa al frente con transición suave.
*/
export interface FotoCollage {
  clave: string;
  src: string;
  alt: string;
  /** Nombre del negocio para las composiciones que muestran etiquetas. */
  etiqueta: string;
  /** `object-position` si el sujeto no está centrado. */
  posicion?: string;
}

defineProps<{
  fotos: readonly FotoCollage[];
  aviso: { titulo: string; detalle: string };
  /** Que se lea como ejemplo, no como un cliente real (C01). */
  nota?: string;
}>();

const activa = ref(1);
</script>

<template>
  <figure class="tu-hero-visual reveal">
    <div class="tu-hero-collage" @mouseleave="activa = 1">
      <!-- Vectores decorativos, estela orbital y destellos alrededor del collage -->
      <svg
        class="tu-hero-vectores"
        viewBox="0 0 600 480"
        fill="none"
        aria-hidden="true"
      >
        <ellipse
          cx="300"
          cy="235"
          rx="275"
          ry="175"
          transform="rotate(-8 300 235)"
          stroke="#3a8fd4"
          stroke-opacity="0.28"
          stroke-width="1.6"
          stroke-dasharray="7 7"
        />
        <path
          d="M 32 340 Q 175 40 330 220 T 572 110"
          stroke="#0d3b6e"
          stroke-opacity="0.2"
          stroke-width="2"
          stroke-linecap="round"
        />
        <!-- Destellos vectoriales de 4 puntas y vectores inferiores -->
        <path
          d="M 42 428 Q 300 478 558 418"
          stroke="#3a8fd4"
          stroke-opacity="0.34"
          stroke-width="2"
          stroke-dasharray="5 7"
          stroke-linecap="round"
        />
        <rect
          x="148"
          y="420"
          width="28"
          height="28"
          rx="8"
          transform="rotate(24 148 420)"
          stroke="#3a8fd4"
          stroke-opacity="0.4"
          stroke-width="1.5"
        />
        <circle
          cx="296"
          cy="450"
          r="18"
          stroke="#0d3b6e"
          stroke-opacity="0.28"
          stroke-width="1.5"
          stroke-dasharray="4 4"
        />
        <circle cx="296" cy="450" r="5" fill="#3a8fd4" fill-opacity="0.55" />
        <path
          class="tu-hero-destello tu-hero-destello--1"
          d="M540 34 L544 46 L556 50 L544 54 L540 66 L536 54 L524 50 L536 46 Z"
          fill="#3a8fd4"
          fill-opacity="0.75"
        />
        <path
          class="tu-hero-destello tu-hero-destello--2"
          d="M46 376 L49 385 L58 388 L49 391 L46 400 L43 391 L34 388 L43 385 Z"
          fill="#0d3b6e"
          fill-opacity="0.55"
        />
        <path
          class="tu-hero-destello tu-hero-destello--1"
          d="M232 436 L235 444 L243 447 L235 450 L232 458 L229 450 L221 447 L229 444 Z"
          fill="#3a8fd4"
          fill-opacity="0.65"
        />
        <circle cx="52" cy="96" r="6" fill="#3a8fd4" fill-opacity="0.45" />
        <circle cx="556" cy="368" r="7" fill="#0d3b6e" fill-opacity="0.3" />
      </svg>

      <!-- Insignia flotante superior -->
      <div class="tu-hero-pulso" aria-hidden="true">
        <span class="tu-hero-pulso-barras"> <i /><i /><i /><i /> </span>
      </div>

      <div
        v-for="(foto, i) in fotos"
        :key="foto.clave"
        class="tu-hero-foto"
        :class="[
          `tu-hero-foto--${i + 1}`,
          { 'tu-hero-foto--activa': activa === i },
        ]"
        @mouseenter="activa = i"
        @click="activa = i"
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
        <span class="tu-hero-foto-etiqueta" aria-hidden="true">{{
          foto.etiqueta
        }}</span>
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
    <figcaption v-if="nota" class="tu-hero-nota">{{ nota }}</figcaption>
  </figure>
</template>

<style scoped>
.tu-hero-nota {
  margin-top: 0.15rem;
  font-size: 0.68rem;
  line-height: 1.2;
  text-align: center;
  color: var(--texto-suave);
}
.tu-hero-visual {
  min-width: 0;
  margin: 0;
  transform-origin: 50% 100%;
}
.tu-hero-collage {
  position: relative;
  display: grid;
  height: clamp(16.5rem, calc(100svh - 11.25rem), 23.5rem);
  grid-template-columns: 1fr 1.22fr 1fr;
  align-items: center;
  gap: 0.65rem;
  padding: 0.45rem 0.5rem 1.2rem;
  isolation: isolate;
}
.tu-hero-vectores {
  position: absolute;
  inset: -0.75rem -1rem;
  width: calc(100% + 2rem);
  height: calc(100% + 1.5rem);
  z-index: 0;
  pointer-events: none;
}
.tu-hero-destello--1 {
  animation: tu-destello-pulso 3.4s ease-in-out infinite;
}
.tu-hero-destello--2 {
  animation: tu-destello-pulso 4.2s ease-in-out 1.2s infinite;
}
.tu-hero-pulso {
  position: absolute;
  z-index: 4;
  left: 6%;
  top: 0.35rem;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.42rem 0.78rem;
  border: 1px solid color-mix(in srgb, var(--acento) 35%, var(--borde));
  border-radius: 999px;
  background: color-mix(in srgb, var(--superficie) 92%, transparent);
  backdrop-filter: blur(14px);
  box-shadow: 0 10px 24px -12px rgb(13 59 110 / 28%);
  color: var(--primario);
  font-size: 0.7rem;
  font-weight: 600;
  pointer-events: none;
}
.tu-hero-pulso::after {
  content: "Sincronizado en vivo";
}
.tu-hero-pulso-barras {
  display: inline-flex;
  align-items: flex-end;
  gap: 2px;
  height: 0.75rem;
}
.tu-hero-pulso-barras i {
  width: 2.5px;
  border-radius: 2px;
  background: #3a8fd4;
  animation: tu-barra-onda 1.2s ease-in-out infinite;
}
.tu-hero-pulso-barras i:nth-child(1) {
  height: 45%;
}
.tu-hero-pulso-barras i:nth-child(2) {
  height: 100%;
  animation-delay: 0.2s;
  background: #0d3b6e;
}
.tu-hero-pulso-barras i:nth-child(3) {
  height: 65%;
  animation-delay: 0.4s;
}
.tu-hero-pulso-barras i:nth-child(4) {
  height: 85%;
  animation-delay: 0.6s;
  background: #0d3b6e;
}
@keyframes tu-destello-pulso {
  50% {
    opacity: 0.35;
    transform: scale(0.86);
  }
}
@keyframes tu-barra-onda {
  50% {
    height: 30%;
  }
}
.tu-hero-foto {
  position: relative;
  height: 82%;
  transform: rotate(-6deg) translateY(0.75rem);
  overflow: hidden;
  border: 4px solid var(--superficie);
  border-radius: 1.35rem;
  background: var(--superficie);
  box-shadow: 0 1.2rem 3.2rem rgb(13 59 110 / 16%);
  cursor: pointer;
  transition:
    transform 0.55s cubic-bezier(0.22, 1, 0.36, 1),
    box-shadow 0.55s cubic-bezier(0.22, 1, 0.36, 1),
    border-color 0.35s ease;
}
.tu-hero-foto--1 {
  animation: tu-foto-flota-1 7s ease-in-out infinite alternate;
}
.tu-hero-foto--2 {
  height: 100%;
  z-index: 2;
  transform: translateY(-0.5rem);
}
.tu-hero-foto--3 {
  height: 82%;
  transform: rotate(6deg) translateY(0.75rem);
  animation: tu-foto-flota-3 8.5s ease-in-out infinite alternate;
}
.tu-hero-foto:hover,
.tu-hero-foto--activa {
  animation: none;
}
.tu-hero-foto--activa {
  z-index: 3;
  transform: translateY(-0.65rem) scale(1.035) rotate(0deg);
  border-color: color-mix(in srgb, var(--acento) 55%, var(--superficie));
  box-shadow: 0 1.6rem 3.8rem rgb(13 59 110 / 26%);
}
@keyframes tu-foto-flota-1 {
  from {
    transform: rotate(-6deg) translateY(0.75rem);
  }
  to {
    transform: rotate(-5deg) translateY(0.15rem);
  }
}
@keyframes tu-foto-flota-3 {
  from {
    transform: rotate(6deg) translateY(0.75rem);
  }
  to {
    transform: rotate(5deg) translateY(0.15rem);
  }
}
.tu-hero-foto img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
  transition: transform 0.85s cubic-bezier(0.22, 1, 0.36, 1);
}
.tu-hero-foto:hover img,
.tu-hero-foto--activa img {
  transform: scale(1.05);
}
.tu-hero-foto-etiqueta {
  position: absolute;
  left: 0.65rem;
  top: 0.65rem;
  padding: 0.22rem 0.6rem;
  border-radius: 999px;
  background: rgb(13 59 110 / 78%);
  backdrop-filter: blur(8px);
  color: #ffffff;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}
.tu-hero-reserva {
  position: absolute;
  z-index: 4;
  right: 10%;
  bottom: 0.55rem;
  display: flex;
  max-width: 16.5rem;
  align-items: center;
  gap: 0.7rem;
  padding: 0.68rem 0.9rem;
  border: 1px solid color-mix(in srgb, var(--acento) 32%, var(--borde));
  border-radius: 1.05rem;
  background: color-mix(in srgb, var(--superficie) 92%, transparent);
  color: var(--texto);
  text-align: left;
  box-shadow: 0 1rem 2.6rem rgb(13 59 110 / 22%);
  backdrop-filter: blur(18px);
  animation: tu-reserva-flota 4.2s ease-in-out infinite;
}
.tu-hero-reserva-check {
  position: relative;
  display: grid;
  width: 2rem;
  height: 2rem;
  flex: 0 0 auto;
  place-content: center;
  border-radius: 50%;
  background: #198754;
  color: #fff;
  box-shadow: 0 0 0 4px rgb(25 135 84 / 18%);
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
  color: var(--texto-suave);
  font-size: 0.7rem;
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
    padding: 1rem 0.5rem 3.2rem;
  }
  .tu-hero-foto {
    border-radius: 1.1rem;
  }
  .tu-hero-reserva {
    right: 0.85rem;
    bottom: 0.85rem;
    max-width: 13.5rem;
    padding: 0.62rem 0.7rem;
  }
}
</style>
