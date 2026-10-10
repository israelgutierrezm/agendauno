<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";
import { onMounted, ref } from "vue";
import { RouterLink } from "vue-router";

import DocumentoLegalContenido from "@/components/DocumentoLegalContenido.vue";
import { api } from "@/lib/api";

/**
 * Términos y condiciones de AgendaUno, la versión vigente publicada desde la
 * plataforma (los mismos que se aceptan al registrar un negocio).
 */
const contenido = ref<string | null>(null);
const version = ref<{ version: number; vigente_desde: string } | null>(null);
const cargando = ref(true);
const error = ref(false);
async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = false;
  try {
    const { data } = await api.get<{
      data: {
        terminos: string | null;
        versiones?: {
          terminos: { version: number; vigente_desde: string } | null;
        };
      };
    }>("/api/v1/legales");
    contenido.value = data.data.terminos;
    version.value = data.data.versiones?.terminos ?? null;
  } catch {
    error.value = true;
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);
</script>

<template>
  <article class="terminos-pagina" aria-labelledby="terminos-titulo">
    <RouterLink to="/" class="tu-enlace text-sm"
      ><IconoNav nombre="atras" :tam="14" class="inline align-[-0.15em]" />
      AgendaUno</RouterLink
    >
    <header>
      <p class="terminos-etiqueta">Información legal</p>
      <h1 id="terminos-titulo">Términos y condiciones</h1>
      <p>Las condiciones para usar AgendaUno en tu negocio.</p>
    </header>
    <p v-if="cargando" role="status">Cargando el documento…</p>
    <div v-else-if="error" role="alert">
      <p>No pudimos consultar los términos vigentes. Intenta de nuevo.</p>
      <button type="button" class="tu-btn tu-btn-fantasma mt-4" @click="cargar">
        Reintentar
      </button>
    </div>
    <template v-else>
      <p v-if="version" class="terminos-version">
        Versión {{ version.version }} · vigente desde el
        {{
          new Intl.DateTimeFormat("es-MX", { dateStyle: "long" }).format(
            new Date(version.vigente_desde),
          )
        }}
      </p>
      <DocumentoLegalContenido tipo="terminos" :contenido="contenido" />
    </template>
  </article>
</template>

<style scoped>
.terminos-pagina {
  max-width: 52rem;
  margin: 0 auto;
  padding: clamp(2rem, 6vw, 5rem) 1.5rem;
}
.terminos-pagina header {
  margin: 1.5rem 0 2rem;
}
.terminos-etiqueta {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--texto-suave);
}
.terminos-pagina h1 {
  margin-top: 0.25rem;
  font-size: clamp(1.8rem, 4vw, 2.4rem);
  font-weight: 600;
  line-height: 1.15;
}
.terminos-version {
  margin-bottom: 1.5rem;
  font-size: 0.875rem;
  color: var(--texto-suave);
}
</style>
