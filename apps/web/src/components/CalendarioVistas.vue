<script setup lang="ts">
import { computed, ref, watch } from "vue";

import AgendaMes from "@/components/AgendaMes.vue";
import IconoNav from "@/components/IconoNav.vue";
import type { SesionAgenda } from "@/lib/agenda";
import { hoyEnNegocio } from "@/lib/hoyNegocio";

/**
 * Calendario personal en lista, día, semana o mes (portal del alumno y del
 * instructor). Recuerda la vista, se mueve por periodos y avisa el rango visible
 * (`rango`) para que quien lo usa cargue esas fechas. La lista la pone quien lo
 * usa (slot `lista`); tocar un evento emite `abrir`.
 */
export interface EventoPeriodo {
  id: string;
  titulo: string;
  inicia: string;
  termina: string | null;
  zona: string;
  /** Segunda línea: sede, con quién, cupo… */
  detalle?: string | null;
  /** Texto corto a la derecha (estado, lugares). */
  estado?: string | null;
  /** Cómo se pinta ese texto: lo propio en primario, lo que pide atención en ámbar. */
  tono?: "primario" | "aviso" | "suave";
  /** Lo propio (sus reservas, sus clases): resaltado en semana y mes. */
  destacado?: boolean;
}
export type Vista = "lista" | "dia" | "semana" | "mes";
const VISTAS: Vista[] = ["lista", "dia", "semana", "mes"];

const props = defineProps<{
  eventos: EventoPeriodo[];
  /** Clave para recordar la vista en este navegador. */
  clave: string;
  cargando?: boolean;
  /**
   * Si falló cargar el periodo: se muestra el aviso con "Reintentar" en lugar de
   * los días (un error nunca se ve como un periodo vacío).
   */
  error?: string | null;
  /** Vista con la que abre (p. ej. desde un acceso directo); si no, la recordada. */
  vistaInicial?: Vista | null;
  /** Cuántos días ve la lista desde hoy (30 si no se dice). */
  diasLista?: number;
  /** Tarjetas de trabajo con hora, nombre y contexto en la vista semanal. */
  detallado?: boolean;
  /** Zona horaria del negocio: define qué día es «hoy» (sin ella, el navegador). */
  zona?: string;
}>();
const emit = defineEmits<{
  abrir: [id: string];
  rango: [rango: { desde: string; hasta: string; vista: Vista }];
  reintentar: [];
}>();

function vistaGuardada(): Vista {
  try {
    const v = localStorage.getItem(props.clave);
    return VISTAS.includes(v as Vista) ? (v as Vista) : "lista";
  } catch {
    return "lista";
  }
}
const vista = ref<Vista>(
  props.vistaInicial && VISTAS.includes(props.vistaInicial)
    ? props.vistaInicial
    : vistaGuardada(),
);
watch(vista, (v) => {
  try {
    localStorage.setItem(props.clave, v);
  } catch {
    // Sin almacenamiento: solo no se recuerda.
  }
});

const hoy = (): Date => {
  if (props.zona) {
    const [a, m, d] = hoyEnNegocio(props.zona).split("-").map(Number);
    return new Date(a, m - 1, d);
  }
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), d.getDate());
};
const fecha = ref(hoy());

function isoDe(d: Date): string {
  const p = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
function fechaLocal(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: zona,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date(iso));
}
function hora(iso: string, zona: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}
function lunesDe(d: Date): Date {
  const r = new Date(d);
  r.setDate(r.getDate() - ((r.getDay() + 6) % 7));
  return r;
}
function mas(d: Date, dias: number): Date {
  const r = new Date(d);
  r.setDate(r.getDate() + dias);
  return r;
}

const ordenados = computed(() =>
  [...props.eventos].sort((a, b) => a.inicia.localeCompare(b.inicia)),
);
function delDia(iso: string): EventoPeriodo[] {
  return ordenados.value.filter((e) => fechaLocal(e.inicia, e.zona) === iso);
}
const semana = computed(() => {
  const lunes = lunesDe(fecha.value);
  return Array.from({ length: 7 }, (_, i) => {
    const d = mas(lunes, i);
    return {
      iso: isoDe(d),
      nombre: new Intl.DateTimeFormat("es-MX", {
        weekday: "short",
        day: "numeric",
      }).format(d),
      esHoy: isoDe(d) === isoDe(hoy()),
      eventos: delDia(isoDe(d)),
    };
  });
});
const semanaVacia = computed(() =>
  semana.value.every((d) => d.eventos.length === 0),
);
const mes = computed(
  () => new Date(fecha.value.getFullYear(), fecha.value.getMonth(), 1),
);
// La vista mensual es la misma de la agenda del negocio.
const comoSesiones = computed<SesionAgenda[]>(() =>
  ordenados.value.map(
    (e) =>
      ({
        id: e.id,
        tipo: "clase",
        oferta: e.titulo,
        oferta_id: e.titulo,
        oferta_precio_clase: null,
        instructor: null,
        instructor_id: null,
        sala: null,
        inicia_en: e.inicia,
        termina_en: e.termina ?? e.inicia,
        zona_horaria: e.zona,
        capacidad: null,
        ocupados: 0,
        en_espera: 0,
        estado: "programada",
      }) as unknown as SesionAgenda,
  ),
);
const destacadas = computed(
  () => new Set(props.eventos.filter((e) => e.destacado).map((e) => e.id)),
);

const etiquetaFecha = computed(() => {
  const f = (o: Intl.DateTimeFormatOptions, d: Date) =>
    new Intl.DateTimeFormat("es-MX", o).format(d);
  if (vista.value === "dia") {
    return f({ weekday: "long", day: "numeric", month: "long" }, fecha.value);
  }
  if (vista.value === "semana") {
    const a = lunesDe(fecha.value);
    return `${f({ day: "numeric", month: "short" }, a)} – ${f({ day: "numeric", month: "short" }, mas(a, 6))}`;
  }
  return f({ month: "long", year: "numeric" }, fecha.value);
});
function mover(delta: number): void {
  const d = new Date(fecha.value);
  if (vista.value === "dia") {
    d.setDate(d.getDate() + delta);
  } else if (vista.value === "semana") {
    d.setDate(d.getDate() + delta * 7);
  } else {
    d.setDate(1);
    d.setMonth(d.getMonth() + delta);
  }
  fecha.value = d;
}
function irFecha(iso: string): void {
  const [a, m, d] = iso.split("-").map(Number);
  fecha.value = new Date(a, m - 1, d);
}
function irDia(iso: string): void {
  irFecha(iso);
  vista.value = "dia";
}
// Si el día o la semana que se ve está vacío: la siguiente fecha con algo.
const siguienteConAlgo = computed<string | null>(() => {
  const desde =
    vista.value === "semana"
      ? mas(lunesDe(fecha.value), 7)
      : mas(fecha.value, 1);
  const limite = isoDe(desde);
  const fechas = props.eventos
    .map((e) => fechaLocal(e.inicia, e.zona))
    .filter((iso) => iso >= limite)
    .sort();
  return fechas[0] ?? null;
});

// Rango visible (fechas locales, fin incluido): la lista ve los próximos días
// (`diasLista`, 30 si no se dice); el mes, sus semanas completas.
const rango = computed(() => {
  if (vista.value === "dia") {
    return { desde: isoDe(fecha.value), hasta: isoDe(fecha.value) };
  }
  if (vista.value === "semana") {
    const a = lunesDe(fecha.value);
    return { desde: isoDe(a), hasta: isoDe(mas(a, 6)) };
  }
  if (vista.value === "mes") {
    const ultimo = new Date(
      mes.value.getFullYear(),
      mes.value.getMonth() + 1,
      0,
    );
    return {
      desde: isoDe(lunesDe(mes.value)),
      hasta: isoDe(mas(lunesDe(ultimo), 6)),
    };
  }
  return {
    desde: isoDe(hoy()),
    hasta: isoDe(mas(hoy(), (props.diasLista ?? 30) - 1)),
  };
});
watch(
  rango,
  (r, anterior) => {
    if (r.desde !== anterior?.desde || r.hasta !== anterior?.hasta) {
      emit("rango", { ...r, vista: vista.value });
    }
  },
  { immediate: true },
);

const color = (e: EventoPeriodo) =>
  e.tono === "primario"
    ? "var(--primario)"
    : e.tono === "aviso"
      ? "var(--aviso)"
      : "var(--texto-suave)";

defineExpose({ irDia });
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div v-if="vista !== 'lista'" class="flex items-center gap-2">
        <button
          type="button"
          class="tu-icono-btn"
          :aria-label="$t('portal.periodo.anterior')"
          @click="mover(-1)"
        >
          <IconoNav nombre="chevron" :tam="18" class="rotate-180" />
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma px-3 py-1.5"
          @click="fecha = hoy()"
        >
          {{ $t("portal.periodo.hoy") }}
        </button>
        <button
          type="button"
          class="tu-icono-btn"
          :aria-label="$t('portal.periodo.siguiente')"
          @click="mover(1)"
        >
          <IconoNav nombre="chevron" :tam="18" />
        </button>
        <span
          class="ml-1 text-sm font-medium first-letter:uppercase"
          :style="{ color: 'var(--texto-suave)' }"
          >{{ etiquetaFecha }}</span
        >
      </div>
      <div v-else><slot name="barra" /></div>
      <div class="tu-segmentado" role="group">
        <button
          v-for="v in VISTAS"
          :key="v"
          type="button"
          :aria-pressed="vista === v"
          @click="vista = v"
        >
          {{ $t(`portal.periodo.vistas.${v}`) }}
        </button>
      </div>
    </div>

    <div v-if="$slots.filtros" class="mt-4"><slot name="filtros" /></div>

    <p v-if="cargando" class="mt-6" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

    <div v-else-if="error" class="mt-6 tu-card p-5 text-sm" role="alert">
      <p style="color: var(--error)">{{ error }}</p>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma mt-3"
        @click="emit('reintentar')"
      >
        {{ $t("comun.reintentar") }}
      </button>
    </div>

    <template v-else>
      <!-- LISTA: la pone quien usa el calendario -->
      <div v-if="vista === 'lista'" class="mt-4">
        <slot name="lista" />
      </div>

      <!-- DÍA -->
      <div v-else-if="vista === 'dia'" class="mt-4 tu-card p-5">
        <ul
          v-if="delDia(isoDe(fecha)).length > 0"
          class="divide-y divide-[var(--borde)]"
        >
          <li v-for="e in delDia(isoDe(fecha))" :key="e.id">
            <button type="button" class="cv-fila" @click="emit('abrir', e.id)">
              <span class="cv-hora">{{ hora(e.inicia, e.zona) }}</span>
              <span class="min-w-0 flex-1">
                <span class="block truncate font-medium">{{ e.titulo }}</span>
                <span
                  v-if="e.detalle"
                  class="block truncate text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ e.detalle }}</span
                >
              </span>
              <span
                v-if="e.estado"
                class="shrink-0 text-xs"
                :style="{ color: color(e) }"
                >{{ e.estado }}</span
              >
            </button>
          </li>
        </ul>
        <p v-else class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("portal.periodo.sinNada") }}
          <button
            v-if="siguienteConAlgo"
            type="button"
            class="tu-enlace ml-1"
            @click="irFecha(siguienteConAlgo)"
          >
            {{ $t("portal.periodo.irSiguiente") }}
          </button>
        </p>
      </div>

      <!-- SEMANA -->
      <template v-else-if="vista === 'semana'">
        <p
          v-if="semanaVacia"
          class="mt-4 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("portal.periodo.sinNada") }}
          <button
            v-if="siguienteConAlgo"
            type="button"
            class="tu-enlace ml-1"
            @click="irFecha(siguienteConAlgo)"
          >
            {{ $t("portal.periodo.irSiguiente") }}
          </button>
        </p>
        <div
          class="mt-4 grid gap-3 md:grid-cols-7"
          :class="{ 'cv-semana-detallada': detallado }"
        >
          <div
            v-for="d in semana"
            :key="d.iso"
            class="tu-card min-h-[8rem] p-3"
          >
            <button
              type="button"
              class="text-sm font-semibold capitalize"
              :style="{ color: d.esHoy ? 'var(--primario)' : 'var(--texto)' }"
              @click="irDia(d.iso)"
            >
              {{ d.nombre }}
            </button>
            <ul class="mt-2 space-y-1">
              <li v-for="e in d.eventos" :key="e.id">
                <button
                  type="button"
                  class="cv-chip"
                  :class="{
                    'cv-chip-propio': e.destacado,
                    'cv-chip-detallado': detallado,
                  }"
                  :title="[e.titulo, e.detalle].filter(Boolean).join(' · ')"
                  @click="emit('abrir', e.id)"
                >
                  <span class="cv-hora">{{ hora(e.inicia, e.zona) }}</span>
                  <span class="truncate">{{ e.titulo }}</span>
                  <span v-if="detallado && e.detalle" class="cv-contexto">{{
                    e.detalle
                  }}</span>
                  <span
                    v-if="detallado && e.estado"
                    :style="{ color: color(e) }"
                    >{{ e.estado }}</span
                  >
                </button>
              </li>
            </ul>
          </div>
        </div>
      </template>

      <!-- MES -->
      <AgendaMes
        v-else
        class="mt-4"
        :mes="mes"
        :sesiones="comoSesiones"
        :catalogo="[]"
        :destacadas="destacadas"
        :hoy="isoDe(hoy())"
        @abrir="(s) => emit('abrir', s.id)"
        @dia="irDia"
      />
    </template>
  </div>
</template>

<style scoped>
.cv-fila {
  display: flex;
  width: 100%;
  align-items: center;
  gap: 0.75rem;
  padding: 0.7rem 0;
  text-align: left;
}
.cv-fila:hover .font-medium {
  color: var(--primario);
}
.cv-hora {
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
  color: var(--texto-suave);
  font-size: 0.85rem;
}
.cv-chip {
  display: flex;
  width: 100%;
  gap: 0.35rem;
  min-width: 0;
  padding: 0.2rem 0.4rem;
  border-radius: 0.35rem;
  font-size: 0.75rem;
  text-align: left;
  background: var(--superficie-2);
}
.cv-chip .cv-hora {
  font-size: 0.75rem;
}
.cv-chip-propio {
  background: color-mix(in srgb, var(--primario) 14%, var(--superficie));
  box-shadow: inset 3px 0 0 var(--primario);
  font-weight: 600;
}
.cv-chip-detallado {
  flex-direction: column;
  padding: 0.7rem 0.6rem;
  gap: 0.3rem;
  min-height: 90px;
}
.cv-chip-detallado .truncate {
  white-space: normal;
  overflow-wrap: anywhere;
}
.cv-contexto {
  font-weight: 400;
  color: var(--texto-suave);
  font-size: 0.7rem;
}
@media (min-width: 768px) and (max-width: 1199px) {
  .cv-semana-detallada {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
