<script setup lang="ts">
import { onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { updateSeo } from "@/lib/seo";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Una dirección que no existe (ADR 0174): se dice, sin mandar al inicio en silencio,
 * con el camino de vuelta. No se indexa. En el subdominio de un negocio, nginx ya la
 * entrega con 404 (ADR 0155); el inicio de ahí es la página del negocio.
 */
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const sesion = useSesionTenantStore();

// Llegó desde otra página de la app: «Volver» la regresa. Si abrió el enlace directo,
// no hay a dónde volver.
const puedeVolver =
  typeof window !== "undefined" &&
  (window.history.state as { back?: unknown } | null)?.back != null;

onMounted(() => {
  updateSeo({
    title: t("noEncontrada.tituloPagina"),
    description: t("noEncontrada.texto"),
    path: route.path,
    index: false,
  });
});
</script>

<template>
  <section class="ne-pagina" aria-labelledby="ne-titulo">
    <p class="ne-etiqueta">{{ $t("noEncontrada.etiqueta") }}</p>
    <h1 id="ne-titulo">{{ $t("noEncontrada.titulo") }}</h1>
    <p class="ne-texto">{{ $t("noEncontrada.texto") }}</p>
    <div class="mt-6 flex flex-wrap gap-3">
      <RouterLink to="/" class="tu-btn tu-btn-primario" data-prueba="ne-inicio">
        {{
          sesion.autenticado
            ? $t("noEncontrada.miInicio")
            : $t("noEncontrada.inicio")
        }}
      </RouterLink>
      <button
        v-if="puedeVolver"
        type="button"
        class="tu-btn tu-btn-secundario"
        data-prueba="ne-volver"
        @click="router.back()"
      >
        {{ $t("noEncontrada.volver") }}
      </button>
    </div>
  </section>
</template>

<style scoped>
.ne-pagina {
  max-width: 40rem;
  margin: 0 auto;
  padding: clamp(3rem, 10vw, 7rem) 1.5rem;
}
.ne-etiqueta {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--texto-suave);
}
.ne-pagina h1 {
  margin-top: 0.5rem;
  font-size: clamp(1.75rem, 4vw, 2.25rem);
  font-weight: 700;
  line-height: 1.2;
}
.ne-texto {
  margin-top: 0.75rem;
  color: var(--texto-suave);
}
</style>
