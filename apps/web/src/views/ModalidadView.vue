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

    <!-- ===================== PRODUCTO (Estrella a la DERECHA) ===================== -->
    <section id="producto" class="tu-banda tu-ancla" :style="BANDA_SUPERFICIE">
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 560"
        fill="none"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
      >
        <defs>
          <linearGradient id="tu-estela-prod-a" x1="0" y1="0.2" x2="1" y2="0.1">
            <stop offset="0%" stop-color="#0d3b6e" stop-opacity="0" />
            <stop offset="32%" stop-color="#3a8fd4" stop-opacity="0.32" />
            <stop
              offset="72%"
              stop-color="var(--marketing-rosa)"
              stop-opacity="0.44"
            />
            <stop
              offset="100%"
              stop-color="var(--marketing-rosa)"
              stop-opacity="0"
            />
          </linearGradient>
          <linearGradient id="tu-estela-prod-b" x1="1" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#3a8fd4" stop-opacity="0" />
            <stop
              offset="50%"
              stop-color="var(--marketing-rosa)"
              stop-opacity="0.24"
            />
            <stop offset="100%" stop-color="#0d3b6e" stop-opacity="0" />
          </linearGradient>
        </defs>
        <path
          d="M-80 155 C 320 55, 680 245, 1080 115 C 1245 62, 1365 88, 1520 58"
          stroke="url(#tu-estela-prod-a)"
          stroke-width="2.4"
          stroke-linecap="round"
        />
        <path
          class="tu-estela-pulso"
          d="M-80 155 C 320 55, 680 245, 1080 115 C 1245 62, 1365 88, 1520 58"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.48"
          stroke-width="2.8"
          stroke-linecap="round"
        />
        <path
          d="M-40 455 C 360 355, 820 510, 1500 365"
          stroke="url(#tu-estela-prod-b)"
          stroke-width="1.6"
          stroke-dasharray="6 10"
          stroke-linecap="round"
        />
        <g class="tu-estrella-movil">
          <path
            d="M1238 72 L1243 87 L1258 92 L1243 97 L1238 112 L1233 97 L1218 92 L1233 87 Z"
            fill="var(--marketing-rosa)"
            fill-opacity="0.56"
          />
        </g>
        <g class="tu-estrella-movil tu-estrella-movil--lenta">
          <path
            d="M1152 126 L1155 135 L1164 138 L1155 141 L1152 150 L1149 141 L1140 138 L1149 135 Z"
            fill="#3a8fd4"
            fill-opacity="0.45"
          />
        </g>
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

    <!-- ===================== FUNCIONES (Sección blanca · Estrella a la IZQUIERDA) ===================== -->
    <section id="soluciones" class="tu-banda tu-ancla" :style="BANDA_FONDO">
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 560"
        fill="none"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
      >
        <defs>
          <linearGradient id="tu-estela-sol" x1="1" y1="0.4" x2="0" y2="0.1">
            <stop offset="0%" stop-color="#0d3b6e" stop-opacity="0" />
            <stop offset="35%" stop-color="#3a8fd4" stop-opacity="0.34" />
            <stop
              offset="72%"
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
          d="M1500 220 C 1140 80, 740 275, 320 112 C 185 60, 80 88, -60 65"
          stroke="url(#tu-estela-sol)"
          stroke-width="2.5"
          stroke-linecap="round"
        />
        <path
          class="tu-estela-pulso"
          d="M1500 220 C 1140 80, 740 275, 320 112 C 185 60, 80 88, -60 65"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.52"
          stroke-width="2.8"
          stroke-linecap="round"
        />
        <path
          d="M-40 445 C 420 335, 910 495, 1490 360"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.24"
          stroke-width="1.4"
          stroke-dasharray="5 9"
        />
        <g class="tu-estrella-movil">
          <path
            d="M168 68 L173 83 L188 88 L173 93 L168 108 L163 93 L148 88 L163 83 Z"
            fill="var(--marketing-rosa)"
            fill-opacity="0.62"
          />
        </g>
        <g class="tu-estrella-movil tu-estrella-movil--lenta">
          <path
            d="M254 122 L257 131 L266 134 L257 137 L254 146 L251 137 L242 134 L251 131 Z"
            fill="#3a8fd4"
            fill-opacity="0.46"
          />
        </g>
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

    <!-- ===================== CÓMO FUNCIONA (Estrella a la DERECHA) ===================== -->
    <section
      id="como-funciona"
      class="tu-banda tu-ancla"
      :style="BANDA_SUPERFICIE"
    >
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 560"
        fill="none"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
      >
        <defs>
          <linearGradient id="tu-estela-pasos" x1="0" y1="0.2" x2="1" y2="0.8">
            <stop offset="0%" stop-color="#0d3b6e" stop-opacity="0" />
            <stop offset="38%" stop-color="#3a8fd4" stop-opacity="0.32" />
            <stop
              offset="75%"
              stop-color="var(--marketing-rosa)"
              stop-opacity="0.45"
            />
            <stop
              offset="100%"
              stop-color="var(--marketing-rosa)"
              stop-opacity="0"
            />
          </linearGradient>
        </defs>
        <path
          d="M-50 175 C 340 85, 760 270, 1180 135 C 1310 95, 1400 115, 1510 90"
          stroke="url(#tu-estela-pasos)"
          stroke-width="2.4"
          stroke-linecap="round"
        />
        <path
          class="tu-estela-pulso"
          d="M-50 175 C 340 85, 760 270, 1180 135 C 1310 95, 1400 115, 1510 90"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.48"
          stroke-width="2.8"
          stroke-linecap="round"
        />
        <g class="tu-estrella-movil">
          <path
            d="M1262 84 L1267 99 L1282 104 L1267 109 L1262 124 L1257 109 L1242 104 L1257 99 Z"
            fill="var(--marketing-rosa)"
            fill-opacity="0.56"
          />
        </g>
        <g class="tu-estrella-movil tu-estrella-movil--lenta">
          <path
            d="M1175 138 L1178 147 L1187 150 L1178 153 L1175 162 L1172 153 L1163 150 L1172 147 Z"
            fill="#3a8fd4"
            fill-opacity="0.44"
          />
        </g>
      </svg>
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-14 sm:py-18">
        <div class="tu-cabecera-con-monograma" data-scroll="cabecera">
          <div class="tu-monograma-sello" aria-hidden="true">
            <LogoProducto
              v-if="modo === 'clases'"
              producto="agendauno"
              variante="isotipo"
              :ancho="210"
              alt="Monograma de AgendaUno"
            />
            <span
              v-else
              class="tu-monograma-iniciales tu-monograma-iniciales--fondo"
              >TU</span
            >
          </div>
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
            :style="{
              transitionDelay: i * 90 + 'ms',
              '--desplaza-x': i === 0 ? '-28px' : i === 2 ? '28px' : '0px',
              '--desplaza-y': i === 1 ? '32px' : '22px',
            }"
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

    <!-- ===================== GIROS (Sección blanca · Estrella a la IZQUIERDA) ===================== -->
    <section
      id="para-quien"
      class="tu-banda tu-ancla tu-modalidad-giros"
      :style="BANDA_FONDO"
    >
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 560"
        fill="none"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
      >
        <defs>
          <linearGradient id="tu-estela-giros" x1="1" y1="0.3" x2="0" y2="0.1">
            <stop offset="0%" stop-color="#0d3b6e" stop-opacity="0" />
            <stop offset="35%" stop-color="#3a8fd4" stop-opacity="0.32" />
            <stop
              offset="72%"
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
          d="M1490 190 C 1100 65, 690 250, 290 105 C 165 58, 70 82, -50 62"
          stroke="url(#tu-estela-giros)"
          stroke-width="2.4"
          stroke-linecap="round"
        />
        <path
          class="tu-estela-pulso"
          d="M1490 190 C 1100 65, 690 250, 290 105 C 165 58, 70 82, -50 62"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.52"
          stroke-width="2.8"
          stroke-linecap="round"
        />
        <g class="tu-estrella-movil">
          <path
            d="M174 72 L179 87 L194 92 L179 97 L174 112 L169 97 L154 92 L169 87 Z"
            fill="var(--marketing-rosa)"
            fill-opacity="0.62"
          />
        </g>
        <g class="tu-estrella-movil tu-estrella-movil--lenta">
          <path
            d="M262 126 L265 135 L274 138 L265 141 L262 150 L259 141 L250 138 L259 135 Z"
            fill="#3a8fd4"
            fill-opacity="0.45"
          />
        </g>
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

    <!-- ===================== RECORRIDO (Estrella a la DERECHA) ===================== -->
    <section id="recorrido" class="tu-banda tu-ancla" :style="BANDA_SUPERFICIE">
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 560"
        fill="none"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
      >
        <defs>
          <linearGradient id="tu-estela-rec" x1="0" y1="0" x2="1" y2="0.6">
            <stop offset="0%" stop-color="#0d3b6e" stop-opacity="0" />
            <stop offset="40%" stop-color="#3a8fd4" stop-opacity="0.32" />
            <stop
              offset="76%"
              stop-color="var(--marketing-rosa)"
              stop-opacity="0.44"
            />
            <stop
              offset="100%"
              stop-color="var(--marketing-rosa)"
              stop-opacity="0"
            />
          </linearGradient>
        </defs>
        <path
          d="M-40 135 C 420 250, 860 40, 1490 195"
          stroke="url(#tu-estela-rec)"
          stroke-width="2.4"
          stroke-linecap="round"
        />
        <path
          class="tu-estela-pulso"
          d="M-40 135 C 420 250, 860 40, 1490 195"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.48"
          stroke-width="2.8"
          stroke-linecap="round"
        />
        <path
          d="M-60 445 C 400 345, 920 495, 1480 375"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.22"
          stroke-width="1.35"
          stroke-dasharray="5 9"
        />
        <g class="tu-estrella-movil">
          <path
            d="M1228 74 L1233 89 L1248 94 L1233 99 L1228 114 L1223 99 L1208 94 L1223 89 Z"
            fill="var(--marketing-rosa)"
            fill-opacity="0.56"
          />
        </g>
        <g class="tu-estrella-movil tu-estrella-movil--lenta">
          <path
            d="M1142 124 L1145 133 L1154 136 L1145 139 L1142 148 L1139 139 L1130 136 L1139 133 Z"
            fill="#3a8fd4"
            fill-opacity="0.44"
          />
        </g>
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
        <div
          class="tu-recorrido-ademas reveal"
          data-scroll="acomodo"
          style="--desplaza-y: 24px"
        >
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

    <!-- ===================== PRECIOS (Sección blanca · Estrella a la IZQUIERDA) ===================== -->
    <section id="precios" class="tu-banda tu-ancla" :style="BANDA_FONDO">
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 560"
        fill="none"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
      >
        <defs>
          <linearGradient
            id="tu-estela-precios"
            x1="1"
            y1="0.4"
            x2="0"
            y2="0.1"
          >
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
          d="M1500 185 C 1080 55, 680 245, 290 100 C 165 55, 70 80, -60 58"
          stroke="url(#tu-estela-precios)"
          stroke-width="2.5"
          stroke-linecap="round"
        />
        <path
          class="tu-estela-pulso"
          d="M1500 185 C 1080 55, 680 245, 290 100 C 165 55, 70 80, -60 58"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.52"
          stroke-width="2.8"
          stroke-linecap="round"
        />
        <path
          d="M-40 450 C 420 345, 920 495, 1490 365"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.22"
          stroke-width="1.35"
          stroke-dasharray="5 9"
        />
        <g class="tu-estrella-movil">
          <path
            d="M162 66 L167 81 L182 86 L167 91 L162 106 L157 91 L142 86 L157 81 Z"
            fill="var(--marketing-rosa)"
            fill-opacity="0.62"
          />
        </g>
        <g class="tu-estrella-movil tu-estrella-movil--lenta">
          <path
            d="M248 118 L251 127 L260 130 L251 133 L248 142 L245 133 L236 130 L245 127 Z"
            fill="#3a8fd4"
            fill-opacity="0.46"
          />
        </g>
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
        <div
          class="tu-precios-complemento mt-10"
          data-scroll="acomodo"
          style="--desplaza-y: 26px"
        >
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

    <!-- ===================== PÁGINA PÚBLICA (Estrella a la DERECHA) ===================== -->
    <section
      id="pagina-publica"
      class="tu-banda tu-ancla"
      :style="BANDA_SUPERFICIE"
    >
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 560"
        fill="none"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
      >
        <defs>
          <linearGradient id="tu-estela-pub" x1="0" y1="0.3" x2="1" y2="0.1">
            <stop offset="0%" stop-color="#0d3b6e" stop-opacity="0" />
            <stop offset="38%" stop-color="#3a8fd4" stop-opacity="0.32" />
            <stop
              offset="76%"
              stop-color="var(--marketing-rosa)"
              stop-opacity="0.45"
            />
            <stop
              offset="100%"
              stop-color="var(--marketing-rosa)"
              stop-opacity="0"
            />
          </linearGradient>
        </defs>
        <path
          d="M-50 180 C 360 70, 780 250, 1190 115 C 1315 72, 1405 95, 1510 70"
          stroke="url(#tu-estela-pub)"
          stroke-width="2.4"
          stroke-linecap="round"
        />
        <path
          class="tu-estela-pulso"
          d="M-50 180 C 360 70, 780 250, 1190 115 C 1315 72, 1405 95, 1510 70"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.48"
          stroke-width="2.8"
          stroke-linecap="round"
        />
        <g class="tu-estrella-movil">
          <path
            d="M1255 72 L1260 87 L1275 92 L1260 97 L1255 112 L1250 97 L1235 92 L1250 87 Z"
            fill="var(--marketing-rosa)"
            fill-opacity="0.56"
          />
        </g>
        <g class="tu-estrella-movil tu-estrella-movil--lenta">
          <path
            d="M1168 124 L1171 133 L1180 136 L1171 139 L1168 148 L1165 139 L1156 136 L1165 133 Z"
            fill="#3a8fd4"
            fill-opacity="0.44"
          />
        </g>
      </svg>
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-14 sm:py-20">
        <div
          class="grid items-center gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16"
        >
          <div
            class="reveal"
            data-scroll="acomodo"
            style="--desplaza-x: -28px; --desplaza-y: 20px"
          >
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

    <!-- ===================== PREGUNTAS (Sección blanca · Estrella a la IZQUIERDA) ===================== -->
    <section id="preguntas" class="tu-banda tu-ancla" :style="BANDA_FONDO">
      <svg
        class="tu-banda-estela"
        viewBox="0 0 1440 560"
        fill="none"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
      >
        <defs>
          <linearGradient id="tu-estela-faq" x1="1" y1="0.4" x2="0" y2="0.1">
            <stop offset="0%" stop-color="#0d3b6e" stop-opacity="0" />
            <stop offset="36%" stop-color="#3a8fd4" stop-opacity="0.32" />
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
          d="M1490 195 C 1090 65, 680 250, 290 105 C 165 58, 70 84, -50 64"
          stroke="url(#tu-estela-faq)"
          stroke-width="2.4"
          stroke-linecap="round"
        />
        <path
          class="tu-estela-pulso"
          d="M1490 195 C 1090 65, 680 250, 290 105 C 165 58, 70 84, -50 64"
          stroke="var(--marketing-rosa)"
          stroke-opacity="0.52"
          stroke-width="2.8"
          stroke-linecap="round"
        />
        <g class="tu-estrella-movil">
          <path
            d="M168 74 L173 89 L188 94 L173 99 L168 114 L163 99 L148 94 L163 89 Z"
            fill="var(--marketing-rosa)"
            fill-opacity="0.62"
          />
        </g>
        <g class="tu-estrella-movil tu-estrella-movil--lenta">
          <path
            d="M254 128 L257 137 L266 140 L257 143 L254 152 L251 143 L242 140 L251 137 Z"
            fill="#3a8fd4"
            fill-opacity="0.46"
          />
        </g>
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

    <!-- ===================== LLAMADO FINAL ===================== -->
    <section class="tu-banda tu-modalidad-final" :style="BANDA_SUPERFICIE">
      <div class="mx-auto max-w-5xl px-4 sm:px-6 py-14 sm:py-20">
        <div class="tu-final-caja text-center reveal" data-scroll="escala">
          <svg
            class="tu-banda-estela"
            viewBox="0 0 1000 420"
            fill="none"
            preserveAspectRatio="xMidYMid slice"
            aria-hidden="true"
          >
            <defs>
              <linearGradient id="tu-estela-final" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#0d3b6e" stop-opacity="0" />
                <stop offset="42%" stop-color="#3a8fd4" stop-opacity="0.34" />
                <stop
                  offset="78%"
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
              d="M-40 330 C 240 190, 560 370, 1040 110"
              stroke="url(#tu-estela-final)"
              stroke-width="2.4"
              stroke-linecap="round"
            />
            <path
              class="tu-estela-pulso"
              d="M-40 330 C 240 190, 560 370, 1040 110"
              stroke="var(--marketing-rosa)"
              stroke-opacity="0.52"
              stroke-width="2.8"
              stroke-linecap="round"
            />
            <path
              d="M-20 90 C 310 210, 690 30, 1030 220"
              stroke="var(--marketing-rosa)"
              stroke-opacity="0.24"
              stroke-width="1.4"
              stroke-dasharray="5 9"
            />
            <g class="tu-estrella-movil">
              <path
                d="M860 72 L865 87 L880 92 L865 97 L860 112 L855 97 L840 92 L855 87 Z"
                fill="var(--marketing-rosa)"
                fill-opacity="0.6"
              />
            </g>
            <g class="tu-estrella-movil tu-estrella-movil--lenta">
              <path
                d="M142 292 L146 303 L157 307 L146 311 L142 322 L138 311 L127 307 L138 303 Z"
                fill="#3a8fd4"
                fill-opacity="0.48"
              />
            </g>
          </svg>
          <div class="tu-monograma-insignia" aria-hidden="true">
            <LogoProducto
              v-if="modo === 'clases'"
              producto="agendauno"
              variante="isotipo"
              :ancho="44"
              alt="Monograma de AgendaUno"
            />
            <span v-else class="tu-monograma-iniciales">TU</span>
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
  transition: transform 0.1s linear;
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
  border: 1px solid color-mix(in srgb, var(--acento) 22%, var(--borde));
  border-radius: 18px;
  background: var(--superficie);
  box-shadow: 0 10px 30px -22px rgb(13 59 110 / 28%);
  overflow: hidden;
  transition: border-color 0.2s ease;
}
.tu-funciones-avanzadas:hover {
  border-color: color-mix(in srgb, var(--acento) 42%, var(--borde));
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
  font-size: 1.5rem;
  color: var(--enlace);
  transition: transform 0.2s;
}
.tu-funciones-avanzadas[open] .tu-avanzadas-signo {
  transform: rotate(45deg);
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
  gap: 0.45rem;
  margin-bottom: 0.45rem;
  padding: 0.28rem 0.75rem;
  border: 1px solid color-mix(in srgb, var(--acento) 28%, var(--borde));
  border-radius: 999px;
  background: color-mix(in srgb, var(--acento) 9%, var(--superficie));
  color: var(--primario);
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.08em;
}
.tu-modalidad-nombre::before {
  content: "";
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: var(--acento);
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
    background: color-mix(in srgb, #3a8fd4 32%, transparent);
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
  background: color-mix(in srgb, #3a8fd4 38%, transparent);
}
.tu-cabecera-con-monograma {
  position: relative;
  isolation: isolate;
}
.tu-cabecera-monograma-texto {
  position: relative;
  z-index: 1;
}
.tu-monograma-sello {
  position: absolute;
  top: -2.2rem;
  right: 2%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 0;
  background: transparent;
  box-shadow: none;
  opacity: 0.12;
  pointer-events: none;
  user-select: none;
  z-index: 0;
}
.tu-monograma-iniciales--fondo {
  font-size: 8.5rem;
  font-weight: 900;
  line-height: 1;
  letter-spacing: -0.06em;
  color: #3a8fd4;
}
.tu-monograma-insignia {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 4.25rem;
  height: 4.25rem;
  margin: 0 auto 1.25rem;
  border-radius: 20px;
  border: 1px solid color-mix(in srgb, #3a8fd4 36%, var(--borde));
  background: linear-gradient(
    145deg,
    color-mix(in srgb, #ffffff 90%, var(--superficie)) 0%,
    color-mix(in srgb, #3a8fd4 14%, var(--fondo)) 100%
  );
  box-shadow:
    0 16px 34px -18px rgb(13 59 110 / 34%),
    0 0 0 6px color-mix(in srgb, #3a8fd4 10%, transparent);
}
.tu-monograma-iniciales {
  font-size: 1.05rem;
  font-weight: 800;
  letter-spacing: -0.04em;
  color: #3a8fd4;
}
@media (max-width: 639px) {
  .tu-monograma-sello {
    top: -1.4rem;
    right: -0.5rem;
    transform: scale(0.72);
    transform-origin: top right;
  }
}
.tu-precios-complemento {
  padding: 1.35rem 1.5rem;
  border: 1px solid color-mix(in srgb, #3a8fd4 26%, var(--borde));
  border-radius: 22px;
  background: color-mix(in srgb, var(--superficie) 72%, var(--fondo));
  box-shadow: 0 14px 34px -26px rgb(13 59 110 / 24%);
}
.tu-precios-pestanas {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  padding: 0.3rem;
  margin-bottom: 1rem;
  border: 1px solid var(--borde);
  border-radius: 14px;
  background: var(--fondo);
}
.tu-precios-pestana {
  padding: 0.55rem 1.05rem;
  border: 0;
  border-radius: 10px;
  background: transparent;
  color: var(--texto-suave);
  font-size: 0.88rem;
  font-weight: 600;
  cursor: pointer;
  transition:
    background-color 0.2s ease,
    color 0.2s ease,
    box-shadow 0.2s ease;
}
.tu-precios-pestana:hover {
  color: var(--texto);
}
.tu-precios-pestana--activa {
  background: #0d3b6e;
  color: #ffffff;
  box-shadow: 0 6px 16px -8px rgb(13 59 110 / 45%);
}
.tu-faq-mas {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.15rem 1.4rem;
  border: 1px solid color-mix(in srgb, #3a8fd4 30%, var(--borde));
  border-radius: 18px;
  background: color-mix(in srgb, #3a8fd4 7%, var(--superficie));
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
  border: 1px solid color-mix(in srgb, #3a8fd4 38%, var(--borde));
  border-radius: 28px;
  background:
    radial-gradient(
      ellipse 70% 65% at 88% 15%,
      color-mix(in srgb, #3a8fd4 22%, transparent) 0%,
      transparent 70%
    ),
    linear-gradient(
      135deg,
      color-mix(in srgb, #0d3b6e 8%, var(--superficie)) 0%,
      color-mix(in srgb, #3a8fd4 12%, var(--fondo)) 100%
    );
  box-shadow: 0 24px 52px -28px rgb(13 59 110 / 28%);
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
