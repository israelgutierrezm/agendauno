<script setup lang="ts">
import { usePreciosPublicos } from "@/marketing/preciosPublicos";
import { computed, ref, type Component } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import CarruselNegocios from "@/components/CarruselNegocios.vue";
import FondoHero from "@/components/FondoHero.vue";
import DemoPaginaPublicaCitas from "@/components/DemoPaginaPublicaCitas.vue";
import DemoPaginaPublicaClases from "@/components/DemoPaginaPublicaClases.vue";
import FuncionesLanding from "@/components/FuncionesLanding.vue";
import HeroCollage, { type FotoCollage } from "@/components/HeroCollage.vue";
import IconoNav from "@/components/IconoNav.vue";
import NegociosAnimados from "@/components/NegociosAnimados.vue";
import PreciosLanding from "@/components/PreciosLanding.vue";
import PreguntasFrecuentes from "@/components/PreguntasFrecuentes.vue";
import ProductoDemo from "@/components/ProductoDemo.vue";
import SellosConfianza from "@/components/SellosConfianza.vue";
import SolucionesEnlaces from "@/components/SolucionesEnlaces.vue";
import TextoDestacado from "@/components/TextoDestacado.vue";
import { trackEvent } from "@/lib/analytics";
import { PRODUCTOS, productoDeModalidad } from "@/lib/producto";
import { useRegistroDelProducto } from "@/marketing/registroProducto";
import { useRevelar } from "@/lib/revelar";
import {
  MODALIDADES,
  NOMBRE_RUTA_MODALIDAD,
  imagenNegocio,
  perfilDeNegocio,
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
| tarjetas blancas (funciones, precios, carrusel) van en gris.
*/
const props = defineProps<{ modo: Modo }>();
const { t } = useI18n();

// Los días de prueba de su modalidad: los que publica el superadmin (con respaldo).
const precios = usePreciosPublicos();
const DIAS_PRUEBA = computed(() => precios.datos[props.modo].dias_prueba);
// Un producto que aún no abre registros (TurnoUno antes de su lanzamiento, ADR 0108):
// sus botones llevan a dejar los datos.
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
const preguntas = computed(() =>
  contenido.value.preguntas.map((clave) => ({
    clave,
    pregunta: t(k(`faq.${clave}.q`)),
    respuesta: t(k(`faq.${clave}.a`)),
  })),
);

// «* Solo para clientes de México» solo donde algo lleva el asterisco.
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

function medirRegistro(placement: string, giro: string | null = null): void {
  trackEvent("marketing_cta_clicked", {
    placement,
    destination: "register",
    mode: props.modo,
    ...(giro === null ? {} : { business_profile: giro }),
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
            {{ contenido.nombre }}
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
            class="tu-hero-sub mt-5 text-lg sm:text-xl max-w-2xl"
            style="color: var(--texto-suave); letter-spacing: -0.01em"
          >
            {{ t(k("hero.subtitulo")) }}
          </p>
          <!-- El botón del hero va en azul; el rosa queda para el menú y el cierre. -->
          <div class="tu-hero-actions mt-7 flex flex-wrap items-center gap-3">
            <RouterLink
              class="tu-btn tu-btn-primario tu-btn-azul text-base px-7 py-3"
              :to="registro"
              data-cta="hero"
              @click="medirRegistro('hero')"
            >
              {{ textoRegistro }}
            </RouterLink>
            <a
              class="tu-btn tu-btn-fantasma text-base px-7 py-3"
              href="#producto"
              @click="medirProducto"
            >
              {{ t("landing.modalidad.verAgenda") }}
            </a>
          </div>
          <p class="tu-hero-proof mt-4 text-sm" :style="SUAVE">
            {{
              registroAbierto
                ? t("landing.prueba", { dias: DIAS_PRUEBA })
                : t("landing.prelanzamiento.proximamente")
            }}
            <span aria-hidden="true">·</span>
            {{ t("landing.pieHero") }}
          </p>
        </div>
        <HeroCollage
          :fotos="fotosHero"
          :aviso="{
            titulo: t(k('hero.avisoTitulo')),
            detalle: t(k('hero.avisoDetalle')),
          }"
        />
      </div>
    </section>

    <!-- ===================== CONFIANZA ===================== -->
    <SellosConfianza :modo="modo" />

    <!-- ===================== PRODUCTO ===================== -->
    <section id="producto" class="tu-banda tu-ancla" :style="BANDA_SUPERFICIE">
      <div class="mx-auto max-w-5xl px-4 sm:px-6 pt-20 sm:pt-28">
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
      <div class="mx-auto max-w-6xl px-4 sm:px-6 pt-10 pb-20 sm:pb-28">
        <ProductoDemo :modo="modo" />
        <div class="mt-8 text-center">
          <RouterLink
            class="tu-btn tu-btn-primario tu-btn-azul px-7 py-3"
            :to="registro"
            data-cta="product_demo"
            @click="medirRegistro('product_demo')"
          >
            {{ t(k("producto.cta")) }}
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

    <!-- ===================== FUNCIONES ===================== -->
    <section id="soluciones" class="tu-banda tu-ancla" :style="BANDA_FONDO">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
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
        <FuncionesLanding :modo="modo" />
      </div>
    </section>

    <!-- ===================== CÓMO FUNCIONA ===================== -->
    <section
      id="como-funciona"
      class="tu-banda tu-ancla"
      :style="BANDA_SUPERFICIE"
    >
      <div class="mx-auto max-w-5xl px-4 sm:px-6 py-20 sm:py-28">
        <h2 class="tu-titulo reveal">
          {{ t("landing.modalidad.pasosTitulo") }}
        </h2>
        <p class="mt-3 text-lg max-w-2xl reveal" :style="SUAVE">
          {{ t(k("comoFunciona.subtitulo")) }}
        </p>
        <ol class="tu-pasos mt-12 grid gap-5 md:grid-cols-3" role="list">
          <li
            v-for="(paso, i) in pasos"
            :key="paso.clave"
            class="tu-card p-7 reveal"
            :data-paso="paso.clave"
            :style="{ transitionDelay: i * 90 + 'ms' }"
          >
            <span class="tu-paso-num" aria-hidden="true">{{ i + 1 }}</span>
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
      </div>
    </section>

    <!-- ===================== GIROS ===================== -->
    <section
      id="para-quien"
      class="tu-banda tu-ancla tu-modalidad-giros"
      :style="BANDA_FONDO"
    >
      <div class="mx-auto max-w-7xl px-4 sm:px-6 py-20 sm:py-28">
        <div class="text-center mx-auto max-w-3xl">
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
            class="tu-btn tu-btn-primario tu-btn-azul px-7 py-3"
            :to="registroCon(elegido ? perfilDeNegocio(activo?.clave) : null)"
            data-cta="business_carousel"
            @click="
              medirRegistro(
                'business_carousel',
                elegido ? perfilDeNegocio(activo?.clave) : null,
              )
            "
          >
            {{ t(k("giros.cta")) }}
            <IconoNav nombre="flecha" :tam="18" />
          </RouterLink>
          <p class="mt-3 text-sm" :style="SUAVE">{{ t(k("giros.pie")) }}</p>
          <p v-if="notas.giros" class="tu-nota-mexico">
            {{ t("landing.soloMexico") }}
          </p>
        </CarruselNegocios>
        <div class="mt-10">
          <h3 class="text-lg font-medium text-center">
            {{ t(k("giros.soluciones")) }}
          </h3>
          <SolucionesEnlaces :modo="modo" />
        </div>
        <p
          v-if="contenido.notaSalud"
          class="tu-alcance-salud mt-10 max-w-3xl mx-auto"
        >
          <strong>{{ t("landing.paraQuien.saludTitulo") }}</strong>
          {{ t("landing.paraQuien.saludAlcance") }}
        </p>
      </div>
    </section>

    <!-- ===================== RECEPCIÓN Y VENTAS ===================== -->
    <section id="operacion" class="tu-banda tu-ancla" :style="BANDA_SUPERFICIE">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div
          class="tu-operacion grid items-center gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16"
        >
          <div class="reveal">
            <p class="tu-seccion-etiqueta">
              {{ t("landing.modalidad.operacionEtiqueta") }}
            </p>
            <h2 class="tu-titulo mt-3">{{ t(k("operacion.titulo")) }}</h2>
            <p class="mt-5 text-lg max-w-xl" :style="SUAVE">
              {{ t(k("operacion.subtitulo")) }}
            </p>
            <ul class="mt-8 space-y-4" role="list">
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
            <RouterLink
              class="tu-link-flecha mt-8"
              :to="registro"
              @click="medirRegistro('operations')"
            >
              {{ t(k("operacion.enlace")) }}
              <IconoNav nombre="chevron" :tam="16" />
            </RouterLink>
          </div>
          <figure class="tu-imagen-marco tu-modalidad-recepcion reveal">
            <img
              :src="contenido.fotos.recepcion.src"
              :alt="contenido.fotos.recepcion.alt"
              width="1536"
              height="1024"
              loading="lazy"
              decoding="async"
            />
          </figure>
        </div>
      </div>
    </section>

    <!-- ===================== PRECIOS ===================== -->
    <section id="precios" class="tu-banda tu-ancla" :style="BANDA_FONDO">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div class="max-w-3xl reveal">
          <p class="tu-seccion-etiqueta">
            {{ t("landing.modalidad.precioEtiqueta") }}
          </p>
          <h2 class="tu-titulo mt-3">{{ t(k("precio.titulo")) }}</h2>
          <p class="mt-5 text-lg leading-relaxed" :style="SUAVE">
            {{ t(k("precio.subtitulo")) }}
          </p>
        </div>
        <!-- Cada tarjeta ya lleva su «Probar» y «Sin tarjeta»: sin repetirlo aquí. -->
        <PreciosLanding :modo="modo" />
      </div>
    </section>

    <!-- ===================== PÁGINA PÚBLICA ===================== -->
    <section
      id="pagina-publica"
      class="tu-banda tu-ancla"
      :style="BANDA_SUPERFICIE"
    >
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div
          class="grid items-center gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16"
        >
          <div class="reveal">
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
                class="tu-btn tu-btn-primario tu-btn-azul px-6 py-3"
                :to="registro"
                data-cta="public_page"
                @click="medirRegistro('public_page')"
              >
                {{ textoRegistro }}
              </RouterLink>
            </div>
          </div>
          <div class="tu-modalidad-demo reveal">
            <component :is="DEMO_PAGINA[modo]" />
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== PREGUNTAS ===================== -->
    <section id="preguntas" class="tu-banda tu-ancla" :style="BANDA_FONDO">
      <div class="mx-auto max-w-4xl px-4 sm:px-6 py-20 sm:py-28">
        <PreguntasFrecuentes
          :titulo="t('landing.modalidad.faqTitulo')"
          :preguntas="preguntas"
        >
          <p v-if="notas.preguntas" class="tu-nota-mexico">
            {{ t("landing.soloMexico") }}
          </p>
        </PreguntasFrecuentes>
      </div>
    </section>

    <!-- ===================== LLAMADO FINAL ===================== -->
    <section class="tu-banda tu-modalidad-final" :style="BANDA_SUPERFICIE">
      <div
        class="mx-auto max-w-3xl px-4 sm:px-6 py-20 sm:py-28 text-center reveal"
      >
        <h2 class="tu-titulo tu-titulo-final">
          {{
            registroAbierto
              ? t(k("final.titulo"))
              : t("landing.prelanzamiento.finalTitulo")
          }}
        </h2>
        <p class="mt-4 text-lg" :style="SUAVE">
          {{
            registroAbierto
              ? t(k("final.subtitulo"), { dias: DIAS_PRUEBA })
              : t("landing.prelanzamiento.finalSubtitulo", {
                  dias: DIAS_PRUEBA,
                })
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
        <!-- Enlace discreto a la otra modalidad: cada negocio es de una sola. -->
        <p class="tu-modalidad-otra">
          {{ t(k("final.otra")) }}
          <RouterLink
            :to="otra"
            class="inline-flex items-center gap-1"
            @click="medirOtra"
            >{{ t(k("final.otraEnlace"), { otro: nombreOtro }) }}
            <IconoNav nombre="flecha" :tam="16"
          /></RouterLink>
        </p>
      </div>
    </section>
  </div>
</template>

<style scoped>
.tu-modalidad-nombre {
  margin-bottom: 0.65rem;
}
/* El carrusel gira fuera de su caja: sin desbordar la página. */
.tu-modalidad-giros {
  overflow: hidden;
}
/* La foto de recepción, la misma proporción en las dos modalidades. */
.tu-modalidad-recepcion img {
  aspect-ratio: 3 / 2;
  object-fit: cover;
  object-position: 50% 30%;
}
.tu-modalidad-demo {
  min-width: 0;
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
