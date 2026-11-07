<script setup lang="ts">
import { usePreciosPublicos } from "@/marketing/preciosPublicos";
import { computed, onMounted, ref, type Component } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import ArranqueYReferidos from "@/components/ArranqueYReferidos.vue";
import AyudaParaEmpezar from "@/components/AyudaParaEmpezar.vue";
import CarruselNegocios from "@/components/CarruselNegocios.vue";
import CasosPiloto from "@/components/CasosPiloto.vue";
import DemoPaginaPublicaCitas from "@/components/DemoPaginaPublicaCitas.vue";
import DemoPaginaPublicaClases from "@/components/DemoPaginaPublicaClases.vue";
import FuncionesLanding from "@/components/FuncionesLanding.vue";
import HeroCollage, { type FotoCollage } from "@/components/HeroCollage.vue";
import FondoHero from "@/components/FondoHero.vue";
import LogoProducto from "@/components/LogoProducto.vue";
import ModulosLanding from "@/components/ModulosLanding.vue";
import TestimoniosLanding from "@/components/TestimoniosLanding.vue";
import IconoNav from "@/components/IconoNav.vue";
import NegociosAnimados from "@/components/NegociosAnimados.vue";
import NovedadesLanding from "@/components/NovedadesLanding.vue";
import PreciosLanding from "@/components/PreciosLanding.vue";
import PreguntasFrecuentes from "@/components/PreguntasFrecuentes.vue";
import ProductoDemo from "@/components/ProductoDemo.vue";
import ReemplazaHerramientas from "@/components/ReemplazaHerramientas.vue";
import RecorridoDemo from "@/components/RecorridoDemo.vue";
import SellosConfianza from "@/components/SellosConfianza.vue";
import TextoDestacado from "@/components/TextoDestacado.vue";
import { trackEvent } from "@/lib/analytics";
import { useDemosActivas } from "@/lib/demos";
import { CASOS } from "@/marketing/casos";
import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import { contarVisitaLanding } from "@/lib/visitaLanding";
import { useRegistroDelProducto } from "@/marketing/registroProducto";
import { useRevelar } from "@/lib/revelar";
import {
  MODALIDADES,
  NOMBRE_RUTA_MODALIDAD,
  imagenNegocio,
  perfilDeNegocio,
  solucionDeNegocio,
  type Modo,
} from "@/marketing/modalidades";
import "@/marketing/landing.css";

/*
| Landing completa de una modalidad: /clases y /citas montan esta misma vista con su
| `modo` (prop de ruta). Habla SOLO de su modalidad; el contenido se declara en
| `MODALIDADES[modo]` (claves) y sus textos van en `landing.clases.*` /
| `landing.citas.*` (lo común a las dos, en `landing.modalidad.*`).
| Se prerenderiza (entry-marketing): `window`, `matchMedia` y `localStorage` solo en
| `onMounted`. La raíz lleva `:key` por modo: no arrastra estado entre /clases y
| /citas aunque se reutilice la vista.
|
| Bandas: alternan gris (`--fondo`) y blanco (`--superficie`); las de componentes con
| tarjetas blancas (funciones, precios, carrusel) van en gris. Los sellos de confianza
| continúan la banda gris del hero. Las funciones avanzadas se consultan a demanda.
*/
const props = defineProps<{ modo: Modo }>();
const { t } = useI18n();
// Embudo de la plataforma (C08): una visita anónima por día a esta landing.
onMounted(() => contarVisitaLanding(productoDeModalidad(props.modo)));

// Los días de prueba de su modalidad: los que publica el superadmin (con respaldo).
const precios = usePreciosPublicos();
const DIAS_PRUEBA = computed(() => precios.datos[props.modo].dias_prueba);
// Un producto cuyo registro cerró el superadmin (prelanzamiento, ADR 0108):
// sus botones llevan a dejar los datos y nada ofrece prueba, registro ni contratar.
const { abierto: registroAbierto } = useRegistroDelProducto(
  productoDeModalidad(props.modo),
);
const textoRegistro = computed(() =>
  registroAbierto.value
    ? t("landing.modalidad.probar")
    : t("landing.prelanzamiento.cta"),
);
const SUAVE = { color: "var(--texto-suave)" };
// En una banda gris, las tarjetas y los círculos de la banda van en blanco.
const BANDA_FONDO = {
  background: "var(--fondo)",
  "--sobre-banda": "var(--superficie)",
};
const BANDA_SUPERFICIE = { background: "var(--superficie)" };
// La página pública de ejemplo de cada modalidad.
const DEMO_PAGINA: Record<Modo, Component> = {
  clases: DemoPaginaPublicaClases,
  citas: DemoPaginaPublicaCitas,
};
// Fotos cuyo sujeto no está al centro.
const POSICION_FOTO: Record<string, string> = { pole: "73% center" };

const raiz = ref<HTMLElement>();
useRevelar(raiz);

const contenido = computed(() => MODALIDADES[props.modo]);
/** Clave de i18n de esta modalidad: `landing.{modo}.{resto}`. */
const k = (resto: string): string => `landing.${props.modo}.${resto}`;
/** Un botón al registro con su propio texto; sin registro abierto, el de la lista. */
const alRegistro = (resto: string): string =>
  registroAbierto.value ? t(k(resto)) : t("landing.prelanzamiento.cta");
const registro = computed(() => ({
  name: "registro",
  query: { modo: props.modo },
}));
/** El registro de esta modalidad y, si se conoce, con su giro (`?giro=`). */
function registroCon(giro: string | null) {
  return giro === null
    ? registro.value
    : { name: "registro", query: { modo: props.modo, giro } };
}
const otra = computed(() => ({
  name: NOMBRE_RUTA_MODALIDAD[contenido.value.otra],
}));
// La otra modalidad es otro producto, en su propio dominio (ADR 0108).
const nombreOtro = computed(
  () => PRODUCTOS[productoDeModalidad(contenido.value.otra)].nombre,
);
// Publicada, el enlace va a la portada del otro dominio (sin JavaScript y para los
// buscadores, `/citas` en agendauno.mx caería en la aplicación sin indexar). En
// desarrollo se queda en la misma, para verla.
const otraUrl = computed(() =>
  import.meta.env.PROD
    ? `https://${PRODUCTOS[productoDeModalidad(contenido.value.otra)].dominio}/`
    : null,
);
const conAsterisco = (textos: readonly string[]): boolean =>
  textos.some((texto) => texto.includes("*"));

const negociosAnimados = computed(() =>
  contenido.value.hero.escritura.map((clave) =>
    t(`landing.heroEscritura.negocios.${clave}`),
  ),
);

function negocio(clave: string) {
  return {
    clave,
    nombre: t(`landing.paraQuien.negocios.${clave}.nombre`),
    descripcion: t(`landing.paraQuien.negocios.${clave}.descripcion`),
    alt: t(`landing.paraQuien.negocios.${clave}.alt`),
    src: imagenNegocio(clave),
    href: solucionDeNegocio(clave),
  };
}
const negocios = computed(() => contenido.value.negocios.map(negocio));
const fotosHero = computed<FotoCollage[]>(() =>
  contenido.value.hero.collage.map((clave) => {
    const n = negocio(clave);
    return {
      clave,
      src: n.src,
      alt: n.alt,
      etiqueta: n.nombre,
      posicion: POSICION_FOTO[clave],
    };
  }),
);

const pasos = computed(() =>
  contenido.value.pasos.map((paso) => ({
    clave: paso,
    titulo: t(k(`pasos.${paso}.titulo`)),
    texto: t(k(`pasos.${paso}.texto`)),
    ejemplo: t(k(`pasos.${paso}.ejemplo`)),
    linea: t(k(`pasos.${paso}.linea`)),
    estado: t(k(`pasos.${paso}.estado`)),
  })),
);
// Lo demás de recepción, en una línea bajo el recorrido.
const beneficiosOperacion = computed(() =>
  contenido.value.operacion.map((clave) => ({
    clave,
    texto: t(k(`operacion.beneficios.${clave}`)),
  })),
);
const beneficiosPagina = computed(() =>
  contenido.value.pagina.map((clave) => ({
    clave,
    texto: t(k(`pagina.beneficios.${clave}`)),
  })),
);
const preguntas = computed(() => {
  const soporte = PRODUCTOS[productoDeModalidad(props.modo)].soporte;
  return contenido.value.preguntas.map((clave) => ({
    clave,
    pregunta: t(k(`faq.${clave}.q`)),
    // El canal de soporte de la marca (C01); a las demás respuestas no les afecta. Sin
    // WhatsApp configurado, la respuesta sin él (no un número de relleno).
    respuesta: t(
      k(
        clave === "soporte" && soporte.whatsapp === ""
          ? `faq.${clave}.aSinWhatsapp`
          : `faq.${clave}.a`,
      ),
      // Lo que cambia con la tarifa publicada, de la misma fuente que la tabla.
      { ...soporte, mesesAnual: precios.datos.citas.meses_anual },
    ),
  }));
});

// Casos reales de los pilotos (autorizados); sin ellos, la sección no aparece.
const casos = computed(() => CASOS[props.modo]);
const pestanaPrecios = ref<"reemplaza" | "aparte">("reemplaza");
const totalPreguntasFaq = computed(() =>
  contenido.value.categoriasFaq.reduce((acc, c) => acc + c.preguntas.length, 0),
);

// La nota de México (`landing.soloMexico`) solo donde algo lleva el asterisco.
const notas = computed(() => ({
  pasos: conAsterisco(pasos.value.map((p) => p.texto)),
  operacion: conAsterisco(beneficiosOperacion.value.map((b) => b.texto)),
  pagina: conAsterisco([
    t(k("pagina.subtitulo")),
    ...beneficiosPagina.value.map((b) => b.texto),
  ]),
  giros: conAsterisco(negocios.value.map((n) => n.descripcion)),
  preguntas: conAsterisco(
    preguntas.value.flatMap((p) => [p.pregunta, p.respuesta]),
  ),
}));

// Sin registro abierto, los botones llevan a la lista de interesados (el registro la
// muestra): se miden como `waitlist`.
function medirRegistro(placement: string, giro: string | null = null): void {
  trackEvent("marketing_cta_clicked", {
    placement,
    destination: registroAbierto.value ? "register" : "waitlist",
    mode: props.modo,
    ...(giro === null ? {} : { business_profile: giro }),
  });
}
// «Agendar una demo» (ADR 0204): solo si la plataforma agenda demos; lleva a su
// página con el producto de esta portada.
const demosActivas = useDemosActivas();
const demo = computed(() => ({
  name: "demo",
  query: { producto: productoDeModalidad(props.modo) },
}));
function medirDemo(placement: string): void {
  trackEvent("marketing_cta_clicked", {
    placement,
    destination: "demo",
    mode: props.modo,
  });
}
function medirProducto(): void {
  trackEvent("marketing_cta_clicked", {
    placement: "hero",
    destination: "product",
    mode: props.modo,
  });
}
function medirOtra(): void {
  trackEvent("marketing_business_mode_selected", {
    mode: contenido.value.otra,
    placement: "mode_page_footer",
  });
}
</script>

<template>
  <div :key="modo" ref="raiz" class="tu-landing tu-modalidad" :data-modo="modo">
    <!-- ===================== HERO ===================== -->
    <section class="tu-banda tu-hero" :style="BANDA_FONDO">
      <FondoHero />
      <div class="tu-hero-layout mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="tu-hero-copy reveal">
          <p class="tu-seccion-etiqueta tu-modalidad-nombre">
            {{ t(k("hero.etiqueta")) }}
          </p>
          <NegociosAnimados
            :negocios="negociosAnimados"
            :prefijo="t('landing.heroEscritura.prefijo')"
            :pausar="t('landing.heroEscritura.pausar')"
            :reanudar="t('landing.heroEscritura.reanudar')"
          />
          <h1 class="tu-display max-w-3xl">
            <TextoDestacado
              :texto="t(k('hero.titulo'))"
              :enfasis="t(k('hero.enfasis'))"
            />
          </h1>
          <p
            class="tu-hero-sub mt-2 text-base sm:text-lg max-w-xl"
            style="color: var(--texto-suave); letter-spacing: -0.01em"
          >
            {{ t(k("hero.subtitulo")) }}
          </p>
          <!-- Una misma acción principal y un mismo color en toda la landing. -->
          <div class="tu-hero-actions mt-3.5 flex flex-wrap items-center gap-3">
            <RouterLink
              class="tu-btn tu-btn-primario text-base px-6 py-2.5"
              :to="registro"
              data-cta="hero"
              @click="medirRegistro('hero')"
            >
              {{ textoRegistro }}
            </RouterLink>
            <a
              class="tu-btn tu-btn-secundario text-base px-6 py-2.5"
              href="#producto"
              @click="medirProducto"
            >
              {{ t("landing.modalidad.verAgenda") }}
            </a>
          </div>
          <p class="tu-hero-proof mt-2.5 text-xs sm:text-sm" :style="SUAVE">
            {{
              registroAbierto
                ? t("landing.prueba", { dias: DIAS_PRUEBA })
                : t("landing.prelanzamiento.proximamente")
            }}
            <span aria-hidden="true">·</span>
            {{
              registroAbierto
                ? t("landing.pieHero")
                : t("landing.prelanzamiento.pieHero")
            }}
          </p>
        </div>
        <HeroCollage
          :fotos="fotosHero"
          :aviso="{
            titulo: t(k('hero.avisoTitulo')),
            detalle: t(k('hero.avisoDetalle')),
          }"
          :nota="t('landing.modalidad.heroNota')"
        />
      </div>
    </section>

    <!-- ===================== CONFIANZA ===================== -->
    <SellosConfianza :modo="modo" />

    <!-- ===================== PRODUCTO (Patrón A: Recuadros TL + BR · Órbita BL) ===================== -->
    <section id="producto" class="tu-banda tu-ancla" :style="BANDA_SUPERFICIE">
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 640"
        fill="none"
        aria-hidden="true"
      >
        <defs>
          <linearGradient
            id="tu-rombo-rosa-grad"
            x1="0%"
            y1="20%"
            x2="100%"
            y2="80%"
          >
            <stop offset="0%" stop-color="#bfdbfe" stop-opacity="0.34" />
            <stop offset="48%" stop-color="#e9d5ff" stop-opacity="0.38" />
            <stop offset="100%" stop-color="#f9a8d4" stop-opacity="0.48" />
          </linearGradient>
          <linearGradient
            id="tu-rombo-azul-grad"
            x1="0%"
            y1="0%"
            x2="100%"
            y2="100%"
          >
            <stop offset="0%" stop-color="#7dd3fc" stop-opacity="0.38" />
            <stop offset="52%" stop-color="#bae6fd" stop-opacity="0.34" />
            <stop offset="100%" stop-color="#ddd6fe" stop-opacity="0.3" />
          </linearGradient>
          <linearGradient
            id="tu-rombo-lila-grad"
            x1="0%"
            y1="10%"
            x2="100%"
            y2="90%"
          >
            <stop offset="0%" stop-color="#c4b5fd" stop-opacity="0.34" />
            <stop offset="50%" stop-color="#fbcfe8" stop-opacity="0.38" />
            <stop offset="100%" stop-color="#bae6fd" stop-opacity="0.32" />
          </linearGradient>
          <linearGradient
            id="tu-orbita-der-sup"
            x1="0%"
            y1="0%"
            x2="100%"
            y2="0%"
          >
            <stop offset="0%" stop-color="#93c5fd" stop-opacity="0.06" />
            <stop offset="24%" stop-color="#38bdf8" stop-opacity="0.72" />
            <stop offset="68%" stop-color="#c084fc" stop-opacity="0.76" />
            <stop offset="100%" stop-color="#f472b6" stop-opacity="0.18" />
          </linearGradient>
          <linearGradient
            id="tu-orbita-izq-inf"
            x1="0%"
            y1="0%"
            x2="100%"
            y2="0%"
          >
            <stop offset="0%" stop-color="#60a5fa" stop-opacity="0.18" />
            <stop offset="48%" stop-color="#38bdf8" stop-opacity="0.74" />
            <stop offset="85%" stop-color="#c084fc" stop-opacity="0.62" />
            <stop offset="100%" stop-color="#f472b6" stop-opacity="0.06" />
          </linearGradient>
          <linearGradient
            id="tu-orbita-izq-sup"
            x1="0%"
            y1="0%"
            x2="100%"
            y2="0%"
          >
            <stop offset="0%" stop-color="#38bdf8" stop-opacity="0.18" />
            <stop offset="35%" stop-color="#38bdf8" stop-opacity="0.76" />
            <stop offset="76%" stop-color="#c084fc" stop-opacity="0.66" />
            <stop offset="100%" stop-color="#f472b6" stop-opacity="0.06" />
          </linearGradient>
          <linearGradient
            id="tu-orbita-der-inf"
            x1="0%"
            y1="0%"
            x2="100%"
            y2="0%"
          >
            <stop offset="0%" stop-color="#c084fc" stop-opacity="0.06" />
            <stop offset="38%" stop-color="#f472b6" stop-opacity="0.74" />
            <stop offset="82%" stop-color="#ec4899" stop-opacity="0.7" />
            <stop offset="100%" stop-color="#fbcfe8" stop-opacity="0.18" />
          </linearGradient>
          <radialGradient
            id="tu-esfera-azul-3d"
            cx="34%"
            cy="28%"
            r="68%"
            fx="28%"
            fy="24%"
          >
            <stop offset="0%" stop-color="#ffffff" />
            <stop offset="32%" stop-color="#bae6fd" />
            <stop offset="72%" stop-color="#38bdf8" />
            <stop offset="100%" stop-color="#0284c7" />
          </radialGradient>
          <radialGradient
            id="tu-esfera-rosa-3d"
            cx="34%"
            cy="28%"
            r="68%"
            fx="28%"
            fy="24%"
          >
            <stop offset="0%" stop-color="#ffffff" />
            <stop offset="32%" stop-color="#fbcfe8" />
            <stop offset="72%" stop-color="#f472b6" />
            <stop offset="100%" stop-color="#db2777" />
          </radialGradient>
        </defs>
        <rect
          class="tu-rombo-flota tu-rombo-flota--alt"
          x="-112"
          y="96"
          width="162"
          height="162"
          rx="32"
          transform="rotate(-24 -31 177)"
          fill="url(#tu-rombo-lila-grad)"
          stroke="#c4b5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.74"
        />
        <rect
          class="tu-rombo-flota"
          x="1398"
          y="375"
          width="152"
          height="152"
          rx="28"
          transform="rotate(-18 1474 451)"
          fill="url(#tu-rombo-azul-grad)"
          stroke="#93c5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.7"
        />
        <g opacity="0.42">
          <path
            d="M -40 485 C 100 400, 270 425, 420 560"
            stroke="url(#tu-orbita-izq-inf)"
            stroke-opacity="0.15"
            stroke-width="4.8"
            stroke-linecap="round"
          />
          <path
            d="M -40 485 C 100 400, 270 425, 420 560"
            stroke="url(#tu-orbita-izq-inf)"
            stroke-width="2.1"
            stroke-linecap="round"
          />
        </g>
        <circle r="7.6" fill="url(#tu-esfera-azul-3d)" opacity="0.52">
          <animateMotion
            path="M -40 485 C 100 400, 270 425, 420 560"
            dur="8.8s"
            repeatCount="indefinite"
            keyPoints="0.22;0.68;0.22"
            keyTimes="0;0.5;1"
            calcMode="spline"
            keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
          />
        </circle>
      </svg>
      <div
        class="mx-auto max-w-5xl px-4 sm:px-6 pt-14 sm:pt-18"
        data-scroll="cabecera"
      >
        <p class="tu-seccion-etiqueta reveal">
          {{ t("landing.modalidad.demoEtiqueta") }}
        </p>
        <h2 class="tu-titulo mt-3 max-w-3xl reveal">
          <TextoDestacado
            :texto="t(k('producto.titulo'))"
            :enfasis="t(k('producto.enfasis'))"
            tono="rosa"
            negrita
          />
        </h2>
        <p class="mt-4 text-lg max-w-2xl reveal" :style="SUAVE">
          {{ t(k("producto.subtitulo")) }}
        </p>
      </div>
      <div class="mx-auto max-w-6xl px-4 sm:px-6 pt-8 pb-14 sm:pb-18">
        <div data-scroll="escala">
          <ProductoDemo :modo="modo" />
        </div>
        <div class="mt-8 text-center">
          <RouterLink
            class="tu-btn tu-btn-primario px-7 py-3"
            :to="registro"
            data-cta="product_demo"
            @click="medirRegistro('product_demo')"
          >
            {{ alRegistro("producto.cta") }}
          </RouterLink>
          <p class="mt-3 text-sm" :style="SUAVE">
            {{
              registroAbierto
                ? t("landing.modalidad.demoPie", { dias: DIAS_PRUEBA })
                : t("landing.prelanzamiento.demoPie")
            }}
          </p>
        </div>
      </div>
    </section>

    <!-- ===================== FUNCIONES (Patrón B: Recuadros TR + BL · Órbita BR) ===================== -->
    <section id="soluciones" class="tu-banda tu-ancla" :style="BANDA_FONDO">
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 640"
        fill="none"
        aria-hidden="true"
      >
        <rect
          class="tu-rombo-flota"
          x="1390"
          y="96"
          width="162"
          height="162"
          rx="32"
          transform="rotate(24 1471 177)"
          fill="url(#tu-rombo-rosa-grad)"
          stroke="#f9a8d4"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.74"
        />
        <rect
          class="tu-rombo-flota tu-rombo-flota--alt"
          x="-110"
          y="375"
          width="152"
          height="152"
          rx="28"
          transform="rotate(18 -34 451)"
          fill="url(#tu-rombo-azul-grad)"
          stroke="#93c5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.7"
        />
        <g opacity="0.42">
          <path
            d="M 1020 560 C 1170 425, 1340 400, 1480 485"
            stroke="url(#tu-orbita-der-inf)"
            stroke-opacity="0.15"
            stroke-width="4.8"
            stroke-linecap="round"
          />
          <path
            d="M 1020 560 C 1170 425, 1340 400, 1480 485"
            stroke="url(#tu-orbita-der-inf)"
            stroke-width="2.1"
            stroke-linecap="round"
          />
        </g>
        <circle r="7.6" fill="url(#tu-esfera-rosa-3d)" opacity="0.52">
          <animateMotion
            path="M 1020 560 C 1170 425, 1340 400, 1480 485"
            dur="8.8s"
            repeatCount="indefinite"
            keyPoints="0.32;0.78;0.32"
            keyTimes="0;0.5;1"
            calcMode="spline"
            keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
          />
        </circle>
      </svg>
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-14 sm:py-18">
        <div data-scroll="cabecera">
          <h2 class="tu-titulo reveal">
            <TextoDestacado
              :texto="t(k('seccion.titulo'))"
              :enfasis="t(k('seccion.enfasis'))"
              negrita
            />
          </h2>
          <p class="mt-3 text-lg max-w-2xl reveal" :style="SUAVE">
            {{ t(k("seccion.subtitulo")) }}
          </p>
        </div>
        <ModulosLanding :modo="modo" class="mt-8" />
        <details class="tu-funciones-avanzadas">
          <summary>
            <span
              ><strong>Explora todas las funcionalidades</strong
              ><small
                >Detalles de operación, herramientas avanzadas e
                integraciones.</small
              ></span
            ><span class="tu-avanzadas-signo" aria-hidden="true">+</span>
          </summary>
          <div class="px-5 pb-5 sm:px-8"><FuncionesLanding :modo="modo" /></div>
          <NovedadesLanding :modo="modo">
            <RouterLink
              class="tu-btn tu-btn-primario px-7 py-3"
              :to="registro"
              data-cta="whats_new"
              @click="medirRegistro('whats_new')"
            >
              {{ alRegistro("novedades.cta") }}
            </RouterLink>
          </NovedadesLanding>
        </details>
      </div>
    </section>

    <!-- ===================== CÓMO FUNCIONA (Patrón A: Recuadros TL + BR · Órbita BL) ===================== -->
    <section
      id="como-funciona"
      class="tu-banda tu-ancla"
      :style="BANDA_SUPERFICIE"
    >
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 640"
        fill="none"
        aria-hidden="true"
      >
        <rect
          class="tu-rombo-flota tu-rombo-flota--alt"
          x="-112"
          y="96"
          width="162"
          height="162"
          rx="32"
          transform="rotate(-24 -31 177)"
          fill="url(#tu-rombo-lila-grad)"
          stroke="#c4b5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.74"
        />
        <rect
          class="tu-rombo-flota"
          x="1398"
          y="375"
          width="152"
          height="152"
          rx="28"
          transform="rotate(-18 1474 451)"
          fill="url(#tu-rombo-azul-grad)"
          stroke="#93c5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.7"
        />
        <g opacity="0.42">
          <path
            d="M -40 485 C 100 400, 270 425, 420 560"
            stroke="url(#tu-orbita-izq-inf)"
            stroke-opacity="0.15"
            stroke-width="4.8"
            stroke-linecap="round"
          />
          <path
            d="M -40 485 C 100 400, 270 425, 420 560"
            stroke="url(#tu-orbita-izq-inf)"
            stroke-width="2.1"
            stroke-linecap="round"
          />
        </g>
        <circle r="7.6" fill="url(#tu-esfera-azul-3d)" opacity="0.52">
          <animateMotion
            path="M -40 485 C 100 400, 270 425, 420 560"
            dur="8.8s"
            repeatCount="indefinite"
            keyPoints="0.22;0.68;0.22"
            keyTimes="0;0.5;1"
            calcMode="spline"
            keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
          />
        </circle>
      </svg>
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-14 sm:py-18">
        <div class="tu-cabecera-con-monograma" data-scroll="cabecera">
          <div class="tu-cabecera-monograma-texto">
            <h2 class="tu-titulo reveal">
              {{
                registroAbierto
                  ? t("landing.modalidad.pasosTitulo")
                  : t("landing.prelanzamiento.pasosTitulo")
              }}
            </h2>
            <p class="mt-3 text-lg max-w-2xl reveal" :style="SUAVE">
              {{ t(k("comoFunciona.subtitulo")) }}
            </p>
          </div>
        </div>
        <ol class="tu-pasos mt-8 grid gap-5 md:grid-cols-3" role="list">
          <li
            v-for="(paso, i) in pasos"
            :key="paso.clave"
            class="tu-card p-6 reveal"
            :data-paso="paso.clave"
            data-scroll="acomodo"
          >
            <div class="tu-paso-top">
              <span class="tu-paso-num" aria-hidden="true">{{ i + 1 }}</span>
              <span class="tu-paso-trazo" aria-hidden="true" />
            </div>
            <h3 class="mt-4 text-xl font-medium tracking-tight">
              {{ paso.titulo }}
            </h3>
            <p class="mt-2" :style="SUAVE">{{ paso.texto }}</p>
            <div class="tu-paso-ejemplo">
              <span class="tu-paso-ejemplo-label">{{
                t("landing.modalidad.ejemplo")
              }}</span>
              <strong>{{ paso.ejemplo }}</strong>
              <span>{{ paso.linea }}</span>
              <span class="tu-paso-status">{{ paso.estado }}</span>
            </div>
          </li>
        </ol>
        <p v-if="notas.pasos" class="tu-nota-mexico">
          {{ t("landing.soloMexico") }}
        </p>
        <AyudaParaEmpezar :modo="modo" class="reveal" />
      </div>
    </section>

    <!-- ===================== GIROS (Patrón B: Recuadros TR + BL · Órbita BR) ===================== -->
    <section
      id="para-quien"
      class="tu-banda tu-ancla tu-modalidad-giros"
      :style="BANDA_FONDO"
    >
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 640"
        fill="none"
        aria-hidden="true"
      >
        <rect
          class="tu-rombo-flota"
          x="1390"
          y="96"
          width="162"
          height="162"
          rx="32"
          transform="rotate(24 1471 177)"
          fill="url(#tu-rombo-rosa-grad)"
          stroke="#f9a8d4"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.74"
        />
        <rect
          class="tu-rombo-flota tu-rombo-flota--alt"
          x="-110"
          y="375"
          width="152"
          height="152"
          rx="28"
          transform="rotate(18 -34 451)"
          fill="url(#tu-rombo-azul-grad)"
          stroke="#93c5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.7"
        />
        <g opacity="0.42">
          <path
            d="M 1020 560 C 1170 425, 1340 400, 1480 485"
            stroke="url(#tu-orbita-der-inf)"
            stroke-opacity="0.15"
            stroke-width="4.8"
            stroke-linecap="round"
          />
          <path
            d="M 1020 560 C 1170 425, 1340 400, 1480 485"
            stroke="url(#tu-orbita-der-inf)"
            stroke-width="2.1"
            stroke-linecap="round"
          />
        </g>
        <circle r="7.6" fill="url(#tu-esfera-rosa-3d)" opacity="0.52">
          <animateMotion
            path="M 1020 560 C 1170 425, 1340 400, 1480 485"
            dur="8.8s"
            repeatCount="indefinite"
            keyPoints="0.32;0.78;0.32"
            keyTimes="0;0.5;1"
            calcMode="spline"
            keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
          />
        </circle>
      </svg>
      <div class="mx-auto max-w-7xl px-4 sm:px-6 py-14 sm:py-20">
        <div class="text-center mx-auto max-w-3xl" data-scroll="cabecera">
          <p class="tu-seccion-etiqueta reveal">
            {{ t(k("giros.etiqueta")) }}
          </p>
          <h2 class="tu-titulo mt-3 reveal">{{ t(k("giros.titulo")) }}</h2>
          <p class="mt-4 text-lg reveal" :style="SUAVE">
            {{ t(k("giros.subtitulo")) }}
          </p>
        </div>
        <!-- Si la persona eligió un negocio del carrusel, el registro llega con su
             giro (`?giro=`); mientras gira solo, solo con la modalidad. -->
        <CarruselNegocios
          v-slot="{ negocio: activo, elegido }"
          :negocios="negocios"
        >
          <RouterLink
            class="tu-btn tu-btn-primario px-7 py-3"
            :to="registroCon(elegido ? perfilDeNegocio(activo?.clave) : null)"
            data-cta="business_carousel"
            @click="
              medirRegistro(
                'business_carousel',
                elegido ? perfilDeNegocio(activo?.clave) : null,
              )
            "
          >
            {{ alRegistro("giros.cta") }}
            <IconoNav nombre="flecha" :tam="18" />
          </RouterLink>
          <p class="mt-3 text-sm" :style="SUAVE">{{ t(k("giros.pie")) }}</p>
          <p v-if="notas.giros" class="tu-nota-mexico">
            {{ t("landing.soloMexico") }}
          </p>
        </CarruselNegocios>
        <p
          v-if="contenido.notaSalud"
          class="tu-alcance-salud mt-10 max-w-3xl mx-auto"
        >
          <strong>{{ t("landing.paraQuien.saludTitulo") }}</strong>
          {{ t("landing.paraQuien.saludAlcance") }}
        </p>
        <!-- Casos reales de los pilotos (autorizados); sin ellos no aparece. -->
        <div v-if="casos.length > 0" id="casos" class="mt-14">
          <h3 class="tu-titulo text-2xl reveal">
            {{ t("landing.modalidad.casosTitulo") }}
          </h3>
          <p class="mt-3 text-lg max-w-2xl reveal" :style="SUAVE">
            {{ t("landing.modalidad.casosSubtitulo") }}
          </p>
          <CasosPiloto :modo="modo" />
        </div>
      </div>
    </section>

    <!-- ===================== RECORRIDO (Patrón A: Recuadros TL + BR · Órbita BL) ===================== -->
    <section id="recorrido" class="tu-banda tu-ancla" :style="BANDA_SUPERFICIE">
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 640"
        fill="none"
        aria-hidden="true"
      >
        <rect
          class="tu-rombo-flota tu-rombo-flota--alt"
          x="-112"
          y="96"
          width="162"
          height="162"
          rx="32"
          transform="rotate(-24 -31 177)"
          fill="url(#tu-rombo-lila-grad)"
          stroke="#c4b5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.74"
        />
        <rect
          class="tu-rombo-flota"
          x="1398"
          y="375"
          width="152"
          height="152"
          rx="28"
          transform="rotate(-18 1474 451)"
          fill="url(#tu-rombo-azul-grad)"
          stroke="#93c5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.7"
        />
        <g opacity="0.42">
          <path
            d="M -40 485 C 100 400, 270 425, 420 560"
            stroke="url(#tu-orbita-izq-inf)"
            stroke-opacity="0.15"
            stroke-width="4.8"
            stroke-linecap="round"
          />
          <path
            d="M -40 485 C 100 400, 270 425, 420 560"
            stroke="url(#tu-orbita-izq-inf)"
            stroke-width="2.1"
            stroke-linecap="round"
          />
        </g>
        <circle r="7.6" fill="url(#tu-esfera-azul-3d)" opacity="0.52">
          <animateMotion
            path="M -40 485 C 100 400, 270 425, 420 560"
            dur="8.8s"
            repeatCount="indefinite"
            keyPoints="0.22;0.68;0.22"
            keyTimes="0;0.5;1"
            calcMode="spline"
            keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
          />
        </circle>
      </svg>
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-14 sm:py-18">
        <div data-scroll="cabecera">
          <p class="tu-seccion-etiqueta reveal">
            {{ t("landing.modalidad.recorridoEtiqueta") }}
          </p>
          <h2 class="tu-titulo mt-3 max-w-3xl reveal">
            {{ t(k("recorrido.titulo")) }}
          </h2>
          <p class="mt-4 text-lg max-w-2xl reveal" :style="SUAVE">
            {{ t(k("recorrido.subtitulo")) }}
          </p>
        </div>
        <div class="mt-8 reveal" data-scroll="escala">
          <RecorridoDemo :modo="modo" />
        </div>
        <div class="tu-recorrido-ademas reveal" data-scroll="acomodo">
          <p class="font-medium">
            {{ t("landing.modalidad.recorridoAdemas") }}
          </p>
          <ul class="mt-3 grid gap-3 sm:grid-cols-3" role="list">
            <li
              v-for="b in beneficiosOperacion"
              :key="b.clave"
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
              <span>{{ b.texto }}</span>
            </li>
          </ul>
          <p v-if="notas.operacion" class="tu-nota-mexico">
            {{ t("landing.soloMexico") }}
          </p>
        </div>
        <RouterLink
          class="tu-link-flecha mt-8"
          :to="registro"
          @click="medirRegistro('journey')"
        >
          {{ alRegistro("operacion.enlace") }}
          <IconoNav nombre="chevron" :tam="16" />
        </RouterLink>
        <TestimoniosLanding :modo="modo" />
      </div>
    </section>

    <!-- ===================== PRECIOS (Patrón B: Recuadros TR + BL · Órbita BR) ===================== -->
    <section id="precios" class="tu-banda tu-ancla" :style="BANDA_FONDO">
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 640"
        fill="none"
        aria-hidden="true"
      >
        <rect
          class="tu-rombo-flota"
          x="1390"
          y="96"
          width="162"
          height="162"
          rx="32"
          transform="rotate(24 1471 177)"
          fill="url(#tu-rombo-rosa-grad)"
          stroke="#f9a8d4"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.74"
        />
        <rect
          class="tu-rombo-flota tu-rombo-flota--alt"
          x="-110"
          y="375"
          width="152"
          height="152"
          rx="28"
          transform="rotate(18 -34 451)"
          fill="url(#tu-rombo-azul-grad)"
          stroke="#93c5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.7"
        />
        <g opacity="0.42">
          <path
            d="M 1020 560 C 1170 425, 1340 400, 1480 485"
            stroke="url(#tu-orbita-der-inf)"
            stroke-opacity="0.15"
            stroke-width="4.8"
            stroke-linecap="round"
          />
          <path
            d="M 1020 560 C 1170 425, 1340 400, 1480 485"
            stroke="url(#tu-orbita-der-inf)"
            stroke-width="2.1"
            stroke-linecap="round"
          />
        </g>
        <circle r="7.6" fill="url(#tu-esfera-rosa-3d)" opacity="0.52">
          <animateMotion
            path="M 1020 560 C 1170 425, 1340 400, 1480 485"
            dur="8.8s"
            repeatCount="indefinite"
            keyPoints="0.32;0.78;0.32"
            keyTimes="0;0.5;1"
            calcMode="spline"
            keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
          />
        </circle>
      </svg>
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-14 sm:py-18">
        <div class="max-w-3xl reveal" data-scroll="cabecera">
          <p class="tu-seccion-etiqueta">
            {{ t("landing.modalidad.precioEtiqueta") }}
          </p>
          <h2 class="tu-titulo mt-3">{{ t(k("precio.titulo")) }}</h2>
          <p class="mt-5 text-lg leading-relaxed" :style="SUAVE">
            {{ t(k("precio.subtitulo")) }}
          </p>
        </div>
        <!-- Cada tarjeta ya lleva su «Probar» y «Sin tarjeta»: sin repetirlo aquí. -->
        <div data-scroll="escala">
          <PreciosLanding :modo="modo" />
        </div>
        <!-- Pestañas compactas para no apilar bloques: qué reemplaza vs servicios aparte. -->
        <div class="tu-precios-complemento mt-10" data-scroll="acomodo">
          <div
            v-if="registroAbierto"
            class="tu-precios-pestanas"
            role="tablist"
            aria-label="Detalles de lo que incluye y servicios adicionales"
          >
            <button
              type="button"
              role="tab"
              :aria-selected="pestanaPrecios === 'reemplaza'"
              class="tu-precios-pestana"
              :class="{
                'tu-precios-pestana--activa': pestanaPrecios === 'reemplaza',
              }"
              @click="pestanaPrecios = 'reemplaza'"
            >
              {{ t("landing.porQue.titulo") }}
            </button>
            <button
              type="button"
              role="tab"
              :aria-selected="pestanaPrecios === 'aparte'"
              class="tu-precios-pestana"
              :class="{
                'tu-precios-pestana--activa': pestanaPrecios === 'aparte',
              }"
              @click="pestanaPrecios = 'aparte'"
            >
              {{ t("landing.porQue.aparte") }}
            </button>
          </div>
          <div v-show="!registroAbierto || pestanaPrecios === 'reemplaza'">
            <h3 v-if="!registroAbierto" class="text-xl font-semibold reveal">
              {{ t("landing.porQue.titulo") }}
            </h3>
            <p class="mt-2 max-w-3xl reveal" :style="SUAVE">
              {{ t("landing.porQue.subtitulo") }}
            </p>
            <ReemplazaHerramientas :modo="modo" class="mt-5 reveal" />
          </div>
          <div v-if="registroAbierto" v-show="pestanaPrecios === 'aparte'">
            <ArranqueYReferidos :modo="modo" class="mt-5 reveal" />
          </div>
        </div>
        <p class="tu-nota-mexico mt-3">{{ t("landing.soloMexico") }}</p>
      </div>
    </section>

    <!-- ===================== PÁGINA PÚBLICA (Patrón A: Recuadros TL + BR · Órbita BL) ===================== -->
    <section
      id="pagina-publica"
      class="tu-banda tu-ancla"
      :style="BANDA_SUPERFICIE"
    >
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 640"
        fill="none"
        aria-hidden="true"
      >
        <rect
          class="tu-rombo-flota tu-rombo-flota--alt"
          x="-112"
          y="96"
          width="162"
          height="162"
          rx="32"
          transform="rotate(-24 -31 177)"
          fill="url(#tu-rombo-lila-grad)"
          stroke="#c4b5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.74"
        />
        <rect
          class="tu-rombo-flota"
          x="1398"
          y="375"
          width="152"
          height="152"
          rx="28"
          transform="rotate(-18 1474 451)"
          fill="url(#tu-rombo-azul-grad)"
          stroke="#93c5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.7"
        />
        <g opacity="0.42">
          <path
            d="M -40 485 C 100 400, 270 425, 420 560"
            stroke="url(#tu-orbita-izq-inf)"
            stroke-opacity="0.15"
            stroke-width="4.8"
            stroke-linecap="round"
          />
          <path
            d="M -40 485 C 100 400, 270 425, 420 560"
            stroke="url(#tu-orbita-izq-inf)"
            stroke-width="2.1"
            stroke-linecap="round"
          />
        </g>
        <circle r="7.6" fill="url(#tu-esfera-azul-3d)" opacity="0.52">
          <animateMotion
            path="M -40 485 C 100 400, 270 425, 420 560"
            dur="8.8s"
            repeatCount="indefinite"
            keyPoints="0.22;0.68;0.22"
            keyTimes="0;0.5;1"
            calcMode="spline"
            keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
          />
        </circle>
      </svg>
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-14 sm:py-20">
        <div
          class="grid items-center gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16"
        >
          <div class="reveal" data-scroll="acomodo">
            <p class="tu-seccion-etiqueta">{{ t(k("pagina.etiqueta")) }}</p>
            <h2 class="tu-titulo mt-3">{{ t(k("pagina.titulo")) }}</h2>
            <p class="mt-5 max-w-xl text-lg leading-relaxed" :style="SUAVE">
              {{ t(k("pagina.subtitulo")) }}
            </p>
            <ul class="mt-8 space-y-4" role="list">
              <li
                v-for="b in beneficiosPagina"
                :key="b.clave"
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
                <span>{{ b.texto }}</span>
              </li>
            </ul>
            <p v-if="notas.pagina" class="tu-nota-mexico">
              {{ t("landing.soloMexico") }}
            </p>
            <div class="mt-8">
              <RouterLink
                class="tu-btn tu-btn-primario px-6 py-3"
                :to="registro"
                data-cta="public_page"
                @click="medirRegistro('public_page')"
              >
                {{ textoRegistro }}
              </RouterLink>
            </div>
          </div>
          <div class="tu-modalidad-demo reveal" data-scroll="escala">
            <component :is="DEMO_PAGINA[modo]" />
            <p
              class="mt-2 text-xs text-center"
              :style="SUAVE"
              data-prueba="demo-pagina-nota"
            >
              {{ t("landing.modalidad.demoPaginaNota") }}
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== PREGUNTAS (Patrón B: Recuadros TR + BL · Órbita BR) ===================== -->
    <section id="preguntas" class="tu-banda tu-ancla" :style="BANDA_FONDO">
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 640"
        fill="none"
        aria-hidden="true"
      >
        <rect
          class="tu-rombo-flota"
          x="1390"
          y="96"
          width="162"
          height="162"
          rx="32"
          transform="rotate(24 1471 177)"
          fill="url(#tu-rombo-rosa-grad)"
          stroke="#f9a8d4"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.74"
        />
        <rect
          class="tu-rombo-flota tu-rombo-flota--alt"
          x="-110"
          y="375"
          width="152"
          height="152"
          rx="28"
          transform="rotate(18 -34 451)"
          fill="url(#tu-rombo-azul-grad)"
          stroke="#93c5fd"
          stroke-opacity="0.32"
          stroke-width="1.2"
          opacity="0.7"
        />
        <g opacity="0.42">
          <path
            d="M 1020 560 C 1170 425, 1340 400, 1480 485"
            stroke="url(#tu-orbita-der-inf)"
            stroke-opacity="0.15"
            stroke-width="4.8"
            stroke-linecap="round"
          />
          <path
            d="M 1020 560 C 1170 425, 1340 400, 1480 485"
            stroke="url(#tu-orbita-der-inf)"
            stroke-width="2.1"
            stroke-linecap="round"
          />
        </g>
        <circle r="7.6" fill="url(#tu-esfera-rosa-3d)" opacity="0.52">
          <animateMotion
            path="M 1020 560 C 1170 425, 1340 400, 1480 485"
            dur="8.8s"
            repeatCount="indefinite"
            keyPoints="0.32;0.78;0.32"
            keyTimes="0;0.5;1"
            calcMode="spline"
            keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
          />
        </circle>
      </svg>
      <div class="mx-auto max-w-5xl px-4 sm:px-6 py-14 sm:py-18">
        <PreguntasFrecuentes
          :titulo="t('landing.modalidad.faqTitulo')"
          :preguntas="preguntas"
          estructurado
        >
          <p v-if="notas.preguntas" class="tu-nota-mexico">
            {{ t("landing.soloMexico") }}
          </p>
          <div class="tu-faq-mas mt-6">
            <div>
              <strong>¿Buscas otra respuesta específica?</strong>
              <p>
                Consulta las {{ totalPreguntasFaq }} preguntas frecuentes
                organizadas por sección y con buscador.
              </p>
            </div>
            <RouterLink
              to="/preguntas-frecuentes"
              class="tu-btn tu-btn-secundario px-5 py-2.5"
              data-prueba="ver-todas-faq"
            >
              Ver todas las preguntas
              <IconoNav nombre="flecha" :tam="16" />
            </RouterLink>
          </div>
        </PreguntasFrecuentes>
      </div>
    </section>

    <!-- ===================== LLAMADO FINAL (Patrón A en tarjeta) ===================== -->
    <section class="tu-banda tu-modalidad-final" :style="BANDA_SUPERFICIE">
      <div class="mx-auto max-w-5xl px-4 sm:px-6 py-14 sm:py-20">
        <div class="tu-final-caja text-center reveal" data-scroll="escala">
          <svg
            class="tu-banda-estela"
            viewBox="0 0 1000 420"
            fill="none"
            aria-hidden="true"
          >
            <rect
              class="tu-rombo-flota tu-rombo-flota--alt"
              x="-105"
              y="32"
              width="156"
              height="156"
              rx="30"
              transform="rotate(-24 -27 110)"
              fill="url(#tu-rombo-lila-grad)"
              stroke="#c4b5fd"
              stroke-opacity="0.32"
              stroke-width="1.2"
              opacity="0.74"
            />
            <rect
              class="tu-rombo-flota"
              x="952"
              y="235"
              width="148"
              height="148"
              rx="28"
              transform="rotate(-18 1026 309)"
              fill="url(#tu-rombo-azul-grad)"
              stroke="#93c5fd"
              stroke-opacity="0.32"
              stroke-width="1.2"
              opacity="0.7"
            />
            <g opacity="0.42">
              <path
                d="M -30 340 C 85 270, 225 295, 345 405"
                stroke="url(#tu-orbita-izq-inf)"
                stroke-opacity="0.15"
                stroke-width="4.8"
                stroke-linecap="round"
              />
              <path
                d="M -30 340 C 85 270, 225 295, 345 405"
                stroke="url(#tu-orbita-izq-inf)"
                stroke-width="2.1"
                stroke-linecap="round"
              />
            </g>
            <circle r="7.4" fill="url(#tu-esfera-azul-3d)" opacity="0.52">
              <animateMotion
                path="M -30 340 C 85 270, 225 295, 345 405"
                dur="8.8s"
                repeatCount="indefinite"
                keyPoints="0.22;0.68;0.22"
                keyTimes="0;0.5;1"
                calcMode="spline"
                keySplines="0.42 0 0.58 1;0.42 0 0.58 1"
              />
            </circle>
          </svg>
          <div class="tu-monograma-insignia" aria-hidden="true">
            <LogoProducto
              v-if="modo === 'clases'"
              producto="agendauno"
              variante="isotipo"
              :ancho="44"
              alt="Monograma de AgendaUno"
            />
            <svg
              v-else
              viewBox="0 0 44 44"
              width="40"
              height="40"
              fill="none"
              aria-hidden="true"
            >
              <path
                d="M 8 28 C 12 14, 28 10, 38 18"
                stroke="#38bdf8"
                stroke-width="2.4"
                stroke-linecap="round"
              />
              <path
                d="M 6 20 C 16 32, 32 30, 38 16"
                stroke="#f472b6"
                stroke-width="2.2"
                stroke-linecap="round"
              />
              <circle cx="29" cy="14" r="4.2" fill="url(#tu-esfera-azul-3d)" />
              <circle cx="16" cy="26" r="3.8" fill="url(#tu-esfera-rosa-3d)" />
            </svg>
          </div>
          <h2 class="tu-titulo tu-titulo-final">
            {{
              registroAbierto
                ? t(k("final.titulo"))
                : t("landing.prelanzamiento.finalTitulo")
            }}
          </h2>
          <p class="tu-final-sub mt-4 text-lg max-w-2xl mx-auto">
            {{
              registroAbierto
                ? t(k("final.subtitulo"), { dias: DIAS_PRUEBA })
                : t("landing.prelanzamiento.finalSubtitulo")
            }}
          </p>
          <RouterLink
            class="tu-btn tu-btn-primario text-base px-7 py-3 mt-8"
            :to="registro"
            data-cta="final"
            @click="medirRegistro('final')"
          >
            {{ textoRegistro }}
          </RouterLink>
          <!-- La demo, si la plataforma agenda demos: en su página (ADR 0204). -->
          <RouterLink
            v-if="demosActivas"
            class="tu-btn tu-btn-secundario text-base px-7 py-3 mt-8 ml-3"
            :to="demo"
            data-cta="final_demo"
            @click="medirDemo('final')"
          >
            {{ t("demoAgendable.cta") }}
          </RouterLink>
          <!-- Enlace discreto a la otra modalidad: cada negocio es de una sola. -->
          <p class="tu-modalidad-otra">
            {{ t(k("final.otra")) }}
            <a
              v-if="otraUrl"
              :href="otraUrl"
              class="inline-flex items-center gap-1"
              @click="medirOtra"
              >{{ t(k("final.otraEnlace"), { otro: nombreOtro }) }}
              <IconoNav nombre="flecha" :tam="16"
            /></a>
            <RouterLink
              v-else
              :to="otra"
              class="inline-flex items-center gap-1"
              @click="medirOtra"
              >{{ t(k("final.otraEnlace"), { otro: nombreOtro }) }}
              <IconoNav nombre="flecha" :tam="16"
            /></RouterLink>
          </p>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.tu-modalidad::before {
  content: "";
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  z-index: 60;
  pointer-events: none;
  background: linear-gradient(
    90deg,
    #0d3b6e 0%,
    #3a8fd4 65%,
    var(--marketing-rosa) 100%
  );
  transform-origin: 0 50%;
  transform: scaleX(var(--scroll-progreso, 0));
}
@media (min-width: 1024px) {
  .tu-hero-layout {
    grid-template-columns: minmax(0, 0.88fr) minmax(0, 1.12fr);
    gap: clamp(1.25rem, 2.2vw, 2.25rem);
  }
}

.tu-modalidad :deep(.tu-titulo-enfasis--negrita) {
  font-weight: 600;
}
.tu-funciones-avanzadas {
  margin-top: 2rem;
  border: 1px solid color-mix(in srgb, #3a8fd4 24%, var(--borde));
  border-radius: 24px;
  background:
    radial-gradient(
      ellipse 60% 50% at 94% 12%,
      color-mix(in srgb, #7dd3fc 12%, transparent) 0%,
      transparent 70%
    ),
    var(--superficie);
  box-shadow:
    0 18px 42px -24px rgb(13 59 110 / 20%),
    0 2px 8px rgb(13 59 110 / 4%);
  overflow: hidden;
  transition:
    border-color 0.25s ease,
    box-shadow 0.25s ease;
}
.tu-funciones-avanzadas:hover {
  border-color: color-mix(in srgb, #3a8fd4 48%, var(--borde));
}
.tu-funciones-avanzadas > summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.4rem 1.6rem;
  cursor: pointer;
  list-style: none;
}
.tu-funciones-avanzadas > summary::-webkit-details-marker {
  display: none;
}
.tu-funciones-avanzadas strong {
  font-size: 1rem;
  font-weight: 600;
  color: var(--primario);
}
.tu-funciones-avanzadas small {
  display: block;
  margin-top: 0.3rem;
  color: var(--texto-suave);
  font-size: 0.85rem;
}
.tu-funciones-avanzadas > summary:focus-visible {
  outline: 2px solid var(--enlace);
  outline-offset: -4px;
}
.tu-avanzadas-signo {
  display: inline-grid;
  place-items: center;
  width: 2.1rem;
  height: 2.1rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, #3a8fd4 26%, var(--borde));
  background: color-mix(in srgb, #3a8fd4 10%, var(--fondo));
  font-size: 1.25rem;
  line-height: 1;
  color: var(--primario);
  transition:
    transform 0.25s ease,
    background-color 0.25s ease;
}
.tu-funciones-avanzadas[open] .tu-avanzadas-signo {
  transform: rotate(45deg);
  background: color-mix(in srgb, #3a8fd4 18%, var(--fondo));
}
@media (max-width: 639px) {
  .tu-funciones-avanzadas > summary {
    padding: 1.2rem;
  }
}
@media (prefers-reduced-motion: reduce) {
  .tu-avanzadas-signo {
    transition: none;
  }
}

.tu-modalidad-nombre {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  margin-bottom: 0.5rem;
  padding: 0.34rem 0.85rem;
  border: 1px solid color-mix(in srgb, #3a8fd4 26%, var(--borde));
  border-radius: 999px;
  background: color-mix(in srgb, var(--superficie) 92%, transparent);
  box-shadow: 0 4px 14px -6px rgb(13 59 110 / 10%);
  color: var(--primario);
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.08em;
}
.tu-modalidad-nombre::before {
  content: "";
  width: 0.42rem;
  height: 0.42rem;
  border-radius: 999px;
  background: var(--acento);
  box-shadow: 0 0 0 3.5px color-mix(in srgb, var(--acento) 22%, transparent);
}
/* El carrusel gira fuera de su caja: sin desbordar la página. */
.tu-modalidad-giros {
  overflow: hidden;
}
/* Lo demás de recepción, bajo el recorrido. */
.tu-recorrido-ademas {
  margin-top: 2rem;
}
.tu-modalidad-demo {
  min-width: 0;
}
.tu-pasos {
  position: relative;
}
@media (min-width: 768px) {
  .tu-pasos::before {
    content: "";
    position: absolute;
    top: 2.85rem;
    left: 10%;
    right: 10%;
    height: 2px;
    background: linear-gradient(
      90deg,
      rgb(56 189 248 / 38%) 0%,
      rgb(192 132 252 / 38%) 50%,
      rgb(244 114 182 / 38%) 100%
    );
    pointer-events: none;
    z-index: 0;
  }
}
.tu-paso-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}
.tu-paso-trazo {
  flex: 1;
  height: 2px;
  max-width: 5.5rem;
  border-radius: 999px;
  background: linear-gradient(
    90deg,
    rgb(56 189 248 / 55%) 0%,
    rgb(244 114 182 / 35%) 100%
  );
}
.tu-cabecera-con-monograma {
  position: relative;
  isolation: isolate;
}
.tu-cabecera-monograma-texto {
  position: relative;
  z-index: 1;
}
.tu-rombo-flota {
  animation: tu-rombo-pulso 8s ease-in-out infinite alternate;
}
.tu-rombo-flota--alt {
  animation-delay: -4s;
}
@keyframes tu-rombo-pulso {
  0% {
    opacity: 0.84;
  }
  100% {
    opacity: 1;
  }
}
.tu-monograma-insignia {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 4.35rem;
  height: 4.35rem;
  margin: 0 auto 1.25rem;
  border-radius: 22px;
  border: 1px solid color-mix(in srgb, #3a8fd4 32%, var(--borde));
  background: linear-gradient(
    145deg,
    color-mix(in srgb, #ffffff 94%, var(--superficie)) 0%,
    color-mix(in srgb, #bae6fd 22%, var(--fondo)) 55%,
    color-mix(in srgb, #fbcfe8 18%, var(--fondo)) 100%
  );
  box-shadow:
    0 18px 36px -18px rgb(13 59 110 / 26%),
    0 0 0 6px color-mix(in srgb, #3a8fd4 9%, transparent);
}
@media (prefers-reduced-motion: reduce) {
  .tu-rombo-flota {
    animation: none;
  }
}
.tu-precios-complemento {
  padding: 1.45rem 1.6rem;
  border: 1px solid color-mix(in srgb, #3a8fd4 24%, var(--borde));
  border-radius: 24px;
  background:
    radial-gradient(
      ellipse 65% 55% at 95% 8%,
      color-mix(in srgb, #7dd3fc 14%, transparent) 0%,
      transparent 70%
    ),
    radial-gradient(
      ellipse 55% 50% at 5% 94%,
      color-mix(in srgb, #fbcfe8 12%, transparent) 0%,
      transparent 70%
    ),
    color-mix(in srgb, var(--superficie) 84%, var(--fondo));
  box-shadow:
    0 18px 42px -26px rgb(13 59 110 / 22%),
    0 2px 8px rgb(13 59 110 / 4%);
}
.tu-precios-pestanas {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  padding: 0.32rem;
  margin-bottom: 1.1rem;
  border: 1px solid color-mix(in srgb, #3a8fd4 24%, var(--borde));
  border-radius: 999px;
  background: color-mix(in srgb, var(--superficie) 92%, var(--fondo));
  box-shadow: 0 8px 22px -14px rgb(13 59 110 / 16%);
}
.tu-precios-pestana {
  padding: 0.52rem 1.15rem;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--texto-suave);
  font-size: 0.86rem;
  font-weight: 600;
  cursor: pointer;
  transition:
    background-color 0.22s ease,
    color 0.22s ease,
    box-shadow 0.22s ease;
}
.tu-precios-pestana:hover {
  color: var(--texto);
}
.tu-precios-pestana--activa {
  background: #0d3b6e;
  color: #ffffff;
  box-shadow: 0 8px 18px -8px rgb(13 59 110 / 45%);
}
.tu-faq-mas {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.25rem 1.5rem;
  border: 1px solid color-mix(in srgb, #3a8fd4 26%, var(--borde));
  border-radius: 24px;
  background:
    radial-gradient(
      ellipse 60% 55% at 92% 15%,
      color-mix(in srgb, #7dd3fc 14%, transparent) 0%,
      transparent 70%
    ),
    color-mix(in srgb, var(--superficie) 92%, var(--fondo));
  box-shadow:
    0 16px 36px -24px rgb(13 59 110 / 18%),
    0 2px 8px rgb(13 59 110 / 4%);
}
.tu-faq-mas strong {
  display: block;
  font-size: 0.98rem;
  font-weight: 600;
  color: var(--primario);
}
.tu-faq-mas p {
  margin-top: 0.2rem;
  font-size: 0.86rem;
  color: var(--texto-suave);
}
.tu-final-caja {
  position: relative;
  isolation: isolate;
  overflow: hidden;
  padding: clamp(2.5rem, 5vw, 4rem) clamp(1.5rem, 4vw, 3.5rem);
  border: 1px solid color-mix(in srgb, #3a8fd4 32%, var(--borde));
  border-radius: 30px;
  background:
    radial-gradient(
      ellipse 52% 48% at 6% 10%,
      color-mix(in srgb, #7dd3fc 20%, transparent) 0%,
      transparent 70%
    ),
    radial-gradient(
      ellipse 52% 48% at 94% 90%,
      color-mix(in srgb, #f472b6 18%, transparent) 0%,
      transparent 70%
    ),
    linear-gradient(
      135deg,
      color-mix(in srgb, #bae6fd 16%, var(--superficie)) 0%,
      var(--superficie) 50%,
      color-mix(in srgb, #fbcfe8 12%, var(--fondo)) 100%
    );
  box-shadow:
    0 26px 56px -28px rgb(13 59 110 / 24%),
    0 2px 10px rgb(13 59 110 / 5%);
}
.dark .tu-final-caja {
  background:
    radial-gradient(
      ellipse 48% 44% at 8% 12%,
      rgb(56 189 248 / 10%) 0%,
      transparent 68%
    ),
    radial-gradient(
      ellipse 48% 44% at 92% 88%,
      rgb(236 72 153 / 9%) 0%,
      transparent 68%
    ),
    var(--superficie);
}
.tu-final-caja > :not(.tu-banda-estela) {
  position: relative;
  z-index: 1;
}
.tu-final-sub {
  color: var(--texto-suave);
}
.tu-modalidad-otra {
  margin-top: 2.25rem;
  color: var(--texto-suave);
  font-size: 0.9rem;
}
.tu-modalidad-otra a {
  color: var(--enlace);
  font-weight: 500;
  text-decoration: none;
}
.tu-modalidad-otra a:hover {
  text-decoration: underline;
}
.tu-modalidad-otra a:focus-visible {
  outline: 2px solid var(--enlace);
  outline-offset: 3px;
  border-radius: 4px;
}
</style>
