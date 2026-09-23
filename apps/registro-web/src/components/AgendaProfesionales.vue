<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from "vue";
import { useI18n } from "vue-i18n";

import {
  aHora,
  aMinutos,
  carriles,
  colorProfesional,
  diaIso,
  duracionMin,
  estadoCita,
  fechaLocal,
  fueraDeHorario,
  iniciales,
  minutosLocal,
  tonoServicio,
  type EstadoCita,
  type SesionAgenda,
  type VentanaAtencion,
} from "@/lib/agenda";

/**
 * Agenda de un día en COLUMNAS por profesional (negocios de citas): cada columna es
 * un barbero/profesional con su horario de atención; las citas se dibujan a escala
 * con el cliente, el servicio (color) y su estado. Muestra la línea de "ahora" y,
 * al pasar el cursor por un hueco libre, un fantasma para agendar ahí.
 */
const props = defineProps<{
  fecha: string; // YYYY-MM-DD (día local del estudio)
  zona: string;
  sesiones: SesionAgenda[];
  profesionales: { id: string; nombre: string }[];
  ventanas: VentanaAtencion[];
  catalogo: string[]; // ids de oferta en orden de catálogo (color estable)
  seleccionada: string | null;
  puedeCrear: boolean;
}>();

const emit = defineEmits<{
  abrir: [sesion: SesionAgenda];
  crear: [datos: { instructorId: string | null; hora: string }];
}>();

const { t } = useI18n();

const PX_HORA = 76;
const PASO = 15;

const ahora = ref(new Date());
let reloj: number | undefined;
onMounted(() => {
  reloj = window.setInterval(() => {
    ahora.value = new Date();
  }, 60_000);
});
onBeforeUnmount(() => window.clearInterval(reloj));

const delDia = computed(() =>
  props.sesiones.filter(
    (s) => fechaLocal(s.inicia_en, s.zona_horaria) === props.fecha,
  ),
);

const ventanasDelDia = computed(() => {
  const dia = diaIso(props.fecha);
  return props.ventanas.filter((v) => v.dia_semana === dia);
});

// Rango visible: 08–20 h por defecto, ampliado a las ventanas y citas del día.
const rango = computed(() => {
  let ini = 8 * 60;
  let fin = 20 * 60;
  for (const v of ventanasDelDia.value) {
    ini = Math.min(ini, Math.floor(aMinutos(v.hora_inicio) / 60) * 60);
    fin = Math.max(fin, Math.ceil(aMinutos(v.hora_fin) / 60) * 60);
  }
  for (const s of delDia.value) {
    const desde = minutosLocal(s.inicia_en, s.zona_horaria);
    ini = Math.min(ini, Math.floor(desde / 60) * 60);
    fin = Math.max(fin, Math.ceil((desde + duracionMin(s)) / 60) * 60);
  }
  return { ini, fin: Math.min(fin, 24 * 60) };
});

const alto = computed(
  () => ((rango.value.fin - rango.value.ini) / 60) * PX_HORA,
);
const y = (min: number): number => ((min - rango.value.ini) / 60) * PX_HORA;

const horas = computed(() => {
  const out: { min: number; etiqueta: string }[] = [];
  for (let m = rango.value.ini; m < rango.value.fin; m += 60) {
    out.push({ min: m, etiqueta: aHora(m) });
  }
  return out;
});

const ESTADO_ESTILO: Record<
  EstadoCita,
  { fondo: string; tinta: string; icono: string }
> = {
  confirmada: {
    fondo: "#E3F5EB",
    tinta: "#0F6B3E",
    icono: "M5 12.5l4.2 4.2L19 7",
  },
  pendiente_pago: {
    fondo: "#FFF1CC",
    tinta: "#7A5200",
    icono:
      "M12 3v18M16.5 7.5c0-1.9-2-3-4.5-3s-4.5 1.2-4.5 3.2c0 4.3 9 2.3 9 6.6 0 2-2 3.2-4.5 3.2S7.5 18.3 7.5 16.5",
  },
  llego: {
    fondo: "#E3EDFF",
    tinta: "#0B4FD1",
    icono: "M14 4h5v16h-5M3 12h11M10 8l4 4-4 4",
  },
  en_servicio: {
    fondo: "#EDE7FF",
    tinta: "#5B21B6",
    icono: "M12 7v5l3 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z",
  },
  completada: {
    fondo: "#EEF0F4",
    tinta: "#475063",
    icono: "M2 12.5l4 4L15 7M9 16.5l1 1L22 7",
  },
  no_asistio: {
    fondo: "#FDE6E6",
    tinta: "#A11B1B",
    icono: "M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9 9l6 6M15 9l-6 6",
  },
  cancelada: {
    fondo: "#EEF0F4",
    tinta: "#667085",
    icono: "M6 6l12 12M18 6L6 18",
  },
};

interface Tarjeta {
  sesion: SesionAgenda;
  top: number;
  alto: number;
  izq: number;
  ancho: number;
  hora: string;
  titulo: string;
  servicio: string;
  estado: EstadoCita | null; // null = clase grupal
  tenue: boolean;
  fondo: string;
  tinta: string;
}

interface Columna {
  id: string | null;
  nombre: string;
  color: string;
  iniciales: string;
  tarjetas: Tarjeta[];
  fuera: { top: number; alto: number; etiqueta: string }[];
  resumen: string;
  ocupacion: number | null;
}

function tarjetasDe(sesiones: SesionAgenda[]): Tarjeta[] {
  const items = sesiones.map((s) => {
    const ini = minutosLocal(s.inicia_en, s.zona_horaria);
    return { s, ini, fin: ini + Math.max(PASO, duracionMin(s)) };
  });
  const lanes = carriles(items.map(({ ini, fin }) => ({ ini, fin })));
  return items.map(({ s, ini, fin }, i) => {
    const esCita = s.tipo === "cita";
    const estado = esCita ? estadoCita(s, ahora.value) : null;
    const tono = tonoServicio(s.oferta_id, props.catalogo, s.oferta);
    const { carril, total } = lanes[i];
    return {
      sesion: s,
      top: y(ini) + 1,
      alto: Math.max(20, ((fin - ini) / 60) * PX_HORA - 3),
      izq: (carril / total) * 100,
      ancho: 100 / total,
      hora: `${aHora(ini)}–${aHora(fin)}`,
      titulo: esCita
        ? (s.cita?.cliente ?? t("agendaVisual.profesionales.sinCliente"))
        : (s.oferta ?? "—"),
      servicio: esCita
        ? (s.oferta ?? "—")
        : t("agendaVisual.profesionales.lugares", {
            ocupados: s.ocupados,
            capacidad: s.capacidad ?? "∞",
          }),
      estado,
      tenue:
        s.estado !== "programada" ||
        estado === "completada" ||
        estado === "no_asistio",
      fondo: tono.fondo,
      tinta: tono.tinta,
    };
  });
}

const columnas = computed<Columna[]>(() => {
  const cols: Columna[] = props.profesionales.map((p, i) => {
    const propias = delDia.value.filter((s) => s.instructor_id === p.id);
    const ventanas = ventanasDelDia.value
      .filter((v) => v.instructor_id === p.id)
      .map((v) => ({
        ini: aMinutos(v.hora_inicio),
        fin: aMinutos(v.hora_fin),
      }));
    const sinVentanasConfiguradas = !props.ventanas.some(
      (v) => v.instructor_id === p.id,
    );
    // Sin horario configurado → no se sombrea (horario desconocido). Con horario pero
    // sin ventanas este día → no atiende.
    const fuera = sinVentanasConfiguradas
      ? []
      : ventanas.length === 0
        ? [{ ini: rango.value.ini, fin: rango.value.fin }]
        : fueraDeHorario(ventanas, rango.value.ini, rango.value.fin);
    const minutosAtencion = ventanas.reduce((a, v) => a + (v.fin - v.ini), 0);
    const minutosOcupados = propias
      .filter((s) => s.estado === "programada")
      .reduce((a, s) => a + duracionMin(s), 0);
    const citas = propias.filter((s) => s.estado === "programada").length;
    return {
      id: p.id,
      nombre: p.nombre,
      color: colorProfesional(i),
      iniciales: iniciales(p.nombre),
      tarjetas: tarjetasDe(propias),
      fuera: fuera.map((f) => ({
        top: y(f.ini),
        alto: y(f.fin) - y(f.ini),
        etiqueta:
          ventanas.length === 0 && !sinVentanasConfiguradas
            ? t("agendaVisual.profesionales.noTrabaja")
            : t("agendaVisual.profesionales.fueraHorario"),
      })),
      resumen: t("agendaVisual.profesionales.citasN", { n: citas }),
      ocupacion:
        minutosAtencion > 0
          ? Math.min(100, Math.round((minutosOcupados / minutosAtencion) * 100))
          : null,
    };
  });

  const sinAsignar = delDia.value.filter(
    (s) =>
      s.instructor_id === null ||
      !props.profesionales.some((p) => p.id === s.instructor_id),
  );
  if (sinAsignar.length > 0) {
    cols.push({
      id: null,
      nombre: t("agendaVisual.profesionales.sinAsignar"),
      color: "#667085",
      iniciales: "?",
      tarjetas: tarjetasDe(sinAsignar),
      fuera: [],
      resumen: t("agendaVisual.profesionales.citasN", {
        n: sinAsignar.length,
      }),
      ocupacion: null,
    });
  }
  return cols;
});

// Línea de "ahora" (solo si el día mostrado es hoy en la zona del estudio).
const lineaAhora = computed<number | null>(() => {
  const iso = ahora.value.toISOString();
  if (fechaLocal(iso, props.zona) !== props.fecha) {
    return null;
  }
  const min = minutosLocal(iso, props.zona);
  return min >= rango.value.ini && min <= rango.value.fin ? y(min) : null;
});
const horaAhora = computed(() =>
  aHora(minutosLocal(ahora.value.toISOString(), props.zona)),
);

// Fantasma de "agendar aquí" al pasar el cursor por un hueco de la columna.
const fantasma = ref<{ col: number; min: number } | null>(null);
function minutoEn(e: MouseEvent): number {
  const caja = (e.currentTarget as HTMLElement).getBoundingClientRect();
  const min = rango.value.ini + ((e.clientY - caja.top) / PX_HORA) * 60;
  return Math.max(
    rango.value.ini,
    Math.min(rango.value.fin - PASO, Math.floor(min / PASO) * PASO),
  );
}
function mover(e: MouseEvent, col: number): void {
  if (!props.puedeCrear || (e.target as HTMLElement).closest("button")) {
    fantasma.value = null;
    return;
  }
  fantasma.value = { col, min: minutoEn(e) };
}
function clicColumna(e: MouseEvent, columna: Columna): void {
  if (!props.puedeCrear || (e.target as HTMLElement).closest("button")) {
    return;
  }
  emit("crear", { instructorId: columna.id, hora: aHora(minutoEn(e)) });
}

// Al abrir (o al cambiar de día) se desplaza hasta la hora actual o la primera cita.
const cuerpo = ref<HTMLElement | null>(null);
function enfocar(): void {
  void nextTick(() => {
    if (cuerpo.value === null) {
      return;
    }
    const primera = Math.min(
      ...columnas.value.flatMap((c) => c.tarjetas.map((x) => x.top)),
    );
    const destino =
      lineaAhora.value ?? (Number.isFinite(primera) ? primera : 0);
    cuerpo.value.scrollTop = Math.max(0, destino - 120);
  });
}
onMounted(enfocar);
watch(() => props.fecha, enfocar);
</script>

<template>
  <div class="ag-prof tu-card overflow-hidden">
    <p
      v-if="columnas.length === 0"
      class="p-8 text-sm text-center"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("agendaVisual.profesionales.sinProfesionales") }}
    </p>

    <div v-else ref="cuerpo" class="ag-scroll">
      <div
        class="ag-rejilla"
        :style="{
          gridTemplateColumns: `3.5rem repeat(${columnas.length}, minmax(11rem, 1fr))`,
        }"
      >
        <!-- Encabezado: un profesional por columna -->
        <div class="ag-esquina" aria-hidden="true"></div>
        <div
          v-for="c in columnas"
          :key="`h-${c.id ?? 'x'}`"
          class="ag-cabecera"
        >
          <span
            class="ag-avatar"
            :style="{
              background: c.color,
              boxShadow: `0 0 0 3px var(--superficie), 0 0 0 4px ${c.color}55`,
            }"
            aria-hidden="true"
            >{{ c.iniciales }}</span
          >
          <span class="min-w-0 flex-1">
            <span class="block font-bold text-sm truncate">{{ c.nombre }}</span>
            <span class="block text-xs" :style="{ color: 'var(--texto-suave)' }"
              >{{ c.resumen
              }}<template v-if="c.ocupacion !== null">
                ·
                {{
                  $t("agendaVisual.profesionales.ocupadoPct", {
                    n: c.ocupacion,
                  })
                }}</template
              ></span
            >
            <span
              v-if="c.ocupacion !== null"
              class="ag-barra"
              aria-hidden="true"
            >
              <span
                :style="{ width: `${c.ocupacion}%`, background: c.color }"
              ></span>
            </span>
          </span>
        </div>

        <!-- Eje de horas -->
        <div class="ag-eje" :style="{ height: `${alto}px` }" aria-hidden="true">
          <span
            v-for="h in horas"
            :key="h.min"
            class="ag-hora"
            :style="{ top: `${y(h.min)}px` }"
            >{{ h.etiqueta }}</span
          >
          <span
            v-if="lineaAhora !== null"
            class="ag-ahora-pill"
            :style="{ top: `${lineaAhora - 9}px` }"
            >{{ horaAhora }}</span
          >
        </div>

        <!-- Columnas -->
        <div
          v-for="(c, ci) in columnas"
          :key="`c-${c.id ?? 'x'}`"
          class="ag-columna"
          :class="{ 'ag-creable': puedeCrear }"
          :style="{ height: `${alto}px` }"
          @mousemove="mover($event, ci)"
          @mouseleave="fantasma = null"
          @click="clicColumna($event, c)"
        >
          <div
            v-for="h in horas"
            :key="`l-${h.min}`"
            class="ag-linea"
            :style="{ top: `${y(h.min)}px` }"
            aria-hidden="true"
          ></div>
          <div
            v-for="h in horas"
            :key="`m-${h.min}`"
            class="ag-linea ag-linea-media"
            :style="{ top: `${y(h.min + 30)}px` }"
            aria-hidden="true"
          ></div>
          <div
            v-for="(f, fi) in c.fuera"
            :key="`f-${fi}`"
            class="ag-fuera"
            :style="{ top: `${f.top}px`, height: `${f.alto}px` }"
          >
            <span v-if="f.alto >= 36">{{ f.etiqueta }}</span>
          </div>

          <div
            v-if="fantasma !== null && fantasma.col === ci"
            class="ag-fantasma"
            :style="{
              top: `${y(fantasma.min) + 1}px`,
              height: `${(30 / 60) * PX_HORA - 3}px`,
            }"
            aria-hidden="true"
          >
            + {{ aHora(fantasma.min) }}
          </div>

          <button
            v-for="tj in c.tarjetas"
            :key="tj.sesion.id"
            type="button"
            class="ag-tarjeta"
            :class="{
              'ag-sel': tj.sesion.id === seleccionada,
              'ag-tenue': tj.tenue,
              'ag-chica': tj.alto < 44,
            }"
            :style="{
              top: `${tj.top}px`,
              height: `${tj.alto}px`,
              left: `calc(${tj.izq}% + 4px)`,
              width: `calc(${tj.ancho}% - 8px)`,
              '--tf': tj.fondo,
              '--tt': tj.tinta,
            }"
            :aria-label="`${tj.hora}, ${tj.titulo}, ${tj.servicio}${tj.estado ? ', ' + $t(`agendaVisual.estadosCita.${tj.estado}`) : ''}`"
            @click="emit('abrir', tj.sesion)"
          >
            <template v-if="tj.alto < 44">
              <span class="ag-linea1">
                <span class="truncate flex-1"
                  >{{ tj.hora.slice(0, 5) }} · {{ tj.titulo }}</span
                >
                <span
                  v-if="tj.estado"
                  class="ag-punto"
                  :style="{ background: ESTADO_ESTILO[tj.estado].tinta }"
                ></span>
              </span>
            </template>
            <template v-else>
              <span class="ag-linea1">
                <span class="truncate flex-1 font-extrabold">{{
                  tj.titulo
                }}</span>
                <span
                  v-if="tj.estado && tj.alto < 72"
                  class="ag-icono-estado"
                  :style="{
                    background: ESTADO_ESTILO[tj.estado].fondo,
                    color: ESTADO_ESTILO[tj.estado].tinta,
                  }"
                >
                  <svg
                    width="10"
                    height="10"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="3"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                  >
                    <path :d="ESTADO_ESTILO[tj.estado].icono" />
                  </svg>
                </span>
              </span>
              <span class="ag-linea2 truncate"
                >{{ tj.hora }} · {{ tj.servicio }}</span
              >
              <span
                v-if="tj.estado && tj.alto >= 72"
                class="ag-chip"
                :style="{
                  background: ESTADO_ESTILO[tj.estado].fondo,
                  color: ESTADO_ESTILO[tj.estado].tinta,
                }"
              >
                <svg
                  width="10"
                  height="10"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="3"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  aria-hidden="true"
                >
                  <path :d="ESTADO_ESTILO[tj.estado].icono" />
                </svg>
                {{ $t(`agendaVisual.estadosCita.${tj.estado}`) }}
              </span>
            </template>
          </button>
        </div>

        <!-- Línea de "ahora" sobre todas las columnas -->
        <div
          v-if="lineaAhora !== null"
          class="ag-ahora"
          :style="{
            top: `${lineaAhora}px`,
            gridColumn: `2 / span ${columnas.length}`,
          }"
          :aria-label="$t('agendaVisual.profesionales.ahora')"
        ></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ag-scroll {
  max-height: calc(100vh - 17rem);
  min-height: 24rem;
  overflow: auto;
}
.ag-rejilla {
  display: grid;
  grid-template-rows: auto 1fr;
  position: relative;
  min-width: fit-content;
}
.ag-esquina,
.ag-cabecera {
  position: sticky;
  top: 0;
  z-index: 6;
  background: var(--superficie);
  border-bottom: 1px solid var(--borde);
}
.ag-cabecera {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.7rem 0.75rem;
  border-left: 1px solid var(--borde);
}
.ag-avatar {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 999px;
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 0.8rem;
  flex-shrink: 0;
}
.ag-barra {
  display: block;
  height: 4px;
  margin-top: 0.3rem;
  border-radius: 999px;
  background: var(--superficie-2);
  overflow: hidden;
}
.ag-barra > span {
  display: block;
  height: 100%;
  border-radius: 999px;
}
.ag-eje {
  position: relative;
  grid-row: 2;
}
.ag-hora {
  position: absolute;
  right: 0.5rem;
  transform: translateY(-50%);
  font-size: 0.68rem;
  font-weight: 700;
  color: var(--texto-suave);
}
.ag-hora:first-child {
  transform: translateY(0.2rem);
}
.ag-ahora-pill {
  position: absolute;
  left: 0.25rem;
  height: 18px;
  padding: 0 0.3rem;
  border-radius: 6px;
  background: #e5484d;
  color: #fff;
  font-size: 0.66rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  z-index: 5;
}
.ag-columna {
  position: relative;
  grid-row: 2;
  border-left: 1px solid var(--borde);
}
.ag-creable {
  cursor: copy;
}
.ag-linea {
  position: absolute;
  left: 0;
  right: 0;
  border-top: 1px solid var(--borde);
  pointer-events: none;
}
.ag-linea-media {
  border-top-style: dashed;
  opacity: 0.55;
}
.ag-fuera {
  position: absolute;
  left: 0;
  right: 0;
  z-index: 1;
  background-image: repeating-linear-gradient(
    135deg,
    color-mix(in srgb, var(--superficie-2) 90%, transparent) 0 6px,
    transparent 6px 12px
  );
  display: flex;
  justify-content: center;
  padding-top: 0.5rem;
  font-size: 0.7rem;
  font-weight: 700;
  color: var(--texto-suave);
  pointer-events: none;
}
.ag-fantasma {
  position: absolute;
  left: 4px;
  right: 4px;
  z-index: 2;
  border: 1.5px dashed var(--acento);
  border-radius: 10px;
  background: color-mix(in srgb, var(--acento) 8%, transparent);
  color: var(--acento);
  font-size: 0.75rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}
.ag-tarjeta {
  position: absolute;
  z-index: 3;
  box-sizing: border-box;
  border: none;
  border-radius: 10px;
  padding: 0.4rem 0.55rem;
  background: var(--tf);
  color: var(--tt);
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  overflow: hidden;
  cursor: pointer;
  box-shadow: 0 1px 2px rgb(16 24 40 / 8%);
  transition:
    transform 0.1s ease,
    box-shadow 0.15s ease;
}
.ag-tarjeta:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 14px rgb(16 24 40 / 14%);
  z-index: 4;
}
.ag-tarjeta:focus-visible {
  outline: 2px solid var(--acento);
  outline-offset: 2px;
}
.ag-chica {
  padding: 0.15rem 0.5rem;
  justify-content: center;
}
.ag-sel {
  box-shadow:
    0 0 0 2px var(--acento),
    0 8px 18px color-mix(in srgb, var(--acento) 28%, transparent);
  z-index: 4;
}
.ag-tenue {
  opacity: 0.55;
}
.ag-linea1 {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
  font-size: 0.8rem;
  font-weight: 700;
  line-height: 1.2;
}
.ag-linea2 {
  font-size: 0.7rem;
  font-weight: 600;
  opacity: 0.85;
}
.ag-punto {
  width: 7px;
  height: 7px;
  border-radius: 999px;
  flex-shrink: 0;
}
.ag-icono-estado {
  width: 18px;
  height: 18px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ag-chip {
  align-self: flex-start;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  margin-top: 0.15rem;
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  font-size: 0.68rem;
  font-weight: 800;
}
/* Posicionada en el área de la fila 2 (las columnas): top relativo a esa área. */
.ag-ahora {
  position: absolute;
  grid-row: 2;
  left: 0;
  right: 0;
  height: 2px;
  background: #e5484d;
  z-index: 5;
  pointer-events: none;
}
.ag-ahora::before {
  content: "";
  position: absolute;
  left: -5px;
  top: -4px;
  width: 10px;
  height: 10px;
  border-radius: 999px;
  background: #e5484d;
}
:global(.dark) .ag-tarjeta {
  background: color-mix(in srgb, var(--tf) 20%, var(--superficie));
  color: var(--tf);
}
</style>
