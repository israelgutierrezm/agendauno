<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import MapaDemanda from "@/components/MapaDemanda.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const { t } = useI18n();

interface Negocio {
  moneda: string;
  ingresos_minor: number;
  ordenes_pagadas: number;
  clases: number;
  ocupacion_pct: number | null;
  // Horas agendadas entre las disponibles (ADR 0081): la de negocios de citas.
  ocupacion_agenda_pct?: number | null;
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
// `GET /reportes/sucursales`: las sucursales, quienes no tienen una y los totales.
interface ReporteSucursales {
  sucursales: SucursalReporte[];
  sin_sucursal: { miembros_activos: number };
  totales: {
    sucursales: number;
    miembros_activos: number;
    sesiones_proximas: number;
  };
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
  // Ocupación de la agenda de quienes atienden (ADR 0081).
  disponible_min?: number;
  agendado_min?: number;
  utilizacion_pct?: number | null;
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
    disponible_min?: number;
    agendado_min?: number;
    utilizacion_pct?: number | null;
  };
  matriz: DemandaCelda[];
  actividades: DemandaActividad[];
}
// `GET /reportes/equipo` (ADR 0081): la agenda de cada quien atiende.
interface CifrasEquipo {
  clases: number;
  citas: number;
  agendado_min: number;
  disponible_min: number;
  agendado_en_horario_min: number;
  presentes: number;
  ausentes: number;
  canceladas: number;
  ingreso_minor: number;
  costo_minor: number;
  margen_minor: number;
  ocupacion_pct: number | null;
  inasistencia_pct: number | null;
}
interface ProfesionalEquipo extends CifrasEquipo {
  id: string | null;
  nombre: string | null;
  foto_url: string | null;
}
interface Equipo {
  moneda: string;
  totales: CifrasEquipo;
  profesionales: ProfesionalEquipo[];
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
  // Cómo conocieron al negocio las altas de la ventana (ADR 0067).
  origenes?: { origen: string; total: number }[];
  origenes_sin_dato?: number;
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
const sinSucursal = ref(0);
const totalesSucursales = ref<ReporteSucursales["totales"] | null>(null);
const rentabilidad = ref<Rentabilidad | null>(null);
const demanda = ref<Demanda | null>(null);
const equipo = ref<Equipo | null>(null);
const tendencias = ref<Tendencias | null>(null);
const agrupacion = ref<"dia" | "semana" | "mes">("dia");
const exportando = ref(false);
const cohortes = ref<Cohortes | null>(null);
// Porcentaje de cada origen sobre quienes dijeron cómo nos conocieron.
function pctOrigen(total: number): string {
  const conDato = (cohortes.value?.origenes ?? []).reduce(
    (n, o) => n + o.total,
    0,
  );
  return conDato > 0 ? `${Math.round((total / conDato) * 100)} %` : "—";
}

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

// En citas cada cita tiene cupo 1: el mapa mide la agenda del equipo (horas
// agendadas entre las disponibles), no el cupo (ADR 0081).
const porAgenda = computed(() => sesion.modalidad === "citas");
const celdasDemanda = computed(() =>
  (demanda.value?.matriz ?? []).filter((c) =>
    porAgenda.value
      ? (c.disponible_min ?? 0) > 0 || c.sesiones > 0
      : c.sesiones > 0,
  ),
);
// «3 clases · 2 citas», solo lo que hubo.
function sesionesTexto(c: CifrasEquipo): string {
  const partes = [
    c.clases > 0 ? t("reportes.equipo.clases", c.clases) : "",
    c.citas > 0 ? t("reportes.equipo.citas", c.citas) : "",
  ].filter((p) => p !== "");
  return partes.length > 0 ? partes.join(" · ") : "—";
}
// Minutos en horas con un decimal ('90' → '1.5 h').
function horas(minutos: number): string {
  return `${new Intl.NumberFormat("es-MX", { maximumFractionDigits: 1 }).format(minutos / 60)} h`;
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

const tarjetas = computed<Indicador[]>(() => {
  const n = negocio.value;
  if (n === null) {
    return [];
  }
  const lista: Omit<Indicador, "etiqueta">[] = [
    {
      clave: "ingresos",
      valor: dinero(n.ingresos_minor, n.moneda),
      icono: "dinero",
      tono: "verde",
    },
    {
      clave: "ocupacion",
      valor: pct(
        porAgenda.value ? (n.ocupacion_agenda_pct ?? null) : n.ocupacion_pct,
      ),
      icono: "pulso",
      tono: "morado",
    },
    {
      clave: "noShow",
      valor: pct(n.no_show_pct),
      icono: "ausente",
      tono: "rosa",
    },
    {
      clave: "alumnos",
      valor: String(n.alumnos_activos),
      icono: "personas",
      tono: "azul",
    },
    {
      clave: "arpu",
      valor: dinero(n.arpu_minor, n.moneda),
      icono: "facturas",
      tono: "naranja",
    },
    {
      clave: "clases",
      valor: String(n.clases),
      icono: "agenda",
      tono: "cielo",
    },
  ];
  return lista.map((k) => ({
    ...k,
    etiqueta: t(`reportes.metricas.${k.clave}`),
  }));
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

async function cargarEquipo(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{ data: Equipo }>(
      `${base.value}/reportes/equipo`,
      {
        params: { desde: desde.value, hasta: hasta.value },
      },
    );
    equipo.value = data.data;
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
      api.get<{ data: ReporteSucursales }>(`${base.value}/reportes/sucursales`),
      cargarRentabilidad(),
      cargarDemanda(),
      cargarTendencias(),
      cargarCohortes(),
      cargarEquipo(),
    ]);
    sucursales.value = s.data.data.sucursales;
    sinSucursal.value = s.data.data.sin_sucursal.miembros_activos;
    totalesSucursales.value = s.data.data.totales;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

/**
 * Pestañas: Resumen, Ingresos, Ocupación y la agenda del equipo dependen del periodo
 * elegido; Clientes (cohortes por mes de alta) y las sucursales (estado actual), no.
 * El selector de periodo solo aparece donde cuenta, y cada sección dice de qué son
 * sus cifras.
 */
type Pestana = "resumen" | "ingresos" | "ocupacion" | "clientes" | "equipo";
const PESTANAS: Pestana[] = [
  "resumen",
  "ingresos",
  "ocupacion",
  "clientes",
  "equipo",
];
const pestana = ref<Pestana>("resumen");
const usaPeriodo = computed(
  () =>
    pestana.value === "resumen" ||
    pestana.value === "ingresos" ||
    pestana.value === "ocupacion" ||
    pestana.value === "equipo",
);
const periodoTexto = computed(() => {
  const f = (ymd: string) =>
    new Intl.DateTimeFormat("es-MX", {
      day: "numeric",
      month: "short",
      year: "numeric",
    }).format(new Date(`${ymd}T12:00:00`));
  return t("operacion.reportes.periodo", {
    desde: f(desde.value),
    hasta: f(hasta.value),
  });
});

function esteMes(): void {
  desde.value = inicioMes();
  hasta.value = iso(new Date());
}

watch([desde, hasta], () => {
  void cargarNegocio();
  void cargarRentabilidad();
  void cargarDemanda();
  void cargarTendencias();
  void cargarEquipo();
});
watch(agrupacion, () => void cargarTendencias());

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('reportes.titulo')" />

    <div class="tu-pestanas mt-6" role="group">
      <button
        v-for="p in PESTANAS"
        :key="p"
        type="button"
        :aria-pressed="pestana === p"
        @click="pestana = p"
      >
        {{ $t(`operacion.reportes.pestanas.${p}`) }}
      </button>
    </div>

    <!-- Periodo -->
    <div v-if="usaPeriodo" class="mt-4 flex flex-wrap items-end gap-3">
      <div>
        <label class="tu-label" for="rd">{{ $t("reportes.desde") }}</label>
        <span class="tu-campo-icono">
          <IconoNav nombre="agenda" :tam="18" />
          <input id="rd" v-model="desde" type="date" class="tu-input w-auto" />
        </span>
      </div>
      <div>
        <label class="tu-label" for="rh">{{ $t("reportes.hasta") }}</label>
        <span class="tu-campo-icono">
          <IconoNav nombre="agenda" :tam="18" />
          <input id="rh" v-model="hasta" type="date" class="tu-input w-auto" />
        </span>
      </div>
      <button class="tu-btn tu-btn-fantasma" type="button" @click="esteMes">
        <IconoNav nombre="agenda" :tam="18" />
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
      <p
        v-if="usaPeriodo"
        class="mt-4 text-xs"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ periodoTexto }}
      </p>

      <!-- Métricas del negocio -->
      <TarjetasIndicadores
        v-if="pestana === 'resumen'"
        class="mt-4"
        :tarjetas="tarjetas"
      />

      <!-- Tendencias de ingresos (Etapa 2) -->
      <template v-if="pestana === 'ingresos'">
        <div class="mt-4 flex flex-wrap items-center gap-3">
          <h2 class="font-medium text-lg">
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
                {{
                  dinero(tendencias.totales.ingresos_minor, tendencias.moneda)
                }}
              </div>
              <div
                class="text-xs mt-1"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("reportes.tendencias.ingresos") }}
              </div>
            </div>
            <div>
              <div class="text-xl font-semibold tabular-nums">
                {{ tendencias.totales.ordenes }}
              </div>
              <div
                class="text-xs mt-1"
                :style="{ color: 'var(--texto-suave)' }"
              >
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
              <div
                class="text-xs mt-1"
                :style="{ color: 'var(--texto-suave)' }"
              >
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
                  fechaBucket(
                    tendencias.serie[tendencias.serie.length - 1].fecha,
                  )
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
      </template>

      <p
        v-if="pestana === 'clientes' && !cohortes"
        class="mt-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("operacion.reportes.sinClientes") }}
      </p>
      <!-- Conversión + cohortes de retención (Etapa 2) -->
      <template v-if="pestana === 'clientes' && cohortes">
        <h2 class="mt-6 font-medium text-lg">
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

        <!-- Cómo nos conocieron (lo dicen al agendar en línea) -->
        <template v-if="(cohortes.origenes ?? []).length > 0">
          <h3 class="mt-6 font-semibold">
            {{ $t("operacion.reportes.origenes.titulo") }}
          </h3>
          <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{
              $t("operacion.reportes.origenes.subtitulo", {
                n: cohortes.meses,
                sinDato: cohortes.origenes_sin_dato ?? 0,
              })
            }}
          </p>
          <ul
            class="mt-3 tu-card px-5 py-2 divide-y divide-[var(--borde)]"
            data-prueba="origenes"
          >
            <li
              v-for="o in cohortes.origenes"
              :key="o.origen"
              class="flex items-center justify-between gap-3 py-2.5 text-sm"
            >
              <span>{{ $t(`perfilPublico.origenes.${o.origen}`) }}</span>
              <span class="tabular-nums font-medium">
                {{ o.total }}
                <span :style="{ color: 'var(--texto-suave)' }"
                  >· {{ pctOrigen(o.total) }}</span
                >
              </span>
            </li>
          </ul>
        </template>

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
      <!-- Agenda del equipo (ADR 0081) -->
      <template v-if="pestana === 'equipo'">
        <h2 class="mt-4 font-medium text-lg">
          {{ $t("reportes.equipo.titulo") }}
        </h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reportes.equipo.subtitulo") }}
        </p>
        <p
          v-if="!equipo || equipo.profesionales.length === 0"
          class="mt-3 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("reportes.equipo.vacio") }}
        </p>
        <template v-else>
          <div class="mt-3 tu-card overflow-x-auto">
            <table class="w-full text-sm" data-prueba="equipo">
              <thead>
                <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
                  <th class="px-4 py-2 font-medium">
                    {{ $t("reportes.equipo.colProfesional") }}
                  </th>
                  <th class="px-4 py-2 font-medium">
                    {{ $t("reportes.equipo.colOcupacion") }}
                  </th>
                  <th
                    class="px-4 py-2 font-medium text-right hidden sm:table-cell"
                  >
                    {{ $t("reportes.equipo.colSesiones") }}
                  </th>
                  <th
                    class="px-4 py-2 font-medium text-right hidden md:table-cell"
                  >
                    {{ $t("reportes.equipo.colInasistencia") }}
                  </th>
                  <th class="px-4 py-2 font-medium text-right">
                    {{ $t("reportes.equipo.colValor") }}
                  </th>
                  <th
                    class="px-4 py-2 font-medium text-right hidden sm:table-cell"
                  >
                    {{ $t("reportes.equipo.colPago") }}
                  </th>
                  <th class="px-4 py-2 font-medium text-right">
                    {{ $t("reportes.equipo.colMargen") }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="p in equipo.profesionales"
                  :key="p.id ?? 'sin'"
                  class="border-t"
                  :style="{ borderColor: 'var(--borde)' }"
                  data-prueba="profesional"
                >
                  <td class="px-4 py-2">
                    <span class="flex items-center gap-2 font-semibold">
                      <img
                        v-if="p.foto_url"
                        :src="p.foto_url"
                        alt=""
                        class="h-7 w-7 rounded-full object-cover shrink-0"
                      />
                      {{ p.nombre ?? $t("reportes.equipo.sinProfesional") }}
                    </span>
                  </td>
                  <td class="px-4 py-2 re-ocupacion">
                    <template v-if="p.ocupacion_pct !== null">
                      <span class="font-semibold tabular-nums">{{
                        pct(p.ocupacion_pct)
                      }}</span>
                      <span class="re-barra" aria-hidden="true"
                        ><span :style="{ width: `${p.ocupacion_pct}%` }"
                      /></span>
                      <span
                        class="block text-xs"
                        :style="{ color: 'var(--texto-suave)' }"
                        >{{ horas(p.agendado_en_horario_min) }} /
                        {{ horas(p.disponible_min) }}</span
                      >
                    </template>
                    <span
                      v-else
                      class="text-xs"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{ $t("reportes.equipo.sinHorario") }} ·
                      {{ horas(p.agendado_min) }}</span
                    >
                  </td>
                  <td class="px-4 py-2 text-right hidden sm:table-cell">
                    {{ sesionesTexto(p) }}
                  </td>
                  <td
                    class="px-4 py-2 text-right hidden md:table-cell"
                    :title="`${p.presentes} / ${p.ausentes}`"
                  >
                    {{ pct(p.inasistencia_pct) }}
                  </td>
                  <td class="px-4 py-2 text-right">
                    {{ dinero(p.ingreso_minor, equipo.moneda) }}
                  </td>
                  <td class="px-4 py-2 text-right hidden sm:table-cell">
                    {{ dinero(p.costo_minor, equipo.moneda) }}
                  </td>
                  <td
                    class="px-4 py-2 text-right font-semibold"
                    :style="{
                      color:
                        p.margen_minor >= 0 ? 'var(--exito)' : 'var(--error)',
                    }"
                  >
                    {{ dinero(p.margen_minor, equipo.moneda) }}
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr
                  class="border-t font-bold"
                  :style="{ borderColor: 'var(--borde)' }"
                >
                  <td class="px-4 py-2">{{ $t("reportes.equipo.total") }}</td>
                  <td class="px-4 py-2">
                    {{ pct(equipo.totales.ocupacion_pct) }}
                  </td>
                  <td class="px-4 py-2 text-right hidden sm:table-cell">
                    {{ sesionesTexto(equipo.totales) }}
                  </td>
                  <td class="px-4 py-2 text-right hidden md:table-cell">
                    {{ pct(equipo.totales.inasistencia_pct) }}
                  </td>
                  <td class="px-4 py-2 text-right">
                    {{ dinero(equipo.totales.ingreso_minor, equipo.moneda) }}
                  </td>
                  <td class="px-4 py-2 text-right hidden sm:table-cell">
                    {{ dinero(equipo.totales.costo_minor, equipo.moneda) }}
                  </td>
                  <td
                    class="px-4 py-2 text-right"
                    :style="{
                      color:
                        equipo.totales.margen_minor >= 0
                          ? 'var(--exito)'
                          : 'var(--error)',
                    }"
                  >
                    {{ dinero(equipo.totales.margen_minor, equipo.moneda) }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
          <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("reportes.equipo.leyenda") }}
          </p>
        </template>
      </template>

      <template v-if="pestana === 'equipo'">
        <h2 class="mt-8 font-medium text-lg">
          {{ $t("reportes.porSucursal") }}
        </h2>
        <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("operacion.reportes.estadoActual") }}
        </p>
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
              <tr
                v-if="sinSucursal > 0"
                class="border-t"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <td class="px-4 py-2" :style="{ color: 'var(--texto-suave)' }">
                  {{ $t("operacion.reportes.sinSucursal") }}
                </td>
                <td class="px-4 py-2 hidden sm:table-cell" />
                <td class="px-4 py-2 hidden sm:table-cell" />
                <td class="px-4 py-2 text-right">{{ sinSucursal }}</td>
                <td class="px-4 py-2 text-right">—</td>
              </tr>
            </tbody>
            <tfoot v-if="totalesSucursales">
              <tr
                class="border-t font-semibold"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <td class="px-4 py-2">{{ $t("operacion.reportes.total") }}</td>
                <td class="px-4 py-2 hidden sm:table-cell" />
                <td class="px-4 py-2 hidden sm:table-cell" />
                <td class="px-4 py-2 text-right">
                  {{ totalesSucursales.miembros_activos }}
                </td>
                <td class="px-4 py-2 text-right">
                  {{ totalesSucursales.sesiones_proximas }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </template>

      <!-- Rentabilidad por clase (R30) -->
      <template v-if="pestana === 'ingresos'">
        <h2 class="mt-8 font-medium text-lg">
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
      </template>

      <!-- Demanda por horario (R31) -->
      <template v-if="pestana === 'ocupacion'">
        <div class="mt-4 tu-card p-5 sm:p-6">
          <template v-if="!demanda || celdasDemanda.length === 0">
            <h2 class="text-xl font-semibold">
              {{ $t("reportes.demanda.titulo") }}
            </h2>
            <EstadoVacio
              icono="reportes"
              :titulo="$t('reportes.demanda.vacio')"
            />
          </template>
          <MapaDemanda
            v-else
            :celdas="celdasDemanda"
            :por-agenda="porAgenda"
            :promedio-pct="
              porAgenda
                ? (demanda.totales.utilizacion_pct ?? null)
                : demanda.totales.ocupacion_pct
            "
          />
          <p
            v-if="porAgenda && demanda?.totales.utilizacion_pct != null"
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            data-prueba="resumen-agenda"
          >
            {{
              $t("reportes.demanda.resumenAgenda", {
                pct: pct(demanda.totales.utilizacion_pct ?? null),
                agendadas: horas(demanda.totales.agendado_min ?? 0),
                disponibles: horas(demanda.totales.disponible_min ?? 0),
              })
            }}
          </p>
        </div>
        <template v-if="demanda && celdasDemanda.length > 0">
          <!-- Por actividad -->
          <h3 class="mt-6 text-lg font-semibold">
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
                    :style="{
                      color: a.espera > 0 ? 'var(--aviso)' : 'inherit',
                    }"
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
    </template>
  </section>
</template>

<style scoped>
.re-ocupacion {
  min-width: 8rem;
}
.re-barra {
  display: block;
  height: 0.25rem;
  margin-top: 0.3rem;
  border-radius: 999px;
  background: var(--borde);
  overflow: hidden;
}
.re-barra > span {
  display: block;
  height: 100%;
  background: var(--primario);
}
</style>
