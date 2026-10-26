<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PestanasSeccion from "@/components/PestanasSeccion.vue";
import SegmentosRetencion from "@/components/SegmentosRetencion.vue";
import { api, mensajeDeError } from "@/lib/api";
import {
  opcionEnUrl,
  useFiltrosEnUrl,
  usePestanaEnUrl,
} from "@/lib/filtrosEnUrl";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const { t } = useI18n();

interface Miembro {
  id: string;
  nombre_completo: string;
  email: string | null;
  estado: "por_vencer" | "vencida";
  vence: string;
  dias_restantes: number;
  ultima_asistencia: string | null;
  dias_sin_asistir: number | null;
}
interface Resumen {
  por_vencer: number;
  vencidas: number;
  dias: number;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const miembros = ref<Miembro[]>([]);
const resumen = ref<Resumen | null>(null);
const DIAS_POR_OMISION = 14;
const dias = ref(DIAS_POR_OMISION);
const VENTANAS = [7, 14, 30, 90];
// Dos pestañas: vencimientos (la tabla) y grupos para recuperar (`?pestana=recuperar`).
const PESTANAS = ["renovaciones", "recuperar"] as const;
const pestana = usePestanaEnUrl(PESTANAS);
const opcionesPestana = computed(() =>
  PESTANAS.map((clave) => ({
    clave,
    texto: t(`retencion.vistas.${clave}`),
  })),
);
const estado = ref<"" | "por_vencer" | "vencida">("");
// El estado, la búsqueda y las páginas los resuelve el API (antes, todo en pantalla).
const visibles = computed(() => miembros.value);
const buscar = ref("");
// Los filtros en la dirección: el inicio abre la lista ya filtrada (M08,
// `?estado=por_vencer|vencida&dias=`) y recargar o volver conserva lo que se veía.
// La ventana por omisión no se escribe.
useFiltrosEnUrl({
  dias: computed({
    get: () => (dias.value === DIAS_POR_OMISION ? "" : String(dias.value)),
    set: (valor: string) => {
      const n = Number(valor);
      if (Number.isInteger(n) && n > 0) {
        dias.value = n;
      }
    },
  }),
  estado: opcionEnUrl(estado, ["", "por_vencer", "vencida"] as const),
  q: buscar,
});
// «Nadie coincide» no es lo mismo que «no hay nadie por renovar».
const filtrado = computed(
  () => estado.value !== "" || buscar.value.trim() !== "",
);
function quitarFiltros(): void {
  estado.value = "";
  buscar.value = "";
}
const pagina = ref(1);
const meta = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
} | null>(null);
function irPagina(n: number): void {
  pagina.value = n;
  void cargar();
}
let espera: ReturnType<typeof setTimeout> | undefined;
watch(buscar, () => {
  clearTimeout(espera);
  espera = setTimeout(() => {
    pagina.value = 1;
    void cargar();
  }, 300);
});
watch(estado, () => {
  pagina.value = 1;
  void cargar();
});
const cargando = ref(true);
const error = ref<string | null>(null);
// "No pudimos consultar" y "no hay pendientes" nunca deben verse igual.
const errorCarga = ref(false);
const exportando = ref(false);

function fechaCorta(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  // `vence` viene como fecha (YYYY-MM-DD); forzamos hora local para no restar un día.
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(`${iso}T00:00:00`));
}

// "en 5 días" / "hoy" / "hace 3 días" — el signo de dias_restantes lo dice todo.
function venceTexto(m: Miembro): string {
  if (m.dias_restantes < 0) {
    return t("retencion.vencioHace", { n: Math.abs(m.dias_restantes) });
  }
  return m.dias_restantes === 0
    ? t("retencion.venceHoy")
    : t("retencion.venceEn", { n: m.dias_restantes });
}
function asistTexto(m: Miembro): string {
  if (m.ultima_asistencia === null || m.dias_sin_asistir === null) {
    return t(
      sesion.esCitas
        ? "retencion.sinAsistenciaCitas"
        : "retencion.sinAsistencia",
    );
  }
  return m.dias_sin_asistir === 0
    ? t("retencion.hoy")
    : t("retencion.haceDias", { n: m.dias_sin_asistir });
}
const columnaActividad = computed(() =>
  t(sesion.esCitas ? "retencion.colActividadCitas" : "retencion.colActividad"),
);

// Los mismos filtros para la tabla y la descarga: se exporta lo que se ve.
function filtros(): Record<string, string | number> {
  return {
    dias: dias.value,
    ...(estado.value !== "" ? { estado: estado.value } : {}),
    ...(buscar.value.trim() !== "" ? { q: buscar.value.trim() } : {}),
  };
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  errorCarga.value = false;
  try {
    const { data } = await api.get<{
      data: {
        resumen: Resumen;
        miembros: Miembro[];
        meta?: typeof meta.value;
      };
    }>(`${base.value}/retencion/por-vencer`, {
      params: { ...filtros(), page: pagina.value },
    });
    miembros.value = data.data.miembros;
    resumen.value = data.data.resumen;
    meta.value = data.data.meta ?? null;
  } catch {
    errorCarga.value = true;
    miembros.value = [];
    resumen.value = null;
  } finally {
    cargando.value = false;
  }
}

// Dar seguimiento: crea su tarea (o dice por qué no) y deja el enlace a Tareas.
const puedeSeguir =
  sesion.puede?.("tareas.gestionar") === true &&
  sesion.puede?.("miembros.gestionar") === true;
const seguimientos = ref<Record<string, string>>({});
const dandoSeguimiento = ref<string | null>(null);
async function darSeguimiento(m: Miembro): Promise<void> {
  dandoSeguimiento.value = m.id;
  error.value = null;
  try {
    const { data } = await api.post<{
      data: { creada: boolean; omitida: string | null };
    }>(`${base.value}/retencion/seguimiento/${m.id}`, {
      estado: m.estado,
      vence: m.vence,
    });
    seguimientos.value = {
      ...seguimientos.value,
      [m.id]: data.data.creada ? "creado" : (data.data.omitida ?? "abierta"),
    };
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    dandoSeguimiento.value = null;
  }
}

async function exportar(): Promise<void> {
  exportando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<Blob>(`${base.value}/retencion/por-vencer`, {
      params: { ...filtros(), formato: "csv" },
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = `retencion-${sesion.slug}.csv`;
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

watch(dias, () => {
  pagina.value = 1;
  void cargar();
});
// La tabla se pide al verla: abrir directo «Para recuperar» no la carga de más.
let tablaCargada = false;
function cargarTabla(): void {
  if (pestana.value === "renovaciones" && !tablaCargada) {
    tablaCargada = true;
    void cargar();
  }
}
watch(pestana, cargarTabla, { immediate: true });
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="
        $t(
          sesion.esCitas
            ? 'operacion.renovaciones.tituloCitas'
            : 'operacion.renovaciones.titulo',
        )
      "
      :subtitulo="
        $t(
          sesion.esCitas
            ? 'operacion.renovaciones.subtituloCitas'
            : 'operacion.renovaciones.subtitulo',
        )
      "
    />

    <PestanasSeccion
      v-model="pestana"
      :opciones="opcionesPestana"
      :etiqueta="$t('retencion.titulo')"
    />

    <SegmentosRetencion v-if="pestana === 'recuperar'" />
    <template v-else>
      <div class="mt-6 flex flex-wrap items-center gap-3">
        <div class="tu-card px-5 py-3">
          <div
            class="text-2xl font-semibold"
            :style="{ color: 'var(--aviso)' }"
          >
            {{ resumen?.por_vencer ?? "—" }}
          </div>
          <div class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("retencion.porVencer") }}
          </div>
        </div>
        <div class="tu-card px-5 py-3">
          <div
            class="text-2xl font-semibold"
            :style="{ color: 'var(--error)' }"
          >
            {{ resumen?.vencidas ?? "—" }}
          </div>
          <div class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("retencion.vencidas") }}
          </div>
        </div>
        <label class="ml-auto flex items-center gap-2 text-sm">
          <span :style="{ color: 'var(--texto-suave)' }">{{
            $t("retencion.ventanaLabel")
          }}</span>
          <select v-model.number="dias" class="tu-input w-auto">
            <option v-if="!VENTANAS.includes(dias)" :value="dias">
              {{ $t("retencion.ventana", { n: dias }) }}
            </option>
            <option :value="7">{{ $t("retencion.ventana", { n: 7 }) }}</option>
            <option :value="14">
              {{ $t("retencion.ventana", { n: 14 }) }}
            </option>
            <option :value="30">
              {{ $t("retencion.ventana", { n: 30 }) }}
            </option>
            <option :value="90">
              {{ $t("retencion.ventana", { n: 90 }) }}
            </option>
          </select>
        </label>
        <label class="flex items-center gap-2 text-sm">
          <span :style="{ color: 'var(--texto-suave)' }">{{
            $t("retencion.mostrar")
          }}</span>
          <select
            v-model="estado"
            class="tu-input w-auto"
            data-prueba="filtro-estado"
          >
            <option value="">{{ $t("retencion.todas") }}</option>
            <option value="por_vencer">{{ $t("retencion.porVencer") }}</option>
            <option value="vencida">{{ $t("retencion.vencidas") }}</option>
          </select>
        </label>
        <input
          v-model="buscar"
          type="search"
          class="tu-input w-56"
          :placeholder="$t('operacion.listadosTres.buscarPadron')"
          :aria-label="$t('operacion.listadosTres.buscarPadron')"
          data-prueba="buscar-retencion"
        />
        <button
          class="tu-btn tu-btn-secundario"
          type="button"
          :disabled="exportando || cargando || (meta?.total ?? 0) === 0"
          data-prueba="exportar-retencion"
          @click="exportar"
        >
          {{
            exportando ? $t("retencion.exportando") : $t("retencion.exportar")
          }}
        </button>
      </div>

      <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
        {{ error }}
      </AvisoTono>
      <p
        v-if="cargando"
        class="mt-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>

      <div
        v-else-if="errorCarga"
        class="mt-6 tu-card flex flex-wrap items-center justify-between gap-3 p-6 text-sm"
        role="alert"
      >
        <span style="color: var(--error)">{{
          $t("operacion.renovaciones.errorCarga")
        }}</span>
        <button type="button" class="tu-btn tu-btn-secundario" @click="cargar">
          {{ $t("comun.reintentar") }}
        </button>
      </div>

      <template v-else>
        <EstadoVacio
          v-if="visibles.length === 0 && filtrado"
          class="tu-card mt-6"
          icono="buscar"
          :titulo="$t('tabla.sinCoincidencias')"
          :texto="$t('tabla.ajustarBusqueda')"
          data-prueba="sin-coincidencias-retencion"
        >
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            @click="quitarFiltros"
          >
            {{ $t("tabla.restablecer") }}
          </button>
        </EstadoVacio>
        <EstadoVacio
          v-else-if="visibles.length === 0"
          class="tu-card mt-6"
          icono="pulso"
          :titulo="
            $t(
              sesion.esCitas
                ? 'operacion.renovaciones.vacioCitas'
                : 'operacion.renovaciones.vacio',
            )
          "
        />
        <div
          v-else
          class="mt-4 tu-card relative overflow-x-auto tu-tabla-adaptable"
        >
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>
                  {{ $t("retencion.colAlumno") }}
                </th>
                <th>
                  {{ $t("retencion.colVence") }}
                </th>
                <th class="hidden sm:table-cell">
                  {{ columnaActividad }}
                </th>
                <th class="text-right"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in visibles" :key="m.id">
                <td :data-etiqueta="$t('retencion.colAlumno')">
                  <RouterLink
                    :to="{ name: 'ficha-miembro', params: { id: m.id } }"
                    class="font-semibold tu-enlace"
                    >{{ m.nombre_completo }}</RouterLink
                  >
                  <span v-if="m.email" class="tu-sub">{{ m.email }}</span>
                </td>
                <!-- Punto y texto (por vencer en aviso, vencida en error) y debajo la
                     fecha y cuánto falta o pasó, como las demás fechas de cobro. -->
                <td :data-etiqueta="$t('retencion.colVence')">
                  <span class="inline-flex flex-col">
                    <span
                      class="tu-estado"
                      :style="{
                        '--tono':
                          m.estado === 'vencida'
                            ? 'var(--error)'
                            : 'var(--aviso)',
                      }"
                      data-prueba="estado-retencion"
                      >{{ $t(`retencion.estados.${m.estado}`) }}</span
                    >
                    <span
                      class="text-xs"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{ fechaCorta(m.vence) }} · {{ venceTexto(m) }}</span
                    >
                  </span>
                </td>
                <td
                  class="hidden sm:table-cell"
                  :data-etiqueta="columnaActividad"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ asistTexto(m) }}
                </td>
                <td class="tu-celda-acciones">
                  <!-- Contactar deja registro: su seguimiento (una tarea donde se
                       anota con qué terminó), no solo un enlace de correo. -->
                  <template v-if="seguimientos[m.id]">
                    <RouterLink
                      :to="{
                        name: 'tareas',
                        query: { tipo: 'seguimiento', q: m.nombre_completo },
                      }"
                      class="tu-enlace mr-3"
                      :data-prueba="`seguimiento-de-${m.id}`"
                      >{{
                        $t(`retencion.seguimiento.${seguimientos[m.id]}`)
                      }}</RouterLink
                    >
                  </template>
                  <button
                    v-else-if="puedeSeguir"
                    type="button"
                    class="tu-btn tu-btn-secundario tu-btn-chico mr-3"
                    :disabled="dandoSeguimiento === m.id"
                    :aria-busy="dandoSeguimiento === m.id || undefined"
                    :data-prueba="`dar-seguimiento-${m.id}`"
                    @click="darSeguimiento(m)"
                  >
                    {{ $t("retencion.seguimiento.dar") }}
                  </button>
                  <RouterLink
                    :to="{ name: 'ficha-miembro', params: { id: m.id } }"
                    class="tu-enlace"
                    >{{ $t("retencion.verFicha") }}</RouterLink
                  >
                </td>
              </tr>
            </tbody>
          </table>
          <PaginacionListado
            v-if="meta && meta.ultima_pagina > 1"
            :page="meta.page"
            :ultima-pagina="meta.ultima_pagina"
            :total="meta.total"
            :per-page="meta.per_page"
            @ir="irPagina"
          />
        </div>
      </template>
    </template>
  </section>
</template>
