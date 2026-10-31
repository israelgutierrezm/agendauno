<template>
  <!-- Lienzo decorativo del hero: resplandores, malla, anillos orbitales, esferas y estelas de luz. -->
  <div class="fondo-hero" aria-hidden="true">
    <div class="fondo-hero-malla" />
    <svg
      class="fondo-hero-svg"
      viewBox="0 0 1440 760"
      fill="none"
      preserveAspectRatio="xMidYMid slice"
    >
      <defs>
        <linearGradient id="estela-azul-1" x1="0%" y1="0%" x2="100%" y2="0%">
          <stop offset="0%" stop-color="#0d3b6e" stop-opacity="0" />
          <stop offset="35%" stop-color="#3a8fd4" stop-opacity="0.38" />
          <stop offset="70%" stop-color="#0d3b6e" stop-opacity="0.28" />
          <stop offset="100%" stop-color="#3a8fd4" stop-opacity="0" />
        </linearGradient>
        <linearGradient id="estela-azul-2" x1="100%" y1="0%" x2="0%" y2="100%">
          <stop offset="0%" stop-color="#3a8fd4" stop-opacity="0" />
          <stop offset="45%" stop-color="#3a8fd4" stop-opacity="0.32" />
          <stop
            offset="80%"
            stop-color="var(--marketing-rosa)"
            stop-opacity="0.22"
          />
          <stop offset="100%" stop-color="#0d3b6e" stop-opacity="0" />
        </linearGradient>
        <linearGradient id="figura-grad" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stop-color="#3a8fd4" stop-opacity="0.22" />
          <stop offset="100%" stop-color="#0d3b6e" stop-opacity="0.06" />
        </linearGradient>
      </defs>

      <!-- Anillos orbitales y círculos punteados detrás del collage y al pie del hero -->
      <g class="fondo-hero-orbita">
        <circle
          cx="1090"
          cy="340"
          r="250"
          stroke="#3a8fd4"
          stroke-opacity="0.18"
          stroke-width="1.5"
          stroke-dasharray="8 10"
        />
        <circle
          cx="1090"
          cy="340"
          r="325"
          stroke="#0d3b6e"
          stroke-opacity="0.1"
          stroke-width="1.2"
        />
      </g>
      <g class="fondo-hero-orbita-inf">
        <circle
          cx="220"
          cy="640"
          r="155"
          stroke="#3a8fd4"
          stroke-opacity="0.22"
          stroke-width="1.5"
          stroke-dasharray="6 8"
        />
        <circle
          cx="220"
          cy="640"
          r="95"
          stroke="#0d3b6e"
          stroke-opacity="0.14"
          stroke-width="1.2"
        />
      </g>

      <!-- Figuras flotantes inferiores (con rotación y traslación animada) -->
      <rect
        class="fondo-figura fondo-figura--4"
        x="290"
        y="625"
        width="88"
        height="34"
        rx="17"
        transform="rotate(-12 290 625)"
        fill="url(#figura-grad)"
        stroke="#3a8fd4"
        stroke-opacity="0.3"
      />
      <rect
        class="fondo-figura fondo-figura--5"
        x="820"
        y="638"
        width="46"
        height="46"
        rx="13"
        transform="rotate(32 820 638)"
        fill="url(#figura-grad)"
        stroke="#3a8fd4"
        stroke-opacity="0.28"
      />
      <g class="fondo-figura fondo-figura--6">
        <circle
          cx="1310"
          cy="640"
          r="36"
          fill="url(#figura-grad)"
          stroke="#3a8fd4"
          stroke-opacity="0.3"
          stroke-dasharray="5 5"
        />
        <circle
          cx="1310"
          cy="640"
          r="14"
          stroke="#0d3b6e"
          stroke-opacity="0.22"
        />
      </g>

      <!-- Estelas curvas ("light trails") -->
      <path
        class="fondo-estela fondo-estela--1"
        d="M-120 490 C 240 180, 620 590, 1020 260 C 1220 100, 1380 210, 1560 90"
        stroke="url(#estela-azul-1)"
        stroke-width="3"
        stroke-linecap="round"
      />
      <path
        class="fondo-estela fondo-estela--2"
        d="M-80 610 C 310 340, 740 660, 1140 350 C 1310 220, 1420 290, 1540 190"
        stroke="url(#estela-azul-2)"
        stroke-width="2"
        stroke-dasharray="12 10"
        stroke-linecap="round"
      />
      <path
        class="fondo-estela fondo-estela--3"
        d="M220 -30 C 510 240, 880 40, 1280 340 C 1380 415, 1460 440, 1540 420"
        stroke="url(#estela-azul-1)"
        stroke-width="1.8"
        stroke-linecap="round"
      />
      <path
        class="fondo-estela fondo-estela--4"
        d="M-60 705 C 260 560, 610 745, 980 605 C 1210 520, 1360 670, 1520 580"
        stroke="url(#estela-azul-1)"
        stroke-width="2.6"
        stroke-linecap="round"
      />
      <path
        class="fondo-estela fondo-estela--5"
        d="M40 745 C 390 640, 760 730, 1150 640 C 1310 600, 1410 660, 1520 635"
        stroke="url(#estela-azul-2)"
        stroke-width="1.6"
        stroke-dasharray="7 9"
        stroke-linecap="round"
      />

      <!-- Esferas / nodos luminosos con halo y destellos animados sobre las estelas -->
      <g class="fondo-nodo fondo-nodo--1">
        <circle cx="430" cy="360" r="5" fill="#3a8fd4" fill-opacity="0.45" />
        <circle cx="430" cy="360" r="12" fill="#3a8fd4" fill-opacity="0.14" />
      </g>
      <g class="fondo-nodo fondo-nodo--2">
        <circle cx="820" cy="392" r="4.5" fill="#0d3b6e" fill-opacity="0.38" />
        <circle cx="820" cy="392" r="11" fill="#3a8fd4" fill-opacity="0.12" />
      </g>
      <g class="fondo-nodo fondo-nodo--1">
        <circle cx="1020" cy="260" r="6" fill="#3a8fd4" fill-opacity="0.5" />
        <circle cx="1020" cy="260" r="15" fill="#3a8fd4" fill-opacity="0.16" />
      </g>
      <g class="fondo-nodo fondo-nodo--2">
        <circle cx="195" cy="635" r="5.5" fill="#3a8fd4" fill-opacity="0.5" />
        <circle cx="195" cy="635" r="13" fill="#3a8fd4" fill-opacity="0.15" />
      </g>
      <g class="fondo-nodo fondo-nodo--1">
        <circle cx="520" cy="662" r="5" fill="#0d3b6e" fill-opacity="0.4" />
        <circle cx="520" cy="662" r="12" fill="#3a8fd4" fill-opacity="0.12" />
      </g>
      <g class="fondo-nodo fondo-nodo--2">
        <circle cx="980" cy="605" r="6" fill="#3a8fd4" fill-opacity="0.52" />
        <circle cx="980" cy="605" r="14" fill="#3a8fd4" fill-opacity="0.16" />
      </g>
      <path
        class="fondo-destello fondo-destello--1"
        d="M720 668 L723 676 L731 679 L723 682 L720 690 L717 682 L709 679 L717 676 Z"
        fill="#3a8fd4"
        fill-opacity="0.55"
      />
      <path
        class="fondo-destello fondo-destello--2"
        d="M1190 578 L1193 586 L1201 589 L1193 592 L1190 600 L1187 592 L1179 589 L1187 586 Z"
        fill="#0d3b6e"
        fill-opacity="0.45"
      />
    </svg>
  </div>
</template>

<style scoped>
.fondo-hero {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  /* Abarca también la franja inferior de sellos de confianza con el mismo tono continuo. */
  bottom: -3.25rem;
  z-index: 0;
  overflow: hidden;
  pointer-events: none;
  background:
    radial-gradient(
      ellipse 65% 58% at 14% 18%,
      color-mix(in srgb, var(--acento) 15%, transparent) 0%,
      transparent 70%
    ),
    radial-gradient(
      ellipse 55% 52% at 84% 28%,
      color-mix(in srgb, var(--primario) 12%, transparent) 0%,
      transparent 72%
    ),
    linear-gradient(
      122deg,
      var(--fondo) 8%,
      color-mix(in srgb, var(--acento) 7%, var(--fondo)) 54%,
      color-mix(in srgb, var(--marketing-rosa) 6%, var(--fondo)) 100%
    );
}
@media (max-width: 1023px) {
  .fondo-hero {
    bottom: -6.5rem;
  }
}
.fondo-hero-malla {
  position: absolute;
  inset: 0;
  background-image: radial-gradient(
    color-mix(in srgb, var(--primario) 18%, transparent) 1.25px,
    transparent 1.25px
  );
  background-size: 26px 26px;
  mask-image: radial-gradient(
    ellipse 70% 60% at 78% 26%,
    #000 0%,
    transparent 75%
  );
  opacity: 0.55;
}
.fondo-hero-svg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  transform: translateY(calc(var(--scroll-y, 0px) * -0.08));
}
.fondo-hero-orbita {
  transform-origin: 1090px 340px;
  animation: orbita-giro 48s linear infinite;
}
.fondo-hero-orbita-inf {
  transform-origin: 220px 640px;
  animation: orbita-giro 56s linear infinite reverse;
}
.fondo-figura--4 {
  animation: figura-flota 7.5s ease-in-out 0.8s infinite;
}
.fondo-figura--5 {
  animation: figura-flota 8.5s ease-in-out 2s infinite reverse;
}
.fondo-figura--6 {
  animation: figura-flota 9.5s ease-in-out 1.2s infinite;
}
.fondo-nodo--1 {
  animation: nodo-pulso 5s ease-in-out infinite;
}
.fondo-nodo--2 {
  animation: nodo-pulso 6.5s ease-in-out 1.4s infinite;
}
.fondo-destello--1 {
  transform-origin: 720px 679px;
  animation: destello-giro 9s ease-in-out infinite alternate;
}
.fondo-destello--2 {
  transform-origin: 1190px 589px;
  animation: destello-giro 11s ease-in-out 1.5s infinite alternate;
}
.fondo-estela--1,
.fondo-estela--4 {
  stroke-dasharray: 1800;
  stroke-dashoffset: 0;
  animation: estela-pulso 10s ease-in-out infinite alternate;
}
@keyframes orbita-giro {
  to {
    transform: rotate(360deg);
  }
}
@keyframes figura-flota {
  50% {
    transform: translateY(-14px);
  }
}
@keyframes nodo-pulso {
  50% {
    opacity: 0.55;
    transform: translateY(-6px);
  }
}
@keyframes destello-giro {
  50% {
    opacity: 0.35;
    transform: scale(0.85) rotate(18deg);
  }
}
@keyframes estela-pulso {
  0% {
    stroke-dashoffset: 260;
    opacity: 0.72;
  }
  100% {
    stroke-dashoffset: 0;
    opacity: 1;
  }
}
@media (prefers-reduced-motion: reduce) {
  .fondo-hero-orbita,
  .fondo-hero-orbita-inf,
  .fondo-figura--4,
  .fondo-figura--5,
  .fondo-figura--6,
  .fondo-nodo--1,
  .fondo-nodo--2,
  .fondo-destello--1,
  .fondo-destello--2,
  .fondo-estela--1,
  .fondo-estela--4 {
    animation: none;
  }
  .fondo-hero-svg {
    transform: none;
  }
}
</style>
