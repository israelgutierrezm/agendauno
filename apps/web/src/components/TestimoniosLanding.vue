<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";
import type { Modo } from "@/marketing/modalidades";
import {
  EJEMPLOS_TESTIMONIOS,
  testimoniosPublicables,
} from "@/marketing/testimonios";
const props = defineProps<{ modo: Modo }>();
const route = useRoute();
const boceto = computed(
  () => import.meta.env.DEV && route.query.boceto === "1",
);
const testimonios = computed(() =>
  boceto.value
    ? EJEMPLOS_TESTIMONIOS[props.modo]
    : testimoniosPublicables(props.modo),
);
</script>

<template>
  <div
    v-if="testimonios.length"
    class="testimonios"
    data-prueba="testimonios-landing"
  >
    <p class="tu-seccion-etiqueta">
      {{
        boceto
          ? "Vista previa · Contenido ficticio"
          : "Experiencias de nuestros clientes"
      }}
    </p>
    <h2 class="tu-titulo mt-3">
      {{
        boceto
          ? "Así se verán las historias de tus clientes."
          : "La experiencia de quienes ya lo usan."
      }}
    </h2>
    <p v-if="boceto" class="testimonios-nota" role="note">
      Boceto: nombres y textos de ejemplo, no testimonios reales. Esta sección
      no aparece en producción hasta cargar reseñas autorizadas.
    </p>
    <div class="testimonios-grid">
      <figure v-for="t in testimonios" :key="t.nombre" class="testimonio">
        <span class="testimonio-comillas" aria-hidden="true">“</span>
        <blockquote>{{ t.cita }}</blockquote>
        <figcaption>
          <span class="testimonio-inicial" aria-hidden="true">{{
            t.nombre.charAt(0)
          }}</span>
          <div>
            <strong>{{ t.nombre }}</strong
            ><span>{{ t.cargo }} · {{ t.negocio }}</span>
          </div>
        </figcaption>
      </figure>
    </div>
  </div>
</template>

<style scoped>
.testimonios {
  margin-top: 4rem;
  border-top: 1px solid var(--borde);
  padding-top: 3rem;
}
.testimonios-nota {
  margin-top: 1rem;
  color: var(--texto-suave);
  max-width: 760px;
  font-size: 0.85rem;
  line-height: 1.6;
}
.testimonios-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 20px;
  margin-top: 28px;
}
.testimonio {
  margin: 0;
  background: var(--fondo);
  border: 1px solid var(--borde);
  padding: 26px;
  border-radius: 18px;
  display: flex;
  flex-direction: column;
}
.testimonio-comillas {
  font:
    3.5rem Georgia,
    serif;
  color: var(--marketing-rosa);
  height: 42px;
}
.testimonio blockquote {
  font-size: 1rem;
  line-height: 1.75;
  flex: 1;
}
.testimonio figcaption {
  display: flex;
  gap: 12px;
  align-items: center;
  border-top: 1px solid var(--borde);
  margin-top: 24px;
  padding-top: 20px;
}
.testimonio-inicial {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 38px;
  height: 38px;
  border-radius: 12px;
  background: var(--superficie);
  color: var(--marketing-rosa);
}
.testimonio strong {
  display: block;
  font-weight: 500;
  font-size: 0.8rem;
}
.testimonio figcaption div > span {
  display: block;
  font-size: 0.7rem;
  color: var(--texto-suave);
  margin-top: 4px;
}
@media (max-width: 800px) {
  .testimonios-grid {
    grid-template-columns: 1fr;
  }
  .testimonio {
    padding: 22px;
  }
}
</style>
