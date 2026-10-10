<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";
import { onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import { api } from "@/lib/api";
import DocumentoLegalContenido from "@/components/DocumentoLegalContenido.vue";

const contenido = ref<string | null>(null);
// Qué versión se ve y desde cuándo rige.
const version = ref<{ version: number; vigente_desde: string } | null>(null);
const cargando = ref(true);
const error = ref(false);
async function cargar() {
  cargando.value = true;
  error.value = false;
  try {
    const { data } = await api.get<{
      data: {
        aviso_privacidad: string | null;
        versiones?: {
          aviso_privacidad: { version: number; vigente_desde: string } | null;
        };
      };
    }>("/api/v1/legales");
    contenido.value = data.data.aviso_privacidad;
    version.value = data.data.versiones?.aviso_privacidad ?? null;
  } catch {
    error.value = true;
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);
</script>

<template>
  <article class="aviso-pagina" aria-labelledby="aviso-titulo">
    <RouterLink to="/" class="tu-enlace text-sm"
      ><IconoNav nombre="atras" :tam="14" class="inline align-[-0.15em]" />
      AgendaUno</RouterLink
    >
    <header>
      <p class="aviso-etiqueta">Información legal</p>
      <h1 id="aviso-titulo">Aviso de privacidad</h1>
      <p>
        Consulta cómo se tratan tus datos personales y cómo ejercer tus
        derechos.
      </p>
    </header>
    <p v-if="cargando" role="status">Cargando el documento…</p>
    <div v-else-if="error" role="alert">
      <p>
        No pudimos consultar el aviso vigente. Intenta de nuevo antes de
        proporcionar datos personales.
      </p>
      <button type="button" class="tu-btn tu-btn-fantasma mt-4" @click="cargar">
        Reintentar
      </button>
    </div>
    <template v-else>
      <p v-if="version" class="aviso-version">
        Versión {{ version.version }} · vigente desde el
        {{
          new Intl.DateTimeFormat("es-MX", { dateStyle: "long" }).format(
            new Date(version.vigente_desde),
          )
        }}
      </p>
      <DocumentoLegalContenido :contenido="contenido" />
    </template>
  </article>
</template>

<style scoped>
.aviso-version {
  margin-bottom: 1.5rem;
  font-size: 0.875rem;
  color: var(--texto-suave);
}
.aviso-pagina {
  max-width: 52rem;
  margin: 0 auto;
  padding: clamp(2rem, 6vw, 5rem) 1.5rem;
}
header {
  margin-block: 2rem 3rem;
  padding-bottom: 2rem;
  border-bottom: 1px solid var(--borde);
}
.aviso-etiqueta {
  font-size: 0.75rem;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--texto-suave);
}
h1 {
  font-size: clamp(2rem, 5vw, 3rem);
  font-weight: 300;
  line-height: 1.2;
  margin-block: 0.75rem 1rem;
}
header > p:last-child {
  color: var(--texto-suave);
  line-height: 1.7;
}
</style>
