<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelClase from "@/components/PanelClase.vue";
import PanelMiembro from "@/components/PanelMiembro.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Sucursal {
  id: string;
  nombre: string;
}
interface Metricas {
  sesiones: number;
  capacidad_total: number;
  confirmadas: number;
  en_espera: number;
  presentes: number;
  ausentes: number;
  ocupacion_pct: number | null;
}
interface SesionDia {
  id: string;
  oferta: string | null;
  instructor: string | null;
  inicia_en: string;
  zona_horaria: string;
  estado: string;
  capacidad: number | null;
  confirmadas: number;
  ofrecidas: number;
  en_espera: number;
  presentes: number;
  ausentes: number;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

function isoHoy(): string {
  const d = new Date();
  const p = (n: number): string => (n < 10 ? `0${n}` : `${n}`);
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

const fecha = ref(isoHoy());
const sucursalFiltro = ref("");
const sucursales = ref<Sucursal[]>([]);
const metricas = ref<Metricas | null>(null);
const sesiones = ref<SesionDia[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const sesionActiva = ref<SesionDia | null>(null);

function abrir(s: SesionDia): void {
  sesionActiva.value = s;
}

// Buscador global de alumno (server-side): encuentra a cualquiera, no solo a los
// primeros 100. Al elegir uno se abre su panel con alertas.
interface MiembroResultado {
  id: string;
  nombre_completo: string;
  email: string | null;
}
const busqueda = ref("");
const resultados = ref<MiembroResultado[]>([]);
const buscando = ref(false);
const miembroActivo = ref<{ id: string; nombre: string } | null>(null);
let tempBusqueda: ReturnType<typeof setTimeout> | undefined;

async function buscar(): Promise<void> {
  const q = busqueda.value.trim();
  if (q.length < 2) {
    resultados.value = [];
    return;
  }
  buscando.value = true;
  try {
    const { data } = await api.get<{ data: MiembroResultado[] }>(
      `${base.value}/miembros`,
      { params: { q } },
    );
    resultados.value = data.data;
  } catch {
    resultados.value = [];
  } finally {
    buscando.value = false;
  }
}
watch(busqueda, () => {
  clearTimeout(tempBusqueda);
  tempBusqueda = setTimeout(() => void buscar(), 300);
});
function abrirMiembro(m: MiembroResultado): void {
  miembroActivo.value = { id: m.id, nombre: m.nombre_completo };
  resultados.value = [];
  busqueda.value = "";
}

function horaCorta(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const params: Record<string, string> = { fecha: fecha.value };
    if (sucursalFiltro.value !== "") {
      params.sucursal_id = sucursalFiltro.value;
    }
    const { data } = await api.get<{
      metricas: Metricas;
      sesiones: SesionDia[];
    }>(`${base.value}/front-desk`, { params });
    metricas.value = data.metricas;
    sesiones.value = data.sesiones;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

const tarjetas = computed(() => {
  const m = metricas.value;
  if (m === null) {
    return [];
  }
  return [
    { clave: "sesiones", valor: String(m.sesiones) },
    {
      clave: "ocupacion",
      valor: m.ocupacion_pct !== null ? `${m.ocupacion_pct}%` : "—",
    },
    { clave: "confirmadas", valor: String(m.confirmadas) },
    { clave: "enEspera", valor: String(m.en_espera) },
    { clave: "presentes", valor: String(m.presentes) },
    { clave: "ausentes", valor: String(m.ausentes) },
  ];
});

watch([fecha, sucursalFiltro], cargar);

onMounted(async () => {
  try {
    const { data } = await api.get<{ data: Sucursal[] }>(
      `${base.value}/sucursales`,
    );
    sucursales.value = data.data;
  } catch {
    // el filtro de sucursal es opcional
  }
  await cargar();
});
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('recepcion.titulo')" />

    <!-- Buscador global de alumno -->
    <div class="relative mt-6">
      <input
        v-model="busqueda"
        type="search"
        class="tu-input"
        :placeholder="$t('recepcion.buscarMiembro')"
      />
      <div
        v-if="busqueda.trim().length >= 2"
        class="absolute z-20 mt-1 w-full tu-card overflow-hidden"
      >
        <p
          v-if="buscando"
          class="px-4 py-3 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>
        <p
          v-else-if="resultados.length === 0"
          class="px-4 py-3 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("recepcion.sinResultados") }}
        </p>
        <ul v-else class="max-h-72 overflow-y-auto">
          <li v-for="m in resultados" :key="m.id">
            <button
              type="button"
              class="w-full border-t px-4 py-2.5 text-left first:border-t-0 hover:brightness-95"
              :style="{ borderColor: 'var(--borde)' }"
              @click="abrirMiembro(m)"
            >
              <span class="block font-medium">{{ m.nombre_completo }}</span>
              <span
                v-if="m.email"
                class="block text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ m.email }}</span
              >
            </button>
          </li>
        </ul>
      </div>
    </div>

    <!-- Controles -->
    <div class="mt-6 flex flex-wrap items-center gap-3">
      <input
        v-model="fecha"
        type="date"
        class="tu-input w-auto"
        :aria-label="$t('recepcion.fecha')"
      />
      <button
        class="tu-btn tu-btn-fantasma"
        type="button"
        @click="fecha = isoHoy()"
      >
        {{ $t("recepcion.hoy") }}
      </button>
      <select
        v-model="sucursalFiltro"
        class="tu-input w-auto"
        :aria-label="$t('recepcion.todasSucursales')"
      >
        <option value="">{{ $t("recepcion.todasSucursales") }}</option>
        <option v-for="s in sucursales" :key="s.id" :value="s.id">
          {{ s.nombre }}
        </option>
      </select>
      <RouterLink
        class="tu-btn tu-btn-fantasma ml-auto"
        :to="{ name: 'agenda' }"
        >{{ $t("recepcion.abrirAgenda") }}</RouterLink
      >
    </div>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <!-- Métricas del día -->
    <div class="mt-6 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
      <div v-for="t in tarjetas" :key="t.clave" class="tu-card p-4 text-center">
        <div class="text-2xl font-extrabold">{{ t.valor }}</div>
        <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
          {{ $t(`recepcion.metricas.${t.clave}`) }}
        </div>
      </div>
    </div>

    <!-- Clases del día -->
    <p
      v-if="!cargando && sesiones.length > 0"
      class="mt-5 text-xs"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("recepcion.tocaClase") }}
    </p>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <p
      v-else-if="sesiones.length === 0"
      class="mt-8 text-center text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("recepcion.vacio") }}
    </p>
    <div v-else class="mt-6 tu-card overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
            <th class="px-4 py-2 font-medium">{{ $t("recepcion.colHora") }}</th>
            <th class="px-4 py-2 font-medium">
              {{ $t("recepcion.colClase") }}
            </th>
            <th class="px-4 py-2 font-medium hidden sm:table-cell">
              {{ $t("recepcion.colInstructor") }}
            </th>
            <th class="px-4 py-2 font-medium text-right">
              {{ $t("recepcion.colOcupacion") }}
            </th>
            <th class="px-4 py-2 font-medium text-right">
              {{ $t("recepcion.colEspera") }}
            </th>
            <th class="px-4 py-2 font-medium text-right">
              {{ $t("recepcion.colAsistencia") }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="s in sesiones"
            :key="s.id"
            class="border-t cursor-pointer transition-colors hover:brightness-95"
            :style="{
              borderColor: 'var(--borde)',
              opacity: s.estado !== 'programada' ? 0.55 : 1,
            }"
            @click="abrir(s)"
          >
            <td class="px-4 py-2 font-semibold">
              {{ horaCorta(s.inicia_en, s.zona_horaria) }}
            </td>
            <td class="px-4 py-2">
              {{ s.oferta ?? "—" }}
              <span v-if="s.estado !== 'programada'" class="tu-badge ml-1">{{
                $t("recepcion.cancelada")
              }}</span>
            </td>
            <td
              class="px-4 py-2 hidden sm:table-cell"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ s.instructor ?? "—" }}
            </td>
            <td class="px-4 py-2 text-right">
              <span
                class="tu-badge"
                :class="
                  s.capacidad !== null && s.confirmadas >= s.capacidad
                    ? 'tu-badge-aviso'
                    : 'tu-badge-exito'
                "
              >
                {{
                  s.capacidad !== null
                    ? `${s.confirmadas}/${s.capacidad}`
                    : s.confirmadas
                }}
              </span>
            </td>
            <td class="px-4 py-2 text-right">{{ s.en_espera }}</td>
            <td class="px-4 py-2 text-right">
              <span :style="{ color: 'var(--exito)' }">{{ s.presentes }}</span>
              /
              <span style="color: var(--error)">{{ s.ausentes }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PanelClase
      v-if="sesionActiva"
      :sesion="sesionActiva"
      @cerrar="sesionActiva = null"
      @cambio="cargar"
    />
    <PanelMiembro
      v-if="miembroActivo"
      :persona-id="miembroActivo.id"
      :nombre="miembroActivo.nombre"
      @cerrar="miembroActivo = null"
    />
  </section>
</template>
