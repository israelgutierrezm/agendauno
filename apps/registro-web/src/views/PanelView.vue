<script setup lang="ts">
import { isAxiosError } from "axios";
import { computed, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import AccesosOperativos from "@/components/AccesosOperativos.vue";
import EnlaceEstudio from "@/components/EnlaceEstudio.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Facturacion {
  plan: string | null;
  estado_facturacion: string;
  trial_termina_en: string | null;
  moneda: string;
  uso: {
    periodo: string;
    // Alumnos activos (clases) o profesionales activos (citas).
    metrica: string;
    cantidad: number;
    regla: string;
    cargo_estimado_minor: number;
  };
}

const sesion = useSesionTenantStore();

interface Quickstart {
  tareas: Array<{
    clave: string;
    hecho: boolean;
    requerido: boolean;
    ruta: string;
  }>;
  progreso: { hechas: number; total: number };
  listo: boolean;
}

const facturacion = ref<Facturacion | null>(null);
const quickstart = ref<Quickstart | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

/** "2026-10-07" → "7 de octubre" (fecha de calendario, sin zona). */
function diaMes(fecha: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
  }).format(new Date(`${fecha}T12:00:00`));
}

/** "2026-09" → "Septiembre". */
const mesDelPeriodo = computed(() => {
  const periodo = facturacion.value?.uso.periodo;
  if (!periodo) {
    return "";
  }
  const mes = new Intl.DateTimeFormat("es-MX", { month: "long" }).format(
    new Date(`${periodo}-15T12:00:00`),
  );
  return mes.charAt(0).toUpperCase() + mes.slice(1);
});

async function cargar(): Promise<void> {
  if (sesion.slug === null) {
    return;
  }
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Facturacion }>(
      `/api/v1/app/${sesion.slug}/facturacion`,
    );
    facturacion.value = data.data;
  } catch (e) {
    // La facturacion es solo para quien tiene facturacion.ver; el resto del staff
    // ve el panel sin ese bloque (no es un error para ellos).
    if (isAxiosError(e) && e.response?.status === 403) {
      facturacion.value = null;
    } else {
      error.value = mensajeDeError(e);
    }
  } finally {
    cargando.value = false;
  }

  // Quickstart (R36): solo para quien gestiona el estudio; se ignora si no aplica.
  if (sesion.puede("estudio.gestionar")) {
    try {
      const { data } = await api.get<{ data: Quickstart }>(
        `/api/v1/app/${sesion.slug}/onboarding/quickstart`,
      );
      quickstart.value = data.data;
    } catch {
      quickstart.value = null;
    }
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 py-8">
    <EncabezadoSeccion :titulo="sesion.estudio?.nombre ?? $t('nav.panel')" />
    <AccesosOperativos />

    <!-- Quickstart (R36): guía de activación mientras falte configuración esencial -->
    <div v-if="quickstart && !quickstart.listo" class="mt-6 tu-card p-5">
      <div class="flex items-baseline justify-between gap-3">
        <h2 class="font-semibold">
          {{ $t("quickstart.titulo") }}
          <span
            class="ml-1 font-normal text-sm tabular-nums"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ quickstart.progreso.hechas }}/{{
              quickstart.progreso.total
            }}</span
          >
        </h2>
        <RouterLink :to="{ name: 'onboarding' }" class="tu-enlace text-sm">
          {{ $t("quickstart.guiada") }}
        </RouterLink>
      </div>
      <div
        class="mt-3 h-1.5 rounded-full overflow-hidden"
        :style="{ background: 'var(--superficie-2)' }"
      >
        <div
          class="h-full rounded-full"
          :style="{
            width: `${(quickstart.progreso.hechas / Math.max(quickstart.progreso.total, 1)) * 100}%`,
            background: 'var(--primario)',
          }"
        />
      </div>
      <ul class="mt-4 space-y-2.5">
        <li
          v-for="t in quickstart.tareas"
          :key="t.clave"
          class="flex items-center justify-between gap-3 text-sm"
        >
          <span
            class="flex items-center gap-2.5 min-w-0"
            :style="t.hecho ? { color: 'var(--texto-suave)' } : {}"
          >
            <svg
              v-if="t.hecho"
              class="h-4 w-4 shrink-0"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2.2"
              stroke-linecap="round"
              stroke-linejoin="round"
              :style="{ color: 'var(--exito)' }"
              aria-hidden="true"
            >
              <path d="M4.5 12.75l6 6 9-13.5" />
            </svg>
            <span
              v-else
              class="h-4 w-4 shrink-0 rounded-full"
              :style="{ border: '1.5px solid var(--borde)' }"
              aria-hidden="true"
            />
            <span>
              {{ $t(`quickstart.tareas.${t.clave}`) }}
              <span v-if="!t.requerido" :style="{ color: 'var(--texto-suave)' }"
                >· {{ $t("quickstart.opcional") }}</span
              >
            </span>
          </span>
          <RouterLink
            v-if="!t.hecho"
            :to="{ name: t.ruta }"
            class="tu-enlace shrink-0"
            >{{ $t("quickstart.ir") }}</RouterLink
          >
        </li>
      </ul>
    </div>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <div v-else-if="error" class="mt-8 tu-card p-5" style="color: var(--error)">
      {{ error }}
      <button class="tu-enlace ml-2" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </div>

    <!-- Suscripción a AgendaUno: estado y lo que va del mes -->
    <div v-else-if="facturacion" class="mt-6 tu-card p-5">
      <div class="flex items-baseline justify-between gap-3">
        <h2 class="font-semibold">{{ $t("panel.suscripcion") }}</h2>
        <RouterLink :to="{ name: 'renta' }" class="tu-enlace text-sm">
          {{ $t("panel.verDetalle") }}
        </RouterLink>
      </div>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t(`panel.estados.${facturacion.estado_facturacion}`)
        }}<template
          v-if="
            facturacion.estado_facturacion === 'trial' &&
            facturacion.trial_termina_en
          "
        >
          ·
          {{
            $t("panel.pruebaHasta", {
              fecha: diaMes(facturacion.trial_termina_en),
            })
          }}</template
        >
      </p>
      <dl class="mt-4 grid grid-cols-2 gap-4 max-w-md">
        <div>
          <dt class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ mesDelPeriodo }}
          </dt>
          <dd class="mt-0.5 text-2xl font-semibold tabular-nums">
            {{ facturacion.uso.cantidad }}
            <span
              class="text-sm font-normal"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t(`cobro.actual.${facturacion.uso.metrica}`) }}</span
            >
          </dd>
        </div>
        <div>
          <dt class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("panel.cargoEstimado") }}
          </dt>
          <dd class="mt-0.5 text-2xl font-semibold tabular-nums">
            {{
              dinero(facturacion.uso.cargo_estimado_minor, facturacion.moneda)
            }}
          </dd>
        </div>
      </dl>
    </div>

    <!-- Enlace público del estudio + QR para compartir -->
    <EnlaceEstudio class="mt-6" />
  </section>
</template>
