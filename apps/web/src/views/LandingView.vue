<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import "@/marketing/landing.css";
import { trackEvent } from "@/lib/analytics";
import { useRevelar } from "@/lib/revelar";
import FondoHero from "@/components/FondoHero.vue";
import HeroCollage from "@/components/HeroCollage.vue";
import ModalidadesLanding from "@/components/ModalidadesLanding.vue";
import NegociosAnimados from "@/components/NegociosAnimados.vue";
import PreguntasFrecuentes from "@/components/PreguntasFrecuentes.vue";
import SellosConfianza from "@/components/SellosConfianza.vue";
import SolucionesEnlaces from "@/components/SolucionesEnlaces.vue";
import TextoDestacado from "@/components/TextoDestacado.vue";
import {
  MODOS,
  NOMBRE_MODALIDAD,
  NOMBRE_RUTA_MODALIDAD,
  type Modo,
} from "@/marketing/modalidades";
import { bandasEstudios, ejemplosCitas, pesos } from "@/marketing/precios";

/*
| Portada «/»: corta, para que cada visitante elija su modalidad en segundos. Cada
| negocio es solo de clases o solo de citas (ADR 0104): lo de cada una vive en
| /clases y /citas (ModalidadView). Aquí solo va lo general: hero con dos botones,
| sellos, las dos modalidades, precios «desde…», giros, preguntas generales y cierre.
| Se prerenderiza (entry-marketing): nada de `window` ni `matchMedia` fuera de
| `onMounted` (la aparición al desplazarse la lleva `useRevelar`).
*/
const { t } = useI18n();
const DIAS_PRUEBA = 30;

const negociosAnimados = computed(() =>
  [
    "pilates",
    "pole",
    "academias",
    "acuaticas",
    "yoga",
    "crossfit",
    "hyrox",
    "barberias",
    "esteticas",
    "spas",
    "wellness",
    "terapeutas",
    "dentistas",
    "psicologos",
    "nutriologos",
  ].map((clave) => t(`landing.heroEscritura.negocios.${clave}`)),
);

// Una foto de cada modalidad: barbería (citas), Pole dance y HYROX (clases).
const fotosHero = computed(() =>
  (
    [
      { clave: "barberia", imagen: "barberia-v1.jpg" },
      { clave: "pole", imagen: "pole-v1.jpg", posicion: "73% center" },
      { clave: "hyrox", imagen: "crossfit-hyrox-v1.webp" },
    ] as const
  ).map((foto) => ({
    clave: foto.clave,
    src: `/assets/landing/disciplinas/${foto.imagen}`,
    alt: t(`landing.heroVisual.${foto.clave}Alt`),
    etiqueta: t(`landing.heroVisual.${foto.clave}`),
    posicion: "posicion" in foto ? foto.posicion : undefined,
  })),
);
const avisoHero = computed(() => ({
  titulo: t("landing.heroVisual.confirmada"),
  detalle: t("landing.heroVisual.confirmadaDetalle"),
}));

// Precio de entrada de cada modalidad (la referencia comercial de precios.ts); el
// detalle de cada una está en /clases#precios y /citas#precios.
const preciosDesde = [
  {
    modo: "clases" as const,
    modelo: "landing.portada.precios.clases",
    capacidad: bandasEstudios[0].capacidad,
    importe: pesos(bandasEstudios[0].subtotal),
    enlace: "landing.portada.precios.verClases",
  },
  {
    modo: "citas" as const,
    modelo: "landing.portada.precios.citas",
    capacidad: ejemplosCitas[0].capacidad,
    importe: pesos(ejemplosCitas[0].subtotal),
    enlace: "landing.portada.precios.verCitas",
  },
];

// Preguntas generales; las de cada modalidad van en /clases y /citas. Quién cambia la
// modalidad (ADR 0104) se explica aquí, no en el registro.
const preguntas = computed(() =>
  (
    [
      "prueba",
      "cancelacion",
      "datos",
      "dosNegocios",
      "cambiarModalidad",
    ] as const
  ).map((clave) => ({
    clave,
    pregunta: t(`landing.portada.faq.${clave}`),
    respuesta: t(`landing.portada.faq.${clave}R`, { dias: DIAS_PRUEBA }),
  })),
);

// Lo que mide cada clic, siempre con `mode` ('clases' | 'citas').
function elegirModalidad(modo: Modo, placement: string): void {
  trackEvent("marketing_business_mode_selected", { mode: modo, placement });
}
function medirPrecios(modo: Modo): void {
  trackEvent("marketing_cta_clicked", {
    placement: "pricing_card",
    destination: "pricing",
    mode: modo,
  });
}
function medirRegistro(modo: Modo): void {
  trackEvent("marketing_cta_clicked", {
    placement: "final",
    destination: "register",
    mode: modo,
  });
}

const raiz = ref<HTMLElement>();
useRevelar(raiz);
</script>

<template>
  <div ref="raiz" class="tu-landing tu-portada">
    <!-- ===================== HERO ===================== -->
    <section class="tu-banda tu-hero" :style="{ background: 'var(--fondo)' }">
      <FondoHero />
      <div class="tu-hero-layout mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="tu-hero-copy reveal">
          <NegociosAnimados
            :negocios="negociosAnimados"
            :prefijo="$t('landing.heroEscritura.prefijo')"
            :pausar="$t('landing.heroEscritura.pausar')"
            :reanudar="$t('landing.heroEscritura.reanudar')"
          />
          <h1 class="tu-display max-w-3xl">
            <TextoDestacado :texto="$t('landing.titulo')" enfasis="Más" />
          </h1>
          <p
            class="tu-hero-sub mt-5 text-lg sm:text-xl max-w-2xl"
            style="color: var(--texto-suave); letter-spacing: -0.01em"
          >
            {{ $t("landing.portada.subtitulo") }}
          </p>

          <!-- La elección: cada botón lleva a la landing de su modalidad. En azul;
               el rosa queda para «Probar gratis» del menú y el cierre. -->
          <p id="portada-elegir" class="tu-hero-pregunta">
            {{ $t("landing.portada.elegir") }}
          </p>
          <div
            class="tu-hero-opciones"
            role="group"
            aria-labelledby="portada-elegir"
          >
            <RouterLink
              v-for="modo in MODOS"
              :key="modo"
              class="tu-btn tu-btn-primario tu-btn-azul tu-hero-opcion"
              :data-modo="modo"
              :to="{ name: NOMBRE_RUTA_MODALIDAD[modo] }"
              @click="elegirModalidad(modo, 'hero')"
            >
              <span class="tu-hero-opcion-titulo"
                >{{ $t(`landing.portada.opciones.${modo}`) }}
                <span aria-hidden="true">→</span></span
              >
              <span class="tu-hero-opcion-giros">{{
                $t(`landing.portada.opciones.${modo}Giros`)
              }}</span>
            </RouterLink>
          </div>
          <p class="tu-hero-modalidad">
            {{ $t("landing.portada.unaModalidad") }}
          </p>
          <p class="tu-hero-proof">
            {{ $t("landing.prueba", { dias: DIAS_PRUEBA }) }}
            <span aria-hidden="true">·</span>
            {{ $t("landing.pieHero") }}
          </p>
        </div>

        <HeroCollage :fotos="fotosHero" :aviso="avisoHero" />
      </div>
    </section>

    <!-- ===================== CONFIANZA ===================== -->
    <SellosConfianza />

    <!-- ===================== MODALIDADES ===================== -->
    <section
      id="producto"
      class="tu-banda tu-ancla"
      :style="{ background: 'var(--superficie)' }"
    >
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div class="tu-modalidades-intro reveal">
          <p class="tu-seccion-etiqueta">
            {{ $t("landing.portada.modalidades.etiqueta") }}
          </p>
          <h2 class="tu-titulo mt-3 max-w-4xl">
            {{ $t("landing.portada.modalidades.titulo") }}
          </h2>
          <p class="mt-4 max-w-2xl" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("landing.portada.modalidades.subtitulo") }}
          </p>
        </div>
        <ModalidadesLanding
          @elegir="elegirModalidad($event, 'business_modes')"
        />
      </div>
    </section>

    <!-- ===================== PRECIOS ===================== -->
    <section
      id="precios"
      class="tu-banda tu-ancla"
      :style="{ background: 'var(--fondo)' }"
    >
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div class="max-w-3xl reveal">
          <p class="tu-seccion-etiqueta">
            {{ $t("landing.portada.precios.etiqueta") }}
          </p>
          <h2 class="tu-titulo mt-3">
            {{ $t("landing.portada.precios.titulo") }}
          </h2>
          <p
            class="mt-5 text-lg leading-relaxed"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("landing.portada.precios.subtitulo") }}
          </p>
        </div>
        <div class="tu-portada-precios">
          <article
            v-for="precio in preciosDesde"
            :key="precio.modo"
            class="tu-portada-precio"
            :data-modo="precio.modo"
          >
            <p class="tu-portada-precio-modalidad">
              {{ NOMBRE_MODALIDAD[precio.modo] }}
            </p>
            <h3>{{ $t(precio.modelo) }}</h3>
            <p class="tu-portada-precio-importe">
              <span>{{ $t("landing.portada.precios.desde") }}</span>
              <strong>{{ precio.importe }}</strong>
              <span>{{ $t("landing.portada.precios.porMes") }}</span>
            </p>
            <p class="tu-portada-precio-detalle">
              {{ $t("landing.portada.precios.iva") }} · {{ precio.capacidad }}
            </p>
            <RouterLink
              class="tu-link-flecha"
              :to="{
                name: NOMBRE_RUTA_MODALIDAD[precio.modo],
                hash: '#precios',
              }"
              @click="medirPrecios(precio.modo)"
            >
              {{ $t(precio.enlace) }}
              <span aria-hidden="true">›</span>
            </RouterLink>
          </article>
        </div>
        <p class="tu-portada-precios-prueba">
          {{ $t("landing.portada.precios.prueba", { dias: DIAS_PRUEBA }) }}
        </p>
      </div>
    </section>

    <!-- ===================== GIROS ===================== -->
    <section
      id="soluciones"
      class="tu-banda tu-ancla"
      :style="{ background: 'var(--superficie)' }"
    >
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div class="max-w-3xl reveal">
          <p class="tu-seccion-etiqueta">
            {{ $t("landing.portada.giros.etiqueta") }}
          </p>
          <h2 class="tu-titulo mt-3">
            {{ $t("landing.portada.giros.titulo") }}
          </h2>
        </div>
        <SolucionesEnlaces dos-columnas>
          <template #pie="{ modo }">
            <RouterLink
              class="tu-link-flecha tu-portada-giros-modalidad"
              :to="{ name: NOMBRE_RUTA_MODALIDAD[modo] }"
              @click="elegirModalidad(modo, 'business_types')"
            >
              {{
                $t(
                  modo === "clases"
                    ? "landing.portada.giros.verClases"
                    : "landing.portada.giros.verCitas",
                )
              }}
              <span aria-hidden="true">›</span>
            </RouterLink>
            <p v-if="modo === 'citas'" class="tu-alcance-salud mt-6">
              <strong>{{ $t("landing.paraQuien.saludTitulo") }}</strong>
              {{ $t("landing.paraQuien.saludAlcance") }}
            </p>
          </template>
        </SolucionesEnlaces>
      </div>
    </section>

    <!-- ===================== FAQ ===================== -->
    <!-- Banda gris: las tarjetas de las preguntas van en blanco (`--sobre-banda`). -->
    <section
      class="tu-banda"
      :style="{
        background: 'var(--fondo)',
        '--sobre-banda': 'var(--superficie)',
      }"
    >
      <div class="mx-auto max-w-4xl px-4 sm:px-6 py-20 sm:py-28">
        <PreguntasFrecuentes
          :titulo="$t('landing.portada.faq.titulo')"
          :preguntas="preguntas"
        />
      </div>
    </section>

    <!-- ===================== CTA FINAL ===================== -->
    <section class="tu-banda" :style="{ background: 'var(--superficie)' }">
      <div
        class="mx-auto max-w-3xl px-4 sm:px-6 py-20 sm:py-28 text-center reveal"
      >
        <h2 class="tu-titulo tu-titulo-final">
          {{ $t("landing.portada.final.titulo") }}
        </h2>
        <p class="mt-4 text-lg" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("landing.portada.final.subtitulo", { dias: DIAS_PRUEBA }) }}
        </p>
        <div class="tu-final-acciones">
          <RouterLink
            v-for="modo in MODOS"
            :key="modo"
            class="tu-btn tu-btn-primario text-base px-7 py-3"
            :data-modo="modo"
            :to="{ name: 'registro', query: { modo } }"
            @click="medirRegistro(modo)"
          >
            {{ $t(`landing.portada.final.${modo}`) }}
          </RouterLink>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
/* Lo común de las páginas comerciales (títulos, hero, aparición, nota de salud) está
   en marketing/landing.css; aquí solo lo propio de la portada. */

/* ---------- Hero: la elección de modalidad ---------- */
.tu-hero-pregunta {
  margin-top: 2rem;
  color: var(--texto);
  font-size: 1rem;
  font-weight: 500;
}
.tu-hero-opciones {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
  max-width: 36rem;
  margin-top: 0.85rem;
}
.tu-hero-opcion {
  flex-direction: column;
  align-items: flex-start;
  justify-content: flex-start;
  gap: 0.25rem;
  min-width: 0;
  min-height: 4.75rem;
  padding: 0.95rem 1.25rem;
  text-align: left;
  text-decoration: none;
}
.tu-hero-opcion-titulo {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 1.15rem;
  font-weight: 500;
}
.tu-hero-opcion-giros {
  font-size: 0.8rem;
  font-weight: 400;
  line-height: 1.35;
}
.tu-hero-modalidad {
  max-width: 36rem;
  margin-top: 0.9rem;
  color: var(--texto-suave);
  font-size: 0.9rem;
  line-height: 1.5;
}
.tu-hero-proof {
  margin-top: 0.5rem;
  color: var(--texto-suave);
  font-size: 0.875rem;
}

/* ---------- Enlaces con flecha: área táctil de 44 px ---------- */
.tu-link-flecha {
  min-height: 2.75rem;
  font-size: 1rem;
}
.tu-link-flecha:focus-visible {
  outline: 2px solid var(--enlace);
  outline-offset: 3px;
  border-radius: 0.25rem;
}

/* ---------- Precios «desde…» ---------- */
.tu-portada-precios {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
  margin-top: 2.25rem;
}
/* Borde de arriba rosa, como las tarjetas de /clases#precios y /citas#precios. */
.tu-portada-precio {
  display: flex;
  min-width: 0;
  flex-direction: column;
  padding: 1.6rem;
  border: 1px solid var(--borde);
  border-top: 3px solid var(--marketing-cta);
  border-radius: var(--landing-radius);
  background: var(--superficie);
}
.tu-portada-precio-modalidad {
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.tu-portada-precio h3 {
  margin-top: 0.5rem;
  font-size: 1.2rem;
  font-weight: 500;
}
.tu-portada-precio-importe {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.35rem;
  margin-top: 1.25rem;
}
.tu-portada-precio-importe span,
.tu-portada-precio-detalle {
  color: var(--texto-suave);
  font-size: 0.85rem;
}
.tu-portada-precio-importe strong {
  font-size: clamp(1.8rem, 3vw, 2.5rem);
  font-variant-numeric: tabular-nums;
  font-weight: 500;
  letter-spacing: -0.04em;
}
.tu-portada-precio-detalle {
  margin-top: 0.25rem;
}
.tu-portada-precio .tu-link-flecha {
  margin-top: 1rem;
  align-self: flex-start;
}
.tu-portada-precios-prueba {
  margin-top: 1.25rem;
  color: var(--texto-suave);
  font-size: 0.9rem;
}

/* ---------- Giros: el enlace a la modalidad al pie de cada columna ---------- */
.tu-portada-giros-modalidad {
  margin-top: 0.75rem;
}

/* ---------- Cierre ---------- */
.tu-final-acciones {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.75rem;
  margin-top: 2rem;
}

@media (max-width: 1023px) {
  .tu-hero-opciones,
  .tu-hero-modalidad {
    margin-inline: auto;
  }
  .tu-hero-opcion {
    align-items: center;
    text-align: center;
  }
}
@media (max-width: 639px) {
  .tu-hero-sub {
    margin-top: 1rem;
  }
  .tu-hero-pregunta {
    margin-top: 1.5rem;
  }
  .tu-portada-precios {
    grid-template-columns: 1fr;
  }
  .tu-final-acciones .tu-btn {
    width: 100%;
  }
}
@media (max-width: 479px) {
  .tu-hero-opciones {
    grid-template-columns: 1fr;
  }
}
</style>
