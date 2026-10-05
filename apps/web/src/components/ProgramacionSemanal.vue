<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import PanelLateral from "@/components/PanelLateral.vue";
import { isoLocal } from "@/lib/misClases";
import {
  filtrarSeries,
  horaFin,
  nombreDia,
  SIN_INSTRUCTOR,
  semanaDe,
  type SerieProgramada,
  sigueVigente,
} from "@/lib/programacion";

/**
 * Las clases que se repiten, como una semana de lunes a domingo filtrable por
 * actividad, instructor y sede. Tocar una abre su detalle (y dejar de repetirla).
 * Las que ya no generan fechas quedan aparte, plegadas.
 */
const props = defineProps<{
  series: SerieProgramada[];
  puedeEliminar: boolean;
}>();
const emit = defineEmits<{ dejar: [serie: SerieProgramada] }>();
const { t } = useI18n();

const hoy = isoLocal(new Date());
const filtro = ref({ actividad: "", instructor: "", sucursal: "" });
const detalle = ref<SerieProgramada | null>(null);

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
const terminadas = computed(() =>
  filtradas.value.filter((s) => !sigueVigente(s, hoy)),
);
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
  <div class="mt-5 tu-card p-5">
    <h2 class="font-medium">{{ $t("reglasAgenda.series") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
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
          class="tu-enlace text-sm"
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

      <details v-if="terminadas.length > 0" class="mt-5">
        <summary
          class="cursor-pointer text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("reglasAgenda.yaNoSeRepiten", { n: terminadas.length }) }}
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
      <p class="mt-5 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("reglasAgenda.detalle.cambiar") }}
      </p>
      <template v-if="puedeEliminar && detalle" #pie>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma text-sm"
          style="color: var(--error)"
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
