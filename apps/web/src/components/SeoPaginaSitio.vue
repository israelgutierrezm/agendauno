<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import CargadorImagen from "@/components/CargadorImagen.vue";
import type { SeoSitio } from "@/lib/sitioWeb";

/**
 * Cómo aparece una página del sitio en buscadores y al compartirse (ADR 0146): su
 * título, su descripción y su imagen. Vacíos, los de siempre. Muestra cómo se vería
 * en un resultado de búsqueda.
 */
const props = defineProps<{
  catalogo: { max_seo_titulo: number; max_seo_descripcion: number };
  // `seo` o `paginas.{n}.seo`: para encontrar sus errores.
  prefijo: string;
  errores: Record<string, string[]>;
  // Lo que se usa si no escribe un título.
  placeholderTitulo: string;
}>();
const seo = defineModel<SeoSitio>({ required: true });

const { t } = useI18n();
const id = computed(() => props.prefijo.replace(/[^a-z0-9]/gi, "-"));
function valor(e: Event): string | null {
  const v = (e.target as HTMLInputElement | HTMLTextAreaElement).value;
  return v.trim() === "" ? null : v;
}
const error = computed(
  () =>
    Object.entries(props.errores)
      .filter(([clave]) => clave.startsWith(`${props.prefijo}.`))
      .map(([, m]) => m[0])[0] ?? null,
);
</script>

<template>
  <fieldset class="sp" data-prueba="seo-pagina">
    <legend class="tu-label">{{ t("sitioWeb.seo.titulo") }}</legend>
    <p class="sp-ayuda">{{ t("sitioWeb.seo.ayuda") }}</p>
    <div class="mt-3 space-y-3">
      <div>
        <label class="tu-label" :for="`sp-titulo-${id}`">{{
          t("sitioWeb.seo.campoTitulo")
        }}</label>
        <input
          :id="`sp-titulo-${id}`"
          class="tu-input"
          :value="seo.titulo ?? ''"
          :maxlength="catalogo.max_seo_titulo"
          :placeholder="placeholderTitulo"
          @input="seo.titulo = valor($event)"
        />
        <p class="sp-ayuda">
          {{
            t("sitioWeb.seo.quedan", {
              n: catalogo.max_seo_titulo - (seo.titulo?.length ?? 0),
            })
          }}
        </p>
      </div>
      <div>
        <label class="tu-label" :for="`sp-descripcion-${id}`">{{
          t("sitioWeb.seo.campoDescripcion")
        }}</label>
        <textarea
          :id="`sp-descripcion-${id}`"
          class="tu-input"
          rows="2"
          :value="seo.descripcion ?? ''"
          :maxlength="catalogo.max_seo_descripcion"
          @input="seo.descripcion = valor($event)"
        ></textarea>
        <p class="sp-ayuda">
          {{
            t("sitioWeb.seo.quedan", {
              n: catalogo.max_seo_descripcion - (seo.descripcion?.length ?? 0),
            })
          }}
        </p>
      </div>
      <div>
        <span class="tu-label">{{ t("sitioWeb.seo.campoImagen") }}</span>
        <CargadorImagen
          :url="seo.imagen_url"
          ruta="sitio/imagenes"
          campo="imagen"
          clave="url"
          quitar-sin-borrar
          proporcion="1.91 / 1"
          :arrastra="t('sitioWeb.secciones.campos.fotoArrastra')"
          @update:url="seo.imagen_url = $event"
        />
      </div>
      <!-- Así se vería en un resultado de búsqueda -->
      <div class="sp-resultado" aria-hidden="true" data-prueba="seo-vista">
        <p class="sp-resultado-titulo">
          {{ seo.titulo ?? placeholderTitulo }}
        </p>
        <p class="sp-resultado-texto">
          {{ seo.descripcion ?? t("sitioWeb.seo.sinDescripcion") }}
        </p>
      </div>
      <AvisoTono v-if="error" tono="peligro" role="alert">
        {{ error }}
      </AvisoTono>
    </div>
  </fieldset>
</template>

<style scoped>
.sp {
  padding-top: 0.75rem;
  border-top: 1px solid var(--borde);
}
.sp-ayuda {
  margin-top: 0.25rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
}
.sp-resultado {
  padding: 0.75rem 0.9rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: var(--superficie);
}
.sp-resultado-titulo {
  overflow: hidden;
  color: #1a0dab;
  font-size: 1rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.dark .sp-resultado-titulo {
  color: #8ab4f8;
}
.sp-resultado-texto {
  margin-top: 0.2rem;
  color: var(--texto-suave);
  font-size: 0.82rem;
}
</style>
