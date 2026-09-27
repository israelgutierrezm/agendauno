<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import TarjetaPrincipal from "@/components/TarjetaPrincipal.vue";
import { aHora, fechaLocal, minutosLocal } from "@/lib/agenda";
import { api, mensajeDeError } from "@/lib/api";
import { lugarDelClima, useClima } from "@/lib/clima";
import { fotoNegocio } from "@/lib/fotoNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * El día de hoy en el Inicio del negocio (GET /inicio/hoy), con el estilo de los
 * Inicios del portal: la tarjeta grande con lo que sigue (o lo que está en curso),
 * los indicadores del día, la foto del giro y el clima del negocio; debajo la
 * agenda del día y lo pendiente (cobros y renovaciones). Cada bloque llega solo si
 * quien entra tiene el permiso de su pantalla.
 */
interface SesionHoy {
  id: string;
  tipo: string;
  oferta: string | null;
  instructor: string | null;
  sucursal: string | null;
  cliente: string | null;
  inicia_en: string;
  zona_horaria: string;
  capacidad: number | null;
  esperados: number;
  llegaron: number;
  sin_marcar: number;
  cancelada: boolean;
  momento: "proxima" | "en_curso" | "termino" | "cancelada";
}
interface Hoy {
  fecha: string;
  agenda: {
    totales: {
      sesiones: number;
      esperados: number;
      llegaron: number;
      sin_marcar: number;
    };
    sesiones: SesionHoy[];
  } | null;
  cobros: {
    ordenes_pendientes: number;
    por_cobrar: { moneda: string; total_minor: number }[];
    en_mora: number;
  } | null;
  renovaciones: { por_vencer: number; vencidas: number; dias: number } | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const hoy = ref<Hoy | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

const zonaNavegador = Intl.DateTimeFormat().resolvedOptions().timeZone;

const foto = computed(() => fotoNegocio(sesion.estudio?.perfil));
// El clima del negocio (su sede) o el de la próxima clase de quien entra.
const { clima, cargar: cargarClima } = useClima(
  () => `/api/v1/app/${sesion.slug}/clima`,
);
const climaLugar = computed(() => lugarDelClima(clima.value, t));

// Lo que está en curso o lo siguiente de hoy (sin las canceladas).
const enCurso = computed(
  () =>
    hoy.value?.agenda?.sesiones.find((s) => s.momento === "en_curso") ?? null,
);
const siguiente = computed(
  () =>
    enCurso.value ??
    hoy.value?.agenda?.sesiones.find((s) => s.momento === "proxima") ??
    null,
);
const etiquetaDia = computed(() => {
  if (enCurso.value) {
    return t("operacion.hoy.enCurso");
  }
  return siguiente.value
    ? t("operacion.hoy.loQueSigue")
    : t("operacion.hoy.titulo");
});
const tituloDia = computed(() => {
  const s = siguiente.value;
  if (s) {
    return nombre(s);
  }
  return (hoy.value?.agenda?.totales.sesiones ?? 0) > 0
    ? t("operacion.hoy.diaTerminado")
    : t("operacion.hoy.sinSesiones");
});

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Hoy }>(
      `/api/v1/app/${sesion.slug}/inicio/hoy`,
      {
        params: {
          fecha: fechaLocal(new Date().toISOString(), zonaNavegador),
        },
      },
    );
    hoy.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function hora(s: SesionHoy): string {
  return aHora(minutosLocal(s.inicia_en, s.zona_horaria));
}

function nombre(s: SesionHoy): string {
  if (s.tipo === "cita" && s.cliente) {
    return `${s.oferta ?? ""} · ${s.cliente}`;
  }
  return s.oferta ?? "—";
}

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

const porCobrar = computed(() =>
  (hoy.value?.cobros?.por_cobrar ?? [])
    .map((m) => dinero(m.total_minor, m.moneda))
    .join(" + "),
);

const indicadores = computed(() => {
  const tot = hoy.value?.agenda?.totales;
  if (!tot) {
    return [];
  }
  return [
    { clave: "sesiones", valor: tot.sesiones, aviso: false },
    { clave: "esperados", valor: tot.esperados, aviso: false },
    { clave: "llegaron", valor: tot.llegaron, aviso: false },
    { clave: "sinMarcar", valor: tot.sin_marcar, aviso: tot.sin_marcar > 0 },
  ].map((i) => ({ ...i, etiqueta: t(`operacion.hoy.indicadores.${i.clave}`) }));
});

const hayPendientes = computed(() => {
  const c = hoy.value?.cobros;
  const r = hoy.value?.renovaciones;
  return (
    (c !== null &&
      c !== undefined &&
      (c.ordenes_pendientes > 0 || c.en_mora > 0)) ||
    (r !== null && r !== undefined && (r.por_vencer > 0 || r.vencidas > 0))
  );
});

onMounted(() => {
  void cargar();
  void cargarClima();
});
</script>

<template>
  <p v-if="cargando" class="mt-6" :style="{ color: 'var(--texto-suave)' }">
    {{ $t("comun.cargando") }}
  </p>
  <div v-else-if="error" class="mt-6 tu-card p-5" style="color: var(--error)">
    {{ error }}
    <button type="button" class="tu-enlace ml-2" @click="cargar">
      {{ $t("comun.reintentar") }}
    </button>
  </div>

  <template v-else-if="hoy">
    <!-- La tarjeta principal: lo que sigue hoy y los indicadores del día -->
    <TarjetaPrincipal
      v-if="hoy.agenda"
      class="mt-6"
      :etiqueta="etiquetaDia"
      :etiqueta-viva="enCurso !== null"
      :foto="foto"
      :clima="clima"
      :clima-lugar="climaLugar"
    >
      <p class="mt-2 text-2xl font-semibold sm:text-3xl">{{ tituloDia }}</p>
      <p v-if="siguiente" class="mt-2">
        {{ hora(siguiente) }}
        <span :style="{ color: 'var(--texto-suave)' }">
          ·
          {{
            [siguiente.sucursal, siguiente.instructor]
              .filter(Boolean)
              .join(" · ")
          }}</span
        >
      </p>
      <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4">
        <div v-for="i in indicadores" :key="i.clave">
          <dt class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ i.etiqueta }}
          </dt>
          <dd
            class="mt-0.5 text-xl font-semibold tabular-nums"
            :style="i.aviso ? { color: 'var(--aviso)' } : undefined"
          >
            {{ i.valor }}
          </dd>
        </div>
      </dl>
      <div class="mt-6 flex flex-wrap items-center gap-4">
        <RouterLink
          :to="{ name: 'agenda' }"
          class="tu-btn tu-btn-primario inline-flex"
        >
          {{ $t("operacion.hoy.abrirAgenda") }}
        </RouterLink>
        <RouterLink
          v-if="sesion.puede('reservas.gestionar')"
          :to="{ name: 'recepcion' }"
          class="tu-enlace text-sm"
        >
          {{ $t("operacion.hoy.irRecepcion") }}
        </RouterLink>
      </div>
    </TarjetaPrincipal>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
      <!-- Agenda de hoy -->
      <section
        v-if="hoy.agenda"
        class="tu-card p-5 lg:col-span-2"
        aria-labelledby="hoy-agenda"
      >
        <div class="flex items-baseline justify-between gap-3">
          <h2 id="hoy-agenda" class="font-semibold">
            {{ $t("operacion.hoy.agenda") }}
          </h2>
          <RouterLink :to="{ name: 'agenda' }" class="tu-enlace text-sm">
            {{ $t("operacion.hoy.verAgenda") }}
          </RouterLink>
        </div>
        <p
          v-if="hoy.agenda.sesiones.length === 0"
          class="mt-3 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("operacion.hoy.sinSesiones") }}
        </p>
        <ul v-else class="mt-3 divide-y divide-[var(--borde)]">
          <li
            v-for="s in hoy.agenda.sesiones"
            :key="s.id"
            class="flex items-start gap-4 py-3"
            :style="s.cancelada ? { color: 'var(--texto-suave)' } : undefined"
          >
            <span class="w-12 shrink-0 font-medium tabular-nums">{{
              hora(s)
            }}</span>
            <span class="min-w-0 flex-1">
              <span
                class="block truncate font-medium"
                :class="{ 'line-through': s.cancelada }"
                >{{ nombre(s) }}</span
              >
              <span
                class="block truncate text-sm"
                :style="{ color: 'var(--texto-suave)' }"
                >{{
                  [s.instructor, s.sucursal].filter(Boolean).join(" · ")
                }}</span
              >
              <RouterLink
                v-if="s.sin_marcar > 0"
                :to="{ name: 'recepcion' }"
                class="mt-0.5 block text-sm"
                :style="{ color: 'var(--aviso)' }"
                >{{ $t("operacion.hoy.pasarLista", s.sin_marcar) }}</RouterLink
              >
            </span>
            <span class="shrink-0 text-right text-sm">
              <span
                v-if="s.tipo !== 'cita' && !s.cancelada"
                class="block tabular-nums"
                >{{
                  s.capacidad
                    ? $t("operacion.hoy.cupo", {
                        n: s.esperados,
                        total: s.capacidad,
                      })
                    : s.esperados
                }}</span
              >
              <span
                class="block"
                :style="{
                  color:
                    s.momento === 'en_curso'
                      ? 'var(--primario)'
                      : 'var(--texto-suave)',
                }"
                >{{ $t(`operacion.hoy.momento.${s.momento}`) }}</span
              >
            </span>
          </li>
        </ul>
      </section>

      <!-- Pendientes: cobros y renovaciones -->
      <section
        v-if="hoy.cobros || hoy.renovaciones"
        class="tu-card p-5"
        :class="{ 'lg:col-span-3': !hoy.agenda }"
        aria-labelledby="hoy-pendientes"
      >
        <h2 id="hoy-pendientes" class="font-semibold">
          {{ $t("operacion.hoy.pendientes") }}
        </h2>
        <p
          v-if="!hayPendientes"
          class="mt-3 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("operacion.hoy.alDia") }}
        </p>
        <ul v-else class="mt-3 space-y-3 text-sm">
          <li v-if="hoy.cobros && hoy.cobros.ordenes_pendientes > 0">
            <RouterLink :to="{ name: 'cobranza' }" class="hoy-pendiente">
              <span>{{
                $t("operacion.hoy.porCobrar", hoy.cobros.ordenes_pendientes)
              }}</span>
              <span class="tabular-nums">{{ porCobrar }}</span>
            </RouterLink>
          </li>
          <li v-if="hoy.cobros && hoy.cobros.en_mora > 0">
            <RouterLink :to="{ name: 'cobranza' }" class="hoy-pendiente">
              <span :style="{ color: 'var(--aviso)' }">{{
                $t("operacion.hoy.enMora", hoy.cobros.en_mora)
              }}</span>
            </RouterLink>
          </li>
          <li v-if="hoy.renovaciones && hoy.renovaciones.por_vencer > 0">
            <RouterLink :to="{ name: 'retencion' }" class="hoy-pendiente">
              <span>{{
                $t(
                  "operacion.hoy.porVencer",
                  {
                    n: hoy.renovaciones.por_vencer,
                    dias: hoy.renovaciones.dias,
                  },
                  hoy.renovaciones.por_vencer,
                )
              }}</span>
            </RouterLink>
          </li>
          <li v-if="hoy.renovaciones && hoy.renovaciones.vencidas > 0">
            <RouterLink :to="{ name: 'retencion' }" class="hoy-pendiente">
              <span :style="{ color: 'var(--aviso)' }">{{
                $t("operacion.hoy.vencidas", hoy.renovaciones.vencidas)
              }}</span>
            </RouterLink>
          </li>
        </ul>
      </section>
    </div>
  </template>
</template>

<style scoped>
.hoy-pendiente {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  color: var(--texto);
}
.hoy-pendiente:hover span:first-child {
  text-decoration: underline;
  text-underline-offset: 2px;
}
</style>
