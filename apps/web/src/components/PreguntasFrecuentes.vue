<script setup lang="ts">
/*
| Preguntas frecuentes de las páginas comerciales (portada, /clases y /citas): un h2 y
| cada pregunta en un `<details>` (se abre sin JavaScript). Los textos llegan ya
| traducidos; lo que va debajo (p. ej. la nota de México, `landing.soloMexico`) entra
| por el slot. Las tarjetas toman `--sobre-banda` (como `marketing/landing.css`).
*/
export interface PreguntaFrecuente {
  clave: string;
  pregunta: string;
  respuesta: string;
}

defineProps<{
  titulo: string;
  preguntas: readonly PreguntaFrecuente[];
}>();
</script>

<template>
  <div class="preguntas-frecuentes">
    <h2 class="tu-titulo reveal">{{ titulo }}</h2>
    <div class="mt-8 space-y-3">
      <details
        v-for="(p, i) in preguntas"
        :key="p.clave"
        class="pregunta tu-card p-5 reveal"
        :data-pregunta="p.clave"
        :style="{ transitionDelay: i * 60 + 'ms' }"
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
  </div>
</template>

<style scoped>
.pregunta {
  background: var(--sobre-banda, var(--fondo));
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
