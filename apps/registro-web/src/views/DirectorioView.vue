<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";

import { api, mensajeDeError } from "@/lib/api";
import { trackEvent } from "@/lib/analytics";

interface EstudioDirectorio {
  slug: string;
  nombre: string;
  perfil: string;
  logo_url: string | null;
  ciudad: string | null;
  pais: string | null;
}

const router = useRouter();
const estudios = ref<EstudioDirectorio[]>([]);
const q = ref("");
const perfil = ref("");
const cargando = ref(true);
const error = ref<string | null>(null);

const PERFILES = [
  "pilates",
  "pole",
  "yoga",
  "danza",
  "gimnasio",
  "natacion",
  "academia",
  "general",
] as const;

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const params: Record<string, string> = {};
    if (q.value.trim() !== "") params.q = q.value.trim();
    if (perfil.value !== "") params.perfil = perfil.value;
    const { data } = await api.get<{ data: EstudioDirectorio[] }>(
      "/api/v1/directorio",
      { params },
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

function verEstudio(slug: string): void {
  trackEvent("community_studio_selected", { source: "directory" });
  void router.push({ name: "estudio-publico", params: { slug } });
}

function limpiar(): void {
  q.value = "";
  perfil.value = "";
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
    <div class="mx-auto max-w-5xl px-4 py-14 sm:py-20 text-center">
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
          <option v-for="item in PERFILES" :key="item" :value="item">
            {{ $t(`registro.perfiles.${item}`) }}
          </option>
        </select>
        <button class="tu-btn tu-btn-primario px-6" type="submit">
          {{ $t("directorio.buscarCta") }}
        </button>
      </form>
    </div>
  </section>

  <section class="mx-auto max-w-5xl px-4 py-10 sm:py-14">
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
      class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
      aria-hidden="true"
    >
      <div v-for="n in 6" :key="n" class="tu-card h-40 animate-pulse" />
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

    <p
      v-else-if="estudios.length === 0"
      class="mt-10 text-center"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{
        q.trim() !== ""
          ? $t("directorio.sinResultados", { q })
          : $t("directorio.vacio")
      }}
    </p>

    <ul v-else class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <li
        v-for="e in estudios"
        :key="e.slug"
        class="tu-card tu-estudio-card p-5 flex flex-col"
      >
        <div class="flex items-center gap-3">
          <img
            v-if="e.logo_url"
            :src="e.logo_url"
            :alt="e.nombre"
            class="h-11 w-11 rounded-lg object-cover"
          />
          <span
            v-else
            class="h-11 w-11 rounded-lg inline-flex items-center justify-center font-bold text-white"
            :style="{ background: 'var(--primario)' }"
            aria-hidden="true"
            >{{ iniciales(e.nombre) }}</span
          >
          <div class="min-w-0">
            <p class="font-bold truncate">{{ e.nombre }}</p>
            <p
              class="text-sm truncate"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ [e.ciudad, e.pais].filter(Boolean).join(", ") || "—" }}
            </p>
          </div>
        </div>
        <span class="tu-badge mt-5 self-start">{{
          $t(`registro.perfiles.${e.perfil}`)
        }}</span>
        <button
          class="tu-btn tu-btn-primario mt-4 w-full justify-center"
          type="button"
          @click="verEstudio(e.slug)"
        >
          {{ $t("directorio.verEstudio") }}
        </button>
      </li>
    </ul>

    <div
      class="mt-14 rounded-[28px] px-6 py-8 sm:px-10 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6"
      :style="{ background: 'var(--fondo)' }"
    >
      <div>
        <h2 class="text-2xl font-bold">{{ $t("directorio.duenoTitulo") }}</h2>
        <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("directorio.duenoDesc") }}
        </p>
      </div>
      <RouterLink
        class="tu-btn tu-btn-primario shrink-0 px-6"
        :to="{ name: 'registro' }"
        @click="
          trackEvent('marketing_cta_clicked', {
            placement: 'directory',
            destination: 'register',
          })
        "
      >
        {{ $t("landing.ctaRegistrar") }}
      </RouterLink>
    </div>
  </section>
</template>

<style scoped>
.tu-directorio-hero {
  background: var(--fondo);
}
.tu-buscador-directorio {
  display: grid;
  gap: 0.65rem;
  max-width: 52rem;
  padding: 0.65rem;
  border-radius: 1.25rem;
  background: var(--superficie);
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
.tu-estudio-card {
  transition: transform 0.2s ease;
}
.tu-estudio-card:hover {
  transform: translateY(-3px);
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
@media (prefers-reduced-motion: reduce) {
  .tu-estudio-card {
    transition: none;
  }
}
</style>
