<script setup lang="ts">
import { computed } from "vue";

import { CASOS } from "@/marketing/casos";
import type { Modo } from "@/marketing/modalidades";

/*
| Casos reales de los pilotos (landing): el problema, el recorrido que resolvieron, lo
| que cambió y una imagen real del producto. Sin casos autorizados no se muestra nada
| (ver marketing/casos.ts).
*/
const props = defineProps<{ modo: Modo }>();
const casos = computed(() => CASOS[props.modo]);
</script>

<template>
  <div v-if="casos.length > 0" class="casos" data-prueba="casos-piloto">
    <article
      v-for="caso in casos"
      :key="caso.negocio"
      class="caso tu-card"
      :data-caso="caso.negocio"
    >
      <figure class="caso-imagen">
        <img
          :src="caso.imagen.src"
          :alt="caso.imagen.alt"
          loading="lazy"
          decoding="async"
        />
      </figure>
      <div class="caso-texto">
        <h3>{{ caso.negocio }}</h3>
        <p class="caso-giro">{{ caso.giro }}</p>
        <dl>
          <div>
            <dt>El problema</dt>
            <dd>{{ caso.problema }}</dd>
          </div>
          <div>
            <dt>Lo que resolvieron</dt>
            <dd>{{ caso.recorrido }}</dd>
          </div>
          <div>
            <dt>Qué cambió</dt>
            <dd>{{ caso.cambio }}</dd>
          </div>
        </dl>
      </div>
    </article>
  </div>
</template>

<style scoped>
.casos {
  display: grid;
  gap: 1.25rem;
  margin-top: 2.5rem;
}
.caso {
  display: grid;
  gap: 1.25rem;
  padding: 1.25rem;
}
@media (min-width: 900px) {
  .caso {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr);
    align-items: center;
  }
}
.caso-imagen img {
  width: 100%;
  border-radius: 12px;
  border: 1px solid var(--borde);
}
.caso-texto h3 {
  font-size: 1.2rem;
  font-weight: 600;
}
.caso-giro {
  font-size: 0.85rem;
  color: var(--texto-suave);
}
.caso-texto dl {
  display: grid;
  gap: 0.75rem;
  margin-top: 1rem;
}
.caso-texto dt {
  font-weight: 600;
  font-size: 0.9rem;
}
.caso-texto dd {
  margin-top: 0.15rem;
  font-size: 0.9rem;
  color: var(--texto-suave);
}
</style>
