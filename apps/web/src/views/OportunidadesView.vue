<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const { t } = useI18n();

interface Oportunidad {
  id: string;
  oferta: string | null;
  actividad: string | null;
  sucursal: string | null;
  instructor: string | null;
  inicia_en: string;
  zona_horaria: string | null;
  capacidad: number;
  ocupados: number;
  libres: number;
  en_espera: number;
  ocupacion_pct: number | null;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedePromover = computed(() => sesion.puede("reservas.gestionar"));

const dias = ref(14);
const oportunidades = ref<Oportunidad[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const aviso = ref<string | null>(null);
const promoviendo = ref<string | null>(null);
const busqueda = ref("");
const sucursal = ref("");
const instructor = ref("");
const soloEspera = ref(false);
const pagina = ref(1);
const porPagina = 20;
const sucursales = computed(() =>
  [
    ...new Set(
      oportunidades.value
        .map((o) => o.sucursal)
        .filter((n): n is string => !!n),
    ),
  ].sort(),
);
const instructores = computed(() =>
  [
    ...new Set(
      oportunidades.value
        .map((o) => o.instructor)
        .filter((n): n is string => !!n),
    ),
  ].sort(),
);
const filtradas = computed(() => {
  const q = busqueda.value.trim().toLocaleLowerCase("es-MX");
  return oportunidades.value.filter(
    (o) =>
      (!sucursal.value || o.sucursal === sucursal.value) &&
      (!instructor.value || o.instructor === instructor.value) &&
      (!soloEspera.value || o.en_espera > 0) &&
      (!q ||
        `${o.oferta ?? ""} ${o.actividad ?? ""} ${o.sucursal ?? ""} ${o.instructor ?? ""}`
          .toLocaleLowerCase("es-MX")
          .includes(q)),
  );
});
const ultimaPagina = computed(() =>
  Math.max(1, Math.ceil(filtradas.value.length / porPagina)),
);
const visibles = computed(() =>
  filtradas.value.slice(
    (pagina.value - 1) * porPagina,
    pagina.value * porPagina,
  ),
);
let solicitud = 0;

function fechaHora(iso: string, zona: string | null): string {
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: zona ?? undefined,
  }).format(new Date(iso));
}

async function cargar(): Promise<void> {
  const actual = ++solicitud;
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Oportunidad[] }>(
      `${base.value}/sesiones/oportunidades`,
      {
        params: { dias: dias.value },
      },
    );
    if (actual !== solicitud) return;
    oportunidades.value = data.data;
    pagina.value = Math.min(pagina.value, ultimaPagina.value);
  } catch (e) {
    if (actual === solicitud) error.value = mensajeDeError(e);
  } finally {
    if (actual === solicitud) cargando.value = false;
  }
}

async function promover(o: Oportunidad): Promise<void> {
  if (
    !(await confirmar(t("confirmaciones.promover"), {
      aceptar: t("confirmaciones.promoverAceptar"),
    }))
  ) {
    return;
  }
  promoviendo.value = o.id;
  aviso.value = null;
  error.value = null;
  try {
    const { data } = await api.post<{ data: { ofrecidas: number } }>(
      `${base.value}/sesiones/${o.id}/promover`,
      {},
    );
    const n = data.data.ofrecidas;
    aviso.value =
      n > 0
        ? t("oportunidades.ofrecidas", { n })
        : t("oportunidades.sinPromover");
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    promoviendo.value = null;
  }
}

// Indicadores del horizonte elegido (patrón de los listados).
const indicadores = computed<Indicador[]>(() => {
  const lista = filtradas.value;
  const conPct = lista.filter((o) => o.ocupacion_pct !== null);
  return [
    {
      clave: "clases",
      etiqueta: t("oportunidadesVisual.kpi.clases"),
      valor: String(lista.length),
      icono: "agenda",
    },
    {
      clave: "libres",
      etiqueta: t("oportunidadesVisual.kpi.libres"),
      valor: String(lista.reduce((s, o) => s + o.libres, 0)),
      icono: "personas",
    },
    {
      clave: "espera",
      etiqueta: t("oportunidadesVisual.kpi.espera"),
      valor: String(lista.reduce((s, o) => s + o.en_espera, 0)),
      icono: "reloj",
      aviso: lista.some((o) => o.en_espera > 0),
    },
    {
      clave: "ocupacion",
      etiqueta: t("oportunidadesVisual.kpi.ocupacion"),
      valor:
        conPct.length > 0
          ? `${Math.round(conPct.reduce((s, o) => s + (o.ocupacion_pct ?? 0), 0) / conPct.length)}%`
          : "—",
      icono: "reportes",
    },
  ];
});

watch(dias, cargar);
watch([busqueda, sucursal, instructor, soloEspera], () => {
  pagina.value = 1;
});
onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('oportunidades.titulo')"
      :subtitulo="$t('oportunidadesVisual.subtitulo')"
    />

    <p v-if="aviso" class="mt-4 text-sm" style="color: var(--exito)">
      {{ aviso }}
    </p>
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
      <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

      <div class="relative tu-card mt-5 overflow-x-auto">
        <div class="tu-filtros">
          <input
            v-model="busqueda"
            class="tu-input"
            type="search"
            placeholder="Buscar clase, sede o instructor"
            aria-label="Buscar lugares disponibles"
          />
          <select
            v-if="sucursales.length > 1"
            v-model="sucursal"
            class="tu-input"
            aria-label="Filtrar por sucursal"
          >
            <option value="">{{ $t("sucursalOperativa.todas") }}</option>
            <option v-for="s in sucursales" :key="s" :value="s">{{ s }}</option>
          </select>
          <select
            v-if="instructores.length > 1"
            v-model="instructor"
            class="tu-input"
            aria-label="Filtrar por instructor"
          >
            <option value="">Todos los instructores</option>
            <option v-for="i in instructores" :key="i" :value="i">
              {{ i }}
            </option>
          </select>
          <label class="flex items-center gap-2 text-sm"
            ><input v-model="soloEspera" type="checkbox" />Con lista de
            espera</label
          >
          <span class="text-sm" :style="{ color: 'var(--texto-suave)' }">{{
            $t("oportunidades.horizonte")
          }}</span>
          <div class="tu-segmentado" role="group">
            <button
              v-for="n in [7, 14, 30]"
              :key="n"
              type="button"
              :aria-pressed="dias === n"
              :data-prueba="`dias-${n}`"
              @click="dias = n"
            >
              {{ $t("oportunidades.dias", { n }) }}
            </button>
          </div>
        </div>
        <p v-if="filtradas.length === 0" class="tu-sin-resultados">
          {{ $t("oportunidades.vacio") }}
        </p>
        <table v-else class="tu-tabla">
          <thead>
            <tr>
              <th>{{ $t("oportunidadesVisual.col.clase") }}</th>
              <th class="hidden sm:table-cell">
                {{ $t("oportunidadesVisual.col.ocupacion") }}
              </th>
              <th class="text-right">
                {{ $t("oportunidadesVisual.col.libres") }}
              </th>
              <th class="text-right hidden md:table-cell">
                {{ $t("oportunidadesVisual.col.espera") }}
              </th>
              <th>
                <span class="sr-only">{{
                  $t("oportunidadesVisual.col.acciones")
                }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in visibles" :key="o.id" data-prueba="oportunidad">
              <td>
                <span class="font-medium">{{
                  o.oferta ?? o.actividad ?? "—"
                }}</span>
                <span class="tu-sub"
                  >{{ fechaHora(o.inicia_en, o.zona_horaria)
                  }}<template v-if="o.sucursal"> · {{ o.sucursal }}</template
                  ><template v-if="o.instructor">
                    · {{ o.instructor }}</template
                  ></span
                >
              </td>
              <td class="hidden sm:table-cell">
                <div class="op-ocupacion">
                  <span class="op-barra" aria-hidden="true"
                    ><span
                      :style="{ width: `${o.ocupacion_pct ?? 0}%` }" /></span
                  ><span class="tabular-nums"
                    >{{ o.ocupados }}/{{ o.capacidad }}</span
                  >
                </div>
              </td>
              <td class="text-right tabular-nums font-medium">
                {{ o.libres }}
              </td>
              <td class="text-right hidden md:table-cell tabular-nums">
                <span
                  v-if="o.en_espera > 0"
                  class="tu-pildora"
                  :style="{ '--tono': 'var(--aviso)' }"
                  >{{ o.en_espera }}</span
                >
                <template v-else>—</template>
              </td>
              <td class="text-right whitespace-nowrap">
                <button
                  v-if="puedePromover && o.en_espera > 0"
                  type="button"
                  class="tu-enlace text-sm"
                  :disabled="promoviendo === o.id"
                  @click="promover(o)"
                >
                  {{
                    promoviendo === o.id
                      ? $t("oportunidades.promoviendo")
                      : $t("oportunidades.promover")
                  }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <PaginacionListado
          :page="pagina"
          :ultima-pagina="ultimaPagina"
          :total="filtradas.length"
          :per-page="porPagina"
          @ir="pagina = $event"
        />
      </div>
    </template>
  </section>
</template>

<style scoped>
.op-ocupacion {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}
.op-barra {
  width: 5rem;
  height: 0.35rem;
  overflow: hidden;
  border-radius: 999px;
  background: var(--superficie-2);
}
.op-barra > span {
  display: block;
  height: 100%;
  background: var(--texto-suave);
}
</style>
