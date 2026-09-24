<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { api } from "@/lib/api";

interface Sucursal {
  id: string;
  nombre: string;
  zona_horaria: string | null;
}
interface Opciones {
  estudio: { slug: string; nombre: string; logo_url: string | null };
  servicios: { id: string }[];
  sucursales: Sucursal[];
}

const route = useRoute();
const router = useRouter();
const slug = computed(() => String(route.params.slug));

const opciones = ref<Opciones | null>(null);
const cargando = ref(true);
const noDisponible = ref(false);

// ¿El estudio ofrece citas en línea? Decide el destino al elegir sede.
const tieneCitas = computed(() => (opciones.value?.servicios.length ?? 0) > 0);

function destinoSucursal(s: Sucursal): {
  name: string;
  params: Record<string, string>;
  query?: Record<string, string>;
} {
  return tieneCitas.value
    ? {
        name: "agendar-cita",
        params: { slug: slug.value },
        query: { sucursal: s.id },
      }
    : { name: "estudio-publico", params: { slug: slug.value } };
}

async function cargar(): Promise<void> {
  cargando.value = true;
  noDisponible.value = false;
  try {
    const { data } = await api.get<{ data: Opciones }>(
      `/api/v1/app/${slug.value}/citas/opciones`,
    );
    opciones.value = data.data;
    // Una sola sede (o ninguna): no hay nada que elegir → directo.
    if (data.data.sucursales.length <= 1) {
      const unica = data.data.sucursales[0];
      void router.replace(
        unica
          ? destinoSucursal(unica)
          : { name: "estudio-publico", params: { slug: slug.value } },
      );
    }
  } catch {
    noDisponible.value = true;
  } finally {
    cargando.value = false;
  }
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
  <div class="min-h-screen" :style="{ background: 'var(--fondo)' }">
    <p
      v-if="cargando"
      class="text-center py-20"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("sucursalesPub.cargando") }}
    </p>

    <section
      v-else-if="noDisponible"
      class="mx-auto max-w-md px-4 py-20 text-center"
    >
      <p class="text-lg font-semibold">
        {{ $t("sucursalesPub.noDisponible") }}
      </p>
      <RouterLink
        :to="{ name: 'directorio' }"
        class="tu-enlace mt-3 inline-block"
      >
        {{ $t("sucursalesPub.volver") }}
      </RouterLink>
    </section>

    <section
      v-else-if="opciones && opciones.sucursales.length > 1"
      class="mx-auto max-w-2xl px-4 py-10"
    >
      <header class="flex items-center gap-3">
        <img
          v-if="opciones.estudio.logo_url"
          :src="opciones.estudio.logo_url"
          :alt="opciones.estudio.nombre"
          class="h-12 w-12 rounded-xl object-cover"
        />
        <span
          v-else
          class="h-12 w-12 rounded-xl inline-flex items-center justify-center text-white font-bold"
          :style="{ background: 'var(--primario)' }"
          >{{ iniciales(opciones.estudio.nombre) }}</span
        >
        <div>
          <h1 class="text-xl font-light">{{ opciones.estudio.nombre }}</h1>
          <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("sucursalesPub.titulo") }}
          </p>
        </div>
      </header>

      <p class="mt-6 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("sucursalesPub.subtitulo") }}
      </p>

      <ul class="mt-4 space-y-3">
        <li v-for="s in opciones.sucursales" :key="s.id">
          <RouterLink
            :to="destinoSucursal(s)"
            class="tu-card p-5 flex items-center justify-between gap-3 transition hover:-translate-y-0.5"
          >
            <span class="flex items-center gap-3 min-w-0">
              <span
                class="h-10 w-10 rounded-xl inline-flex items-center justify-center shrink-0"
                :style="{
                  background: 'var(--primario-suave)',
                  color: 'var(--primario-fuerte)',
                }"
                aria-hidden="true"
              >
                <svg
                  width="20"
                  height="20"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                >
                  <path
                    d="M12 21s-6-5.2-6-10a6 6 0 1 1 12 0c0 4.8-6 10-6 10z"
                  />
                  <circle cx="12" cy="11" r="2.2" />
                </svg>
              </span>
              <span class="font-semibold truncate">{{ s.nombre }}</span>
            </span>
            <span
              class="shrink-0 text-lg"
              :style="{ color: 'var(--texto-suave)' }"
              aria-hidden="true"
              >›</span
            >
          </RouterLink>
        </li>
      </ul>
    </section>
  </div>
</template>
