<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";
import { computed, onMounted, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DocumentoLegalContenido from "@/components/DocumentoLegalContenido.vue";
import { api, noEncontrado } from "@/lib/api";
import { updateSeo } from "@/lib/seo";
import { slugDeContexto, urlCanonicaEstudio } from "@/lib/tenant";

/**
 * El aviso de privacidad que un negocio publica para sus clientes: se consulta sin
 * sesión, desde su página o antes de agendar sin cuenta.
 */
interface Aviso {
  negocio: string;
  titulo: string;
  contenido: string;
  version: number;
  publicado_en: string | null;
}

const route = useRoute();
const slug = computed(
  () => String(route.params.slug ?? "") || slugDeContexto() || "",
);
const aviso = ref<Aviso | null>(null);
const cargando = ref(true);
const noPublicado = ref(false);
const error = ref(false);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = false;
  noPublicado.value = false;
  try {
    const { data } = await api.get<{ data: Aviso }>(
      `/api/v1/app/${slug.value}/aviso-privacidad`,
    );
    aviso.value = data.data;
    updateSeo({
      title: `${data.data.titulo} · ${data.data.negocio} | AgendaUno`,
      description: `Cómo trata ${data.data.negocio} los datos personales de sus clientes.`,
      path: urlCanonicaEstudio(
        slug.value,
        `/estudio/${slug.value}/aviso-de-privacidad`,
      ),
      index: false,
    });
  } catch (e) {
    if (noEncontrado(e)) {
      noPublicado.value = true;
    } else {
      error.value = true;
    }
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);
</script>

<template>
  <article class="an-pagina" aria-labelledby="an-titulo">
    <RouterLink
      :to="{ name: 'estudio-publico', params: { slug } }"
      class="tu-enlace text-sm"
      ><IconoNav nombre="atras" :tam="14" class="inline align-[-0.15em]" />
      {{ aviso?.negocio ?? "Volver" }}</RouterLink
    >
    <p v-if="cargando" class="mt-8" role="status">Cargando el aviso…</p>
    <div v-else-if="error" class="mt-8" role="alert">
      <p>No pudimos consultar el aviso. Intenta de nuevo.</p>
      <button type="button" class="tu-btn tu-btn-fantasma mt-4" @click="cargar">
        Reintentar
      </button>
    </div>
    <p v-else-if="noPublicado" class="mt-8" role="status">
      Este negocio aún no publica su aviso de privacidad. Pídeselo antes de
      compartir tus datos.
    </p>
    <template v-else-if="aviso">
      <header>
        <p class="an-etiqueta">{{ aviso.negocio }}</p>
        <h1 id="an-titulo">{{ aviso.titulo }}</h1>
        <p class="an-version">
          Versión {{ aviso.version }}
          <template v-if="aviso.publicado_en">
            · vigente desde el
            {{
              new Intl.DateTimeFormat("es-MX", { dateStyle: "long" }).format(
                new Date(aviso.publicado_en),
              )
            }}
          </template>
        </p>
      </header>
      <DocumentoLegalContenido :contenido="aviso.contenido" />
    </template>
  </article>
</template>

<style scoped>
.an-pagina {
  max-width: 52rem;
  margin: 0 auto;
  padding: clamp(2rem, 6vw, 5rem) 1.5rem;
}
.an-pagina header {
  margin: 1.5rem 0 2rem;
}
.an-etiqueta {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--texto-suave);
}
.an-pagina h1 {
  margin-top: 0.25rem;
  font-size: clamp(1.8rem, 4vw, 2.4rem);
  font-weight: 600;
  line-height: 1.15;
}
.an-version {
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: var(--texto-suave);
}
</style>
