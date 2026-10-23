<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";

import { api, mensajeDeError } from "@/lib/api";
import { porCategoria, reproductorDe, type Video } from "@/lib/videos";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Los videos del negocio para el cliente (ADR 0184): los de sus planes vigentes, con
 * su reproductor (YouTube, Vimeo) o el enlace; de los demás, el título y qué plan los
 * incluye.
 */
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const videos = ref<Video[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const viendo = ref<string | null>(null);
const grupos = computed(() => porCategoria(videos.value));

onMounted(async () => {
  try {
    const { data } = await api.get<{ data: Video[] }>(
      `${base.value}/mi/videos`,
    );
    videos.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
});
</script>

<template>
  <section class="tu-pagina">
    <h1 class="text-2xl font-semibold">{{ $t("videos.mios.titulo") }}</h1>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("videos.mios.subtitulo") }}
    </p>

    <p v-if="cargando" class="mt-6" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-else-if="error" class="mt-6" role="alert" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-else-if="videos.length === 0"
      class="mt-6"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("videos.mios.vacio") }}
    </p>

    <section v-for="[categoria, lista] in grupos" :key="categoria" class="mt-6">
      <h2 v-if="categoria" class="text-base font-semibold">{{ categoria }}</h2>
      <ul class="mt-2 grid gap-3 sm:grid-cols-2">
        <li
          v-for="v in lista"
          :key="v.id"
          class="tu-card p-4"
          :data-prueba="`video-${v.id}`"
        >
          <p class="font-medium">{{ v.titulo }}</p>
          <p
            v-if="v.descripcion"
            class="mt-1 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ v.descripcion }}
          </p>
          <template v-if="v.disponible && v.url">
            <div
              v-if="viendo === v.id && reproductorDe(v.url)"
              class="mt-3 aspect-video w-full overflow-hidden rounded-lg"
            >
              <iframe
                :src="reproductorDe(v.url) ?? undefined"
                :title="v.titulo"
                class="h-full w-full"
                allow="autoplay; fullscreen; picture-in-picture"
                allowfullscreen
                referrerpolicy="strict-origin-when-cross-origin"
              />
            </div>
            <button
              v-if="reproductorDe(v.url) && viendo !== v.id"
              type="button"
              class="tu-btn tu-btn-primario mt-3"
              @click="viendo = v.id"
            >
              {{ $t("videos.mios.ver") }}
            </button>
            <a
              v-else-if="!reproductorDe(v.url)"
              :href="v.url"
              target="_blank"
              rel="noopener noreferrer"
              class="tu-btn tu-btn-primario mt-3 inline-block"
              >{{ $t("videos.mios.abrir") }}</a
            >
          </template>
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              v.planes.length > 0
                ? $t("videos.mios.incluidoEn", {
                    planes: v.planes.map((p) => p.nombre).join(", "),
                  })
                : $t("videos.mios.conPlan")
            }}
            <RouterLink
              :to="{ name: 'mis-pagos', hash: '#mp-comprar' }"
              class="tu-enlace"
              >{{ $t("videos.mios.verPlanes") }}</RouterLink
            >
          </p>
        </li>
      </ul>
    </section>
  </section>
</template>
