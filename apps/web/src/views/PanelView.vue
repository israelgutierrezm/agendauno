<script setup lang="ts">
import { isAxiosError } from "axios";
import { computed, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";

import AccesosOperativos from "@/components/AccesosOperativos.vue";
import EnlaceEstudio from "@/components/EnlaceEstudio.vue";
import PonEnMarcha, { type Quickstart } from "@/components/PonEnMarcha.vue";
import ResumenDelDia from "@/components/ResumenDelDia.vue";
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

/**
 * Inicio del negocio: el día de hoy primero (agenda, asistencia, cobros y
 * renovaciones), luego los accesos a la operación y, al final, la suscripción.
 */
const nombre = computed(() => {
  const u = sesion.usuario;
  return (u?.nombre_pila ?? u?.nombre ?? "").trim().split(/\s+/)[0] ?? "";
});
// "sábado 27 de septiembre": el día que se está viendo.
const hoyTexto = new Intl.DateTimeFormat("es-MX", {
  weekday: "long",
  day: "numeric",
  month: "long",
}).format(new Date());

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
    <h1 class="text-2xl font-semibold">
      {{
        nombre
          ? $t("portal.inicio.saludo", { nombre })
          : $t("portal.inicio.saludoSinNombre")
      }}
    </h1>
    <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
      {{
        $t("operacion.hoy.saludo", {
          estudio: sesion.estudio?.nombre ?? "",
          fecha: hoyTexto,
        })
      }}
    </p>

    <!-- Quickstart (R36): guía de activación mientras falte configuración esencial -->
    <PonEnMarcha
      v-if="quickstart && !quickstart.listo"
      class="mt-6"
      :quickstart="quickstart"
    />

    <ResumenDelDia />

    <AccesosOperativos />

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
