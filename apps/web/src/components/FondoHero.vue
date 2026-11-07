<template>
  <!-- Lienzo decorativo del hero: ondas pastel suaves y discretas en las esquinas. -->
  <div class="fondo-hero" aria-hidden="true">
    <svg
      class="fondo-hero-svg"
      viewBox="0 0 1440 900"
      fill="none"
      preserveAspectRatio="none"
    >
      <defs>
        <!-- Ondas superiores izquierdas (discretas) -->
        <linearGradient
          id="onda-sup-izq-ext"
          x1="0%"
          y1="0%"
          x2="100%"
          y2="100%"
        >
          <stop offset="0%" stop-color="#7dd3fc" stop-opacity="0.22" />
          <stop offset="55%" stop-color="#bae6fd" stop-opacity="0.1" />
          <stop offset="100%" stop-color="#dbeafe" stop-opacity="0" />
        </linearGradient>
        <linearGradient id="onda-sup-izq-int" x1="0%" y1="0%" x2="90%" y2="95%">
          <stop offset="0%" stop-color="#93c5fd" stop-opacity="0.26" />
          <stop offset="60%" stop-color="#bae6fd" stop-opacity="0.14" />
          <stop offset="100%" stop-color="#eff6ff" stop-opacity="0" />
        </linearGradient>
        <linearGradient id="onda-sup-borde" x1="100%" y1="0%" x2="0%" y2="100%">
          <stop offset="0%" stop-color="#ffffff" stop-opacity="0.65" />
          <stop offset="55%" stop-color="#bae6fd" stop-opacity="0.45" />
          <stop offset="100%" stop-color="#bae6fd" stop-opacity="0.1" />
        </linearGradient>

        <!-- Ondas inferiores izquierdas (discretas) -->
        <linearGradient
          id="onda-inf-izq-ext"
          x1="0%"
          y1="100%"
          x2="100%"
          y2="0%"
        >
          <stop offset="0%" stop-color="#7dd3fc" stop-opacity="0.2" />
          <stop offset="55%" stop-color="#bae6fd" stop-opacity="0.1" />
          <stop offset="100%" stop-color="#e0f2fe" stop-opacity="0" />
        </linearGradient>
        <linearGradient
          id="onda-inf-izq-int"
          x1="0%"
          y1="100%"
          x2="85%"
          y2="15%"
        >
          <stop offset="0%" stop-color="#60a5fa" stop-opacity="0.25" />
          <stop offset="52%" stop-color="#93c5fd" stop-opacity="0.15" />
          <stop offset="100%" stop-color="#bae6fd" stop-opacity="0.02" />
        </linearGradient>
        <linearGradient
          id="onda-inf-izq-borde"
          x1="0%"
          y1="0%"
          x2="100%"
          y2="100%"
        >
          <stop offset="0%" stop-color="#ffffff" stop-opacity="0.65" />
          <stop offset="55%" stop-color="#bae6fd" stop-opacity="0.45" />
          <stop offset="100%" stop-color="#7dd3fc" stop-opacity="0.12" />
        </linearGradient>

        <!-- Ondas inferiores derechas (rosa + lavanda discretas) -->
        <linearGradient
          id="onda-inf-der-lavanda"
          x1="0%"
          y1="100%"
          x2="100%"
          y2="0%"
        >
          <stop offset="0%" stop-color="#93c5fd" stop-opacity="0.16" />
          <stop offset="55%" stop-color="#c4b5fd" stop-opacity="0.12" />
          <stop offset="100%" stop-color="#fbcfe8" stop-opacity="0" />
        </linearGradient>
        <linearGradient
          id="onda-inf-der-rosa"
          x1="100%"
          y1="100%"
          x2="0%"
          y2="0%"
        >
          <stop offset="0%" stop-color="#f472b6" stop-opacity="0.26" />
          <stop offset="52%" stop-color="#f9a8d4" stop-opacity="0.16" />
          <stop offset="100%" stop-color="#fce7f3" stop-opacity="0.02" />
        </linearGradient>
        <linearGradient
          id="onda-inf-der-borde"
          x1="0%"
          y1="100%"
          x2="100%"
          y2="0%"
        >
          <stop offset="0%" stop-color="#fbcfe8" stop-opacity="0.35" />
          <stop offset="50%" stop-color="#ffffff" stop-opacity="0.65" />
          <stop offset="100%" stop-color="#f9a8d4" stop-opacity="0.25" />
        </linearGradient>
      </defs>

      <!-- Ondas suaves superiores izquierdas -->
      <g class="fondo-onda fondo-onda--sup">
        <path
          d="M -30 -25 L 310 -25 C 245 95, 165 215, -30 340 Z"
          fill="url(#onda-sup-izq-ext)"
        />
        <path
          d="M -30 -25 L 185 -25 C 148 78, 108 175, -30 268 Z"
          fill="url(#onda-sup-izq-int)"
        />
        <path
          d="M 185 -22 C 148 78, 108 175, -26 266"
          stroke="url(#onda-sup-borde)"
          stroke-width="1.4"
          stroke-linecap="round"
        />
      </g>

      <!-- Ondas suaves inferiores izquierdas -->
      <g class="fondo-onda fondo-onda--inf-izq">
        <path
          d="M -30 535 C 105 570, 198 695, 310 915 L -30 915 Z"
          fill="url(#onda-inf-izq-ext)"
        />
        <path
          d="M -30 625 C 82 645, 165 745, 238 915 L -30 915 Z"
          fill="url(#onda-inf-izq-int)"
        />
        <path
          d="M -26 537 C 105 572, 198 696, 308 912"
          stroke="url(#onda-inf-izq-borde)"
          stroke-opacity="0.45"
          stroke-width="1.1"
          stroke-linecap="round"
        />
        <path
          d="M -26 627 C 82 647, 165 746, 236 912"
          stroke="url(#onda-inf-izq-borde)"
          stroke-width="1.5"
          stroke-linecap="round"
        />
      </g>

      <!-- Ondas suaves inferiores derechas -->
      <g class="fondo-onda fondo-onda--inf-der">
        <path
          d="M 990 915 C 1115 810, 1255 740, 1470 675 L 1470 915 Z"
          fill="url(#onda-inf-der-lavanda)"
        />
        <path
          d="M 1210 915 C 1265 772, 1345 655, 1470 572 L 1470 915 Z"
          fill="url(#onda-inf-der-rosa)"
        />
        <path
          d="M 1212 912 C 1266 772, 1346 656, 1466 575"
          stroke="url(#onda-inf-der-borde)"
          stroke-width="1.5"
          stroke-linecap="round"
        />
      </g>
    </svg>
  </div>
</template>

<style scoped>
.fondo-hero {
  position: absolute !important;
  top: -1.5rem;
  left: 0;
  right: 0;
  /* Abarca desde el borde superior de la ventana hasta cubrir el 100% del alto bajo la barra de sellos. */
  bottom: -3.8rem;
  z-index: 0 !important;
  overflow: hidden;
  pointer-events: none;
  background:
    radial-gradient(
      ellipse 42% 40% at 4% 8%,
      rgb(125 211 252 / 20%) 0%,
      transparent 70%
    ),
    radial-gradient(
      ellipse 38% 38% at 4% 92%,
      rgb(96 165 250 / 18%) 0%,
      transparent 68%
    ),
    radial-gradient(
      ellipse 40% 40% at 96% 92%,
      rgb(244 114 182 / 18%) 0%,
      rgb(196 181 253 / 10%) 45%,
      transparent 70%
    ),
    radial-gradient(
      ellipse 44% 38% at 92% 10%,
      rgb(147 197 253 / 15%) 0%,
      transparent 68%
    ),
    linear-gradient(
      135deg,
      color-mix(in srgb, #bae6fd 14%, var(--fondo)) 0%,
      var(--fondo) 48%,
      color-mix(in srgb, #fbcfe8 10%, var(--fondo)) 100%
    );
}
.dark .fondo-hero {
  background:
    radial-gradient(
      ellipse 40% 38% at 5% 8%,
      rgb(56 189 248 / 9%) 0%,
      transparent 68%
    ),
    radial-gradient(
      ellipse 36% 36% at 5% 92%,
      rgb(59 130 246 / 8%) 0%,
      transparent 66%
    ),
    radial-gradient(
      ellipse 38% 38% at 95% 92%,
      rgb(236 72 153 / 8%) 0%,
      transparent 68%
    ),
    var(--fondo);
}
.dark .fondo-hero-svg {
  opacity: 0.24;
}
@media (max-width: 899px) {
  .fondo-hero {
    top: 0;
    bottom: -6.5rem;
  }
}
@media (min-width: 900px) and (max-width: 1023px) {
  .fondo-hero {
    bottom: -6.5rem;
  }
}
.fondo-hero-svg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}
.fondo-onda--sup {
  animation: onda-respira 11s ease-in-out infinite alternate;
}
.fondo-onda--inf-izq {
  animation: onda-respira 13s ease-in-out 1.5s infinite alternate;
}
.fondo-onda--inf-der {
  animation: onda-respira 12s ease-in-out 0.8s infinite alternate;
}
@keyframes onda-respira {
  0% {
    transform: translate3d(0, 0, 0) scale(1);
  }
  100% {
    transform: translate3d(0, -6px, 0) scale(1.01);
  }
}
@media (prefers-reduced-motion: reduce) {
  .fondo-onda--sup,
  .fondo-onda--inf-izq,
  .fondo-onda--inf-der {
    animation: none;
  }
  .fondo-hero-svg {
    transform: none;
  }
}
</style>
