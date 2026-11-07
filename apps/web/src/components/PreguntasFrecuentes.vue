<script setup lang="ts">
/*
| Preguntas frecuentes de las páginas comerciales (portada, /clases y /citas): un h2 y
| cada pregunta en un `<details>` (se abre sin JavaScript). Los textos llegan ya
| traducidos; lo que va debajo (p. ej. la nota de México, `landing.soloMexico`) entra
| por el slot. Las tarjetas toman `--sobre-banda` (como `marketing/landing.css`).
| Con `estructurado`, las mismas preguntas van como datos estructurados (FAQPage) para
| los buscadores: solo lo que se ve en la página (comparativo del 2026-10-20).
*/
import { computed, h } from "vue";

export interface PreguntaFrecuente {
  clave: string;
  pregunta: string;
  respuesta: string;
}

const props = defineProps<{
  titulo: string;
  preguntas: readonly PreguntaFrecuente[];
  estructurado?: boolean;
}>();

const jsonLd = computed(() =>
  JSON.stringify({
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: props.preguntas.map((p) => ({
      "@type": "Question",
      name: p.pregunta,
      acceptedAnswer: { "@type": "Answer", text: p.respuesta },
    })),
  }).replace(/</g, "\\u003c"),
);
// El `<script type="application/ld+json">` (solo datos, no se ejecuta).
const DatosEstructurados = () =>
  h("script", {
    type: "application/ld+json",
    "data-prueba": "faq-ld",
    innerHTML: jsonLd.value,
  });
</script>

<template>
  <div class="preguntas-frecuentes">
    <div class="preguntas-cabecera" data-scroll="cabecera">
      <h2 class="tu-titulo reveal">{{ titulo }}</h2>
      <slot name="cabecera" />
    </div>
    <div class="preguntas-grid mt-8">
      <details
        v-for="p in preguntas"
        :key="p.clave"
        class="pregunta tu-card p-5 reveal"
        :data-pregunta="p.clave"
        data-scroll="acomodo"
      >
        <summary>
          {{ p.pregunta }}
          <svg
            class="pregunta-marca"
            aria-hidden="true"
            viewBox="0 0 24 24"
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path d="m6 9 6 6 6-6" />
          </svg>
        </summary>
        <p>{{ p.respuesta }}</p>
      </details>
    </div>
    <slot />
    <DatosEstructurados v-if="estructurado && preguntas.length > 0" />
  </div>
</template>

<style scoped>
.preguntas-cabecera {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
}
.preguntas-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.85rem;
  align-items: start;
}
@media (min-width: 768px) {
  .preguntas-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
  }
}
.pregunta {
  border: 1px solid color-mix(in srgb, #3a8fd4 22%, var(--borde));
  border-radius: 20px;
  background:
    radial-gradient(
      ellipse 65% 50% at 95% 8%,
      color-mix(in srgb, #7dd3fc 10%, transparent) 0%,
      transparent 70%
    ),
    var(--sobre-banda, var(--superficie));
  box-shadow:
    0 12px 28px -22px rgb(13 59 110 / 20%),
    0 2px 6px rgb(13 59 110 / 3%);
  transition:
    border-color 0.22s ease,
    box-shadow 0.22s ease;
}
.pregunta:hover,
.pregunta[open] {
  border-color: color-mix(in srgb, #3a8fd4 48%, var(--borde));
  box-shadow:
    0 18px 36px -22px rgb(13 59 110 / 26%),
    0 4px 10px rgb(13 59 110 / 5%);
}
.pregunta summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  cursor: pointer;
  list-style: none;
  font-weight: 500;
}
.pregunta summary::-webkit-details-marker {
  display: none;
}
/* Flecha (SVG, sin «+» de texto) que gira al abrir. */
.pregunta-marca {
  flex: 0 0 auto;
  color: var(--texto-suave);
  transition: transform 0.15s ease;
}
.pregunta[open] .pregunta-marca {
  transform: rotate(180deg);
}
.pregunta summary:focus-visible {
  outline: 2px solid var(--enlace);
  outline-offset: 4px;
  border-radius: 4px;
}
.pregunta p {
  margin-top: 0.75rem;
  color: var(--texto-suave);
  font-size: 0.875rem;
  line-height: 1.6;
}
@media (prefers-reduced-motion: reduce) {
  .pregunta-marca {
    transition: none;
  }
}
</style>
