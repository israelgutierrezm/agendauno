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

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import {
  aHora,
  aMinutos,
  carriles,
  COLOR_ESTADO_CITA,
  diaIso,
  duracionMin,
  estadoCita,
  fechaLocal,
  fueraDeHorario,
  minutosLocal,
  pagoCita,
  tonoServicio,
  type BloqueoAgenda,
  type EstadoCita,
  type PagoCita,
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
  // Comida, vacaciones o cierre (2.2): se sombrean como "fuera de horario".
  bloqueos?: BloqueoAgenda[];
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
  pago: PagoCita | null; // aparte de la atención (2.6)
  tenue: boolean;
  fondo: string;
  tinta: string;
  // Alto (px) de la preparación antes y de la limpieza después (2.3).
  antes: number;
  despues: number;
}

interface Columna {
  id: string | null;
  nombre: string;
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
      pago: esCita ? pagoCita(s) : null,
      tenue:
        s.estado !== "programada" ||
        estado === "completada" ||
        estado === "no_asistio",
      fondo: tono.fondo,
      tinta: tono.tinta,
      antes: margenPx(s.ocupa_desde, s.inicia_en),
      despues: margenPx(s.termina_en, s.ocupa_hasta),
    };
  });
}

// Alto a escala de un margen (preparación o limpieza) entre dos instantes.
function margenPx(
  desde: string | null | undefined,
  hasta: string | null | undefined,
): number {
  if (!desde || !hasta) {
    return 0;
  }
  const min = (new Date(hasta).getTime() - new Date(desde).getTime()) / 60000;
  return min > 0 ? (min / 60) * PX_HORA : 0;
}

// Tramos del día bloqueados para esa persona (suyos o de toda la sede), a escala.
function bloqueosDe(
  instructorId: string,
): { top: number; alto: number; etiqueta: string }[] {
  return (props.bloqueos ?? [])
    .filter(
      (b) =>
        (b.ambito === "profesional" && b.instructor_id === instructorId) ||
        b.ambito === "sede",
    )
    .flatMap((b) => {
      const diaIni = fechaLocal(b.desde, props.zona);
      const diaFin = fechaLocal(b.hasta, props.zona);
      if (diaIni > props.fecha || diaFin < props.fecha) {
        return [];
      }
      const ini = Math.max(
        rango.value.ini,
        diaIni < props.fecha ? 0 : minutosLocal(b.desde, props.zona),
      );
      const fin = Math.min(
        rango.value.fin,
        diaFin > props.fecha ? 24 * 60 : minutosLocal(b.hasta, props.zona),
      );
      return fin > ini
        ? [{ top: y(ini), alto: y(fin) - y(ini), etiqueta: b.motivo }]
        : [];
    });
}

const columnas = computed<Columna[]>(() => {
  const cols: Columna[] = props.profesionales.map((p) => {
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
      tarjetas: tarjetasDe(propias),
      fuera: [
        ...fuera.map((f) => ({
          top: y(f.ini),
          alto: y(f.fin) - y(f.ini),
          etiqueta:
            ventanas.length === 0 && !sinVentanasConfiguradas
              ? t("agendaVisual.profesionales.noTrabaja")
              : t("agendaVisual.profesionales.fueraHorario"),
        })),
        ...bloqueosDe(p.id),
      ],
      resumen: t("agendaVisual.profesionales.citasN", { n: citas }, citas),
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
      tarjetas: tarjetasDe(sinAsignar),
      fuera: [],
      resumen: t(
        "agendaVisual.profesionales.citasN",
        { n: sinAsignar.length },
        sinAsignar.length,
      ),
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
          <AvatarIniciales :nombre="c.nombre" tam="md" />
          <span class="min-w-0 flex-1">
            <span class="block font-semibold text-sm truncate">{{
              c.nombre
            }}</span>
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
              <span :style="{ width: `${c.ocupacion}%` }"></span>
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

          <!-- Preparación y limpieza: ocupan la agenda, no son parte de la cita. -->
          <template v-for="tj in c.tarjetas" :key="`m-${tj.sesion.id}`">
            <div
              v-if="tj.antes > 0"
              class="ag-margen"
              :style="{
                top: `${tj.top - tj.antes}px`,
                height: `${tj.antes - 1}px`,
                left: `calc(${tj.izq}% + 4px)`,
                width: `calc(${tj.ancho}% - 8px)`,
              }"
              :title="$t('margenesServicio.enAgenda')"
              aria-hidden="true"
            ></div>
            <div
              v-if="tj.despues > 0"
              class="ag-margen"
              :style="{
                top: `${tj.top + tj.alto + 1}px`,
                height: `${tj.despues - 1}px`,
                left: `calc(${tj.izq}% + 4px)`,
                width: `calc(${tj.ancho}% - 8px)`,
              }"
              :title="$t('margenesServicio.enAgenda')"
              aria-hidden="true"
            ></div>
          </template>

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
                  :style="{ background: COLOR_ESTADO_CITA[tj.estado] }"
                ></span>
              </span>
            </template>
            <template v-else>
              <span class="ag-linea1">
                <span class="truncate flex-1 font-semibold">{{
                  tj.titulo
                }}</span>
                <span
                  v-if="tj.estado && tj.alto < 72"
                  class="ag-punto"
                  :style="{ background: COLOR_ESTADO_CITA[tj.estado] }"
                ></span>
              </span>
              <span class="ag-linea2 truncate"
                >{{ tj.hora }} · {{ tj.servicio }}</span
              >
              <span v-if="tj.estado && tj.alto >= 72" class="ag-estado">
                <span
                  class="ag-punto"
                  :style="{ background: COLOR_ESTADO_CITA[tj.estado] }"
                ></span>
                {{ $t(`agendaVisual.estadosCita.${tj.estado}`) }}
                <!-- El pago, aparte y solo si falta: "Por cobrar". -->
                <template
                  v-if="tj.pago === 'por_cobrar' || tj.pago === 'por_pagar'"
                >
                  ·
                  <span style="color: var(--aviso)">{{
                    $t(`agendaOperacion.pago.${tj.pago}`)
                  }}</span></template
                >
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
  background: var(--primario);
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
  font-weight: 500;
  color: var(--texto-suave);
  font-variant-numeric: tabular-nums;
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
  font-weight: 600;
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
  font-weight: 500;
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
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}
/* Como en la demo de la landing: tinte muy suave del servicio y su color en el
   borde izquierdo; el texto en el color normal. */
.ag-tarjeta {
  position: absolute;
  z-index: 3;
  box-sizing: border-box;
  border: none;
  border-left: 3px solid var(--tt);
  border-radius: 0.55rem;
  padding: 0.4rem 0.55rem;
  background: color-mix(in srgb, var(--tt) 11%, var(--superficie));
  color: var(--texto);
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  overflow: hidden;
  cursor: pointer;
  transition: filter 0.15s ease;
}
.ag-tarjeta:hover {
  filter: brightness(0.97);
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
  box-shadow: 0 0 0 2px var(--acento);
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
  font-weight: 600;
  line-height: 1.2;
}
.ag-linea2 {
  font-size: 0.7rem;
  color: var(--texto-suave);
}
.ag-punto {
  width: 7px;
  height: 7px;
  border-radius: 999px;
  flex-shrink: 0;
}
.ag-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  margin-top: 0.1rem;
  font-size: 0.68rem;
  font-weight: 500;
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
  background: color-mix(in srgb, var(--tf) 18%, var(--superficie));
  border-left-color: var(--tf);
}
/* Preparación / limpieza del servicio: tramo tenue junto a la cita. */
.ag-margen {
  position: absolute;
  border-radius: 0.3rem;
  background: repeating-linear-gradient(
    135deg,
    transparent 0 4px,
    var(--borde) 4px 5px
  );
  pointer-events: none;
}
</style>
