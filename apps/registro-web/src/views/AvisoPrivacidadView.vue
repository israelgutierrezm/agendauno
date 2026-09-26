<script setup lang="ts">
import { onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import { api } from "@/lib/api";
import AvisoPrivacidadContenido from "@/components/AvisoPrivacidadContenido.vue";

const contenido = ref<string | null>(null);
const cargando = ref(true);
const error = ref(false);
async function cargar() {
  cargando.value = true;
  error.value = false;
  try {
    const { data } = await api.get<{
      data: { aviso_privacidad: string | null };
    }>("/api/v1/legales");
    contenido.value = data.data.aviso_privacidad;
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
    <RouterLink to="/" class="tu-enlace text-sm">← AgendaUno</RouterLink>
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
    <AvisoPrivacidadContenido v-else :contenido="contenido" />
  </article>
</template>

<style scoped>
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
