<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import FormularioCaptacion from "@/components/FormularioCaptacion.vue";
import { urlReproductor, type SeccionSitio } from "@/lib/sitioWeb";

/**
 * Un bloque propio en la página pública del negocio (ADR 0146): texto con imagen,
 * galería, preguntas, testimonios, llamado a la acción, video, beneficios o su
 * formulario de captación, con su fondo y su alineación. Todo es texto: nada de HTML.
 * Sus esquinas siguen las del sitio (`--radio-*`, ADR 0146 fase 3).
 *
 * El video no carga nada de YouTube o Vimeo hasta que la persona le da clic (sin
 * cookies de terceros antes); el reproductor se arma con su proveedor y su id.
 */
const props = defineProps<{
  bloque: SeccionSitio;
  slug: string;
  // El formulario solo si el negocio recibe prospectos (Captación).
  captacionActiva?: boolean;
  // Lleva `/p/{pagina}` a la página de su sitio (ADR 0146); sin él, tal cual.
  enlace?: (enlace: string) => string;
}>();

const { t } = useI18n();
const datos = computed(() => props.bloque.datos ?? {});
const fondo = computed(() => props.bloque.estilo?.fondo ?? "normal");
const centrado = computed(() => props.bloque.estilo?.alineacion === "centro");

// Un enlace a otra página se abre aparte; una sección o una ruta del sitio, aquí.
function externo(enlace: string | null | undefined): boolean {
  return /^https?:\/\//i.test(enlace ?? "");
}
function destino(enlace: string): string {
  return props.enlace ? props.enlace(enlace) : enlace;
}

const reproduciendo = ref(false);
const reproductor = computed(() =>
  urlReproductor(datos.value.proveedor, datos.value.video_id),
);
const proveedor = computed(() =>
  datos.value.proveedor === "vimeo" ? "Vimeo" : "YouTube",
);
</script>

<template>
  <section
    :id="`bloque-${bloque.id ?? bloque.tipo}`"
    class="bs"
    :class="[`bs-fondo-${fondo}`, { 'bs-centro': centrado }]"
    :data-prueba="`bloque-${bloque.tipo}`"
  >
    <div class="mx-auto max-w-5xl px-4 py-12">
      <!-- Texto con imagen -->
      <div
        v-if="bloque.tipo === 'texto_imagen'"
        class="grid items-center gap-8"
        :class="{ 'md:grid-cols-2': bloque.foto_url }"
      >
        <img
          v-if="bloque.foto_url"
          :src="bloque.foto_url"
          :alt="bloque.titulo ?? ''"
          class="bs-foto"
          :class="{ 'md:order-2': datos.lado === 'derecha' }"
          loading="lazy"
        />
        <div>
          <h2 v-if="bloque.titulo" class="text-2xl font-light">
            {{ bloque.titulo }}
          </h2>
          <p v-if="bloque.texto" class="mt-4 whitespace-pre-line">
            {{ bloque.texto }}
          </p>
          <a
            v-if="datos.boton_texto && datos.boton_enlace"
            :href="destino(datos.boton_enlace)"
            class="tu-btn tu-btn-primario bs-boton mt-6"
            :target="externo(datos.boton_enlace) ? '_blank' : undefined"
            :rel="
              externo(datos.boton_enlace) ? 'noopener noreferrer' : undefined
            "
            >{{ datos.boton_texto }}</a
          >
        </div>
      </div>

      <!-- Llamado a la acción -->
      <div v-else-if="bloque.tipo === 'llamado'" class="bs-llamado">
        <h2 v-if="bloque.titulo" class="text-2xl font-light">
          {{ bloque.titulo }}
        </h2>
        <p v-if="bloque.texto" class="mt-3 whitespace-pre-line">
          {{ bloque.texto }}
        </p>
        <a
          v-if="datos.boton_texto && datos.boton_enlace"
          :href="destino(datos.boton_enlace)"
          class="tu-btn tu-btn-primario bs-boton mt-6"
          :target="externo(datos.boton_enlace) ? '_blank' : undefined"
          :rel="externo(datos.boton_enlace) ? 'noopener noreferrer' : undefined"
          >{{ datos.boton_texto }}</a
        >
      </div>

      <template v-else>
        <h2 v-if="bloque.titulo" class="text-2xl font-light">
          {{ bloque.titulo }}
        </h2>
        <p
          v-if="bloque.texto && bloque.tipo !== 'formulario'"
          class="mt-3 whitespace-pre-line"
        >
          {{ bloque.texto }}
        </p>

        <!-- Galería -->
        <ul v-if="bloque.tipo === 'galeria'" class="bs-galeria mt-6">
          <li v-for="f in datos.fotos ?? []" :key="f.url">
            <figure>
              <img :src="f.url" :alt="f.pie ?? ''" loading="lazy" />
              <figcaption v-if="f.pie">{{ f.pie }}</figcaption>
            </figure>
          </li>
        </ul>

        <!-- Preguntas frecuentes -->
        <div v-else-if="bloque.tipo === 'preguntas'" class="bs-preguntas mt-6">
          <details v-for="(p, i) in datos.items ?? []" :key="i">
            <summary>{{ p.pregunta }}</summary>
            <p v-if="p.respuesta" class="whitespace-pre-line">
              {{ p.respuesta }}
            </p>
          </details>
        </div>

        <!-- Testimonios -->
        <ul v-else-if="bloque.tipo === 'testimonios'" class="bs-tarjetas mt-6">
          <li v-for="(p, i) in datos.items ?? []" :key="i" class="bs-tarjeta">
            <blockquote class="whitespace-pre-line">«{{ p.texto }}»</blockquote>
            <p v-if="p.nombre" class="mt-3 text-sm font-medium">
              {{ p.nombre }}
            </p>
          </li>
        </ul>

        <!-- Beneficios -->
        <ul v-else-if="bloque.tipo === 'beneficios'" class="bs-tarjetas mt-6">
          <li v-for="(p, i) in datos.items ?? []" :key="i" class="bs-tarjeta">
            <p class="font-medium">{{ p.titulo }}</p>
            <p v-if="p.texto" class="mt-1 text-sm bs-suave">{{ p.texto }}</p>
          </li>
        </ul>

        <!-- Video: una portada; el reproductor, al dar clic -->
        <div
          v-else-if="bloque.tipo === 'video' && reproductor"
          class="bs-video mt-6"
        >
          <iframe
            v-if="reproduciendo"
            :src="reproductor"
            :title="bloque.titulo ?? t('sitioWeb.publico.verVideo')"
            allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
            allowfullscreen
            data-prueba="reproductor"
          ></iframe>
          <button
            v-else
            type="button"
            class="bs-portada-video"
            :style="
              bloque.foto_url
                ? { backgroundImage: `url(${bloque.foto_url})` }
                : undefined
            "
            data-prueba="ver-video"
            @click="reproduciendo = true"
          >
            <span class="bs-play" aria-hidden="true"
              ><svg
                viewBox="0 0 24 24"
                width="26"
                height="26"
                fill="currentColor"
              >
                <path d="M8 5.5v13l11-6.5z" /></svg
            ></span>
            <span class="bs-play-texto">{{
              t("sitioWeb.publico.verVideo")
            }}</span>
          </button>
          <p v-if="!reproduciendo" class="mt-2 text-xs bs-suave">
            {{ t("sitioWeb.publico.videoAviso", { proveedor }) }}
          </p>
        </div>

        <!-- Formulario de captación -->
        <div
          v-else-if="bloque.tipo === 'formulario' && captacionActiva"
          class="bs-formulario mt-6"
        >
          <p v-if="bloque.texto" class="mb-4 whitespace-pre-line">
            {{ bloque.texto }}
          </p>
          <FormularioCaptacion
            :slug="slug"
            :formulario="datos.formulario_id ?? null"
            compacto
          />
        </div>
      </template>
    </div>
  </section>
</template>

<style scoped>
.bs-fondo-suave {
  background: color-mix(in srgb, var(--primario) 6%, var(--superficie));
}
.bs-fondo-marca {
  background: var(--primario);
  color: var(--primario-contraste);
}
.bs-fondo-oscuro {
  background: #111827;
  color: #f9fafb;
}
.bs-centro {
  text-align: center;
}
.bs-centro .bs-galeria,
.bs-centro .bs-tarjetas {
  text-align: left;
}
.bs-suave {
  color: var(--texto-suave);
}
.bs-fondo-marca .bs-suave,
.bs-fondo-oscuro .bs-suave {
  color: inherit;
  opacity: 0.8;
}
/* Sobre su color o sobre oscuro, el botón se invierte para que se lea. */
.bs-fondo-marca .bs-boton,
.bs-fondo-oscuro .bs-boton {
  background: #ffffff;
  color: #111827;
}
.bs-foto {
  width: 100%;
  border-radius: var(--radio-imagen, 1rem);
  object-fit: cover;
  aspect-ratio: 4 / 3;
}
.bs-llamado {
  max-width: 42rem;
}
.bs-centro .bs-llamado {
  margin-inline: auto;
}
.bs-galeria {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr));
  gap: 0.75rem;
}
.bs-galeria img {
  width: 100%;
  aspect-ratio: 4 / 3;
  border-radius: var(--radio-tarjeta, 0.75rem);
  object-fit: cover;
}
.bs-galeria figcaption {
  margin-top: 0.35rem;
  font-size: 0.85rem;
  opacity: 0.8;
}
.bs-preguntas {
  display: grid;
  gap: 0.5rem;
  max-width: 48rem;
  text-align: left;
}
.bs-centro .bs-preguntas {
  margin-inline: auto;
}
.bs-preguntas details {
  padding: 0.85rem 1rem;
  border: 1px solid color-mix(in srgb, currentColor 18%, transparent);
  border-radius: var(--radio-tarjeta, 0.75rem);
}
.bs-preguntas summary {
  cursor: pointer;
  font-weight: 500;
}
.bs-preguntas details p {
  margin-top: 0.5rem;
  opacity: 0.85;
}
.bs-tarjetas {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr));
  gap: 1rem;
}
.bs-tarjeta {
  padding: 1.1rem 1.2rem;
  border: 1px solid color-mix(in srgb, currentColor 15%, transparent);
  border-radius: var(--radio-tarjeta, 1rem);
}
.bs-video {
  max-width: 48rem;
}
.bs-centro .bs-video {
  margin-inline: auto;
}
.bs-video iframe,
.bs-portada-video {
  display: block;
  width: 100%;
  aspect-ratio: 16 / 9;
  border: 0;
  border-radius: var(--radio-imagen, 1rem);
}
.bs-portada-video {
  position: relative;
  display: grid;
  place-items: center;
  align-content: center;
  gap: 0.5rem;
  background-color: color-mix(in srgb, var(--primario) 70%, #000);
  background-position: center;
  background-size: cover;
  color: #ffffff;
}
.bs-play {
  display: grid;
  place-items: center;
  width: 4rem;
  height: 4rem;
  border-radius: 999px;
  background: rgb(0 0 0 / 0.55);
  font-size: 1.5rem;
}
.bs-play-texto {
  padding: 0.2rem 0.6rem;
  border-radius: 0.4rem;
  background: rgb(0 0 0 / 0.55);
  font-size: 0.9rem;
}
.bs-formulario {
  max-width: 36rem;
  text-align: left;
}
.bs-centro .bs-formulario {
  margin-inline: auto;
}
</style>
