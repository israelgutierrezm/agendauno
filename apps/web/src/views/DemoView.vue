<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import DemoAgendable from "@/components/DemoAgendable.vue";
import { esProducto, PRODUCTOS, productoActual } from "@/lib/producto";

/**
 * La página para agendar una demo (ADR 0204), como la de la competencia: a ella llevan
 * los botones «Agendar una demo» de la landing. Del producto de la página de donde
 * viene (`?producto=`; si no, el del dominio) y, si se conoce, con su giro ya elegido
 * (`?giro=`). Si la plataforma no agenda demos, lo dice y ofrece crear la cuenta.
 */
const { t } = useI18n();
const ruta = useRoute();

const producto = computed(() =>
  esProducto(ruta.query.producto) ? ruta.query.producto : productoActual(),
);
const giro = computed(() =>
  typeof ruta.query.giro === "string" ? ruta.query.giro : null,
);
// La duración la fija el superadmin: llega con los horarios.
const minutos = ref<number | null>(null);
const apagadas = ref(false);
const registro = computed(() => ({
  name: "registro",
  query: { modo: PRODUCTOS[producto.value].modalidad },
}));
</script>

<template>
  <section class="dv-pagina" data-prueba="pagina-demo">
    <section class="dv-texto">
      <h1 class="dv-titulo">{{ t("demoAgendable.pagina.titulo") }}</h1>
      <p class="mt-3 text-lg" :style="{ color: 'var(--texto-suave)' }">
        {{
          minutos === null
            ? t("demoAgendable.pagina.subtituloSinMinutos")
            : t("demoAgendable.pagina.subtitulo", { minutos })
        }}
      </p>
      <ul class="dv-puntos mt-6">
        <li v-for="p in ['uno', 'dos', 'tres']" :key="p">
          {{ t(`demoAgendable.pagina.puntos.${p}`) }}
        </li>
      </ul>
    </section>

    <section class="dv-formulario">
      <div
        v-if="apagadas"
        class="tu-card p-6 sm:p-8"
        data-prueba="demos-apagadas"
      >
        <h2 class="text-xl font-medium">
          {{ t("demoAgendable.pagina.apagadas") }}
        </h2>
        <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
          {{ t("demoAgendable.pagina.apagadasTexto") }}
        </p>
        <RouterLink class="tu-btn tu-btn-primario mt-5" :to="registro">
          {{ t("demoAgendable.pagina.crearCuenta") }}
        </RouterLink>
      </div>
      <DemoAgendable
        v-else
        :producto="producto"
        :giro-inicial="giro"
        @apagadas="apagadas = true"
        @minutos="(n) => (minutos = n)"
      />
    </section>
  </section>
</template>

<style scoped>
/* El texto a la izquierda y el formulario a la derecha; en el teléfono, uno arriba
   del otro. */
.dv-pagina {
  display: grid;
  gap: 2rem;
  max-width: 72rem;
  margin: 0 auto;
  padding: 3rem 1rem 4rem;
}
@media (min-width: 1024px) {
  .dv-pagina {
    grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
    align-items: start;
    gap: 3rem;
    padding-top: 4.5rem;
  }
  .dv-texto {
    position: sticky;
    top: 6rem;
  }
}
.dv-titulo {
  font-size: clamp(1.9rem, 4vw, 2.6rem);
  font-weight: 600;
  letter-spacing: -0.02em;
  line-height: 1.15;
}
.dv-puntos {
  display: grid;
  gap: 0.75rem;
}
.dv-puntos li {
  position: relative;
  padding-left: 1.25rem;
}
/* Un punto discreto, del color del texto suave: sin íconos ni adornos. */
.dv-puntos li::before {
  content: "";
  position: absolute;
  left: 0;
  top: 0.6em;
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 999px;
  background: var(--texto-suave);
}
</style>
