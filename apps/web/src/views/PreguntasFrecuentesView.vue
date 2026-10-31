<script setup lang="ts">
import { computed, h, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import LogoProducto from "@/components/LogoProducto.vue";
import { trackEvent } from "@/lib/analytics";
import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import { MODALIDADES, type Modo } from "@/marketing/modalidades";
import { usePreciosPublicos } from "@/marketing/preciosPublicos";
import { useRegistroDelProducto } from "@/marketing/registroProducto";
import { MODO_COMERCIAL } from "@/marketing/seoConfig";
import "@/marketing/landing.css";

const props = withDefaults(defineProps<{ modo?: Modo }>(), {
  modo: () => MODO_COMERCIAL,
});

const { t } = useI18n();
const precios = usePreciosPublicos();
const k = (resto: string) => `landing.${props.modo}.${resto}`;

const contenido = computed(() => MODALIDADES[props.modo]);
const producto = computed(() => productoDeModalidad(props.modo));
const {
  abierto: registroAbierto,
  diasPrueba: DIAS_PRUEBA,
  nombre: marca,
} = useRegistroDelProducto(productoDeModalidad(props.modo));

const soporte = computed(() => PRODUCTOS[producto.value].soporte);

const busqueda = ref("");
const categoriaActiva = ref<string>("todas");

function traducirPregunta(clave: string) {
  return {
    clave,
    pregunta: t(k(`faq.${clave}.q`)),
    respuesta: t(
      k(
        clave === "soporte" && soporte.value.whatsapp === ""
          ? `faq.${clave}.aSinWhatsapp`
          : `faq.${clave}.a`,
      ),
      { ...soporte.value, mesesAnual: precios.datos.citas.meses_anual },
    ),
  };
}

const secciones = computed(() =>
  contenido.value.categoriasFaq.map((cat) => ({
    ...cat,
    items: cat.preguntas.map(traducirPregunta),
  })),
);

const totalPreguntas = computed(() =>
  secciones.value.reduce((acc, s) => acc + s.items.length, 0),
);

const terminoNormalizado = computed(() =>
  busqueda.value
    .trim()
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, ""),
);

const normalizar = (texto: string) =>
  texto
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "");

const seccionesFiltradas = computed(() => {
  const q = terminoNormalizado.value;
  return secciones.value
    .filter(
      (s) =>
        categoriaActiva.value === "todas" || s.clave === categoriaActiva.value,
    )
    .map((s) => ({
      ...s,
      items:
        q === ""
          ? s.items
          : s.items.filter(
              (p) =>
                normalizar(p.pregunta).includes(q) ||
                normalizar(p.respuesta).includes(q),
            ),
    }))
    .filter((s) => s.items.length > 0);
});

const todasLasPreguntas = computed(() =>
  secciones.value.flatMap((s) => s.items),
);

const tieneNotaMexico = computed(() =>
  todasLasPreguntas.value.some(
    (p) => p.pregunta.includes("*") || p.respuesta.includes("*"),
  ),
);

const registro = computed(() => ({
  name: "registro",
  query: { modo: props.modo },
}));

const textoRegistro = computed(() =>
  registroAbierto.value
    ? t("landing.modalidad.probar")
    : t("landing.prelanzamiento.cta"),
);

function medirRegistro(): void {
  trackEvent("marketing_cta_clicked", {
    placement: "faq_page",
    destination: registroAbierto.value ? "register" : "waitlist",
    mode: props.modo,
  });
}

const jsonLd = computed(() =>
  JSON.stringify({
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: todasLasPreguntas.value.map((p) => ({
      "@type": "Question",
      name: p.pregunta,
      acceptedAnswer: { "@type": "Answer", text: p.respuesta },
    })),
  }).replace(/</g, "\\u003c"),
);

const DatosEstructurados = () =>
  h("script", {
    type: "application/ld+json",
    "data-prueba": "faq-page-ld",
    innerHTML: jsonLd.value,
  });
</script>

<template>
  <div class="tu-landing tu-faq-pagina" :data-modo="modo">
    <!-- ===================== CABECERA FAQ ===================== -->
    <section
      class="tu-banda tu-faq-hero"
      style="background: var(--fondo); --sobre-banda: var(--superficie)"
    >
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 460"
        fill="none"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
      >
        <defs>
          <linearGradient id="tu-faq-estela-a" x1="0" y1="0.2" x2="1" y2="0.1">
            <stop offset="0%" stop-color="#0d3b6e" stop-opacity="0" />
            <stop offset="36%" stop-color="#3a8fd4" stop-opacity="0.34" />
            <stop
              offset="74%"
              stop-color="var(--marketing-rosa)"
              stop-opacity="0.48"
            />
            <stop
              offset="100%"
              stop-color="var(--marketing-rosa)"
              stop-opacity="0"
            />
          </linearGradient>
        </defs>
        <path
          d="M-60 170 C 340 60, 760 250, 1180 110 C 1310 68, 1400 88, 1510 64"
          stroke="url(#tu-faq-estela-a)"
          stroke-width="2.5"
          stroke-linecap="round"
        />
        <path
          class="tu-estela-pulso"
          d="M-60 170 C 340 60, 760 250, 1180 110 C 1310 68, 1400 88, 1510 64"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.52"
          stroke-width="2.8"
          stroke-linecap="round"
        />
        <g class="tu-estrella-movil">
          <path
            d="M1240 68 L1245 83 L1260 88 L1245 93 L1240 108 L1235 93 L1220 88 L1235 83 Z"
            fill="var(--marketing-rosa)"
            fill-opacity="0.6"
          />
        </g>
        <g class="tu-estrella-movil tu-estrella-movil--lenta">
          <path
            d="M180 86 L183 95 L192 98 L183 101 L180 110 L177 101 L168 98 L177 95 Z"
            fill="#3a8fd4"
            fill-opacity="0.46"
          />
        </g>
      </svg>

      <div class="mx-auto max-w-5xl px-4 sm:px-6 pt-10 pb-12 sm:pt-14 sm:pb-16">
        <nav class="tu-faq-miga" aria-label="Ruta de navegación">
          <RouterLink to="/" class="tu-faq-volver">
            <IconoNav nombre="flecha" :tam="15" class="tu-faq-flecha-izq" />
            Volver a {{ marca }}
          </RouterLink>
          <span aria-hidden="true">/</span>
          <span>Preguntas frecuentes</span>
        </nav>

        <div class="mt-6 max-w-3xl">
          <p class="tu-seccion-etiqueta">
            Centro de respuestas · {{ totalPreguntas }} preguntas
          </p>
          <h1 class="tu-display mt-2">
            Preguntas frecuentes sobre {{ marca }}
          </h1>
          <p class="mt-3 text-lg" style="color: var(--texto-suave)">
            Encuentra respuestas rápidas sobre operación diaria, cobros,
            facturación, migración desde otro sistema y tarifas.
          </p>
        </div>

        <!-- Buscador y filtros por sección -->
        <div class="tu-faq-controles mt-8">
          <div class="tu-faq-buscador">
            <svg
              aria-hidden="true"
              viewBox="0 0 24 24"
              width="18"
              height="18"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <circle cx="11" cy="11" r="8" />
              <path d="m21 21-4.3-4.3" />
            </svg>
            <input
              v-model="busqueda"
              type="search"
              placeholder="Buscar una duda (ej. CFDI, migración, precios, QR...)"
              aria-label="Buscar en preguntas frecuentes"
              data-prueba="faq-buscador"
            />
            <button
              v-if="busqueda"
              type="button"
              class="tu-faq-limpiar"
              aria-label="Limpiar búsqueda"
              @click="busqueda = ''"
            >
              Limpiar
            </button>
          </div>

          <div
            class="tu-faq-pestanas"
            role="tablist"
            aria-label="Filtrar preguntas por categoría"
          >
            <button
              type="button"
              role="tab"
              :aria-selected="categoriaActiva === 'todas'"
              class="tu-faq-pestana"
              :class="{ 'tu-faq-pestana--activa': categoriaActiva === 'todas' }"
              data-categoria="todas"
              @click="categoriaActiva = 'todas'"
            >
              Todas
              <span class="tu-faq-conteo">{{ totalPreguntas }}</span>
            </button>
            <button
              v-for="s in secciones"
              :key="s.clave"
              type="button"
              role="tab"
              :aria-selected="categoriaActiva === s.clave"
              class="tu-faq-pestana"
              :class="{
                'tu-faq-pestana--activa': categoriaActiva === s.clave,
              }"
              :data-categoria="s.clave"
              @click="categoriaActiva = s.clave"
            >
              {{ s.titulo }}
              <span class="tu-faq-conteo">{{ s.items.length }}</span>
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== SECCIONES DE PREGUNTAS ===================== -->
    <section
      class="tu-banda"
      style="background: var(--superficie); --sobre-banda: var(--fondo)"
    >
      <div class="mx-auto max-w-5xl px-4 sm:px-6 py-12 sm:py-16 space-y-12">
        <div
          v-if="seccionesFiltradas.length === 0"
          class="tu-faq-vacio text-center py-12"
          data-prueba="faq-sin-resultados"
        >
          <p class="text-lg font-medium">
            No encontramos preguntas con «{{ busqueda }}».
          </p>
          <p class="mt-2 text-sm" style="color: var(--texto-suave)">
            Prueba con otra palabra o muestra todas las categorías.
          </p>
          <button
            type="button"
            class="tu-btn tu-btn-secundario mt-5 px-5 py-2.5"
            @click="
              busqueda = '';
              categoriaActiva = 'todas';
            "
          >
            Ver todas las preguntas
          </button>
        </div>

        <section
          v-for="seccion in seccionesFiltradas"
          :id="`faq-${seccion.clave}`"
          :key="seccion.clave"
          class="tu-faq-bloque"
          :data-seccion-faq="seccion.clave"
        >
          <div class="tu-faq-bloque-cabecera">
            <div>
              <h2 class="text-2xl font-semibold tracking-tight">
                {{ seccion.titulo }}
              </h2>
              <p class="mt-1 text-sm" style="color: var(--texto-suave)">
                {{ seccion.descripcion }}
              </p>
            </div>
            <span class="tu-faq-insignia">
              {{ seccion.items.length }}
              {{ seccion.items.length === 1 ? "pregunta" : "preguntas" }}
            </span>
          </div>

          <div class="tu-faq-grid mt-5">
            <details
              v-for="p in seccion.items"
              :key="p.clave"
              class="tu-faq-item tu-card p-5"
              :data-pregunta="p.clave"
              :open="terminoNormalizado !== '' || undefined"
            >
              <summary>
                <span>{{ p.pregunta }}</span>
                <svg
                  class="tu-faq-chevron"
                  aria-hidden="true"
                  viewBox="0 0 24 24"
                  width="18"
                  height="18"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path d="m6 9 6 6 6-6" />
                </svg>
              </summary>
              <p>{{ p.respuesta }}</p>
            </details>
          </div>
        </section>

        <p v-if="tieneNotaMexico" class="tu-nota-mexico">
          {{ t("landing.soloMexico") }}
        </p>

        <!-- Caja de cierre y contacto directo -->
        <div class="tu-faq-cierre">
          <div class="tu-monograma-sello" aria-hidden="true">
            <LogoProducto
              v-if="modo === 'clases'"
              producto="agendauno"
              variante="isotipo"
              :ancho="34"
              alt="Monograma de AgendaUno"
            />
            <span v-else class="tu-monograma-iniciales">TU</span>
          </div>
          <div class="tu-faq-cierre-texto">
            <h3>¿Listo para probarlo en tu negocio?</h3>
            <p>
              {{
                registroAbierto
                  ? t(k("final.subtitulo"), { dias: DIAS_PRUEBA })
                  : t("landing.prelanzamiento.finalSubtitulo")
              }}
              Si tienes una duda particular, escríbenos a
              <a class="tu-enlace" :href="`mailto:${soporte.correo}`">{{
                soporte.correo
              }}</a
              >.
            </p>
          </div>
          <div class="tu-faq-cierre-acciones">
            <RouterLink
              class="tu-btn tu-btn-primario px-6 py-3"
              :to="registro"
              data-cta="faq_final"
              @click="medirRegistro"
            >
              {{ textoRegistro }}
            </RouterLink>
          </div>
        </div>
      </div>
    </section>

    <DatosEstructurados />
  </div>
</template>

<style scoped>
.tu-faq-miga {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  font-size: 0.85rem;
  color: var(--texto-suave);
}
.tu-faq-volver {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  color: var(--enlace);
  font-weight: 500;
  text-decoration: none;
}
.tu-faq-volver:hover {
  text-decoration: underline;
}
.tu-faq-flecha-izq {
  transform: rotate(180deg);
}
.tu-faq-controles {
  display: grid;
  gap: 1rem;
}
.tu-faq-buscador {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1.1rem;
  border: 1px solid color-mix(in srgb, #3a8fd4 34%, var(--borde));
  border-radius: 16px;
  background: var(--superficie);
  box-shadow: 0 12px 28px -20px rgb(13 59 110 / 24%);
  color: var(--texto-suave);
}
.tu-faq-buscador:focus-within {
  border-color: #3a8fd4;
  box-shadow: 0 0 0 3px color-mix(in srgb, #3a8fd4 18%, transparent);
}
.tu-faq-buscador input {
  flex: 1;
  min-width: 0;
  border: 0;
  background: transparent;
  color: var(--texto);
  font-size: 0.96rem;
  outline: none;
}
.tu-faq-limpiar {
  padding: 0.25rem 0.65rem;
  border-radius: 999px;
  border: 1px solid var(--borde);
  background: var(--fondo);
  color: var(--texto-suave);
  font-size: 0.76rem;
  font-weight: 600;
  cursor: pointer;
}
.tu-faq-pestanas {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem;
}
.tu-faq-pestana {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.5rem 0.95rem;
  border-radius: 999px;
  border: 1px solid var(--borde);
  background: var(--superficie);
  color: var(--texto-suave);
  font-size: 0.84rem;
  font-weight: 500;
  cursor: pointer;
  transition:
    background-color 0.2s ease,
    border-color 0.2s ease,
    color 0.2s ease;
}
.tu-faq-pestana:hover {
  border-color: color-mix(in srgb, #3a8fd4 45%, var(--borde));
  color: var(--texto);
}
.tu-faq-pestana--activa {
  border-color: #0d3b6e;
  background: linear-gradient(135deg, #0d3b6e 0%, #3a8fd4 100%);
  color: #ffffff;
}
.tu-faq-conteo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.4rem;
  height: 1.4rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: color-mix(in srgb, currentColor 14%, transparent);
  font-size: 0.72rem;
  font-weight: 700;
}
.tu-faq-bloque {
  padding: 1.6rem;
  border-radius: 22px;
  border: 1px solid color-mix(in srgb, #3a8fd4 22%, var(--borde));
  background: var(--fondo);
  box-shadow: 0 16px 36px -28px rgb(13 59 110 / 22%);
}
.tu-faq-bloque-cabecera {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--borde);
}
.tu-faq-insignia {
  padding: 0.28rem 0.75rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, #3a8fd4 30%, var(--borde));
  background: color-mix(in srgb, #3a8fd4 10%, var(--superficie));
  color: var(--primario);
  font-size: 0.75rem;
  font-weight: 600;
}
.tu-faq-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 0.85rem;
  align-items: start;
}
@media (min-width: 768px) {
  .tu-faq-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
.tu-faq-item {
  border: 1px solid var(--borde);
  background: var(--superficie);
  transition: border-color 0.2s ease;
}
.tu-faq-item:hover,
.tu-faq-item[open] {
  border-color: color-mix(in srgb, #3a8fd4 45%, var(--borde));
}
.tu-faq-item summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  cursor: pointer;
  list-style: none;
  font-weight: 500;
}
.tu-faq-item summary::-webkit-details-marker {
  display: none;
}
.tu-faq-chevron {
  flex: 0 0 auto;
  color: var(--texto-suave);
  transition: transform 0.15s ease;
}
.tu-faq-item[open] .tu-faq-chevron {
  transform: rotate(180deg);
}
.tu-faq-item p {
  margin-top: 0.75rem;
  color: var(--texto-suave);
  font-size: 0.875rem;
  line-height: 1.6;
}
.tu-faq-cierre {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1.25rem;
  padding: 1.5rem 1.75rem;
  border-radius: 22px;
  border: 1px solid color-mix(in srgb, #3a8fd4 35%, var(--borde));
  background: linear-gradient(
    135deg,
    color-mix(in srgb, #0d3b6e 6%, var(--fondo)) 0%,
    color-mix(in srgb, #3a8fd4 12%, var(--superficie)) 100%
  );
}
.tu-faq-cierre-texto {
  flex: 1;
  min-width: min(18rem, 100%);
}
.tu-faq-cierre-texto h3 {
  font-size: 1.15rem;
  font-weight: 600;
}
.tu-faq-cierre-texto p {
  margin-top: 0.25rem;
  font-size: 0.9rem;
  color: var(--texto-suave);
}
.tu-monograma-sello {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3.25rem;
  height: 3.25rem;
  flex-shrink: 0;
  border-radius: 16px;
  border: 1px solid color-mix(in srgb, #3a8fd4 32%, var(--borde));
  background: var(--fondo);
}
.tu-monograma-iniciales {
  font-size: 1.05rem;
  font-weight: 800;
  color: #0d3b6e;
}
</style>
