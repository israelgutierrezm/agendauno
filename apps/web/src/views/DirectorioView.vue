<script setup lang="ts">
import { onMounted, ref } from "vue";
import { RouterLink, useRouter } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import { api, mensajeDeError } from "@/lib/api";
import { PRODUCTOS, productoActual } from "@/lib/producto";
import { trackEvent } from "@/lib/analytics";
import { recordarNegocio } from "@/lib/negociosRecientes";
import {
  MODOS,
  PERFILES_POR_MODO,
  perfilVisibleAlPublico,
} from "@/marketing/modalidades";
import { useRegistroDelProducto } from "@/marketing/registroProducto";

interface EstudioDirectorio {
  slug: string;
  nombre: string;
  perfil: string;
  logo_url: string | null;
  ciudad: string | null;
  pais: string | null;
}

const router = useRouter();
// Si el producto aún no recibe registros (ADR 0108), quien administra un negocio deja
// sus datos en la lista de interesados (el registro la muestra).
const { abierto: registroAbierto } = useRegistroDelProducto();
const estudios = ref<EstudioDirectorio[]>([]);
const q = ref("");
const perfil = ref("");
const cargando = ref(true);
const error = ref<string | null>(null);

// Los giros del filtro: los de la modalidad del producto (ADR 0108; el directorio de
// cada dominio lista solo sus negocios), la misma lista que el registro y que acepta
// el filtro del API (`PerfilNegocio`), para que no se desalineen.
const GRUPOS_PERFILES = MODOS.filter(
  (modo) => modo === PRODUCTOS[productoActual()].modalidad,
).map((modo) => ({
  modo,
  perfiles: PERFILES_POR_MODO[modo],
}));

const CATEGORIAS_DESTACADAS = [
  {
    clave: "pilates",
    trazos: ["M5 18c3-5 11-5 14 0", "M8 12a4 4 0 1 1 8 0", "M4 21h16"],
  },
  {
    clave: "pole",
    trazos: ["M12 3v18", "M7 7c3 0 5 2 5 5", "M17 17c-3 0-5-2-5-5"],
  },
  {
    clave: "academia",
    trazos: ["M4 20h16", "M6 18V9l6-5 6 5v9", "M9 12h6", "M9 15h6"],
  },
  {
    clave: "barberia",
    trazos: [
      "M7 7l10 10",
      "M17 7 7 17",
      "M6 4a3 3 0 1 0 0 6 3 3 0 0 0 0-6z",
      "M18 14a3 3 0 1 0 0 6 3 3 0 0 0 0-6z",
    ],
  },
] as const;

const IMAGENES_PERFIL: Record<string, string> = {
  barberia: "barberia-v1.jpg",
  estetica: "estetica-v1.jpg",
  salon: "estetica-v1.jpg",
  spa: "estetica-v1.jpg",
  salud: "consultorios-v1.webp",
  pilates: "pilates-v1.jpg",
  pole: "pole-v1.jpg",
  yoga: "yoga-v1.jpg",
  danza: "danza-v1.jpg",
  gimnasio: "gimnasio-v1.jpg",
  crossfit: "crossfit-v1.webp",
  hyrox: "crossfit-hyrox-v1.webp",
  natacion: "natacion-v1.jpg",
  academia: "academias-v1.jpg",
  general: "academias-v1.jpg",
  general_citas: "wellness-v1.webp",
};

function imagenPerfil(valor: string): string {
  const archivo = IMAGENES_PERFIL[valor] ?? IMAGENES_PERFIL.general;
  return `/assets/landing/disciplinas/${archivo}`;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const params: Record<string, string> = {};
    if (q.value.trim() !== "") params.q = q.value.trim();
    // Cada producto lista sus negocios (ADR 0108); no se mide como filtro.
    const producto = productoActual();
    if (perfil.value !== "") params.perfil = perfil.value;
    const { data } = await api.get<{ data: EstudioDirectorio[] }>(
      "/api/v1/directorio",
      { params: { ...params, producto } },
    );
    estudios.value = data.data;
    if (Object.keys(params).length > 0) {
      trackEvent("community_search_submitted", {
        has_text_query: params.q !== undefined,
        business_profile: params.perfil ?? "all",
        results_count: data.data.length,
      });
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function verEstudio(estudio: EstudioDirectorio): void {
  trackEvent("community_studio_selected", { source: "directory" });
  recordarNegocio({
    slug: estudio.slug,
    nombre: estudio.nombre,
    logo_url: estudio.logo_url,
    ciudad: estudio.ciudad,
    pais: estudio.pais,
  });
  void router.push({
    name: "estudio-publico",
    params: { slug: estudio.slug },
  });
}

function limpiar(): void {
  q.value = "";
  perfil.value = "";
  void cargar();
}

function seleccionarPerfil(valor: string): void {
  perfil.value = perfil.value === valor ? "" : valor;
  void cargar();
}

function iniciales(nombre: string): string {
  return nombre
    .split(" ")
    .slice(0, 2)
    .map((p) => p.charAt(0))
    .join("")
    .toUpperCase();
}

onMounted(cargar);
</script>

<template>
  <section class="tu-directorio-hero">
    <div class="mx-auto max-w-4xl px-4 py-14 sm:py-20 text-center">
      <p
        class="text-sm font-semibold uppercase tracking-widest"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("directorio.etiqueta") }}
      </p>
      <h1 class="mt-3 text-4xl sm:text-5xl font-extrabold tracking-tight">
        {{ $t("directorio.titulo") }}
      </h1>
      <p
        class="mt-4 mx-auto max-w-2xl text-lg"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("directorio.subtitulo") }}
      </p>

      <form
        class="tu-buscador-directorio mx-auto mt-8"
        @submit.prevent="cargar"
      >
        <label class="tu-busqueda-texto">
          <svg
            aria-hidden="true"
            width="20"
            height="20"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
          >
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-4-4" />
          </svg>
          <span class="sr-only">{{ $t("directorio.buscar") }}</span>
          <input
            v-model="q"
            type="search"
            :placeholder="$t('directorio.buscar')"
            :aria-label="$t('directorio.buscar')"
          />
        </label>
        <select v-model="perfil" :aria-label="$t('directorio.disciplina')">
          <option value="">{{ $t("directorio.todas") }}</option>
          <optgroup
            v-for="grupo in GRUPOS_PERFILES"
            :key="grupo.modo"
            :label="$t(`modalidadNegocio.nombres.${grupo.modo}`)"
          >
            <option v-for="item in grupo.perfiles" :key="item" :value="item">
              {{ $t(`registro.perfiles.${item}`) }}
            </option>
          </optgroup>
        </select>
        <button class="tu-btn tu-btn-primario px-6" type="submit">
          {{ $t("directorio.buscarCta") }}
        </button>
      </form>

      <div
        class="tu-categorias"
        role="list"
        :aria-label="$t('directorio.categoriasTitulo')"
      >
        <button
          v-for="categoria in CATEGORIAS_DESTACADAS"
          :key="categoria.clave"
          type="button"
          class="tu-categoria"
          :class="{ 'tu-categoria--activa': perfil === categoria.clave }"
          :aria-pressed="perfil === categoria.clave"
          @click="seleccionarPerfil(categoria.clave)"
        >
          <span class="tu-categoria-icono" aria-hidden="true">
            <svg
              width="23"
              height="23"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.65"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path
                v-for="(trazo, i) in categoria.trazos"
                :key="i"
                :d="trazo"
              />
            </svg>
          </span>
          <span>{{ $t(`registro.perfiles.${categoria.clave}`) }}</span>
        </button>
      </div>
    </div>
  </section>

  <section class="mx-auto max-w-4xl px-4 py-10 sm:py-16">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p
        v-if="cargando"
        class="text-sm font-semibold"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>
      <p
        v-else-if="!error"
        class="text-sm font-semibold"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("directorio.resultados", { n: estudios.length }) }}
      </p>
      <button
        v-if="q || perfil"
        class="tu-enlace text-sm"
        type="button"
        @click="limpiar"
      >
        {{ $t("directorio.limpiar") }}
      </button>
    </div>

    <div
      v-if="cargando"
      class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
      aria-hidden="true"
    >
      <div v-for="n in 6" :key="n" class="tu-card h-80 animate-pulse" />
    </div>

    <div
      v-else-if="error"
      class="mt-8 tu-card p-5"
      :style="{ color: 'var(--error)' }"
    >
      {{ error }}
      <button class="tu-enlace ml-2" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </div>

    <div
      v-else-if="estudios.length === 0"
      class="tu-public-empty mt-8"
      role="status"
      :style="{ color: 'var(--texto-suave)' }"
    >
      <p>
        {{
          q.trim() !== ""
            ? $t("directorio.sinResultados", { q })
            : $t("directorio.vacio")
        }}
      </p>
      <p class="mt-2 text-sm">
        Prueba con el nombre del negocio o pide a su equipo el enlace directo de
        reservas.
      </p>
      <button
        v-if="q || perfil"
        type="button"
        class="tu-btn tu-btn-fantasma mt-4"
        @click="limpiar"
      >
        Quitar filtros
      </button>
    </div>

    <ul v-else class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      <li v-for="e in estudios" :key="e.slug" class="tu-card tu-estudio-card">
        <button class="tu-estudio-accion" type="button" @click="verEstudio(e)">
          <span class="tu-estudio-portada">
            <img
              :src="imagenPerfil(e.perfil)"
              alt=""
              width="1122"
              height="1402"
              loading="lazy"
              decoding="async"
            />
            <span class="tu-estudio-degradado" aria-hidden="true"></span>
            <!-- «Otro negocio con clases / de citas» es para el registro, no para
                 los clientes: sin insignia. -->
            <span
              v-if="perfilVisibleAlPublico(e.perfil)"
              class="tu-estudio-perfil"
              >{{ $t(`registro.perfiles.${e.perfil}`) }}</span
            >
            <img
              v-if="e.logo_url"
              :src="e.logo_url"
              :alt="e.nombre"
              class="tu-estudio-logo"
            />
            <span
              v-else
              class="tu-estudio-logo tu-estudio-iniciales"
              aria-hidden="true"
            >
              {{ iniciales(e.nombre) }}
            </span>
          </span>
          <span class="tu-estudio-info">
            <span class="min-w-0 text-left">
              <strong class="tu-estudio-nombre">{{ e.nombre }}</strong>
              <span class="tu-estudio-ubicacion">
                <svg
                  width="15"
                  height="15"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  aria-hidden="true"
                >
                  <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z" />
                  <circle cx="12" cy="10" r="2.5" />
                </svg>
                {{
                  [e.ciudad, e.pais].filter(Boolean).join(", ") ||
                  $t("directorio.ubicacionPendiente")
                }}
              </span>
            </span>
            <span class="tu-estudio-flecha" aria-hidden="true"
              ><IconoNav nombre="flecha" :tam="16"
            /></span>
          </span>
          <span class="tu-estudio-cta">{{ $t("directorio.verEstudio") }}</span>
        </button>
      </li>
    </ul>

    <div
      class="mt-14 rounded-[28px] px-6 py-8 sm:px-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6"
      :style="{ background: 'var(--fondo)' }"
    >
      <div>
        <h2 class="text-2xl font-light">{{ $t("directorio.duenoTitulo") }}</h2>
        <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
          {{
            registroAbierto
              ? $t("directorio.duenoDesc")
              : $t("directorio.duenoDescPrelanzamiento")
          }}
        </p>
      </div>
      <RouterLink
        class="tu-btn tu-btn-primario shrink-0 px-6"
        :to="{ name: 'registro' }"
        data-prueba="registrar-negocio"
        @click="
          trackEvent('marketing_cta_clicked', {
            placement: 'directory',
            destination: registroAbierto ? 'register' : 'waitlist',
          })
        "
      >
        {{
          registroAbierto
            ? $t("landing.ctaRegistrar")
            : $t("landing.prelanzamiento.cta")
        }}
      </RouterLink>
    </div>
  </section>
</template>

<style scoped>
.tu-directorio-hero {
  position: relative;
  overflow: hidden;
  background:
    radial-gradient(
      circle at 12% 16%,
      rgb(53 194 249 / 16%),
      transparent 28rem
    ),
    radial-gradient(circle at 89% 68%, rgb(0 112 255 / 15%), transparent 24rem),
    var(--fondo);
}
.tu-directorio-hero::after {
  position: absolute;
  inset: auto -8rem -15rem auto;
  width: 28rem;
  height: 28rem;
  border: 1px solid color-mix(in srgb, var(--primario) 13%, transparent);
  border-radius: 50%;
  box-shadow: 0 0 0 4rem color-mix(in srgb, var(--primario) 4%, transparent);
  content: "";
  pointer-events: none;
}
.tu-buscador-directorio {
  position: relative;
  z-index: 1;
  display: grid;
  gap: 0.65rem;
  max-width: 52rem;
  padding: 0.65rem;
  border-radius: 1.25rem;
  background: var(--superficie);
  box-shadow: 0 1rem 3.5rem rgb(39 55 73 / 10%);
}
.tu-busqueda-texto {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 0.65rem;
  padding: 0 0.75rem;
  color: var(--texto-suave);
}
.tu-busqueda-texto input,
.tu-buscador-directorio select {
  min-width: 0;
  width: 100%;
  min-height: 2.75rem;
  border: 0;
  outline: 0;
  background: transparent;
  color: var(--texto);
}
.tu-buscador-directorio select {
  padding: 0 0.75rem;
  border-radius: 0.8rem;
  background: var(--fondo);
}
.tu-categorias {
  position: relative;
  z-index: 1;
  display: flex;
  max-width: 45rem;
  margin: 1.35rem auto 0;
  justify-content: center;
  gap: 0.65rem;
}
.tu-categoria {
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.52rem 0.8rem;
  border: 1px solid var(--borde);
  border-radius: 999px;
  background: color-mix(in srgb, var(--superficie) 86%, transparent);
  color: var(--texto-suave);
  font-size: 0.76rem;
  font-weight: 650;
  backdrop-filter: blur(14px);
  transition:
    transform 0.2s ease,
    border-color 0.2s ease,
    background 0.2s ease;
}
.tu-categoria:hover,
.tu-categoria--activa {
  border-color: color-mix(in srgb, var(--primario) 45%, var(--borde));
  background: color-mix(in srgb, var(--primario) 10%, var(--superficie));
  color: var(--texto);
  transform: translateY(-2px);
}
.tu-categoria-icono {
  display: grid;
  width: 2rem;
  height: 2rem;
  place-content: center;
  border-radius: 50%;
  background: var(--fondo);
  color: var(--primario);
}
.tu-estudio-card {
  overflow: hidden;
  border-radius: 1.5rem;
  transition:
    transform 0.25s ease,
    box-shadow 0.25s ease;
}
.tu-estudio-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 1.4rem 3.5rem rgb(37 49 64 / 13%);
}
.tu-estudio-accion {
  display: flex;
  width: 100%;
  flex-direction: column;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: inherit;
}
.tu-estudio-portada {
  position: relative;
  display: block;
  height: 12.5rem;
  overflow: hidden;
}
.tu-estudio-portada > img:first-child {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.55s cubic-bezier(0.22, 1, 0.36, 1);
}
.tu-estudio-card:hover .tu-estudio-portada > img:first-child {
  transform: scale(1.045);
}
.tu-estudio-degradado {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    180deg,
    rgb(15 22 31 / 4%) 40%,
    rgb(15 22 31 / 42%)
  );
}
.tu-estudio-perfil {
  position: absolute;
  top: 0.85rem;
  left: 0.85rem;
  padding: 0.42rem 0.65rem;
  border: 1px solid rgb(255 255 255 / 42%);
  border-radius: 999px;
  background: rgb(16 24 34 / 52%);
  color: white;
  font-size: 0.68rem;
  font-weight: 700;
  backdrop-filter: blur(12px);
}
.tu-estudio-logo {
  position: absolute;
  left: 1rem;
  bottom: -0.15rem;
  width: 3.6rem;
  height: 3.6rem;
  border: 0.25rem solid var(--superficie);
  border-radius: 1rem;
  background: var(--superficie);
  object-fit: cover;
  box-shadow: 0 0.5rem 1.4rem rgb(16 25 36 / 20%);
}
.tu-estudio-iniciales {
  display: grid;
  place-content: center;
  background: var(--primario);
  color: white;
  font-size: 0.8rem;
  font-weight: 800;
}
.tu-estudio-info {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.25rem 1.15rem 0.7rem;
}
.tu-estudio-nombre,
.tu-estudio-ubicacion {
  display: block;
}
.tu-estudio-nombre {
  overflow: hidden;
  font-size: 1.08rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tu-estudio-ubicacion {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  margin-top: 0.3rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
}
.tu-estudio-flecha {
  display: grid;
  width: 2.25rem;
  height: 2.25rem;
  flex: 0 0 auto;
  place-content: center;
  border-radius: 50%;
  background: var(--fondo);
  color: var(--primario);
  transition: transform 0.2s ease;
}
.tu-estudio-card:hover .tu-estudio-flecha {
  transform: translateX(3px);
}
.tu-estudio-cta {
  padding: 0 1.15rem 1.15rem;
  color: var(--primario);
  font-size: 0.76rem;
  font-weight: 700;
}
@media (min-width: 768px) {
  .tu-buscador-directorio {
    grid-template-columns: minmax(0, 1.4fr) minmax(12rem, 0.8fr) auto;
    border-radius: 999px;
  }
  .tu-buscador-directorio select {
    border-radius: 999px;
  }
}
@media (max-width: 639px) {
  .tu-categorias {
    justify-content: flex-start;
    overflow-x: auto;
    padding-bottom: 0.4rem;
    scrollbar-width: none;
  }
  .tu-categoria {
    flex: 0 0 auto;
  }
}
@media (prefers-reduced-motion: reduce) {
  .tu-estudio-card,
  .tu-estudio-portada > img:first-child,
  .tu-estudio-flecha,
  .tu-categoria {
    transition: none;
  }
}
</style>
