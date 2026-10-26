<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import {
  filtrarSeries,
  horaFin,
  nombreDia,
  SIN_INSTRUCTOR,
  semanaDe,
  type SerieProgramada,
  sigueVigente,
} from "@/lib/programacion";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Las clases que se repiten, como una semana de lunes a domingo filtrable por
 * actividad, instructor y sede. Tocar una abre su detalle (y dejar de repetirla).
 * Las que ya no generan fechas quedan aparte, plegadas y por páginas: se juntan con
 * cada cambio de horario, así que se piden de 10 en 10 al API (`estado=terminadas`)
 * con los mismos filtros.
 */
const props = defineProps<{
  // Las que siguen vigentes (la semana tipo).
  series: SerieProgramada[];
  puedeEliminar: boolean;
  base: string;
}>();
const emit = defineEmits<{
  dejar: [serie: SerieProgramada];
  // Cambió su fin o se cancelaron fechas: se vuelve a cargar.
  cambiada: [];
}>();
const { t } = useI18n();

// «Hoy» del negocio (su zona horaria), no del navegador de quien mira.
const sesion = useSesionTenantStore();
const hoy = hoyEnNegocio(sesion.zonaHoraria);
const filtro = ref({ actividad: "", instructor: "", sucursal: "" });
const detalle = ref<SerieProgramada | null>(null);
const toast = useToastStore();
const puedeGestionar = computed(() => sesion.puede("agenda.gestionar"));

// Hasta cuándo se repite y cancelar desde una fecha (ADR 0223).
const fin = ref("");
const sinFin = ref(true);
const cancelarDesde = ref(hoy);
const guardandoSerie = ref(false);
watch(detalle, (s) => {
  fin.value = s?.vigente_hasta ?? "";
  sinFin.value = s?.vigente_hasta === null;
  cancelarDesde.value = hoy;
});

async function guardarFin(): Promise<void> {
  const s = detalle.value;
  if (s === null || guardandoSerie.value || (!sinFin.value && fin.value === ""))
    return;
  guardandoSerie.value = true;
  try {
    const { data } = await api.put<{ data: { vigente_hasta: string | null } }>(
      `${props.base}/plantillas-horario/${s.id}/fin`,
      { vigente_hasta: sinFin.value ? null : fin.value },
    );
    const hasta = data.data.vigente_hasta;
    toast.exito(
      t(
        "reglasAgenda.detalle.finGuardado",
        { fecha: hasta !== null ? fecha(hasta) : "" },
        hasta !== null ? 1 : 2,
      ),
    );
    detalle.value = null;
    emit("cambiada");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardandoSerie.value = false;
  }
}

async function cancelarFechas(): Promise<void> {
  const s = detalle.value;
  if (s === null || guardandoSerie.value || cancelarDesde.value === "") return;
  guardandoSerie.value = true;
  try {
    const url = `${props.base}/plantillas-horario/${s.id}/terminar`;
    const { data: previa } = await api.post<{
      data: { sesiones: number; reservas: number };
    }>(url, { desde: cancelarDesde.value, previsualizar: true });
    if (previa.data.sesiones === 0) {
      toast.info(t("reglasAgenda.detalle.sinFechasPorCancelar"));
      return;
    }
    if (
      !(await confirmar(
        t("reglasAgenda.detalle.confirmarCancelarFechas", {
          sesiones: previa.data.sesiones,
          reservas: previa.data.reservas,
          fecha: fecha(cancelarDesde.value),
        }),
        { peligro: true, aceptar: t("reglasAgenda.detalle.cancelarFechas") },
      ))
    ) {
      return;
    }
    const { data } = await api.post<{ data: { canceladas: number } }>(url, {
      desde: cancelarDesde.value,
    });
    toast.exito(
      t(
        "reglasAgenda.detalle.fechasCanceladas",
        { n: data.data.canceladas },
        data.data.canceladas,
      ),
    );
    detalle.value = null;
    emit("cambiada");
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardandoSerie.value = false;
  }
}

function unicos(pares: [string | null, string | null][]): {
  id: string;
  nombre: string;
}[] {
  const mapa = new Map<string, string>();
  for (const [id, nombre] of pares) {
    if (id !== null) {
      mapa.set(id, nombre ?? "—");
    }
  }
  return [...mapa]
    .map(([id, nombre]) => ({ id, nombre }))
    .sort((a, b) => a.nombre.localeCompare(b.nombre));
}

const actividades = computed(() =>
  unicos(props.series.map((s) => [s.actividad_id, s.actividad])),
);
const instructores = computed(() =>
  unicos(props.series.map((s) => [s.instructor_id, s.instructor])),
);
const haySinInstructor = computed(() =>
  props.series.some((s) => s.instructor_id === null),
);
const sucursales = computed(() =>
  unicos(props.series.map((s) => [s.sucursal, s.sucursal])),
);

const filtrando = computed(
  () =>
    filtro.value.actividad !== "" ||
    filtro.value.instructor !== "" ||
    filtro.value.sucursal !== "",
);
const filtradas = computed(() => filtrarSeries(props.series, filtro.value));
const vigentes = computed(() =>
  filtradas.value.filter((s) => sigueVigente(s, hoy)),
);

// Las que ya no se repiten: por páginas, de la que terminó más reciente a la más vieja.
const POR_PAGINA = 10;
const terminadas = ref<SerieProgramada[]>([]);
const totalTerminadas = ref(0);
const cargandoTerminadas = ref(false);
let turno = 0;
async function cargarTerminadas(pagina = 1): Promise<void> {
  const mio = ++turno;
  cargandoTerminadas.value = true;
  const f = filtro.value;
  try {
    const { data } = await api.get<{
      data: SerieProgramada[];
      meta: { total: number };
    }>(`${props.base}/plantillas-horario`, {
      params: {
        estado: "terminadas",
        page: pagina,
        per_page: POR_PAGINA,
        ...(f.actividad ? { actividad_id: f.actividad } : {}),
        ...(f.instructor ? { instructor_id: f.instructor } : {}),
        ...(f.sucursal ? { sucursal: f.sucursal } : {}),
      },
    });
    if (mio !== turno) return;
    terminadas.value =
      pagina === 1 ? data.data : [...terminadas.value, ...data.data];
    totalTerminadas.value = data.meta.total;
  } catch {
    // Sin las viejas, la semana sigue a la vista; se reintenta al cambiar algo.
  } finally {
    if (mio === turno) cargandoTerminadas.value = false;
  }
}
function masTerminadas(): void {
  void cargarTerminadas(Math.floor(terminadas.value.length / POR_PAGINA) + 1);
}
// Al entrar, al filtrar y cuando cambian las vigentes (p. ej. dejó de repetir una).
watch([filtro, () => props.series], () => void cargarTerminadas(1), {
  deep: true,
  immediate: true,
});
const semana = computed(() => semanaDe(vigentes.value));
const clasesPorSemana = computed(() =>
  vigentes.value.reduce((n, s) => n + s.dias_semana.length, 0),
);

function limpiar(): void {
  filtro.value = { actividad: "", instructor: "", sucursal: "" };
}

function fecha(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(`${iso}T12:00:00`));
}

function dias(s: SerieProgramada): string {
  return [...s.dias_semana]
    .sort((a, b) => a - b)
    .map((d) => nombreDia(d))
    .join(", ");
}

function vigencia(s: SerieProgramada): string {
  return [
    t("reglasAgenda.desde", { fecha: fecha(s.vigente_desde) }),
    s.vigente_hasta
      ? t("reglasAgenda.hasta", { fecha: fecha(s.vigente_hasta) })
      : null,
  ]
    .filter(Boolean)
    .join(" ");
}

function dejar(s: SerieProgramada): void {
  detalle.value = null;
  emit("dejar", s);
}
</script>

<template>
  <!-- Sin título propio: la pestaña «Programación recurrente» ya la nombra. -->
  <div class="mt-5 tu-card p-5">
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("reglasAgenda.seriesAyuda") }}
    </p>

    <p
      v-if="series.length === 0"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("reglasAgenda.sinSeries") }}
    </p>

    <template v-else>
      <div class="mt-4 flex flex-wrap items-center gap-2">
        <select
          v-model="filtro.actividad"
          class="tu-input w-auto"
          data-prueba="filtro-actividad"
          :aria-label="$t('reglasAgenda.filtroActividad')"
        >
          <option value="">{{ $t("reglasAgenda.todasActividades") }}</option>
          <option v-for="a in actividades" :key="a.id" :value="a.id">
            {{ a.nombre }}
          </option>
        </select>
        <select
          v-model="filtro.instructor"
          class="tu-input w-auto"
          data-prueba="filtro-instructor"
          :aria-label="$t('reglasAgenda.filtroInstructor')"
        >
          <option value="">{{ $t("reglasAgenda.todosInstructores") }}</option>
          <option v-for="i in instructores" :key="i.id" :value="i.id">
            {{ i.nombre }}
          </option>
          <option v-if="haySinInstructor" :value="SIN_INSTRUCTOR">
            {{ $t("reglasAgenda.sinInstructor") }}
          </option>
        </select>
        <select
          v-if="sucursales.length > 1"
          v-model="filtro.sucursal"
          class="tu-input w-auto"
          :aria-label="$t('reglasAgenda.filtroSucursal')"
        >
          <option value="">{{ $t("reglasAgenda.todasSucursales") }}</option>
          <option v-for="s in sucursales" :key="s.id" :value="s.id">
            {{ s.nombre }}
          </option>
        </select>
        <button
          v-if="filtrando"
          type="button"
          class="tu-btn tu-btn-discreto tu-btn-chico"
          data-prueba="quitar-filtros"
          @click="limpiar"
        >
          {{ $t("reglasAgenda.quitarFiltros") }}
        </button>
        <p
          class="text-sm sm:ml-auto"
          data-prueba="clases-semana"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{
            $t(
              "reglasAgenda.clasesPorSemana",
              { n: clasesPorSemana },
              clasesPorSemana,
            )
          }}
        </p>
      </div>

      <p
        v-if="vigentes.length === 0"
        class="mt-4 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("reglasAgenda.sinSeriesFiltro") }}
      </p>
      <div v-else class="ps-semana mt-4" data-prueba="semana">
        <section
          v-for="d in semana"
          :key="d.dia"
          class="ps-dia"
          :class="{ 'ps-dia-vacio': d.clases.length === 0 }"
          :data-dia="d.dia"
        >
          <h3 class="ps-dia-titulo">
            <span class="first-letter:uppercase">{{ nombreDia(d.dia) }}</span>
            <span :style="{ color: 'var(--texto-suave)' }">{{
              d.clases.length
            }}</span>
          </h3>
          <ul class="ps-lista">
            <li v-for="s in d.clases" :key="s.id">
              <button type="button" class="ps-clase" @click="detalle = s">
                <span class="ps-hora">{{ s.hora_local }}</span>
                <span class="ps-nombre">{{ s.oferta ?? "—" }}</span>
                <span class="ps-quien">{{
                  s.instructor ?? $t("reglasAgenda.sinInstructor")
                }}</span>
              </button>
            </li>
          </ul>
        </section>
      </div>

      <details v-if="totalTerminadas > 0" class="mt-5" data-prueba="terminadas">
        <summary
          class="cursor-pointer text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("reglasAgenda.yaNoSeRepiten", { n: totalTerminadas }) }}
        </summary>
        <ul class="mt-2">
          <li v-for="s in terminadas" :key="s.id" class="ps-fila text-sm">
            <button
              type="button"
              class="min-w-0 text-left"
              @click="detalle = s"
            >
              <p class="truncate">{{ s.oferta ?? "—" }} · {{ s.hora_local }}</p>
              <p
                class="mt-0.5 truncate text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ vigencia(s) }}
              </p>
            </button>
          </li>
        </ul>
        <button
          v-if="terminadas.length < totalTerminadas"
          type="button"
          class="tu-enlace mt-2 text-sm"
          :disabled="cargandoTerminadas"
          data-prueba="mas-terminadas"
          @click="masTerminadas"
        >
          {{
            $t(
              "reglasAgenda.verMasTerminadas",
              { n: totalTerminadas - terminadas.length },
              totalTerminadas - terminadas.length,
            )
          }}
        </button>
      </details>
    </template>

    <PanelLateral
      :abierto="detalle !== null"
      :titulo="detalle?.oferta ?? ''"
      @cerrar="detalle = null"
    >
      <dl v-if="detalle" class="ps-detalle text-sm">
        <dt>{{ $t("reglasAgenda.detalle.actividad") }}</dt>
        <dd>{{ detalle.actividad ?? "—" }}</dd>
        <dt>{{ $t("reglasAgenda.detalle.dias") }}</dt>
        <dd class="first-letter:uppercase">{{ dias(detalle) }}</dd>
        <dt>{{ $t("reglasAgenda.detalle.horario") }}</dt>
        <dd>
          {{ detalle.hora_local }}–{{
            horaFin(detalle.hora_local, detalle.duracion_minutos)
          }}
          ·
          {{
            $t("reglasAgenda.detalle.minutos", { n: detalle.duracion_minutos })
          }}
        </dd>
        <dt>{{ $t("reglasAgenda.detalle.instructor") }}</dt>
        <dd>{{ detalle.instructor ?? $t("reglasAgenda.sinInstructor") }}</dd>
        <dt>{{ $t("reglasAgenda.detalle.sucursal") }}</dt>
        <dd>{{ detalle.sucursal ?? "—" }}</dd>
        <template v-if="detalle.capacidad !== null">
          <dt>{{ $t("reglasAgenda.detalle.lugares") }}</dt>
          <dd>{{ detalle.capacidad }}</dd>
        </template>
        <dt>{{ $t("reglasAgenda.detalle.vigencia") }}</dt>
        <dd class="first-letter:uppercase">{{ vigencia(detalle) }}</dd>
      </dl>
      <template v-if="detalle && puedeGestionar">
        <!-- Hasta cuándo se repite: solo se alarga (ADR 0223). -->
        <fieldset class="mt-5 grid gap-2" data-prueba="fin-serie">
          <legend class="tu-label">{{ $t("reglasAgenda.detalle.fin") }}</legend>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="sinFin" type="checkbox" />
            {{ $t("reglasAgenda.detalle.sinFin") }}
          </label>
          <input
            v-if="!sinFin"
            v-model="fin"
            type="date"
            class="tu-input w-auto"
            :min="hoy"
            :aria-label="$t('reglasAgenda.detalle.fin')"
          />
          <span class="tu-hint">{{ $t("reglasAgenda.detalle.finAyuda") }}</span>
          <div>
            <button
              type="button"
              class="tu-btn tu-btn-secundario tu-btn-chico"
              :disabled="guardandoSerie || (!sinFin && fin === '')"
              data-prueba="guardar-fin"
              @click="guardarFin"
            >
              {{ $t("reglasAgenda.detalle.guardarFin") }}
            </button>
          </div>
        </fieldset>
        <!-- Cancelar esta y las siguientes, con cuántas personas afecta. -->
        <div class="mt-5 grid gap-2" data-prueba="cancelar-desde">
          <label class="tu-label" for="ps-cancelar-desde">{{
            $t("reglasAgenda.detalle.cancelarDesde")
          }}</label>
          <input
            id="ps-cancelar-desde"
            v-model="cancelarDesde"
            type="date"
            class="tu-input w-auto"
            :min="hoy"
          />
          <div>
            <button
              type="button"
              class="tu-btn tu-btn-peligro tu-btn-chico"
              :disabled="guardandoSerie || cancelarDesde === ''"
              data-prueba="cancelar-fechas"
              @click="cancelarFechas"
            >
              {{ $t("reglasAgenda.detalle.cancelarFechas") }}
            </button>
          </div>
        </div>
      </template>
      <p class="mt-5 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("reglasAgenda.detalle.cambiar") }}
      </p>
      <template v-if="puedeEliminar && detalle" #pie>
        <button
          type="button"
          class="tu-btn tu-btn-peligro"
          @click="dejar(detalle)"
        >
          {{ $t("reglasAgenda.dejarDeRepetir") }}
        </button>
      </template>
    </PanelLateral>
  </div>
</template>

<style scoped>
.ps-semana {
  display: grid;
  gap: 1rem;
}
.ps-dia-titulo {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  padding-bottom: 0.375rem;
  border-bottom: 1px solid var(--borde);
  font-size: 0.8125rem;
  font-weight: 500;
}
.ps-lista {
  display: grid;
  gap: 0.375rem;
  margin-top: 0.5rem;
}
.ps-clase {
  display: grid;
  grid-template-columns: 3.25rem minmax(0, 1fr);
  column-gap: 0.5rem;
  width: 100%;
  padding: 0.5rem 0.625rem;
  border: 1px solid var(--borde);
  border-radius: 0.5rem;
  background: var(--superficie);
  text-align: left;
  font-size: 0.8125rem;
}
.ps-clase:hover {
  background: var(--fondo);
}
.ps-hora {
  grid-row: span 2;
  color: var(--texto-suave);
  font-variant-numeric: tabular-nums;
}
.ps-nombre,
.ps-quien {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ps-nombre {
  font-weight: 500;
}
.ps-quien {
  color: var(--texto-suave);
  font-size: 0.75rem;
}
/* En pantallas angostas, solo los días con clases. */
@media (max-width: 1023px) {
  .ps-dia-vacio {
    display: none;
  }
}
/* En escritorio, siete columnas de lunes a domingo. */
@media (min-width: 1024px) {
  .ps-semana {
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 0.5rem;
  }
  .ps-clase {
    grid-template-columns: minmax(0, 1fr);
    padding: 0.5rem;
  }
  .ps-hora {
    grid-row: auto;
    font-size: 0.75rem;
  }
}
.ps-fila {
  display: flex;
  padding: 0.625rem 0;
  border-top: 1px solid var(--borde);
}
.ps-fila:first-child {
  border-top: 0;
}
.ps-detalle {
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr);
  gap: 0.625rem 1.5rem;
}
.ps-detalle dt {
  color: var(--texto-suave);
}
</style>
