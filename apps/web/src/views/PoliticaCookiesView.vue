<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";
import { onMounted, ref } from "vue";
import { RouterLink } from "vue-router";

import { PRODUCTOS, productoActual } from "@/lib/producto";

import DocumentoLegalContenido from "@/components/DocumentoLegalContenido.vue";
import { api } from "@/lib/api";
import type { Ambito } from "@/lib/atribucion";
import { leerPreferencias } from "@/lib/consentimiento";
import { elegir } from "@/lib/etiquetas";
import { slugDeContexto } from "@/lib/tenant";

/**
 * Política de cookies (ADR 0206), la versión vigente publicada desde la plataforma, y
 * debajo la elección de este navegador para el sitio en que se abre (el producto o, en
 * el subdominio de un negocio, ese negocio), para cambiarla aquí mismo: la misma que
 * pide el aviso de cookies.
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
        cookies?: string | null;
        versiones?: {
          cookies?: { version: number; vigente_desde: string } | null;
        };
      };
    }>("/api/v1/legales");
    contenido.value = data.data.cookies ?? null;
    version.value = data.data.versiones?.cookies ?? null;
  } catch {
    error.value = true;
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);
// La marca del dominio en que se abre (ADR 0131).
const producto = productoActual();
const marca = PRODUCTOS[producto].nombre;

// La elección de este navegador en el sitio del dominio.
const negocio = slugDeContexto(window.location.hostname, "");
const ambito: Ambito =
  negocio !== null
    ? `negocio:${negocio.toLowerCase()}`
    : `plataforma:${producto}`;
const previa = leerPreferencias(ambito);
const analitica = ref(previa?.analitica ?? false);
const publicidad = ref(previa?.publicidad ?? false);
const guardada = ref(false);
function guardar(): void {
  elegir({ analitica: analitica.value, publicidad: publicidad.value }, ambito);
  guardada.value = true;
}
</script>

<template>
  <article class="cookies-pagina" aria-labelledby="cookies-titulo">
    <RouterLink to="/" class="tu-enlace text-sm"
      ><IconoNav nombre="atras" :tam="14" class="inline align-[-0.15em]" />
      {{ marca }}</RouterLink
    >
    <header>
      <p class="cookies-etiqueta">Información legal</p>
      <h1 id="cookies-titulo">Política de cookies</h1>
      <p>Qué cookies usa {{ marca }}, para qué sirven y cómo eliges cuáles.</p>
    </header>

    <form
      class="cookies-eleccion"
      aria-labelledby="cookies-eleccion-titulo"
      data-prueba="cookies-eleccion"
      @submit.prevent="guardar"
    >
      <h2 id="cookies-eleccion-titulo">Tu elección en este navegador</h2>
      <p class="cookies-nota">
        Las cookies necesarias siempre están activas: mantienen tu sesión, tu
        elección y la seguridad del sitio, y no miden nada.
      </p>
      <label class="cookies-opcion">
        <input
          v-model="analitica"
          type="checkbox"
          data-prueba="cookies-analitica"
          @change="guardada = false"
        />
        <span>
          <strong>Analítica</strong>
          <small
            >Google Analytics: qué páginas se visitan y cómo se llega a
            ellas.</small
          >
        </span>
      </label>
      <label class="cookies-opcion">
        <input
          v-model="publicidad"
          type="checkbox"
          data-prueba="cookies-publicidad"
          @change="guardada = false"
        />
        <span>
          <strong>Publicidad</strong>
          <small
            >Medir los resultados de los anuncios (Meta y Google) y mejorar las
            campañas.</small
          >
        </span>
      </label>
      <div class="cookies-acciones">
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          data-prueba="cookies-guardar"
        >
          Guardar mi elección
        </button>
        <p
          v-if="guardada"
          class="cookies-nota"
          role="status"
          data-prueba="cookies-guardada"
        >
          Guardamos tu elección en este navegador.
        </p>
      </div>
    </form>

    <p v-if="cargando" role="status">Cargando el documento…</p>
    <div v-else-if="error" role="alert">
      <p>No pudimos consultar la política vigente. Intenta de nuevo.</p>
      <button
        type="button"
        class="tu-btn tu-btn-secundario mt-4"
        @click="cargar"
      >
        Reintentar
      </button>
    </div>
    <template v-else>
      <p v-if="version" class="cookies-version">
        Versión {{ version.version }} · vigente desde el
        {{
          new Intl.DateTimeFormat("es-MX", { dateStyle: "long" }).format(
            new Date(version.vigente_desde),
          )
        }}
      </p>
      <DocumentoLegalContenido tipo="cookies" :contenido="contenido" />
    </template>
  </article>
</template>

<style scoped>
.cookies-pagina {
  max-width: 52rem;
  margin: 0 auto;
  padding: clamp(2rem, 6vw, 5rem) 1.5rem;
}
.cookies-pagina header {
  margin: 1.5rem 0 2rem;
}
.cookies-etiqueta {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--texto-suave);
}
.cookies-pagina h1 {
  margin-top: 0.25rem;
  font-size: clamp(1.8rem, 4vw, 2.4rem);
  font-weight: 600;
  line-height: 1.15;
}
.cookies-eleccion {
  display: grid;
  gap: 0.75rem;
  margin-bottom: 2rem;
  padding: 1.25rem;
  border: 1px solid var(--borde);
  border-radius: 0.9rem;
  background: var(--superficie);
}
.cookies-eleccion h2 {
  font-size: 1.05rem;
  font-weight: 600;
}
.cookies-opcion {
  display: flex;
  gap: 0.6rem;
  align-items: flex-start;
  font-size: 0.9rem;
}
.cookies-opcion input {
  margin-top: 0.25rem;
}
.cookies-opcion span {
  display: grid;
}
.cookies-opcion small,
.cookies-nota {
  font-size: 0.85rem;
  color: var(--texto-suave);
}
.cookies-acciones {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}
.cookies-version {
  margin-bottom: 1.5rem;
  font-size: 0.875rem;
  color: var(--texto-suave);
}
</style>
