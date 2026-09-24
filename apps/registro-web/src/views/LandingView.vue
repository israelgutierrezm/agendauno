<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import { trackEvent } from "@/lib/analytics";

const { t } = useI18n();
const DIAS_PRUEBA = 14;

// Iconos de línea (outline 24x24, currentColor) — estilo SF Symbols, sin emoji.
const ICONOS: Record<string, string[]> = {
  agenda: [
    "M4 8.5A1.5 1.5 0 0 1 5.5 7h13A1.5 1.5 0 0 1 20 8.5V19a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 19z",
    "M4 11h16",
    "M8 4.5v3",
    "M16 4.5v3",
  ],
  reservas: ["M5 12.5l4 4 10-10", "M12 21a9 9 0 1 1 0-18 9 9 0 0 1 0 18z"],
  membresias: ["M4 8.5h16v3a2 2 0 0 0 0 4v3H4v-3a2 2 0 0 0 0-4z", "M14 8.5v11"],
  pagos: [
    "M3.5 7.5A1.5 1.5 0 0 1 5 6h14a1.5 1.5 0 0 1 1.5 1.5v9A1.5 1.5 0 0 1 19 18H5a1.5 1.5 0 0 1-1.5-1.5z",
    "M3.5 10h17",
    "M7 14.5h4",
  ],
  pos: [
    "M4 7.5h16l-1 9.5a1.5 1.5 0 0 1-1.5 1.3H6.5A1.5 1.5 0 0 1 5 17z",
    "M8.5 7.5V6a3.5 3.5 0 0 1 7 0v1.5",
    "M9.5 11.5h5",
  ],
  reportes: ["M4 20V13", "M9 20V8", "M14 20v-4", "M19 20V5", "M3.5 20h17"],
} as const;

const funciones = [
  { icono: "agenda", clave: "agenda" },
  { icono: "reservas", clave: "reservas" },
  { icono: "membresias", clave: "membresias" },
  { icono: "pagos", clave: "pagos" },
  { icono: "pos", clave: "pos" },
  { icono: "reportes", clave: "reportes" },
] as const;

const resultados = ["clases", "cobros", "control"] as const;
const sellosConfianza = ["prueba", "tenant", "cobro", "cancelacion"] as const;

const beneficiosMoviles = ["b1", "b2", "b3"] as const;

const pasos = [
  { n: 1, t: "p1t", d: "p1d" },
  { n: 2, t: "p2t", d: "p2d" },
  { n: 3, t: "p3t", d: "p3d" },
] as const;

const faqs = [
  { q: "q1", a: "a1" },
  { q: "q2", a: "a2" },
  { q: "q3", a: "a3" },
  { q: "q4", a: "a4" },
  { q: "q5", a: "a5" },
  { q: "q6", a: "a6" },
  { q: "q7", a: "a7" },
  { q: "q8", a: "a8" },
  { q: "q9", a: "a9" },
] as const;

function medirCta(ubicacion: string, destino: string): void {
  trackEvent("marketing_cta_clicked", {
    placement: ubicacion,
    destination: destino,
  });
}

function verProducto(): void {
  medirCta("hero", "product_demo");
  document
    .querySelector("#producto")
    ?.scrollIntoView({ behavior: "smooth", block: "start" });
}

const DISCIPLINAS = [
  { clave: "pilates", imagen: "pilates-v1.jpg", destacada: true },
  { clave: "pole", imagen: "pole-v1.jpg", destacada: true },
  { clave: "natacion", imagen: "natacion-v1.jpg", destacada: false },
  { clave: "gimnasio", imagen: "gimnasio-v1.jpg", destacada: false },
  { clave: "yoga", imagen: "yoga-v1.jpg", destacada: false },
  { clave: "danza", imagen: "danza-v1.jpg", destacada: false },
  { clave: "crossfit", imagen: "crossfit-v1.jpg", destacada: false },
  { clave: "academias", imagen: "academias-v1.jpg", destacada: false },
] as const;

const verticales = computed(() =>
  DISCIPLINAS.map((disciplina) => ({
    ...disciplina,
    nombre: t(`landing.paraQuien.disciplinas.${disciplina.clave}.nombre`),
    descripcion: t(
      `landing.paraQuien.disciplinas.${disciplina.clave}.descripcion`,
    ),
    alt: t(`landing.paraQuien.disciplinas.${disciplina.clave}.alt`),
    src: `/assets/landing/disciplinas/${disciplina.imagen}`,
  })),
);

// Bloques de la agenda de ejemplo (mockup) — el color por tipo de clase.
const clasesDemo = [
  { clave: "clase1", color: "#c8d8e0", pct: 100, etq: "lleno", vivo: true },
  { clave: "clase2", color: "#e8d0d0", pct: 70, etq: "lugares", vivo: false },
  { clave: "clase3", color: "#dddc8c", pct: 45, etq: "lugares", vivo: false },
] as const;

// --- Animaciones: reveal-on-scroll + contadores, respetando prefers-reduced-motion.
let observador: IntersectionObserver | undefined;

function animarContador(el: HTMLElement): void {
  const objetivo = Number(el.dataset.contador ?? "0");
  const sufijo = el.dataset.sufijo ?? "";
  const duracion = 1100;
  const inicio = performance.now();
  const paso = (ahora: number): void => {
    const p = Math.min(1, (ahora - inicio) / duracion);
    const val = Math.round(objetivo * (1 - Math.pow(1 - p, 3))); // easeOutCubic
    el.textContent = `${val}${sufijo}`;
    if (p < 1) {
      requestAnimationFrame(paso);
    }
  };
  requestAnimationFrame(paso);
}

onMounted(() => {
  const nodos = Array.from(document.querySelectorAll<HTMLElement>(".reveal"));
  const contadores = Array.from(
    document.querySelectorAll<HTMLElement>("[data-contador]"),
  );
  const reducido =
    window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;

  if (reducido) {
    nodos.forEach((n) => n.classList.add("reveal-in"));
    contadores.forEach((c) => {
      c.textContent = `${c.dataset.contador ?? ""}${c.dataset.sufijo ?? ""}`;
    });
    return;
  }

  observador = new IntersectionObserver(
    (entradas) => {
      for (const e of entradas) {
        if (e.isIntersecting) {
          e.target.classList.add("reveal-in");
          e.target
            .querySelectorAll<HTMLElement>("[data-contador]")
            .forEach(animarContador);
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
          <span class="tu-eyebrow">{{ $t("landing.etiqueta") }}</span>
          <h1 class="tu-display max-w-3xl">{{ $t("landing.titulo") }}</h1>
          <p
            class="tu-hero-sub mt-6 text-xl sm:text-2xl max-w-2xl"
            style="color: var(--texto-suave); letter-spacing: -0.01em"
          >
            {{ $t("landing.subtitulo") }}
          </p>
          <div class="tu-hero-actions mt-9 flex flex-wrap items-center gap-3">
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
              @click="verProducto"
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
          <div class="tu-imagen-marco tu-imagen-cielo">
            <img
              src="/assets/landing/turnouno-calendar.webp"
              :alt="$t('landing.producto.imagenAlt')"
              width="1776"
              height="887"
              fetchpriority="high"
              decoding="async"
            />
            <span class="tu-hero-chip">
              <span class="tu-hero-chip-icon" aria-hidden="true">
                <svg
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M4 8.5A1.5 1.5 0 0 1 5.5 7h13A1.5 1.5 0 0 1 20 8.5V19a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 19z"
                  />
                  <path d="M4 11h16M8 4.5v3M16 4.5v3" />
                </svg>
              </span>
              <span>{{ $t("landing.producto.agendaTitulo") }}</span>
            </span>
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

    <!-- ===================== RESULTADOS ===================== -->
    <section class="tu-banda" :style="{ background: 'var(--superficie)' }">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <p class="tu-seccion-etiqueta reveal">
          {{ $t("landing.resultados.etiqueta") }}
        </p>
        <h2 class="tu-titulo mt-3 max-w-4xl reveal">
          {{ $t("landing.resultados.titulo") }}
        </h2>
        <div class="tu-resultados mt-12 grid gap-5 lg:grid-cols-3">
          <article
            v-for="(resultado, i) in resultados"
            :key="resultado"
            class="tu-card p-7 sm:p-8 reveal"
            :style="{ transitionDelay: i * 90 + 'ms' }"
          >
            <div
              class="tu-resultado-grafico"
              :class="`tu-resultado-grafico--${resultado}`"
              aria-hidden="true"
            >
              <template v-if="resultado === 'clases'">
                <div class="tu-ocupacion-anillo">
                  <strong>92%</strong>
                  <span>lleno</span>
                </div>
                <div class="tu-asientos">
                  <span
                    v-for="n in 8"
                    :key="n"
                    :class="{ libre: n === 8 }"
                  ></span>
                </div>
              </template>
              <template v-else-if="resultado === 'cobros'">
                <div v-for="n in 3" :key="n" class="tu-pago-linea">
                  <span class="tu-pago-avatar"></span>
                  <span class="tu-pago-barra"></span>
                  <span class="tu-pago-check">✓</span>
                </div>
              </template>
              <template v-else>
                <div class="tu-mini-barras">
                  <span v-for="n in 7" :key="n"></span>
                </div>
                <div class="tu-mini-tendencia">
                  <span></span><span></span><span></span><span></span>
                </div>
              </template>
            </div>
            <span class="tu-resultado-num" aria-hidden="true"
              >0{{ i + 1 }}</span
            >
            <h3 class="mt-8 text-2xl font-semibold tracking-tight">
              {{ $t(`landing.puntos.${resultado}`) }}
            </h3>
            <p
              class="mt-3 text-base leading-relaxed"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t(`landing.puntos.${resultado}Desc`) }}
            </p>
          </article>
        </div>
      </div>
    </section>

    <!-- ===================== PRODUCTO ===================== -->
    <section
      id="producto"
      class="tu-banda tu-ancla"
      :style="{ background: 'var(--fondo)' }"
    >
      <div class="mx-auto max-w-5xl px-4 sm:px-6 pt-20 sm:pt-28">
        <p class="tu-seccion-etiqueta reveal">
          {{ $t("landing.producto.etiqueta") }}
        </p>
        <h2 class="tu-titulo mt-3 max-w-3xl reveal">
          {{ $t("landing.producto.titulo") }}
        </h2>
        <p
          class="mt-4 text-lg max-w-2xl reveal"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("landing.producto.subtitulo") }}
        </p>
      </div>

      <!-- Mockup funcional animado: los textos siguen siendo HTML traducible. -->
      <div class="mx-auto max-w-5xl px-4 sm:px-6 pt-10 pb-20 sm:pb-28">
        <div class="tu-ventana reveal mx-auto max-w-4xl">
          <!-- Barra de título -->
          <div class="tu-ventana-barra">
            <span class="tu-punto" style="background: #ff5f57"></span>
            <span class="tu-punto" style="background: #febc2e"></span>
            <span class="tu-punto" style="background: #28c840"></span>
            <span
              class="ml-3 text-xs font-semibold"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("landing.producto.barra") }} · AgendaUno</span
            >
            <span
              class="ml-auto text-[10px] uppercase tracking-wide"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("landing.producto.demo") }}</span
            >
          </div>
          <div class="flex">
            <!-- Sidebar simulada -->
            <div
              class="hidden sm:flex w-40 shrink-0 flex-col gap-2 p-3"
              :style="{ background: 'var(--barra)' }"
            >
              <div
                class="h-6 rounded-lg"
                :style="{ background: 'var(--barra-activo)' }"
              ></div>
              <div
                v-for="i in 6"
                :key="i"
                class="h-3.5 rounded-md"
                :style="{ background: 'var(--barra-suave)', opacity: 0.8 }"
              ></div>
            </div>
            <!-- Contenido -->
            <div
              class="flex-1 p-4 sm:p-6"
              :style="{ background: 'var(--superficie)' }"
            >
              <!-- Métricas (contadores animados, datos de ejemplo) -->
              <div class="grid grid-cols-3 gap-3">
                <div class="tu-card p-3">
                  <div class="text-2xl font-extrabold" data-contador="128">
                    0
                  </div>
                  <div
                    class="text-[11px]"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ $t("landing.producto.m1") }}
                  </div>
                </div>
                <div class="tu-card p-3">
                  <div
                    class="text-2xl font-extrabold"
                    data-contador="86"
                    data-sufijo="%"
                  >
                    0%
                  </div>
                  <div
                    class="text-[11px]"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ $t("landing.producto.m2") }}
                  </div>
                </div>
                <div class="tu-card p-3">
                  <div class="text-2xl font-extrabold" data-contador="24">
                    0
                  </div>
                  <div
                    class="text-[11px]"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ $t("landing.producto.m3") }}
                  </div>
                </div>
              </div>
              <h4 class="mt-5 font-semibold text-sm">
                {{ $t("landing.producto.agendaTitulo") }}
              </h4>
              <div class="mt-2 space-y-2">
                <div v-for="c in clasesDemo" :key="c.clave" class="tu-card p-3">
                  <div class="flex items-center justify-between gap-2">
                    <span class="flex items-center gap-2 text-sm font-medium">
                      <span
                        class="h-2.5 w-2.5 rounded-full"
                        :style="{ background: c.color }"
                      ></span>
                      {{ $t(`landing.producto.${c.clave}`) }}
                      <span
                        v-if="c.vivo"
                        class="tu-vivo"
                        aria-hidden="true"
                      ></span>
                    </span>
                    <span
                      class="tu-badge"
                      :class="
                        c.etq === 'lleno' ? 'tu-badge-aviso' : 'tu-badge-exito'
                      "
                    >
                      {{
                        c.etq === "lleno"
                          ? $t("landing.producto.lleno")
                          : $t("landing.producto.lugares")
                      }}
                    </span>
                  </div>
                  <div class="tu-barra mt-2">
                    <span
                      class="tu-barra-fill"
                      :style="{ '--pct': c.pct + '%', background: c.color }"
                    ></span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== CÓMO FUNCIONA ===================== -->
    <section class="tu-banda" :style="{ background: 'var(--superficie)' }">
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
            <h3 class="mt-4 font-semibold text-xl tracking-tight">
              {{ $t(`landing.comoFunciona.${p.t}`) }}
            </h3>
            <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`landing.comoFunciona.${p.d}`) }}
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== FUNCIONES ===================== -->
    <section class="tu-banda" :style="{ background: 'var(--fondo)' }">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <h2 class="tu-titulo reveal">{{ $t("landing.seccionTitulo") }}</h2>
        <p
          class="mt-3 text-lg max-w-2xl reveal"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("landing.seccionSub") }}
        </p>
        <div
          class="tu-funciones mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
        >
          <div
            v-for="(f, i) in funciones"
            :key="f.clave"
            class="tu-card p-7 reveal"
            :style="{ transitionDelay: (i % 3) * 90 + 'ms' }"
          >
            <span class="tu-icono-caja" aria-hidden="true">
              <svg
                width="24"
                height="24"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path v-for="(d, j) in ICONOS[f.icono]" :key="j" :d="d" />
              </svg>
            </span>
            <h3 class="mt-4 font-semibold text-xl tracking-tight">
              {{ $t(`landing.funciones.${f.clave}`) }}
            </h3>
            <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`landing.funciones.${f.clave}Desc`) }}
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== OPERACIÓN MÓVIL ===================== -->
    <section class="tu-banda" :style="{ background: 'var(--superficie)' }">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div
          class="tu-operacion grid items-center gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16"
        >
          <div class="reveal">
            <p class="tu-seccion-etiqueta">
              {{ $t("landing.operacion.etiqueta") }}
            </p>
            <h2 class="tu-titulo mt-3">{{ $t("landing.operacion.titulo") }}</h2>
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
              src="/assets/landing/turnouno-checkin-pos.webp"
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
    <section class="tu-banda" :style="{ background: 'var(--fondo)' }">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div
          class="tu-precio-layout grid items-center gap-10 lg:grid-cols-[1fr_1.05fr] lg:gap-16"
        >
          <div class="reveal">
            <p class="tu-seccion-etiqueta">
              {{ $t("landing.precio.etiqueta") }}
            </p>
            <h2 class="tu-titulo mt-3">{{ $t("landing.precio.titulo") }}</h2>
            <p
              class="mt-5 max-w-xl text-lg leading-relaxed"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("landing.precio.subtitulo") }}
            </p>
          </div>

          <article class="tu-precio-card reveal">
            <span class="tu-precio-badge">{{
              $t("landing.precio.badge", { dias: DIAS_PRUEBA })
            }}</span>
            <p
              class="mt-6 text-sm font-semibold uppercase tracking-widest"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("landing.precio.modelo") }}
            </p>
            <p class="mt-2 text-4xl sm:text-5xl font-bold tracking-tight">
              {{ $t("landing.precio.valor") }}
            </p>
            <p class="mt-3" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("landing.precio.detalle") }}
            </p>
            <ul class="mt-7 space-y-3" role="list">
              <li v-for="n in 4" :key="n" class="tu-check-item">
                <span class="tu-check" aria-hidden="true">✓</span>
                <span>{{ $t(`landing.precio.i${n}`) }}</span>
              </li>
            </ul>
            <RouterLink
              class="tu-btn tu-btn-primario mt-8 w-full justify-center text-base py-3"
              :to="{ name: 'registro' }"
              @click="medirCta('pricing', 'register')"
            >
              {{ $t("landing.ctaRegistrar") }}
            </RouterLink>
          </article>
        </div>
      </div>
    </section>

    <!-- ===================== COMUNIDAD ===================== -->
    <section class="tu-banda" :style="{ background: 'var(--superficie)' }">
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <div
          class="grid items-center gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:gap-16"
        >
          <div class="reveal">
            <p class="tu-seccion-etiqueta">
              {{ $t("landing.comunidad.etiqueta") }}
            </p>
            <h2 class="tu-titulo mt-3">{{ $t("landing.comunidad.titulo") }}</h2>
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
              <RouterLink
                class="tu-link-flecha"
                :to="{ name: 'directorio' }"
                @click="medirCta('community_benefit', 'directory')"
              >
                {{ $t("landing.comunidad.enlace") }}
                <span aria-hidden="true">›</span>
              </RouterLink>
            </div>
          </div>

          <div class="tu-escaparate-demo reveal" aria-hidden="true">
            <div class="tu-escaparate-cabecera">
              <span class="tu-escaparate-logo">LU</span>
              <div>
                <p class="font-bold text-lg">Lumen Pilates</p>
                <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                  Roma Norte · Ciudad de México
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

    <!-- ===================== DISCIPLINAS ===================== -->
    <section
      class="tu-banda tu-disciplinas"
      :style="{ background: 'var(--fondo)' }"
    >
      <div class="mx-auto max-w-6xl px-4 sm:px-6 py-20 sm:py-28">
        <p class="tu-seccion-etiqueta reveal">
          {{ $t("landing.paraQuien.etiqueta") }}
        </p>
        <h2 class="tu-titulo mt-3 reveal">
          {{ $t("landing.paraQuien.titulo") }}
        </h2>
        <p
          class="mt-3 text-lg max-w-2xl reveal"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("landing.paraQuien.subtitulo") }}
        </p>
        <div class="tu-disciplinas-grid mt-10">
          <article
            v-for="(v, i) in verticales"
            :key="v.clave"
            class="tu-disciplina-card reveal"
            :class="[
              v.destacada ? 'tu-disciplina-card--destacada' : '',
              `tu-disciplina-card--${v.clave}`,
            ]"
            :style="{
              transitionDelay: (i % 4) * 80 + 'ms',
            }"
          >
            <img
              class="tu-disciplina-imagen"
              :src="v.src"
              :alt="v.alt"
              loading="lazy"
              decoding="async"
              width="1122"
              height="1402"
            />
            <span
              v-if="v.clave === 'pole'"
              class="tu-disciplina-brillo"
              aria-hidden="true"
            ></span>
            <div class="tu-disciplina-contenido">
              <span class="tu-disciplina-chip">
                {{ $t("landing.paraQuien.incluye") }}
              </span>
              <h3>{{ v.nombre }}</h3>
              <p>{{ v.descripcion }}</p>
            </div>
            <span class="tu-disciplina-flecha" aria-hidden="true">✦</span>
          </article>
        </div>
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
        <h2
          class="font-light tracking-tight text-4xl sm:text-5xl"
          style="letter-spacing: -0.025em; line-height: 1.07"
        >
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
  --landing-radius: 28px;
  overflow: clip;
}
.tu-ancla {
  scroll-margin-top: 3.5rem;
}
.tu-hero-layout {
  display: grid;
  min-height: calc(100svh - 3.5rem);
  align-items: center;
  gap: clamp(2.5rem, 5vw, 4rem);
  padding-top: clamp(3.5rem, 8vh, 6.5rem);
  padding-bottom: clamp(3.5rem, 8vh, 6.5rem);
}
.tu-hero-copy {
  position: relative;
  z-index: 1;
}
.tu-hero-visual {
  min-width: 0;
  transform-origin: 50% 100%;
}
.tu-hero-visual .tu-imagen-marco {
  position: relative;
  aspect-ratio: 5 / 4;
}
.tu-hero-visual img {
  height: 100%;
  object-fit: cover;
  object-position: center;
}
.tu-hero-chip {
  position: absolute;
  bottom: 1.25rem;
  left: 1.25rem;
  display: inline-flex;
  align-items: center;
  gap: 0.65rem;
  max-width: calc(100% - 2.5rem);
  padding: 0.65rem 0.9rem;
  border: 1px solid rgb(255 255 255 / 72%);
  border-radius: 999px;
  background: rgb(255 255 255 / 84%);
  color: #1d1d1f;
  font-size: 0.88rem;
  font-weight: 600;
  backdrop-filter: blur(18px);
  -webkit-backdrop-filter: blur(18px);
}
.tu-hero-chip-icon {
  display: inline-flex;
  color: #0066cc;
}
.tu-hero-proof {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.45rem;
}

.tu-confianza {
  background: var(--fondo);
  padding: 0 0 2.5rem;
}
.tu-confianza-grid {
  display: grid;
  gap: 0.75rem;
  padding: 1.1rem 1.25rem;
  border-radius: 999px;
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

/* Títulos grandes y aireados: la jerarquía hace el trabajo, no los adornos. */
.tu-titulo {
  font-weight: 700;
  font-size: clamp(2.35rem, 5vw, 3.5rem);
  letter-spacing: -0.028em;
  line-height: 1.05;
}
.tu-display {
  font-weight: 700;
  font-size: clamp(3.2rem, 5vw, 4.75rem);
  letter-spacing: -0.04em;
  line-height: 1.04;
  text-wrap: balance;
}
.tu-eyebrow,
.tu-seccion-etiqueta {
  display: inline-block;
  color: #b64400;
  font-size: 0.82rem;
  font-weight: 600;
  letter-spacing: 0.01em;
}
.tu-seccion-etiqueta {
  color: var(--texto-suave);
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

/* Las tarjetas de la landing son superficies planas de 28 px, sin borde ni sombra. */
.tu-landing .tu-card {
  border: 0;
  border-radius: var(--landing-radius);
}
.tu-resultados .tu-card,
.tu-pasos .tu-card,
.tu-landing details.tu-card {
  background: var(--fondo);
}
.tu-funciones .tu-card {
  background: var(--superficie);
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
.tu-resultado-grafico--clases {
  align-items: center;
  justify-content: space-around;
  background: #e7f2f8;
}
.tu-ocupacion-anillo {
  position: relative;
  display: grid;
  width: 7.2rem;
  aspect-ratio: 1;
  place-content: center;
  border-radius: 50%;
  background: conic-gradient(#0071e3 0 92%, rgb(255 255 255 / 0.72) 92% 100%);
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
  background: #f5e8e8;
}
.tu-pago-linea {
  display: grid;
  grid-template-columns: 2.25rem 1fr 1.8rem;
  align-items: center;
  gap: 0.7rem;
  min-height: 2.8rem;
  padding: 0 0.7rem;
  border: 1px solid rgb(110 50 50 / 0.07);
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
  background: linear-gradient(145deg, #d89c9c, #7f9ab4);
}
.tu-pago-barra {
  width: 72%;
  height: 0.48rem;
  border-radius: 999px;
  background: #d8c9c9;
  box-shadow: 0 0.78rem #eadfdf;
}
.tu-pago-check {
  display: grid;
  width: 1.55rem;
  height: 1.55rem;
  place-content: center;
  border-radius: 50%;
  background: #21844a;
  color: #fff;
  font-size: 0.72rem;
  font-weight: 700;
}
.tu-resultado-grafico--control {
  align-items: flex-end;
  justify-content: space-between;
  padding: 1.35rem 1.45rem 1.2rem;
  background: #e9e9e2;
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
  background: #596680;
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
  background: #d06435;
  box-shadow: 0 0 0 1px rgb(208 100 53 / 0.28);
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
  border-radius: var(--landing-radius);
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
  background: #f6e5e7;
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
  border-radius: var(--landing-radius);
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
.tu-icono-caja {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 3.25rem;
  width: 3.25rem;
  border-radius: 1rem;
  background: var(--fondo);
  color: var(--texto);
}

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

/* Precio: una sola tarjeta de decisión, sin planes artificiales. */
.tu-precio-card {
  padding: clamp(1.75rem, 4vw, 3rem);
  border-radius: var(--landing-radius);
  background: var(--superficie);
}
.tu-precio-badge {
  display: inline-flex;
  padding: 0.45rem 0.75rem;
  border-radius: 999px;
  background: #f0e4d3;
  color: #6b360d;
  font-size: 0.82rem;
  font-weight: 700;
}

/* Vista pública del estudio: prueba visual del beneficio de Comunidad. */
.tu-escaparate-demo {
  overflow: hidden;
  border-radius: var(--landing-radius);
  background: var(--fondo);
}
.tu-escaparate-cabecera {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 1.5rem;
  background: #edf5fb;
}
.tu-escaparate-logo {
  display: inline-flex;
  height: 3.25rem;
  width: 3.25rem;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  border-radius: 1rem;
  background: #2e3642;
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
  border-radius: 999px;
  background: #0071e3;
  color: #fff;
  font-size: 0.9rem;
  font-weight: 600;
}

/* Mosaico editorial por disciplina. */
.tu-disciplinas {
  overflow: hidden;
}
.tu-disciplinas-grid {
  display: grid;
  grid-template-columns: 1fr;
  grid-auto-flow: dense;
  gap: 1rem;
}
.tu-disciplina-card {
  position: relative;
  min-height: 20rem;
  overflow: hidden;
  border-radius: var(--landing-radius);
  background: #1b1d22;
  color: #fff;
  isolation: isolate;
  transition:
    opacity 0.7s ease,
    transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
}
.tu-disciplina-card::after {
  position: absolute;
  z-index: 1;
  inset: 0;
  background:
    linear-gradient(180deg, rgb(7 12 19 / 0.04) 28%, rgb(7 12 19 / 0.82) 100%),
    linear-gradient(90deg, rgb(7 12 19 / 0.28), transparent 52%);
  content: "";
  pointer-events: none;
}
.tu-disciplina-imagen {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transform: scale(1.025);
  transition: transform 1s cubic-bezier(0.22, 1, 0.36, 1);
}
.tu-disciplina-card:hover .tu-disciplina-imagen {
  transform: scale(1.075);
}
.tu-disciplina-contenido {
  position: absolute;
  z-index: 2;
  right: 1.35rem;
  bottom: 1.35rem;
  left: 1.35rem;
  max-width: 28rem;
  text-shadow: 0 1px 18px rgb(0 0 0 / 0.28);
}
.tu-disciplina-chip {
  display: inline-flex;
  margin-bottom: 0.75rem;
  padding: 0.42rem 0.7rem;
  border: 1px solid rgb(255 255 255 / 0.34);
  border-radius: 999px;
  background: rgb(10 16 24 / 0.28);
  backdrop-filter: blur(12px);
  color: rgb(255 255 255 / 0.9);
  font-size: 0.69rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}
.tu-disciplina-contenido h3 {
  font-size: clamp(1.7rem, 3vw, 2.45rem);
  font-weight: 700;
  letter-spacing: -0.035em;
  line-height: 1;
}
.tu-disciplina-contenido p {
  max-width: 23rem;
  margin-top: 0.55rem;
  color: rgb(255 255 255 / 0.84);
  font-size: 0.9rem;
  line-height: 1.45;
}
.tu-disciplina-flecha {
  position: absolute;
  z-index: 2;
  top: 1.15rem;
  right: 1.15rem;
  display: grid;
  width: 2.45rem;
  height: 2.45rem;
  place-content: center;
  border: 1px solid rgb(255 255 255 / 0.35);
  border-radius: 50%;
  background: rgb(10 16 24 / 0.22);
  backdrop-filter: blur(12px);
  color: #fff;
  font-size: 1.1rem;
}
.tu-disciplina-card--pole .tu-disciplina-imagen {
  animation: tu-pole-movimiento 9s ease-in-out infinite alternate;
}
.tu-disciplina-brillo {
  position: absolute;
  z-index: 1;
  top: -18%;
  right: -12%;
  width: 13rem;
  height: 13rem;
  border-radius: 50%;
  background: radial-gradient(circle, rgb(255 219 217 / 0.5), transparent 67%);
  filter: blur(6px);
  animation: tu-pole-brillo 5.5s ease-in-out infinite;
}
@keyframes tu-pole-movimiento {
  from {
    transform: scale(1.04) translate3d(0, -0.6%, 0);
  }
  to {
    transform: scale(1.1) translate3d(-1.2%, 1.1%, 0);
  }
}
@keyframes tu-pole-brillo {
  50% {
    opacity: 0.45;
    transform: translate3d(-2.5rem, 2rem, 0) scale(1.18);
  }
}

/* Reveal on scroll. */
.reveal {
  opacity: 0;
  transform: translateY(18px);
  transition:
    opacity 0.7s ease,
    transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
  will-change: opacity, transform;
}
.reveal-in {
  opacity: 1;
  transform: none;
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
  .reveal,
  .tu-barra-fill,
  .tu-disciplina-card,
  .tu-imagen-marco img {
    transition: none;
  }
  .tu-vivo,
  .tu-hero-visual.reveal-in .tu-imagen-marco,
  .tu-disciplina-card--pole .tu-disciplina-imagen,
  .tu-disciplina-brillo,
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
  .tu-disciplinas-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (min-width: 640px) and (max-width: 1023px) {
  .tu-disciplinas-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .tu-disciplina-card--destacada {
    grid-column: span 2;
    min-height: 26rem;
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
  .tu-hero-visual .tu-imagen-marco {
    aspect-ratio: 16 / 10;
  }
}

@media (max-width: 639px) {
  .tu-display {
    font-size: clamp(2.65rem, 12vw, 3.25rem);
  }
  .tu-imagen-marco {
    border-radius: 20px;
  }
  .tu-hero-layout {
    gap: 1.75rem;
    padding-top: 2.5rem;
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
  .tu-hero-chip {
    bottom: 0.75rem;
    left: 0.75rem;
    max-width: calc(100% - 1.5rem);
    padding: 0.5rem 0.7rem;
    font-size: 0.78rem;
  }
  .tu-confianza {
    padding-bottom: 1.5rem;
  }
  .tu-confianza-grid {
    grid-template-columns: 1fr 1fr;
    border-radius: 22px;
  }
  .tu-clase-publica {
    grid-template-columns: 3.1rem minmax(0, 1fr);
  }
  .tu-clase-publica > .tu-badge {
    display: none;
  }
  .tu-disciplina-card {
    min-height: 22rem;
    border-radius: 22px;
  }
}
</style>
