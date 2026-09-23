<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const { t } = useI18n();

interface Negocio {
  moneda: string;
  ingresos_minor: number;
  ordenes_pagadas: number;
  clases: number;
  ocupacion_pct: number | null;
  no_show_pct: number | null;
  alumnos_activos: number;
  arpu_minor: number | null;
}
interface SucursalReporte {
  id: string;
  nombre: string;
  region: string | null;
  moneda: string | null;
  miembros_activos: number;
  sesiones_proximas: number;
}
interface RentabilidadOferta {
  id: string | null;
  oferta: string;
  precio_clase_minor: number | null;
  sesiones: number;
  asistentes: number;
  ingreso_minor: number;
  costo_minor: number;
  margen_minor: number;
  sin_costo_unitario: number;
}
interface Rentabilidad {
  moneda: string;
  totales: {
    asistentes: number;
    ingreso_minor: number;
    costo_minor: number;
    margen_minor: number;
    sin_costo_unitario: number;
  };
  ofertas: RentabilidadOferta[];
}
interface DemandaCelda {
  dia: number;
  hora: number;
  sesiones: number;
  capacidad: number;
  confirmadas: number;
  espera: number;
  ocupacion_pct: number | null;
}
interface DemandaActividad {
  id: string | null;
  actividad: string;
  sesiones: number;
  capacidad: number;
  confirmadas: number;
  espera: number;
  ocupacion_pct: number | null;
}
interface Demanda {
  totales: {
    sesiones: number;
    capacidad: number;
    confirmadas: number;
    espera: number;
    ocupacion_pct: number | null;
  };
  matriz: DemandaCelda[];
  actividades: DemandaActividad[];
}
interface PuntoSerie {
  fecha: string;
  ingresos_minor: number;
  ordenes: number;
}
interface Tendencias {
  agrupacion: string;
  moneda: string;
  serie: PuntoSerie[];
  por_producto: {
    producto: string;
    ingresos_minor: number;
    unidades: number;
  }[];
  totales: {
    ingresos_minor: number;
    ordenes: number;
    ticket_promedio_minor: number | null;
  };
}
interface Cohorte {
  mes: string;
  tamano: number;
  retencion: (number | null)[];
}
interface Cohortes {
  meses: number;
  cohortes: Cohorte[];
  conversion: { registrados: number; compraron: number; activos: number };
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

function iso(d: Date): string {
  const p = (n: number): string => (n < 10 ? `0${n}` : `${n}`);
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
function inicioMes(): string {
  const d = new Date();
  return iso(new Date(d.getFullYear(), d.getMonth(), 1));
}

const desde = ref(inicioMes());
const hasta = ref(iso(new Date()));
const negocio = ref<Negocio | null>(null);
const sucursales = ref<SucursalReporte[]>([]);
const rentabilidad = ref<Rentabilidad | null>(null);
const demanda = ref<Demanda | null>(null);
const tendencias = ref<Tendencias | null>(null);
const agrupacion = ref<"dia" | "semana" | "mes">("dia");
const exportando = ref(false);
const cohortes = ref<Cohortes | null>(null);

// Etiqueta corta del mes de una cohorte ('2026-09' → 'sep 26').
function mesCorto(iso: string): string {
  const [y, m] = iso.split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", {
    month: "short",
    year: "2-digit",
  }).format(new Date(y, m - 1, 1));
}
// % de conversión de un paso respecto a los registrados.
function pctConv(n: number): string {
  const base = cohortes.value?.conversion.registrados ?? 0;
  return base > 0 ? `${Math.round((n / base) * 100)}%` : "—";
}
const cargando = ref(true);
const error = ref<string | null>(null);

// Altura de cada barra (0..100%) relativa al ingreso máximo de la serie.
const maxIngreso = computed(() =>
  Math.max(1, ...(tendencias.value?.serie.map((p) => p.ingresos_minor) ?? [0])),
);
function barra(p: PuntoSerie): string {
  return `${Math.round((p.ingresos_minor / maxIngreso.value) * 100)}%`;
}
function fechaBucket(iso: string): string {
  const d = new Date(`${iso}T00:00:00`);
  return agrupacion.value === "mes"
    ? new Intl.DateTimeFormat("es-MX", {
        month: "short",
        year: "2-digit",
      }).format(d)
    : new Intl.DateTimeFormat("es-MX", {
        day: "numeric",
        month: "short",
      }).format(d);
}

// Etiquetas de días (lun..dom) desde i18n; el índice 0 corresponde a `dia = 1`.
const diasSemana = computed(() => t("reportes.demanda.dias").split(","));
// Horas presentes en la matriz (unión, ordenadas) → filas del heatmap.
const horasDemanda = computed(() => {
  const set = new Set<number>();
  demanda.value?.matriz.forEach((c) => set.add(c.hora));
  return [...set].sort((a, b) => a - b);
});
function celdaDemanda(dia: number, hora: number): DemandaCelda | undefined {
  return demanda.value?.matriz.find((c) => c.dia === dia && c.hora === hora);
}
// Fondo del heatmap: más ocupación = acento más intenso (12%..92%).
function colorOcupacion(pct: number | null): string {
  if (pct === null) {
    return "transparent";
  }
  const mezcla = Math.round(12 + Math.min(100, pct) * 0.8);
  return `color-mix(in srgb, var(--primario) ${mezcla}%, transparent)`;
}

function dinero(minor: number | null, moneda: string): string {
  if (minor === null) {
    return "—";
  }
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
function pct(v: number | null): string {
  return v !== null ? `${v}%` : "—";
}

const tarjetas = computed(() => {
  const n = negocio.value;
  if (n === null) {
    return [];
  }
  return [
    { clave: "ingresos", valor: dinero(n.ingresos_minor, n.moneda) },
    { clave: "ocupacion", valor: pct(n.ocupacion_pct) },
    { clave: "noShow", valor: pct(n.no_show_pct) },
    { clave: "alumnos", valor: String(n.alumnos_activos) },
    { clave: "arpu", valor: dinero(n.arpu_minor, n.moneda) },
    { clave: "clases", valor: String(n.clases) },
  ];
});

async function cargarNegocio(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{ data: Negocio }>(
      `${base.value}/reportes/negocio`,
      {
        params: { desde: desde.value, hasta: hasta.value },
      },
    );
    negocio.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function cargarRentabilidad(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{ data: Rentabilidad }>(
      `${base.value}/reportes/rentabilidad`,
      {
        params: { desde: desde.value, hasta: hasta.value },
      },
    );
    rentabilidad.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function cargarDemanda(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{ data: Demanda }>(
      `${base.value}/reportes/demanda`,
      {
        params: { desde: desde.value, hasta: hasta.value },
      },
    );
    demanda.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function cargarTendencias(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{ data: Tendencias }>(
      `${base.value}/reportes/tendencias`,
      {
        params: {
          desde: desde.value,
          hasta: hasta.value,
          agrupacion: agrupacion.value,
        },
      },
    );
    tendencias.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function exportarTendencias(): Promise<void> {
  exportando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<Blob>(`${base.value}/reportes/tendencias`, {
      params: {
        desde: desde.value,
        hasta: hasta.value,
        agrupacion: agrupacion.value,
        formato: "csv",
      },
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = `tendencias-${sesion.slug}.csv`;
    document.body.appendChild(enlace);
    enlace.click();
    document.body.removeChild(enlace);
    URL.revokeObjectURL(url);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    exportando.value = false;
  }
}

async function cargarCohortes(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{ data: Cohortes }>(
      `${base.value}/reportes/cohortes`,
      { params: { meses: 6 } },
    );
    cohortes.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

async function cargar(): Promise<void> {
  cargando.value = true;
  try {
    const [, s] = await Promise.all([
      cargarNegocio(),
      api.get<{ data: SucursalReporte[] }>(`${base.value}/reportes/sucursales`),
      cargarRentabilidad(),
      cargarDemanda(),
      cargarTendencias(),
      cargarCohortes(),
    ]);
    sucursales.value = s.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function esteMes(): void {
  desde.value = inicioMes();
  hasta.value = iso(new Date());
}

watch([desde, hasta], () => {
  void cargarNegocio();
  void cargarRentabilidad();
  void cargarDemanda();
  void cargarTendencias();
});
watch(agrupacion, () => void cargarTendencias());

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('reportes.titulo')" />

    <!-- Periodo -->
    <div class="mt-6 flex flex-wrap items-end gap-3">
      <div>
        <label class="tu-label" for="rd">{{ $t("reportes.desde") }}</label>
        <input id="rd" v-model="desde" type="date" class="tu-input w-auto" />
      </div>
      <div>
        <label class="tu-label" for="rh">{{ $t("reportes.hasta") }}</label>
        <input id="rh" v-model="hasta" type="date" class="tu-input w-auto" />
      </div>
      <button class="tu-btn tu-btn-fantasma" type="button" @click="esteMes">
        {{ $t("reportes.esteMes") }}
      </button>
    </div>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <!-- Métricas del negocio -->
      <div class="mt-6 tu-card px-5 py-4 grid grid-cols-3 lg:grid-cols-6 gap-4">
        <div v-for="card in tarjetas" :key="card.clave">
          <div class="text-xl font-semibold tabular-nums">{{ card.valor }}</div>
          <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
            {{ $t(`reportes.metricas.${card.clave}`) }}
          </div>
        </div>
      </div>

      <!-- Tendencias de ingresos (Etapa 2) -->
      <div class="mt-8 flex flex-wrap items-center gap-3">
        <h2 class="font-bold text-lg">
          {{ $t("reportes.tendencias.titulo") }}
        </h2>
        <div class="ml-auto flex items-center gap-2">
          <select
            v-model="agrupacion"
            class="tu-input w-auto"
            :aria-label="$t('reportes.tendencias.agrupacion')"
          >
            <option value="dia">{{ $t("reportes.tendencias.dia") }}</option>
            <option value="semana">
              {{ $t("reportes.tendencias.semana") }}
            </option>
            <option value="mes">{{ $t("reportes.tendencias.mes") }}</option>
          </select>
          <button
            class="tu-btn tu-btn-fantasma"
            type="button"
            :disabled="
              exportando || !tendencias || tendencias.serie.length === 0
            "
            @click="exportarTendencias"
          >
            {{
              exportando
                ? $t("reportes.tendencias.exportando")
                : $t("reportes.tendencias.exportar")
            }}
          </button>
        </div>
      </div>

      <template v-if="tendencias">
        <!-- Totales del periodo -->
        <div class="mt-3 tu-card px-5 py-4 grid grid-cols-3 gap-4">
          <div>
            <div class="text-xl font-semibold tabular-nums">
              {{ dinero(tendencias.totales.ingresos_minor, tendencias.moneda) }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("reportes.tendencias.ingresos") }}
            </div>
          </div>
          <div>
            <div class="text-xl font-semibold tabular-nums">
              {{ tendencias.totales.ordenes }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("reportes.tendencias.ordenes") }}
            </div>
          </div>
          <div>
            <div class="text-xl font-semibold tabular-nums">
              {{
                dinero(
                  tendencias.totales.ticket_promedio_minor,
                  tendencias.moneda,
                )
              }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("reportes.tendencias.ticket") }}
            </div>
          </div>
        </div>

        <!-- Barras de ingresos por bucket -->
        <div class="mt-4 tu-card p-5">
          <p
            v-if="tendencias.totales.ingresos_minor === 0"
            class="text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("reportes.tendencias.vacio") }}
          </p>
          <template v-else>
            <div
              class="flex items-end gap-1 h-40 border-b"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <div
                v-for="p in tendencias.serie"
                :key="p.fecha"
                class="flex-1 min-w-[2px] rounded-t transition-all"
                :style="{
                  height: barra(p),
                  background: 'var(--primario)',
                  minHeight: p.ingresos_minor > 0 ? '3px' : '0',
                }"
                :title="`${fechaBucket(p.fecha)} · ${dinero(p.ingresos_minor, tendencias.moneda)} · ${p.ordenes} órd.`"
              />
            </div>
            <div
              class="flex justify-between text-xs mt-1"
              :style="{ color: 'var(--texto-suave)' }"
            >
              <span>{{ fechaBucket(tendencias.serie[0].fecha) }}</span>
              <span>{{
                fechaBucket(tendencias.serie[tendencias.serie.length - 1].fecha)
              }}</span>
            </div>
          </template>
        </div>

        <!-- Desglose por producto -->
        <h3 class="mt-6 font-semibold">
          {{ $t("reportes.tendencias.porProducto") }}
        </h3>
        <p
          v-if="tendencias.por_producto.length === 0"
          class="mt-3 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("reportes.tendencias.sinProducto") }}
        </p>
        <div v-else class="mt-3 tu-card overflow-hidden">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
                <th class="px-4 py-2 font-medium">
                  {{ $t("reportes.tendencias.colProducto") }}
                </th>
                <th class="px-4 py-2 font-medium text-right">
                  {{ $t("reportes.tendencias.colUnidades") }}
                </th>
                <th class="px-4 py-2 font-medium text-right">
                  {{ $t("reportes.tendencias.colIngresos") }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="p in tendencias.por_producto"
                :key="p.producto"
                class="border-t"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <td class="px-4 py-2 font-semibold">{{ p.producto }}</td>
                <td class="px-4 py-2 text-right">{{ p.unidades }}</td>
                <td class="px-4 py-2 text-right">
                  {{ dinero(p.ingresos_minor, tendencias.moneda) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <!-- Conversión + cohortes de retención (Etapa 2) -->
      <template v-if="cohortes">
        <h2 class="mt-8 font-bold text-lg">
          {{ $t("reportes.conversion.titulo") }}
        </h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reportes.conversion.subtitulo", { n: cohortes.meses }) }}
        </p>
        <div class="mt-3 tu-card px-5 py-4 grid grid-cols-3 gap-4">
          <div>
            <div class="text-xl font-semibold tabular-nums">
              {{ cohortes.conversion.registrados }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("reportes.conversion.registrados") }}
            </div>
          </div>
          <div>
            <div class="text-xl font-semibold tabular-nums">
              {{ cohortes.conversion.compraron }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("reportes.conversion.compraron") }} ·
              {{ pctConv(cohortes.conversion.compraron) }}
            </div>
          </div>
          <div>
            <div class="text-xl font-semibold tabular-nums">
              {{ cohortes.conversion.activos }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("reportes.conversion.activos") }} ·
              {{ pctConv(cohortes.conversion.activos) }}
            </div>
          </div>
        </div>

        <h3 class="mt-6 font-semibold">{{ $t("reportes.cohortes.titulo") }}</h3>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reportes.cohortes.subtitulo") }}
        </p>
        <div class="mt-3 tu-card overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-center" :style="{ color: 'var(--texto-suave)' }">
                <th class="px-3 py-2 font-medium text-left">
                  {{ $t("reportes.cohortes.colCohorte") }}
                </th>
                <th class="px-2 py-2 font-medium text-right">
                  {{ $t("reportes.cohortes.colAltas") }}
                </th>
                <th
                  v-for="k in cohortes.meses"
                  :key="k"
                  class="px-2 py-2 font-medium"
                >
                  {{ $t("reportes.cohortes.mesN", { n: k - 1 }) }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="c in cohortes.cohortes"
                :key="c.mes"
                class="border-t"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <td class="px-3 py-1.5 font-semibold whitespace-nowrap">
                  {{ mesCorto(c.mes) }}
                </td>
                <td class="px-2 py-1.5 text-right">{{ c.tamano }}</td>
                <td
                  v-for="(r, i) in c.retencion"
                  :key="i"
                  class="px-1 py-1 text-center"
                >
                  <div
                    v-if="r !== null && c.tamano > 0"
                    class="rounded-lg py-1.5 text-xs font-semibold"
                    :style="{ background: colorOcupacion(r) }"
                  >
                    {{ r }}%
                  </div>
                  <span v-else :style="{ color: 'var(--borde)' }">·</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reportes.cohortes.leyenda") }}
        </p>
      </template>

      <!-- Por sucursal -->
      <h2 class="mt-8 font-bold text-lg">{{ $t("reportes.porSucursal") }}</h2>
      <p
        v-if="sucursales.length === 0"
        class="mt-3 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("reportes.sinSucursales") }}
      </p>
      <div v-else class="mt-3 tu-card overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
              <th class="px-4 py-2 font-medium">
                {{ $t("reportes.colSucursal") }}
              </th>
              <th class="px-4 py-2 font-medium hidden sm:table-cell">
                {{ $t("reportes.colRegion") }}
              </th>
              <th class="px-4 py-2 font-medium hidden sm:table-cell">
                {{ $t("reportes.colMoneda") }}
              </th>
              <th class="px-4 py-2 font-medium text-right">
                {{ $t("reportes.colMiembros") }}
              </th>
              <th class="px-4 py-2 font-medium text-right">
                {{ $t("reportes.colClases") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="s in sucursales"
              :key="s.id"
              class="border-t"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <td class="px-4 py-2 font-semibold">{{ s.nombre }}</td>
              <td
                class="px-4 py-2 hidden sm:table-cell"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ s.region ?? "—" }}
              </td>
              <td class="px-4 py-2 hidden sm:table-cell">
                {{ s.moneda ?? "—" }}
              </td>
              <td class="px-4 py-2 text-right">{{ s.miembros_activos }}</td>
              <td class="px-4 py-2 text-right">{{ s.sesiones_proximas }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Rentabilidad por clase (R30) -->
      <h2 class="mt-8 font-bold text-lg">
        {{ $t("reportes.rentabilidad.titulo") }}
      </h2>
      <p
        v-if="!rentabilidad || rentabilidad.ofertas.length === 0"
        class="mt-3 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("reportes.rentabilidad.vacio") }}
      </p>
      <template v-else>
        <div class="mt-3 tu-card overflow-hidden">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
                <th class="px-4 py-2 font-medium">
                  {{ $t("reportes.rentabilidad.colClase") }}
                </th>
                <th
                  class="px-4 py-2 font-medium text-right hidden sm:table-cell"
                >
                  {{ $t("reportes.rentabilidad.colSesiones") }}
                </th>
                <th class="px-4 py-2 font-medium text-right">
                  {{ $t("reportes.rentabilidad.colAsistentes") }}
                </th>
                <th class="px-4 py-2 font-medium text-right">
                  {{ $t("reportes.rentabilidad.colIngreso") }}
                </th>
                <th class="px-4 py-2 font-medium text-right">
                  {{ $t("reportes.rentabilidad.colCosto") }}
                </th>
                <th class="px-4 py-2 font-medium text-right">
                  {{ $t("reportes.rentabilidad.colMargen") }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="o in rentabilidad.ofertas"
                :key="o.id ?? o.oferta"
                class="border-t"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <td class="px-4 py-2 font-semibold">{{ o.oferta }}</td>
                <td class="px-4 py-2 text-right hidden sm:table-cell">
                  {{ o.sesiones }}
                </td>
                <td class="px-4 py-2 text-right">{{ o.asistentes }}</td>
                <td class="px-4 py-2 text-right">
                  {{ dinero(o.ingreso_minor, rentabilidad.moneda) }}
                </td>
                <td class="px-4 py-2 text-right">
                  {{ dinero(o.costo_minor, rentabilidad.moneda) }}
                </td>
                <td
                  class="px-4 py-2 text-right font-semibold"
                  :style="{
                    color:
                      o.margen_minor >= 0 ? 'var(--exito)' : 'var(--error)',
                  }"
                >
                  {{ dinero(o.margen_minor, rentabilidad.moneda) }}
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr
                class="border-t font-bold"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <td class="px-4 py-2">
                  {{ $t("reportes.rentabilidad.total") }}
                </td>
                <td class="px-4 py-2 hidden sm:table-cell"></td>
                <td class="px-4 py-2 text-right">
                  {{ rentabilidad.totales.asistentes }}
                </td>
                <td class="px-4 py-2 text-right">
                  {{
                    dinero(
                      rentabilidad.totales.ingreso_minor,
                      rentabilidad.moneda,
                    )
                  }}
                </td>
                <td class="px-4 py-2 text-right">
                  {{
                    dinero(
                      rentabilidad.totales.costo_minor,
                      rentabilidad.moneda,
                    )
                  }}
                </td>
                <td
                  class="px-4 py-2 text-right"
                  :style="{
                    color:
                      rentabilidad.totales.margen_minor >= 0
                        ? 'var(--exito)'
                        : 'var(--error)',
                  }"
                >
                  {{
                    dinero(
                      rentabilidad.totales.margen_minor,
                      rentabilidad.moneda,
                    )
                  }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p
          v-if="rentabilidad.totales.sin_costo_unitario > 0"
          class="mt-3 text-xs"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{
            $t("reportes.rentabilidad.sinCosto", {
              n: rentabilidad.totales.sin_costo_unitario,
            })
          }}
        </p>
      </template>

      <!-- Demanda por horario (R31) -->
      <h2 class="mt-8 font-bold text-lg">
        {{ $t("reportes.demanda.titulo") }}
      </h2>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("reportes.demanda.subtitulo") }}
      </p>
      <p
        v-if="!demanda || demanda.matriz.length === 0"
        class="mt-3 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("reportes.demanda.vacio") }}
      </p>
      <template v-else>
        <!-- Heatmap día × hora -->
        <div class="mt-3 tu-card overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-center" :style="{ color: 'var(--texto-suave)' }">
                <th class="px-3 py-2 font-medium text-left">
                  {{ $t("reportes.demanda.hora") }}
                </th>
                <th
                  v-for="(d, i) in diasSemana"
                  :key="i"
                  class="px-2 py-2 font-medium"
                >
                  {{ d }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="h in horasDemanda"
                :key="h"
                class="border-t"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <td class="px-3 py-1.5 font-semibold whitespace-nowrap">
                  {{ String(h).padStart(2, "0") }}:00
                </td>
                <td
                  v-for="dia in [1, 2, 3, 4, 5, 6, 7]"
                  :key="dia"
                  class="px-1 py-1 text-center"
                >
                  <div
                    v-if="celdaDemanda(dia, h)"
                    class="relative rounded-lg py-1.5 text-xs font-semibold"
                    :style="{
                      background: colorOcupacion(
                        celdaDemanda(dia, h)!.ocupacion_pct,
                      ),
                    }"
                    :title="`${celdaDemanda(dia, h)!.confirmadas}/${celdaDemanda(dia, h)!.capacidad}`"
                  >
                    {{ pct(celdaDemanda(dia, h)!.ocupacion_pct) }}
                    <span
                      v-if="celdaDemanda(dia, h)!.espera > 0"
                      class="absolute top-0.5 right-0.5 h-1.5 w-1.5 rounded-full"
                      :style="{ background: 'var(--aviso)' }"
                      :title="$t('reportes.demanda.colEspera')"
                    />
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reportes.demanda.leyenda") }}
        </p>

        <!-- Por actividad -->
        <h3 class="mt-6 font-semibold">
          {{ $t("reportes.demanda.porActividad") }}
        </h3>
        <div class="mt-3 tu-card overflow-hidden">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
                <th class="px-4 py-2 font-medium">
                  {{ $t("reportes.demanda.colActividad") }}
                </th>
                <th
                  class="px-4 py-2 font-medium text-right hidden sm:table-cell"
                >
                  {{ $t("reportes.demanda.colSesiones") }}
                </th>
                <th class="px-4 py-2 font-medium text-right">
                  {{ $t("reportes.demanda.colConfirmadas") }}
                </th>
                <th class="px-4 py-2 font-medium text-right">
                  {{ $t("reportes.demanda.colEspera") }}
                </th>
                <th class="px-4 py-2 font-medium text-right">
                  {{ $t("reportes.demanda.colOcupacion") }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="a in demanda.actividades"
                :key="a.id ?? a.actividad"
                class="border-t"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <td class="px-4 py-2 font-semibold">{{ a.actividad }}</td>
                <td class="px-4 py-2 text-right hidden sm:table-cell">
                  {{ a.sesiones }}
                </td>
                <td class="px-4 py-2 text-right">{{ a.confirmadas }}</td>
                <td
                  class="px-4 py-2 text-right font-semibold"
                  :style="{ color: a.espera > 0 ? 'var(--aviso)' : 'inherit' }"
                >
                  {{ a.espera }}
                </td>
                <td class="px-4 py-2 text-right font-semibold">
                  {{ pct(a.ocupacion_pct) }}
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr
                class="border-t font-bold"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <td class="px-4 py-2">{{ $t("reportes.demanda.total") }}</td>
                <td class="px-4 py-2 text-right hidden sm:table-cell">
                  {{ demanda.totales.sesiones }}
                </td>
                <td class="px-4 py-2 text-right">
                  {{ demanda.totales.confirmadas }}
                </td>
                <td class="px-4 py-2 text-right">
                  {{ demanda.totales.espera }}
                </td>
                <td class="px-4 py-2 text-right">
                  {{ pct(demanda.totales.ocupacion_pct) }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </template>
    </template>
  </section>
</template>
