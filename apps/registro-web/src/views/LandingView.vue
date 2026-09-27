<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import { trackEvent } from "@/lib/analytics";
import CarruselNegocios from "@/components/CarruselNegocios.vue";
import ProductoDemo from "@/components/ProductoDemo.vue";
import FuncionesLanding from "@/components/FuncionesLanding.vue";
import ModalidadesLanding from "@/components/ModalidadesLanding.vue";
import SolucionesEnlaces from "@/components/SolucionesEnlaces.vue";
import TextoDestacado from "@/components/TextoDestacado.vue";
import NegociosAnimados from "@/components/NegociosAnimados.vue";
import PreciosLanding from "@/components/PreciosLanding.vue";

const { t } = useI18n();
const DIAS_PRUEBA = 30;
const negociosAnimados = computed(() =>
  [
    "pilates",
    "pole",
    "academias",
    "acuaticas",
    "yoga",
    "barberias",
    "esteticas",
    "spas",
    "wellness",
    "terapeutas",
    "dentistas",
    "psicologos",
  ].map((clave) => t(`landing.heroEscritura.negocios.${clave}`)),
);

const modoDemo = ref<"clases" | "citas">("clases");
function elegirAgenda(modo: "clases" | "citas"): void {
  modoDemo.value = modo;
  trackEvent("marketing_business_mode_selected", {
    mode: modo,
    placement: "business_modes",
  });
}
const sellosConfianza = [
  "prueba",
  "configuracion",
  "cobro",
  "cancelacion",
] as const;

const beneficiosMoviles = ["b1", "b2", "b3"] as const;

const pasos = [
  { n: 1, t: "p1t", d: "p1d" },
  { n: 2, t: "p2t", d: "p2d" },
  { n: 3, t: "p3t", d: "p3d" },
] as const;

const faqs = [
  { q: "q1", a: "a1" },
  { q: "q6", a: "a6" },
  { q: "q9", a: "a9" },
  { q: "q2", a: "a2" },
  { q: "q7", a: "a7" },
  { q: "q4", a: "a4" },
  { q: "q5", a: "a5" },
  { q: "q8", a: "a8" },
  { q: "q3", a: "a3" },
] as const;

function medirCta(ubicacion: string, destino: string): void {
  trackEvent("marketing_cta_clicked", {
    placement: ubicacion,
    destination: destino,
  });
}

const NEGOCIOS = [
  { clave: "pilates", imagen: "pilates-v1.jpg", destacada: true },
  { clave: "pole", imagen: "pole-v1.jpg", destacada: true },
  { clave: "academias", imagen: "academias-v1.jpg", destacada: false },
  { clave: "acuaticas", imagen: "natacion-v1.jpg", destacada: false },
  { clave: "barberia", imagen: "barberia-v1.jpg", destacada: false },
  { clave: "estetica", imagen: "estetica-v1.jpg", destacada: false },
  { clave: "dentistas", imagen: "consultorios-v1.webp", destacada: false },
  { clave: "psicologos", imagen: "psicologia-v1.webp", destacada: false },
  { clave: "wellness", imagen: "wellness-v1.webp", destacada: false },
  { clave: "spa", imagen: "spa-v1.webp", destacada: false },
  { clave: "terapeutas", imagen: "terapeutas-v1.webp", destacada: false },
  { clave: "gimnasio", imagen: "gimnasio-v1.jpg", destacada: false },
  { clave: "danza", imagen: "danza-v1.jpg", destacada: false },
  { clave: "yoga", imagen: "yoga-v1.jpg", destacada: false },
] as const;

const negocios = computed(() =>
  NEGOCIOS.map((negocio) => ({
    ...negocio,
    nombre: t(`landing.paraQuien.negocios.${negocio.clave}.nombre`),
    descripcion: t(`landing.paraQuien.negocios.${negocio.clave}.descripcion`),
    alt: t(`landing.paraQuien.negocios.${negocio.clave}.alt`),
    src: `/assets/landing/disciplinas/${negocio.imagen}`,
  })),
);

const heroNegocios = [
  { clave: "pole", imagen: "pole-v1.jpg" },
  { clave: "pilates", imagen: "pilates-v1.jpg" },
  { clave: "barberia", imagen: "barberia-v1.jpg" },
] as const;
const flujoReserva = [
  "servicio",
  "profesional",
  "horario",
  "confirmacion",
] as const;

// Aparición al entrar en pantalla, respetando prefers-reduced-motion.
let observador: IntersectionObserver | undefined;

onMounted(() => {
  const nodos = Array.from(document.querySelectorAll<HTMLElement>(".reveal"));
  const reducido =
    window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;

  if (reducido || !("IntersectionObserver" in window)) {
    nodos.forEach((n) => n.classList.add("reveal-in"));
    return;
  }

  observador = new IntersectionObserver(
    (entradas) => {
      for (const e of entradas) {
        if (e.isIntersecting) {
          e.target.classList.add("reveal-in");
          observador?.unobserve(e.target);
        }
      }
    },
    { threshold: 0.18 },
  );
  nodos.forEach((n) => observador?.observe(n));
});
onBeforeUnmount(() => observador?.disconnect());
</script>

<template>
  <div class="tu-landing">
    <!-- ===================== HERO ===================== -->
    <section class="tu-banda tu-hero" :style="{ background: 'var(--fondo)' }">
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
            {{ $t("landing.subtitulo") }}
          </p>
          <ul
            class="tu-hero-flow"
            :aria-label="$t('landing.heroVisual.flujoTitulo')"
          >
            <li v-for="paso in flujoReserva" :key="paso">
              {{ $t(`landing.heroVisual.flujo.${paso}`) }}
            </li>
          </ul>
          <div class="tu-hero-actions mt-7 flex flex-wrap items-center gap-3">
            <RouterLink
              class="tu-btn tu-btn-primario text-base px-7 py-3"
              :to="{ name: 'registro' }"
              @click="medirCta('hero', 'register')"
            >
              {{ $t("landing.ctaRegistrar") }}
            </RouterLink>
            <a
              class="tu-btn tu-btn-fantasma text-base px-7 py-3"
              href="#producto"
              @click="medirCta('hero', 'product')"
            >
              {{ $t("landing.ctaProducto") }}
            </a>
          </div>
          <p
            class="tu-hero-proof mt-4 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            <span aria-hidden="true">✓</span>
            {{ $t("landing.prueba", { dias: DIAS_PRUEBA }) }}
            <span aria-hidden="true">·</span>
            {{ $t("landing.pieHero") }}
          </p>
        </div>

        <figure class="tu-hero-visual reveal">
          <div class="tu-hero-collage">
            <div
              v-for="(negocio, i) in heroNegocios"
              :key="negocio.clave"
              class="tu-hero-foto"
              :class="`tu-hero-foto--${i + 1}`"
            >
              <img
                :src="`/assets/landing/disciplinas/${negocio.imagen}`"
                :alt="$t(`landing.heroVisual.${negocio.clave}Alt`)"
                width="1122"
                height="1402"
                :fetchpriority="i === 0 ? 'high' : undefined"
                decoding="async"
              />
              <span>{{ $t(`landing.heroVisual.${negocio.clave}`) }}</span>
            </div>
            <div class="tu-hero-reserva">
              <span class="tu-hero-reserva-check" aria-hidden="true">✓</span>
              <span>
                <strong>{{ $t("landing.heroVisual.confirmada") }}</strong>
                <small>{{ $t("landing.heroVisual.confirmadaDetalle") }}</small>
              </span>
            </div>
          </div>
        </figure>
      </div>
    </section>

    <!-- ===================== CONFIANZA ===================== -->
    <section class="tu-confianza" :aria-label="$t('landing.confianza.titulo')">
      <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <ul class="tu-confianza-grid" role="list">
          <li
            v-for="sello in sellosConfianza"
            :key="sello"
            class="tu-confianza-item"
          >
            <span class="tu-confianza-check" aria-hidden="true">✓</span>
            <span>{{ $t(`landing.confianza.${sello}`) }}</span>
          </li>
        </ul>
      </div>
    </section>

    <!-- ===================== PRODUCTO ===================== -->
    <section
      id="producto"
      class="tu-banda tu-ancla"
      :style="{ background: 'var(--superficie)' }"
    >
      <div class="mx-auto max-w-5xl px-4 sm:px-6 pt-20 sm:pt-28">
        <p class="tu-seccion-etiqueta reveal">
          {{ $t("landing.producto.etiqueta") }}
        </p>
        <h2 class="tu-titulo mt-3 max-w-3xl reveal">
          <TextoDestacado
            :texto="$t('landing.producto.titulo')"
            enfasis="agenda visual"
            tono="rosa"
            negrita
          />
        </h2>
        <p
          class="mt-4 text-lg max-w-2xl reveal"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("landing.producto.subtitulo") }}
        </p>
      </div>

      <div class="mx-auto max-w-6xl px-4 sm:px-6 pt-10 pb-20 sm:pb-28">
        <ProductoDemo v-model="modoDemo" />
        <div class="mt-8 text-center">
          <RouterLink
            class="tu-btn tu-btn-primario px-7 py-3"
            :to="{ name: 'registro' }"
            @click="medirCta('product_demo', 'register')"
          >
            Quiero organizar mi negocio
          </RouterLink>
          <p class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ DIAS_PRUEBA }} días para probarlo. Sin tarjeta.
          </p>
        </div>
      </div>
    </section>

    <!-- ===================== MODALIDADES ===================== -->
    <section class="tu-banda" :style="{ background: 'var(--fondo)' }">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div class="tu-modalidades-intro">
          <p class="tu-seccion-etiqueta">Pensada para tu forma de trabajar</p>
          <h2 class="tu-titulo mt-3 max-w-4xl">
            Tu negocio tiene su <strong class="tu-enfasis-rosa">ritmo</strong>.
            Tu <strong class="tu-enfasis-rosa">agenda</strong> también.
          </h2>
          <p class="mt-4 max-w-2xl" :style="{ color: 'var(--texto-suave)' }">
            Organiza los lugares de una clase o el tiempo de cada profesional.
            Elige una modalidad para explorar su agenda.
          </p>
        </div>
        <ModalidadesLanding @elegir="elegirAgenda" />
      </div>
    </section>

    <!-- ===================== CÓMO FUNCIONA ===================== -->
    <section
      id="como-funciona"
      class="tu-banda tu-ancla"
      :style="{ background: 'var(--superficie)' }"
    >
      <div class="mx-auto max-w-5xl px-4 sm:px-6 py-20 sm:py-28">
        <h2 class="tu-titulo reveal">
          {{ $t("landing.comoFunciona.titulo") }}
        </h2>
        <p
          class="mt-3 text-lg max-w-2xl reveal"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("landing.comoFunciona.subtitulo") }}
        </p>
        <div class="tu-pasos mt-12 grid gap-5 sm:grid-cols-3">
          <div
            v-for="(p, i) in pasos"
            :key="p.n"
            class="tu-card p-7 reveal"
            :style="{ transitionDelay: i * 90 + 'ms' }"
          >
            <div class="tu-paso-num">{{ p.n }}</div>
            <h3 class="mt-4 font-light text-xl tracking-tight">
              {{ $t(`landing.comoFunciona.${p.t}`) }}
            </h3>
            <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`landing.comoFunciona.${p.d}`) }}
            </p>
            <div class="tu-paso-ejemplo">
              <span class="tu-paso-ejemplo-label">Ejemplo</span>
              <template v-if="p.n === 1">
                <strong>Pilates Reformer</strong>
                <span>Lunes · 18:00 · Andrea</span>
                <span class="tu-paso-status">8 lugares disponibles</span>
              </template>
              <template v-else-if="p.n === 2">
                <strong>Tu página de reservas</strong>
                <span>tuestudio.agendauno.mx</span>
                <span class="tu-paso-status">Elige clase y horario →</span>
              </template>
              <template v-else>
                <strong>Una reserva en tu agenda</strong>
                <span>Pilates · Sofía · 18:00</span>
                <span class="tu-paso-status"
                  >Confirmada · 7 lugares libres</span
                >
              </template>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== FUNCIONES ===================== -->
    <section
      id="soluciones"
      class="tu-banda tu-ancla"
      :style="{ background: 'var(--fondo)' }"
    >
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <h2 class="tu-titulo reveal">
          <TextoDestacado
            :texto="$t('landing.seccionTitulo')"
            enfasis="crecer"
            negrita
          />
        </h2>
        <p
          class="mt-3 text-lg max-w-2xl reveal"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("landing.seccionSub") }}
        </p>
        <FuncionesLanding />
      </div>
    </section>

    <!-- ===================== OPERACIÓN ===================== -->
    <section
      id="operacion"
      class="tu-banda"
      :style="{ background: 'var(--superficie)' }"
    >
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div
          class="tu-operacion grid items-center gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16"
        >
          <div class="reveal">
            <p class="tu-seccion-etiqueta">
              {{ $t("landing.operacion.etiqueta") }}
            </p>
            <h2 class="tu-titulo mt-3">
              {{ $t("landing.operacion.titulo") }}
            </h2>
            <p
              class="mt-5 text-lg max-w-xl"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("landing.operacion.subtitulo") }}
            </p>
            <ul class="mt-8 space-y-4" role="list">
              <li
                v-for="beneficio in beneficiosMoviles"
                :key="beneficio"
                class="tu-check-item"
              >
                <span class="tu-check" aria-hidden="true">
                  <svg
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path d="m5 12.5 4 4 10-10" />
                  </svg>
                </span>
                <span>{{ $t(`landing.operacion.${beneficio}`) }}</span>
              </li>
            </ul>
            <RouterLink
              class="tu-link-flecha mt-8"
              :to="{ name: 'registro' }"
              @click="medirCta('operations', 'register')"
            >
              {{ $t("landing.operacion.enlace") }}
              <span aria-hidden="true">›</span>
            </RouterLink>
          </div>

          <figure class="tu-imagen-marco tu-imagen-rosa reveal">
            <img
              :src="'/assets/landing/agendauno-checkin-pos.webp'"
              :alt="$t('landing.operacion.imagenAlt')"
              width="1536"
              height="1024"
              loading="lazy"
              decoding="async"
            />
          </figure>
        </div>
      </div>
    </section>

    <!-- ===================== PRECIO ===================== -->
    <section
      id="precios"
      class="tu-banda tu-ancla"
      :style="{ background: 'var(--fondo)' }"
    >
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div class="max-w-3xl reveal">
          <p class="tu-seccion-etiqueta">{{ $t("landing.precio.etiqueta") }}</p>
          <h2 class="tu-titulo mt-3">
            {{ $t("landing.precio.titulo") }}
          </h2>
          <p
            class="mt-5 text-lg leading-relaxed"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("landing.precio.subtitulo") }}
          </p>
        </div>
        <PreciosLanding />
        <div class="tu-precio-prueba mt-6">
          <div>
            <strong
              >{{ $t("landing.precio.badge", { dias: DIAS_PRUEBA }) }} · Sin
              tarjeta</strong
            >
            <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("landing.precio.pruebaDetalle") }}
            </p>
          </div>
          <RouterLink
            class="tu-btn tu-btn-primario px-7 py-3"
            :to="{ name: 'registro' }"
            @click="medirCta('pricing', 'register')"
            >{{ $t("landing.ctaRegistrar") }}</RouterLink
          >
        </div>
      </div>
    </section>

    <!-- ===================== PÁGINA PÚBLICA / EXPLORAR ===================== -->
    <section class="tu-banda" :style="{ background: 'var(--superficie)' }">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div
          class="grid items-center gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16"
        >
          <div class="reveal">
            <p class="tu-seccion-etiqueta">
              {{ $t("landing.comunidad.etiqueta") }}
            </p>
            <h2 class="tu-titulo mt-3">
              {{ $t("landing.comunidad.titulo") }}
            </h2>
            <p
              class="mt-5 max-w-xl text-lg leading-relaxed"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("landing.comunidad.subtitulo") }}
            </p>
            <ul class="mt-8 space-y-4" role="list">
              <li v-for="n in 3" :key="n" class="tu-check-item">
                <span class="tu-check" aria-hidden="true">✓</span>
                <span>{{ $t(`landing.comunidad.i${n}`) }}</span>
              </li>
            </ul>
            <div class="mt-8 flex flex-wrap items-center gap-5">
              <RouterLink
                class="tu-btn tu-btn-primario px-6 py-3"
                :to="{ name: 'registro' }"
                @click="medirCta('community_benefit', 'register')"
              >
                {{ $t("landing.comunidad.cta") }}
              </RouterLink>
            </div>
          </div>

          <div class="tu-escaparate-demo reveal" aria-hidden="true">
            <div class="tu-escaparate-cabecera">
              <span class="tu-escaparate-logo">D27</span>
              <div>
                <p class="font-light text-lg">Impulso Studio</p>
                <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                  Juárez · Ciudad de México
                </p>
              </div>
              <span class="tu-badge tu-badge-exito ml-auto">{{
                $t("landing.comunidad.demoAbierto")
              }}</span>
            </div>
            <div class="tu-escaparate-contenido">
              <div class="flex items-center justify-between gap-4">
                <p class="font-semibold">
                  {{ $t("landing.comunidad.demoTitulo") }}
                </p>
                <span
                  class="text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ $t("landing.comunidad.demoSemana") }}</span
                >
              </div>
              <div class="mt-4 space-y-3">
                <div v-for="n in 3" :key="n" class="tu-clase-publica">
                  <div class="tu-clase-fecha">
                    <strong>{{ $t(`landing.comunidad.demo${n}Dia`) }}</strong>
                    <span>{{ $t(`landing.comunidad.demo${n}Hora`) }}</span>
                  </div>
                  <div class="min-w-0">
                    <p class="font-semibold truncate">
                      {{ $t(`landing.comunidad.demo${n}Clase`) }}
                    </p>
                    <p
                      class="text-sm truncate"
                      :style="{ color: 'var(--texto-suave)' }"
                    >
                      {{ $t(`landing.comunidad.demo${n}Coach`) }}
                    </p>
                  </div>
                  <span class="tu-badge ml-auto">{{
                    $t(`landing.comunidad.demo${n}Cupo`)
                  }}</span>
                </div>
              </div>
              <div class="tu-demo-boton mt-5">
                {{ $t("landing.comunidad.demoCta") }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== TIPOS DE NEGOCIO ===================== -->
    <section
      id="para-quien"
      class="tu-banda tu-disciplinas tu-ancla"
      :style="{ background: 'var(--fondo)' }"
    >
      <div class="mx-auto max-w-7xl px-4 sm:px-6 py-20 sm:py-28">
        <div class="text-center mx-auto max-w-3xl">
          <p class="tu-seccion-etiqueta reveal">
            {{ $t("landing.paraQuien.etiqueta") }}
          </p>
          <h2 class="tu-titulo mt-3 reveal">
            {{ $t("landing.paraQuien.titulo") }}
          </h2>
          <p
            class="mt-4 text-lg reveal"
            :style="{ color: 'var(--texto-suave)' }"
          >
            Tú haces que quieran volver. AgendaUno te ayuda a organizar cada
            clase, cada cita y cada nueva reserva.
          </p>
        </div>
        <CarruselNegocios :negocios="negocios">
          <RouterLink
            class="tu-btn tu-btn-primario px-7 py-3"
            :to="{ name: 'registro' }"
            @click="medirCta('business_carousel', 'register')"
          >
            Empieza con tu negocio
            <span aria-hidden="true">↗</span>
          </RouterLink>
          <p class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
            Tu agenda. Tu equipo. Tu próxima reserva.
          </p>
        </CarruselNegocios>
        <div class="mt-10">
          <h3 class="text-lg font-light text-center">
            Conoce AgendaUno para tu tipo de negocio
          </h3>
          <SolucionesEnlaces />
        </div>
        <p class="tu-alcance-salud mt-10 max-w-3xl mx-auto">
          <strong>{{ $t("landing.paraQuien.saludTitulo") }}</strong>
          {{ $t("landing.paraQuien.saludAlcance") }}
        </p>
      </div>
    </section>

    <!-- ===================== FAQ ===================== -->
    <section class="tu-banda" :style="{ background: 'var(--superficie)' }">
      <div class="mx-auto max-w-4xl px-4 sm:px-6 py-20 sm:py-28">
        <h2 class="tu-titulo reveal">{{ $t("landing.faq.titulo") }}</h2>
        <div class="mt-8 space-y-3">
          <details
            v-for="(f, i) in faqs"
            :key="f.q"
            class="tu-card p-5 reveal"
            :style="{ transitionDelay: i * 60 + 'ms' }"
          >
            <summary
              class="font-semibold cursor-pointer list-none flex items-center justify-between gap-3"
            >
              {{ $t(`landing.faq.${f.q}`) }}
              <span aria-hidden="true" :style="{ color: 'var(--texto-suave)' }"
                >+</span
              >
            </summary>
            <p class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`landing.faq.${f.a}`, { dias: DIAS_PRUEBA }) }}
            </p>
          </details>
        </div>
      </div>
    </section>

    <!-- ===================== CTA FINAL ===================== -->
    <section class="tu-banda" :style="{ background: 'var(--fondo)' }">
      <div
        class="mx-auto max-w-3xl px-4 sm:px-6 py-20 sm:py-28 text-center reveal"
      >
        <h2 class="tu-titulo tu-titulo-final">
          {{ $t("landing.ctaFinalTitulo") }}
        </h2>
        <p class="mt-4 text-lg" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("landing.ctaFinalSub", { dias: DIAS_PRUEBA }) }}
        </p>
        <RouterLink
          class="tu-btn tu-btn-primario text-base px-7 py-3 mt-8"
          :to="{ name: 'registro' }"
          @click="medirCta('final', 'register')"
        >
          {{ $t("landing.ctaRegistrar") }}
        </RouterLink>
      </div>
    </section>
  </div>
</template>

<style scoped>
.tu-landing {
  --landing-radius: var(--radio-tarjeta, 18px);
  overflow: clip;
}
.tu-ancla {
  scroll-margin-top: 6.5rem;
}
.tu-pasos > div {
  display: flex;
  flex-direction: column;
}
.tu-paso-ejemplo {
  display: grid;
  gap: 0.45rem;
  padding: 1.1rem;
  border: 1px solid var(--borde);
  border-radius: 1rem;
  background: var(--fondo);
  margin-top: 1.5rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.tu-paso-ejemplo strong {
  color: var(--texto);
}
.tu-paso-ejemplo-label {
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.62rem;
}
.tu-paso-status {
  color: var(--primario-fuerte);
  font-weight: 600;
  padding-top: 0.3rem;
}
.tu-hero-layout {
  display: grid;
  min-height: calc(100svh - 5rem);
  align-items: center;
  gap: clamp(2.5rem, 5vw, 4rem);
  padding-top: clamp(2.75rem, 6vh, 5rem);
  padding-bottom: clamp(2.75rem, 6vh, 5rem);
}
.tu-hero-copy {
  position: relative;
  z-index: 1;
}
.tu-hero-visual {
  min-width: 0;
  transform-origin: 50% 100%;
}
.tu-hero-collage {
  position: relative;
  display: grid;
  height: clamp(32rem, 54vw, 41rem);
  grid-template-columns: 0.9fr 1.08fr 0.9fr;
  align-items: center;
  gap: 0.65rem;
  overflow: hidden;
  padding: 1rem;
  border-radius: var(--radio-panel, 28px);
  background:
    radial-gradient(circle at 84% 18%, rgb(79 127 144 / 16%), transparent 32%),
    linear-gradient(145deg, #e9edf1 0%, #eef4f5 52%, #e3edef 100%);
}
.tu-hero-foto {
  position: relative;
  height: 82%;
  overflow: hidden;
  border: 1px solid rgb(255 255 255 / 72%);
  border-radius: 1.5rem;
  background: #fff;
  box-shadow: 0 1.2rem 3.5rem rgb(36 51 70 / 13%);
}
.tu-hero-foto--2 {
  height: 96%;
}
.tu-hero-foto--1 img {
  object-position: 65% center;
}
.tu-hero-foto--3 {
  height: 76%;
}
.tu-hero-foto img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
  transition: transform 1.1s cubic-bezier(0.22, 1, 0.36, 1);
}
.tu-hero-foto:hover img {
  transform: scale(1.045);
}
.tu-hero-foto > span {
  position: absolute;
  right: 0.55rem;
  bottom: 0.55rem;
  left: 0.55rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem 0.65rem;
  border: 1px solid rgb(255 255 255 / 48%);
  border-radius: 8px;
  background: rgb(14 22 32 / 58%);
  color: #fff;
  font-size: 0.72rem;
  font-weight: 600;
  backdrop-filter: blur(14px);
}
.tu-hero-reserva {
  position: absolute;
  z-index: 3;
  right: 1.4rem;
  bottom: 1.4rem;
  display: flex;
  max-width: 17rem;
  align-items: center;
  gap: 0.75rem;
  padding: 0.8rem 1rem;
  border: 1px solid rgb(255 255 255 / 72%);
  border-radius: 1.15rem;
  background: rgb(255 255 255 / 88%);
  color: #17212e;
  box-shadow: 0 1rem 2.8rem rgb(38 51 67 / 18%);
  backdrop-filter: blur(18px);
  animation: tu-reserva-flota 4.6s ease-in-out infinite;
}
.tu-hero-reserva-check {
  display: grid;
  width: 2.15rem;
  height: 2.15rem;
  flex: 0 0 auto;
  place-content: center;
  border-radius: 50%;
  background: #198754;
  color: #fff;
  font-weight: 700;
}
.tu-hero-reserva strong,
.tu-hero-reserva small {
  display: block;
}
.tu-hero-reserva strong {
  font-size: 0.84rem;
}
.tu-hero-reserva small {
  margin-top: 0.1rem;
  color: #5f6975;
  font-size: 0.7rem;
}
.tu-hero-foto--1 {
  animation: tu-foto-flota 7s ease-in-out infinite alternate;
}
.tu-hero-foto--3 {
  animation: tu-foto-flota 8s -3s ease-in-out infinite alternate-reverse;
}
@keyframes tu-foto-flota {
  to {
    transform: translateY(-0.65rem);
  }
}
@keyframes tu-reserva-flota {
  50% {
    transform: translateY(-0.45rem);
  }
}
.tu-hero-proof {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.45rem;
}
.tu-hero-flow {
  display: flex;
  justify-content: center;
  max-width: 42rem;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-top: 1.45rem;
  margin-inline: auto;
  padding: 0;
  list-style: none;
}
.tu-hero-flow li {
  display: inline-flex;
  align-items: center;
  gap: 0.42rem;
  padding: 0.42rem 0.62rem;
  border: 1px solid var(--marketing-operacion);
  border-radius: 8px;
  background: var(--marketing-operacion);
  color: #fff;
  font-size: 0.75rem;
  font-weight: 600;
}
.tu-hero-flow li span {
  display: grid;
  width: 1.28rem;
  height: 1.28rem;
  place-items: center;
  border-radius: 50%;
  background: var(--primario);
  color: #fff;
  font-size: 0.65rem;
}
.tu-enfasis-rosa {
  color: var(--marketing-rosa);
  font-weight: 700;
}

.tu-confianza {
  background: var(--fondo);
  padding: 0 0 2.5rem;
}
.tu-confianza-grid {
  display: grid;
  gap: 0.75rem;
  padding: 1.1rem 1.25rem;
  border-radius: var(--radio-tarjeta, 18px);
  background: var(--superficie);
}
.tu-confianza-item {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.55rem;
  color: var(--texto-suave);
  font-size: 0.82rem;
  font-weight: 600;
  text-align: center;
}
.tu-confianza-check {
  color: var(--exito);
  font-weight: 800;
}

/* Titulares ligeros; el contraste queda reservado para palabras clave. */
.tu-titulo {
  font-weight: 300;
  font-size: clamp(2.05rem, 4.35vw, 3.05rem);
  letter-spacing: -0.028em;
  line-height: 1.14;
}
.tu-display {
  font-weight: 300;
  font-size: clamp(2.8rem, 4.35vw, 4.125rem);
  letter-spacing: -0.04em;
  line-height: 1.12;
  text-wrap: balance;
}
.tu-titulo-final {
  font-size: clamp(1.95rem, 4.35vw, 2.625rem);
}
.tu-precio-titulo {
  font-size: 1.625rem;
  font-weight: 300;
}
.tu-eyebrow,
.tu-seccion-etiqueta {
  display: inline-block;
  color: var(--enlace);
  font-size: 0.82rem;
  font-weight: 600;
  letter-spacing: 0.01em;
}
.tu-seccion-etiqueta {
  color: var(--texto-suave);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

/* Radios de tarjetas separados de las imágenes y de los paneles grandes. */
.tu-landing .tu-card {
  border: 0;
  border-radius: var(--landing-radius);
}
.tu-resultados .tu-card,
.tu-pasos .tu-card,
.tu-landing details.tu-card {
  background: var(--fondo);
}
.tu-resultado-num {
  color: var(--texto-suave);
  font-size: 0.78rem;
  font-weight: 600;
  letter-spacing: 0.08em;
}
.tu-resultado-grafico {
  position: relative;
  display: flex;
  min-height: 11.5rem;
  margin: -0.2rem -0.2rem 1.7rem;
  overflow: hidden;
  border-radius: 1.4rem;
}
.tu-resultado-grafico--agenda {
  align-items: center;
  justify-content: space-around;
  background: #e7f3ff;
}
.tu-ocupacion-anillo {
  position: relative;
  display: grid;
  width: 7.2rem;
  aspect-ratio: 1;
  place-content: center;
  border-radius: 50%;
  background: conic-gradient(
    var(--primario) 0 92%,
    rgb(255 255 255 / 0.72) 92% 100%
  );
  text-align: center;
  animation: tu-grafico-flota 5s ease-in-out infinite;
}
.tu-ocupacion-anillo::before {
  position: absolute;
  inset: 0.62rem;
  border-radius: inherit;
  background: #f8fbfd;
  content: "";
}
.tu-ocupacion-anillo strong,
.tu-ocupacion-anillo span {
  position: relative;
  z-index: 1;
}
.tu-ocupacion-anillo strong {
  color: #12334f;
  font-size: 1.55rem;
  line-height: 1;
}
.tu-ocupacion-anillo span {
  margin-top: 0.25rem;
  color: #547089;
  font-size: 0.72rem;
}
.tu-asientos {
  display: grid;
  grid-template-columns: repeat(2, 1.7rem);
  gap: 0.55rem;
}
.tu-asientos span {
  height: 1.7rem;
  border-radius: 0.58rem;
  background: #61a7dc;
  box-shadow: inset 0 -4px rgb(0 58 112 / 0.12);
}
.tu-asientos span.libre {
  border: 1px dashed #8ba5b8;
  background: rgb(255 255 255 / 0.6);
  box-shadow: none;
  animation: tu-asiento-pulso 2.2s ease-in-out infinite;
}
.tu-resultado-grafico--cobros {
  flex-direction: column;
  justify-content: center;
  gap: 0.65rem;
  padding: 1.3rem;
  background: #e8f9fd;
}
.tu-pago-linea {
  display: grid;
  grid-template-columns: 2.25rem 1fr 1.8rem;
  align-items: center;
  gap: 0.7rem;
  min-height: 2.8rem;
  padding: 0 0.7rem;
  border: 1px solid rgb(3 27 78 / 8%);
  border-radius: 0.9rem;
  background: rgb(255 255 255 / 0.73);
  animation: tu-pago-entra 5s ease-in-out infinite;
}
.tu-pago-linea:nth-child(2) {
  animation-delay: -3.3s;
}
.tu-pago-linea:nth-child(3) {
  animation-delay: -1.6s;
}
.tu-pago-avatar {
  width: 1.8rem;
  height: 1.8rem;
  border-radius: 50%;
  background: #4f7f90;
}
.tu-pago-barra {
  width: 72%;
  height: 0.48rem;
  border-radius: 999px;
  background: #c9e6f5;
  box-shadow: 0 0.78rem #dceff8;
}
.tu-pago-check {
  display: grid;
  width: 1.55rem;
  height: 1.55rem;
  place-content: center;
  border-radius: 50%;
  background: #198754;
  color: #fff;
  font-size: 0.72rem;
  font-weight: 700;
}
.tu-resultado-grafico--control {
  align-items: flex-end;
  justify-content: space-between;
  padding: 1.35rem 1.45rem 1.2rem;
  background: #e9edf5;
}
.tu-mini-barras {
  display: flex;
  height: 8rem;
  align-items: flex-end;
  gap: 0.48rem;
}
.tu-mini-barras span {
  width: 0.78rem;
  height: 35%;
  border-radius: 999px;
  background: #031b4e;
  transform-origin: bottom;
  animation: tu-barra-respira 3.4s ease-in-out infinite alternate;
}
.tu-mini-barras span:nth-child(2) {
  height: 52%;
  animation-delay: -1.8s;
}
.tu-mini-barras span:nth-child(3) {
  height: 43%;
  animation-delay: -0.8s;
}
.tu-mini-barras span:nth-child(4) {
  height: 69%;
  animation-delay: -2.4s;
}
.tu-mini-barras span:nth-child(5) {
  height: 61%;
  animation-delay: -1.2s;
}
.tu-mini-barras span:nth-child(6) {
  height: 84%;
  animation-delay: -2.8s;
}
.tu-mini-barras span:nth-child(7) {
  height: 96%;
  animation-delay: -0.4s;
}
.tu-mini-tendencia {
  display: flex;
  align-items: flex-end;
  gap: 0.6rem;
  padding-bottom: 1.4rem;
}
.tu-mini-tendencia span {
  width: 0.56rem;
  height: 0.56rem;
  border: 2px solid #fff;
  border-radius: 50%;
  background: #00c6f2;
  box-shadow: 0 0 0 1px rgb(0 198 242 / 28%);
}
.tu-mini-tendencia span:nth-child(2) {
  transform: translateY(-0.8rem);
}
.tu-mini-tendencia span:nth-child(3) {
  transform: translateY(-0.35rem);
}
.tu-mini-tendencia span:nth-child(4) {
  transform: translateY(-1.65rem);
}
@keyframes tu-grafico-flota {
  50% {
    transform: translateY(-0.35rem) rotate(1deg);
  }
}
@keyframes tu-asiento-pulso {
  50% {
    opacity: 0.45;
    transform: scale(0.9);
  }
}
@keyframes tu-pago-entra {
  0%,
  18%,
  100% {
    transform: translateX(0);
    opacity: 1;
  }
  8% {
    transform: translateX(0.45rem);
    opacity: 0.72;
  }
}
@keyframes tu-barra-respira {
  to {
    transform: scaleY(0.72);
    opacity: 0.68;
  }
}

/* Fotografía de producto: el color vive en la imagen, no en la interfaz. */
.tu-imagen-marco {
  overflow: hidden;
  border-radius: var(--radio-imagen, 22px);
  background: var(--fondo);
}
.tu-imagen-marco img {
  display: block;
  width: 100%;
  height: auto;
  transition: transform 0.9s cubic-bezier(0.22, 1, 0.36, 1);
}
.tu-imagen-marco:hover img {
  transform: scale(1.012);
}
.tu-hero-visual.reveal-in .tu-imagen-marco {
  animation: tu-entrada-producto 1s cubic-bezier(0.22, 1, 0.36, 1) both;
}
.tu-imagen-cielo {
  background: #edf5fb;
}
.tu-imagen-rosa {
  background: #e7f8fc;
}
@keyframes tu-entrada-producto {
  from {
    transform: translateY(24px) scale(0.985);
  }
  to {
    transform: none;
  }
}

/* Ventana de app (mockup). */
.tu-ventana {
  border: 1px solid var(--borde);
  border-radius: var(--radio-panel, 28px);
  overflow: hidden;
  background: var(--superficie);
}
.tu-ventana-barra {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  background: var(--fondo);
  border-bottom: 1px solid var(--borde);
}
.tu-punto {
  height: 0.75rem;
  width: 0.75rem;
  border-radius: 9999px;
}

/* Barra de ocupación que se llena al revelarse. */
.tu-barra {
  height: 6px;
  border-radius: 9999px;
  background: var(--borde);
  overflow: hidden;
}
.tu-barra-fill {
  display: block;
  height: 100%;
  width: 0;
  border-radius: 9999px;
}
.reveal-in .tu-barra-fill {
  width: var(--pct);
  transition: width 1.1s cubic-bezier(0.22, 1, 0.36, 1);
}

/* Punto "en vivo" pulsante. */
.tu-vivo {
  height: 7px;
  width: 7px;
  border-radius: 9999px;
  background: var(--exito);
  box-shadow: 0 0 0 0 color-mix(in srgb, var(--exito) 60%, transparent);
  animation: tu-pulso 1.8s ease-out infinite;
}
@keyframes tu-pulso {
  0% {
    box-shadow: 0 0 0 0 color-mix(in srgb, var(--exito) 55%, transparent);
  }
  70% {
    box-shadow: 0 0 0 7px transparent;
  }
  100% {
    box-shadow: 0 0 0 0 transparent;
  }
}

/* Número de paso. */
.tu-paso-num {
  height: 2.5rem;
  width: 2.5rem;
  border-radius: 9999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 1.1rem;
  background: var(--texto);
  color: var(--superficie);
}

/* Caja de icono de función. */

.tu-check-item {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  color: var(--texto);
  font-size: 1rem;
}
.tu-check {
  display: inline-flex;
  height: 2rem;
  width: 2rem;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  background: var(--fondo);
  color: var(--texto);
}
.tu-link-flecha {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  color: var(--enlace);
  font-size: 1.05rem;
  font-weight: 500;
  text-decoration: none;
}
.tu-link-flecha:hover {
  text-decoration: underline;
}

/* La suscripción y los cobros a clientes se explican por separado. */
.tu-precio-modelos {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1.5rem;
}
.tu-precio-nota {
  padding-top: 1.25rem;
  border-top: 1px solid var(--borde);
  color: var(--texto-suave);
  font-size: 0.85rem;
  line-height: 1.6;
}
.tu-precio-prueba {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 1.5rem;
  padding: 1.5rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta, 18px);
}
@media (max-width: 639px) {
  .tu-precio-modelos {
    grid-template-columns: 1fr;
  }
  .tu-precio-prueba .tu-btn {
    width: 100%;
    justify-content: center;
  }
}
.tu-precio-card {
  padding: clamp(1.75rem, 4vw, 3rem);
  border-radius: var(--landing-radius);
  background: var(--superficie);
}
.tu-precio-badge {
  display: inline-flex;
  padding: 0.45rem 0.75rem;
  border-radius: 8px;
  background: var(--primario-suave);
  color: var(--enlace);
  font-size: 0.82rem;
  font-weight: 700;
}

/* Vista pública del negocio: prueba visual del enlace de reservas. */
.tu-escaparate-demo {
  overflow: hidden;
  border-radius: var(--radio-panel, 28px);
  background: var(--fondo);
}
.tu-escaparate-cabecera {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 1.5rem;
  background: #e7f3ff;
}
.tu-escaparate-logo {
  display: inline-flex;
  height: 3.25rem;
  width: 3.25rem;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  border-radius: 1rem;
  background: #031b4e;
  color: #fff;
  font-size: 0.85rem;
  font-weight: 800;
}
.tu-escaparate-contenido {
  padding: 1.5rem;
}
.tu-clase-publica {
  display: grid;
  grid-template-columns: 3.4rem minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.9rem;
  padding: 0.9rem;
  border-radius: 1.15rem;
  background: var(--superficie);
}
.tu-clase-fecha {
  display: flex;
  flex-direction: column;
  color: var(--texto);
  font-size: 0.78rem;
  line-height: 1.25;
}
.tu-demo-boton {
  display: flex;
  min-height: 2.75rem;
  align-items: center;
  justify-content: center;
  border-radius: var(--radio-boton, 11px);
  background: var(--primario);
  color: #fff;
  font-size: 0.9rem;
  font-weight: 600;
}

/* Mosaico editorial por disciplina. */
.tu-disciplinas {
  overflow: hidden;
}
.tu-alcance-salud {
  padding: 1rem 1.15rem;
  border: 1px solid color-mix(in srgb, var(--primario) 16%, var(--borde));
  border-left: 3px solid var(--acento);
  border-radius: 1rem;
  background: color-mix(in srgb, var(--superficie) 78%, transparent);
  color: var(--texto-suave);
  font-size: 0.88rem;
  line-height: 1.6;
}
.tu-alcance-salud strong {
  color: var(--texto);
}
/* Reveal on scroll. */
.reveal {
  opacity: 1;
  transform: none;
  transition:
    opacity 0.7s ease,
    transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
  will-change: opacity, transform;
}
.reveal-in {
  opacity: 1;
  transform: none;
  animation: entrada-contenido 0.7s ease both;
}
@keyframes entrada-contenido {
  from {
    transform: translateY(18px);
  }
  to {
    transform: none;
  }
}

details > summary::-webkit-details-marker {
  display: none;
}
details[open] > summary > span {
  transform: rotate(45deg);
  display: inline-block;
  transition: transform 0.15s ease;
}

@media (prefers-reduced-motion: reduce) {
  .reveal-in {
    animation: none;
  }
  .reveal,
  .tu-barra-fill,
  .tu-imagen-marco img {
    transition: none;
  }
  .tu-vivo,
  .tu-hero-visual.reveal-in .tu-imagen-marco,
  .tu-hero-foto--1,
  .tu-hero-foto--3,
  .tu-hero-reserva,
  .tu-ocupacion-anillo,
  .tu-asientos span.libre,
  .tu-pago-linea,
  .tu-mini-barras span {
    animation: none;
  }
}

@media (min-width: 1024px) {
  .tu-hero-layout {
    grid-template-columns: minmax(0, 1.08fr) minmax(0, 0.92fr);
  }
  .tu-confianza-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (max-width: 1023px) {
  .tu-hero-layout {
    min-height: auto;
    text-align: center;
  }
  .tu-hero-sub {
    margin-inline: auto;
  }
  .tu-hero-actions {
    justify-content: center;
  }
  .tu-hero-proof {
    justify-content: center;
  }
  .tu-hero-visual {
    width: min(100%, 48rem);
    margin-inline: auto;
  }
  .tu-hero-collage {
    height: min(41rem, 72vw);
  }
}

@media (max-width: 639px) {
  .tu-display {
    font-size: clamp(2.3rem, 10.4vw, 2.85rem);
  }
  .tu-imagen-marco {
    border-radius: 20px;
  }
  .tu-hero-layout {
    gap: 1.75rem;
    padding-top: 2rem;
    padding-bottom: 3rem;
  }
  .tu-hero-sub {
    margin-top: 1.25rem;
    font-size: 1.05rem;
    line-height: 1.45;
  }
  .tu-hero-actions {
    margin-top: 1.75rem;
  }
  .tu-hero-proof {
    margin-top: 0.8rem;
  }
  .tu-hero-collage {
    height: 27rem;
    gap: 0.4rem;
    padding: 0.6rem;
    border-radius: 1.5rem;
  }
  .tu-hero-foto {
    border-radius: 1.1rem;
  }
  .tu-hero-foto > span {
    right: 0.3rem;
    bottom: 0.3rem;
    left: 0.3rem;
    padding: 0.38rem 0.3rem;
    font-size: 0.6rem;
  }
  .tu-hero-reserva {
    right: 0.85rem;
    bottom: 0.85rem;
    max-width: 13.5rem;
    padding: 0.62rem 0.7rem;
  }
  .tu-confianza {
    padding-bottom: 1.5rem;
  }
  .tu-confianza-grid {
    grid-template-columns: 1fr 1fr;
    border-radius: var(--radio-tarjeta, 18px);
  }
  .tu-clase-publica {
    grid-template-columns: 3.1rem minmax(0, 1fr);
  }
  .tu-clase-publica > .tu-badge {
    display: none;
  }
}
</style>
