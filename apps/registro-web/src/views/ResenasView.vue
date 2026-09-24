<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Reseñas de los alumnos: promedio general y por profesional, y cada comentario;
 * un comentario se puede ocultar de la página pública del negocio.
 */
interface Resena {
  id: string;
  calificacion: number;
  comentario: string | null;
  visible: boolean;
  actividad: string | null;
  con: string | null;
  persona: string | null;
  fecha: string | null;
}
interface Promedio {
  promedio: number;
  total: number;
  nombre?: string | null;
}

const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeOcultar = computed(() => sesion.puede("miembros.gestionar"));

const resenas = ref<Resena[]>([]);
const general = ref<Promedio | null>(null);
const porProfesional = ref<Promedio[]>([]);
const cargando = ref(true);

function fecha(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        day: "numeric",
        month: "short",
      }).format(new Date(iso))
    : "";
}

async function cargar(): Promise<void> {
  cargando.value = true;
  try {
    const { data } = await api.get<{
      data: Resena[];
      resumen: { general: Promedio; por_profesional: Promedio[] };
    }>(`${base.value}/resenas`);
    resenas.value = data.data;
    general.value = data.resumen.general;
    porProfesional.value = data.resumen.por_profesional;
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    cargando.value = false;
  }
}

async function alternar(r: Resena): Promise<void> {
  try {
    const { data } = await api.put<{ data: Resena }>(
      `${base.value}/resenas/${r.id}/visible`,
      { visible: !r.visible },
    );
    Object.assign(r, data.data);
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('resenas.titulo')" />

    <div
      v-if="general && general.total > 0"
      class="mt-5 grid gap-4 sm:grid-cols-[12rem_1fr]"
    >
      <div class="tu-card p-5">
        <p class="text-3xl font-light tabular-nums">
          {{ general.promedio.toFixed(1) }}
          <span style="color: var(--aviso)">★</span>
        </p>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("resenas.total", { n: general.total }) }}
        </p>
      </div>
      <div v-if="porProfesional.length > 0" class="tu-card p-5">
        <p class="tu-label">{{ $t("resenas.porProfesional") }}</p>
        <ul class="mt-1 space-y-1 text-sm">
          <li
            v-for="p in porProfesional"
            :key="p.nombre ?? ''"
            class="flex justify-between gap-3"
          >
            <span>{{ p.nombre ?? "—" }}</span>
            <span class="tabular-nums">
              {{ p.promedio.toFixed(1) }} ★ ·
              {{ $t("resenas.total", { n: p.total }) }}
            </span>
          </li>
        </ul>
      </div>
    </div>

    <div class="mt-5 tu-card p-5">
      <p
        v-if="cargando"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>
      <p
        v-else-if="resenas.length === 0"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("resenas.vacio") }}
      </p>
      <ul v-else>
        <li
          v-for="r in resenas"
          :key="r.id"
          class="flex flex-wrap items-start justify-between gap-3 border-t py-3 first:border-t-0"
          :style="{
            borderColor: 'var(--borde)',
            opacity: r.visible ? 1 : 0.6,
          }"
        >
          <div class="min-w-0">
            <p class="text-sm">
              <span style="color: var(--aviso)">{{
                "★".repeat(r.calificacion)
              }}</span>
              <span :style="{ color: 'var(--borde)' }">{{
                "★".repeat(5 - r.calificacion)
              }}</span>
              <span class="ml-2 font-medium">{{ r.persona ?? "—" }}</span>
            </p>
            <p v-if="r.comentario" class="mt-1 text-sm">{{ r.comentario }}</p>
            <p class="mt-0.5 text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ r.actividad ?? "—" }}
              <template v-if="r.con"> · {{ r.con }}</template>
              · {{ fecha(r.fecha) }}
              <template v-if="!r.visible">
                · {{ $t("resenas.oculta") }}</template
              >
            </p>
          </div>
          <button
            v-if="puedeOcultar && r.comentario"
            type="button"
            class="tu-enlace text-sm"
            @click="alternar(r)"
          >
            {{ r.visible ? $t("resenas.ocultar") : $t("resenas.mostrar") }}
          </button>
        </li>
      </ul>
    </div>
  </section>
</template>
