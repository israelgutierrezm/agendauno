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
      <!-- Figuras traslúcidas a contraesquina y tramos posteriores del anillo orbital -->
      <svg
        class="tu-hero-vectores"
        viewBox="0 0 640 420"
        fill="none"
        aria-hidden="true"
      >
        <defs>
          <!-- Rectángulo redondeado superior derecho (arriba de HYROX) -->
          <linearGradient
            id="rombo-rosa-grad"
            x1="0%"
            y1="30%"
            x2="100%"
            y2="70%"
          >
            <stop offset="0%" stop-color="#bfdbfe" stop-opacity="0.28" />
            <stop offset="48%" stop-color="#e9d5ff" stop-opacity="0.32" />
            <stop offset="100%" stop-color="#fbcfe8" stop-opacity="0.46" />
          </linearGradient>

          <!-- Rectángulo redondeado inferior izquierdo a contraesquina (abajo de Pilates) -->
          <linearGradient
            id="rombo-inf-izq-grad"
            x1="0%"
            y1="0%"
            x2="100%"
            y2="100%"
          >
            <stop offset="0%" stop-color="#7dd3fc" stop-opacity="0.34" />
            <stop offset="52%" stop-color="#bae6fd" stop-opacity="0.26" />
            <stop offset="100%" stop-color="#e9d5ff" stop-opacity="0.2" />
          </linearGradient>

          <!-- Arco superior alargado (sobre "Sincronizado en vivo" y "Pole dance") -->
          <linearGradient
            id="arco-sup-grad"
            gradientUnits="userSpaceOnUse"
            x1="-12"
            y1="72"
            x2="505"
            y2="56"
          >
            <stop offset="0%" stop-color="#93c5fd" stop-opacity="0" />
            <stop offset="16%" stop-color="#60a5fa" stop-opacity="0.72" />
            <stop offset="48%" stop-color="#38bdf8" stop-opacity="0.9" />
            <stop offset="78%" stop-color="#c084fc" stop-opacity="0.72" />
            <stop offset="100%" stop-color="#f472b6" stop-opacity="0.15" />
          </linearGradient>

          <!-- Bucle izquierdo alargado hacia fuera -->
          <linearGradient
            id="arco-izq-grad"
            gradientUnits="userSpaceOnUse"
            x1="-45"
            y1="104"
            x2="92"
            y2="302"
          >
            <stop offset="0%" stop-color="#60a5fa" stop-opacity="0.24" />
            <stop offset="25%" stop-color="#38bdf8" stop-opacity="0.9" />
            <stop offset="65%" stop-color="#60a5fa" stop-opacity="0.85" />
            <stop offset="100%" stop-color="#f472b6" stop-opacity="0.78" />
          </linearGradient>

          <!-- Arco inferior alargado (bajo Pilates y Pole dance) -->
          <linearGradient
            id="arco-inf-grad"
            gradientUnits="userSpaceOnUse"
            x1="78"
            y1="338"
            x2="336"
            y2="376"
          >
            <stop offset="0%" stop-color="#f472b6" stop-opacity="0.16" />
            <stop offset="50%" stop-color="#f472b6" stop-opacity="0.88" />
            <stop offset="82%" stop-color="#f9a8d4" stop-opacity="0.88" />
            <stop offset="100%" stop-color="#f472b6" stop-opacity="0.24" />
          </linearGradient>

          <!-- Tramo posterior derecho alargado hacia fuera de HYROX -->
          <linearGradient
            id="arco-der-post-grad"
            gradientUnits="userSpaceOnUse"
            x1="518"
            y1="178"
            x2="678"
            y2="272"
          >
            <stop offset="0%" stop-color="#c084fc" stop-opacity="0.12" />
            <stop offset="52%" stop-color="#e879f9" stop-opacity="0.65" />
            <stop offset="100%" stop-color="#f472b6" stop-opacity="0.9" />
          </linearGradient>

          <!-- Sombra ambiental bajo las tarjetas -->
          <radialGradient id="halo-inf-collage" cx="50%" cy="50%" r="50%">
            <stop offset="0%" stop-color="#0d3b6e" stop-opacity="0.18" />
            <stop offset="55%" stop-color="#f472b6" stop-opacity="0.1" />
            <stop offset="100%" stop-color="#ffffff" stop-opacity="0" />
          </radialGradient>

          <!-- Esfera azul con efecto 3D suave (sin sombra exterior) -->
          <radialGradient
            id="esfera-azul-3d"
            cx="34%"
            cy="28%"
            r="68%"
            fx="28%"
            fy="24%"
          >
            <stop offset="0%" stop-color="#ffffff" />
            <stop offset="32%" stop-color="#bae6fd" />
            <stop offset="72%" stop-color="#38bdf8" />
            <stop offset="100%" stop-color="#0284c7" />
          </radialGradient>
        </defs>

        <!-- Sombra ambiental suave bajo las tarjetas -->
        <ellipse
          cx="295"
          cy="368"
          rx="205"
          ry="32"
          fill="url(#halo-inf-collage)"
        />

        <!-- Figura traslúcida detrás de la esquina superior derecha (arriba de HYROX) -->
        <rect
          class="tu-hero-rombo-rosa"
          x="436"
          y="6"
          width="172"
          height="172"
          rx="32"
          transform="rotate(34 522 92)"
          fill="url(#rombo-rosa-grad)"
          stroke="#ffffff"
          stroke-opacity="0.65"
          stroke-width="1.3"
        />

        <!-- Figura traslúcida a contraesquina (abajo de Pilates) -->
        <rect
          class="tu-hero-rombo-azul"
          x="18"
          y="238"
          width="168"
          height="168"
          rx="32"
          transform="rotate(34 102 322)"
          fill="url(#rombo-inf-izq-grad)"
          stroke="#ffffff"
          stroke-opacity="0.6"
          stroke-width="1.3"
        />

        <!-- 1. Arco orbital superior plano + esfera 3D azul con movimiento suave sobre la curva -->
        <path
          d="M -12 72 C 105 -32, 325 -34, 505 56"
          stroke="url(#arco-sup-grad)"
          stroke-opacity="0.16"
          stroke-width="5"
          stroke-linecap="round"
        />
        <path
          d="M -12 72 C 105 -32, 325 -34, 505 56"
          stroke="url(#arco-sup-grad)"
          stroke-width="2.4"
          stroke-linecap="round"
        />
        <circle r="8.2" fill="url(#esfera-azul-3d)">
          <animateMotion
            path="M -12 72 C 105 -32, 325 -34, 505 56"
            dur="9s"
            repeatCount="indefinite"
            keyPoints="0.36;0.64;0.36"
            keyTimes="0;0.5;1"
            calcMode="spline"
            keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
          />
        </circle>

        <!-- 2. Bucle orbital izquierdo plano -->
        <path
          d="M 88 104 C -72 146, -76 258, 92 302"
          stroke="url(#arco-izq-grad)"
          stroke-opacity="0.16"
          stroke-width="5.5"
          stroke-linecap="round"
        />
        <path
          d="M 88 104 C -72 146, -76 258, 92 302"
          stroke="url(#arco-izq-grad)"
          stroke-width="2.5"
          stroke-linecap="round"
        />

        <!-- 3. Tramo orbital inferior plano -->
        <path
          d="M 78 338 C 148 402, 244 406, 336 376"
          stroke="url(#arco-inf-grad)"
          stroke-opacity="0.18"
          stroke-width="5.5"
          stroke-linecap="round"
        />
        <path
          d="M 78 338 C 148 402, 244 406, 336 376"
          stroke="url(#arco-inf-grad)"
          stroke-width="2.5"
          stroke-linecap="round"
        />

        <!-- 4. Tramo posterior derecho plano -->
        <path
          d="M 518 178 C 638 202, 692 236, 678 272"
          stroke="url(#arco-der-post-grad)"
          stroke-opacity="0.16"
          stroke-width="5.5"
          stroke-linecap="round"
        />
        <path
          d="M 518 178 C 638 202, 692 236, 678 272"
          stroke="url(#arco-der-post-grad)"
          stroke-width="2.5"
          stroke-linecap="round"
        />
      </svg>

      <!-- Tramo frontal del arco orbital derecho plano + esfera 3D rosa centrada en la curva -->
      <svg
        class="tu-hero-vectores tu-hero-vectores--frente"
        viewBox="0 0 640 420"
        fill="none"
        aria-hidden="true"
      >
        <defs>
          <linearGradient
            id="arco-frente-rosa"
            gradientUnits="userSpaceOnUse"
            x1="678"
            y1="272"
            x2="428"
            y2="352"
          >
            <stop offset="0%" stop-color="#f472b6" stop-opacity="0.9" />
            <stop offset="50%" stop-color="#ec4899" stop-opacity="0.92" />
            <stop offset="85%" stop-color="#f472b6" stop-opacity="0.88" />
            <stop offset="100%" stop-color="#fbcfe8" stop-opacity="0.22" />
          </linearGradient>

          <!-- Esfera rosa con efecto 3D suave (sin sombra exterior) -->
          <radialGradient
            id="esfera-rosa-3d"
            cx="34%"
            cy="28%"
            r="68%"
            fx="28%"
            fy="24%"
          >
            <stop offset="0%" stop-color="#ffffff" />
            <stop offset="32%" stop-color="#fbcfe8" />
            <stop offset="72%" stop-color="#f472b6" />
            <stop offset="100%" stop-color="#db2777" />
          </radialGradient>
        </defs>

        <path
          d="M 678 272 C 658 316, 562 342, 428 352"
          stroke="url(#arco-frente-rosa)"
          stroke-opacity="0.2"
          stroke-width="6"
          stroke-linecap="round"
        />
        <path
          d="M 678 272 C 658 316, 562 342, 428 352"
          stroke="url(#arco-frente-rosa)"
          stroke-width="2.6"
          stroke-linecap="round"
        />

        <!-- Esfera 3D rosa con movimiento suave sobre la curva frontal -->
        <circle r="8.6" fill="url(#esfera-rosa-3d)">
          <animateMotion
            path="M 678 272 C 658 316, 562 342, 428 352"
            dur="8s"
            repeatCount="indefinite"
            keyPoints="0.18;0.52;0.18"
            keyTimes="0;0.5;1"
            calcMode="spline"
            keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
          />
        </circle>
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
  height: clamp(18.5rem, calc(100svh - 10.8rem), 24.5rem);
  grid-template-columns: 1fr 1.22fr 1fr;
  grid-template-rows: minmax(0, 1fr);
  align-items: center;
  gap: 0;
  padding: 2.35rem 3.1rem 2.2rem;
  isolation: isolate;
}
.tu-hero-vectores {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  z-index: 0;
  overflow: visible;
  pointer-events: none;
}
.tu-hero-vectores--frente {
  z-index: 3;
}
.tu-hero-rombo-rosa {
  transform-origin: 522px 92px;
  animation: tu-rombo-flota 8s ease-in-out infinite alternate;
}
.tu-hero-rombo-azul {
  transform-origin: 102px 322px;
  animation: tu-rombo-flota 9s ease-in-out 1.2s infinite alternate-reverse;
}
.tu-hero-pulso {
  position: absolute;
  z-index: 4;
  left: 11%;
  top: 2.25rem;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.42rem 0.88rem;
  border: 1px solid color-mix(in srgb, var(--superficie) 65%, var(--borde));
  border-radius: 999px;
  background: color-mix(in srgb, var(--superficie) 94%, transparent);
  backdrop-filter: blur(16px);
  box-shadow: 0 12px 28px -12px rgb(13 59 110 / 20%);
  color: var(--primario);
  font-size: 0.72rem;
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
  height: 0.78rem;
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
  background: var(--primario);
}
.tu-hero-pulso-barras i:nth-child(3) {
  height: 65%;
  animation-delay: 0.4s;
}
.tu-hero-pulso-barras i:nth-child(4) {
  height: 85%;
  animation-delay: 0.6s;
  background: var(--primario);
}
@keyframes tu-rombo-flota {
  from {
    transform: translateY(0) rotate(34deg);
  }
  to {
    transform: translateY(-5px) rotate(36deg);
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
  min-height: 0;
  z-index: 1;
  transform: rotate(-6deg) translate(0.75rem, 0.65rem);
  overflow: hidden;
  border: 5px solid color-mix(in srgb, var(--superficie) 95%, var(--borde));
  border-radius: 1.35rem;
  background: var(--superficie);
  box-shadow:
    0 1.35rem 3rem -0.55rem rgb(13 59 110 / 24%),
    0 0 0 1px color-mix(in srgb, #3a8fd4 16%, transparent);
  cursor: pointer;
  transition:
    transform 0.55s cubic-bezier(0.22, 1, 0.36, 1),
    box-shadow 0.55s cubic-bezier(0.22, 1, 0.36, 1),
    border-color 0.35s ease;
}
.tu-hero-foto--2 {
  height: 98%;
  z-index: 2;
  transform: rotate(-2.5deg) translateY(-0.15rem);
}
.tu-hero-foto--3 {
  height: 83%;
  z-index: 1;
  transform: rotate(-1.5deg) translate(-0.75rem, 0.55rem);
}
.tu-hero-foto--activa {
  z-index: 2;
  border-color: color-mix(in srgb, var(--superficie) 98%, var(--borde));
  box-shadow:
    0 1.6rem 3.6rem -0.5rem rgb(13 59 110 / 26%),
    0 0 0 1px color-mix(in srgb, #3a8fd4 24%, transparent);
}
.tu-hero-foto--1.tu-hero-foto--activa {
  z-index: 3;
  transform: translate(0.55rem, -0.3rem) scale(1.03) rotate(-2deg);
}
.tu-hero-foto--3.tu-hero-foto--activa {
  z-index: 3;
  transform: translate(-0.55rem, -0.3rem) scale(1.03) rotate(-0.5deg);
}
.tu-hero-foto--2.tu-hero-foto--activa {
  z-index: 2;
  transform: rotate(-2deg) translateY(-0.25rem) scale(1.015);
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
  transform: scale(1.04);
}
.tu-hero-foto-etiqueta {
  position: absolute;
  left: 0.7rem;
  top: 0.7rem;
  padding: 0.24rem 0.68rem;
  border-radius: 999px;
  background: rgb(18 48 89 / 82%);
  backdrop-filter: blur(8px);
  color: #ffffff;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}
.tu-hero-reserva {
  position: absolute;
  z-index: 4;
  left: 37%;
  bottom: 0.9rem;
  display: flex;
  width: max-content;
  max-width: 21.5rem;
  align-items: center;
  gap: 0.78rem;
  padding: 0.76rem 1.15rem;
  border: 1px solid color-mix(in srgb, var(--superficie) 65%, var(--borde));
  border-radius: 1.15rem;
  background: color-mix(in srgb, var(--superficie) 95%, transparent);
  backdrop-filter: blur(18px);
  box-shadow:
    0 1.35rem 3rem -0.9rem rgb(13 59 110 / 24%),
    0 2px 10px rgb(13 59 110 / 6%);
  color: var(--texto);
  font-size: 0.76rem;
  line-height: 1.3;
  animation: tu-aviso-flota 5s ease-in-out infinite;
}
@keyframes tu-aviso-flota {
  0%,
  100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-0.28rem);
  }
}
.tu-hero-reserva strong {
  display: block;
  font-size: 0.84rem;
  font-weight: 600;
  color: var(--primario);
}
.tu-hero-reserva small {
  display: block;
  margin-top: 0.14rem;
  color: var(--texto-suave);
  font-size: 0.71rem;
  white-space: nowrap;
}
.tu-hero-reserva-check {
  display: inline-flex;
  height: 2.15rem;
  width: 2.15rem;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  background: #10b981;
  color: #ffffff;
  box-shadow: 0 0 0 4px rgb(16 185 129 / 18%);
}
@media (max-width: 639px) {
  .tu-hero-collage {
    height: clamp(16rem, 68vw, 20rem);
    padding: 0.75rem 0.65rem 1.4rem;
  }
  .tu-hero-pulso {
    display: none;
  }
  .tu-hero-foto-etiqueta {
    left: 0.45rem;
    top: 0.45rem;
    padding: 0.16rem 0.5rem;
    font-size: 0.62rem;
  }
  .tu-hero-reserva {
    right: 0.5rem;
    left: 0.5rem;
    bottom: 0.25rem;
    width: auto;
    max-width: 17.5rem;
    margin-inline: auto;
    padding: 0.55rem 0.75rem;
  }
  .tu-hero-reserva small {
    white-space: normal;
  }
}
@media (prefers-reduced-motion: reduce) {
  .tu-hero-foto,
  .tu-hero-foto img {
    transition: none;
  }
  .tu-hero-reserva,
  .tu-hero-rombo-rosa,
  .tu-hero-rombo-azul,
  .tu-hero-pulso-barras i {
    animation: none;
  }
}
</style>
