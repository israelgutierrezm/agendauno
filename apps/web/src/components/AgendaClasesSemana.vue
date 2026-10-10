<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";

import {
  duracionMin,
  fechaLocal,
  minutosLocal,
  pctCupo,
  tonoServicio,
  aHora,
  type SesionAgenda,
} from "@/lib/agenda";

/**
 * Semana de CLASES en una cuadrícula por hora (07:00–21:00, o más si hay clases
 * fuera): cada clase ocupa su horario con el color de su tipo, su hora, quién la
 * imparte y su cupo; las que se enciman van lado a lado. Un aviso en texto solo si
 * hace falta (llena, en espera…) y la línea de «ahora» en el día de hoy.
 */
const props = defineProps<{
  dias: { iso: string; nombre: string; dia: number; esHoy: boolean }[];
  sesiones: SesionAgenda[];
  catalogo: string[];
  seleccionada: string | null;
}>();

const emit = defineEmits<{ abrir: [sesion: SesionAgenda] }>();

const { t } = useI18n();

const ahora = ref(new Date());
let reloj: number | undefined;
onMounted(() => {
  reloj = window.setInterval(() => {
    ahora.value = new Date();
  }, 60_000);
});
onBeforeUnmount(() => window.clearInterval(reloj));

// Alto de una hora en la cuadrícula.
const PX_HORA = 72;

interface Tarjeta {
  sesion: SesionAgenda;
  inicio: number;
  fin: number;
  hora: string;
  nombre: string;
  detalle: string;
  cupo: string;
  pct: number;
  // Aviso en texto; `urgente` (en curso / cancelada) va en rojo, el resto en la
  // tinta de la tarjeta.
  aviso: { texto: string; urgente: boolean } | null;
  pasada: boolean;
  enCurso: boolean;
  fondo: string;
  tinta: string;
}

function tarjeta(s: SesionAgenda): Tarjeta {
  const tono = tonoServicio(s.oferta_id, props.catalogo, s.oferta);
  const ini = new Date(s.inicia_en).getTime();
  const fin = new Date(s.termina_en).getTime();
  const t0 = ahora.value.getTime();
  const cancelada = s.estado !== "programada";
  const pasada = fin <= t0;
  const enCurso = !cancelada && ini <= t0 && t0 < fin;
  const pct = pctCupo(s) ?? 0;
  const libres =
    s.capacidad !== null ? Math.max(0, s.capacidad - s.ocupados) : null;

  let aviso: Tarjeta["aviso"] = null;
  if (cancelada) {
    aviso = { texto: t("agendaVisual.semana.cancelada"), urgente: true };
  } else if (enCurso) {
    aviso = { texto: t("agendaVisual.semana.enCurso"), urgente: true };
  } else if (!pasada && s.en_espera > 0) {
    aviso = {
      texto: t("agendaVisual.semana.enEspera", { n: s.en_espera }),
      urgente: false,
    };
  } else if (!pasada && libres === 0) {
    aviso = { texto: t("agendaVisual.semana.llena"), urgente: false };
  } else if (!pasada && libres !== null && libres <= 2) {
    aviso = {
      texto: t("agendaVisual.semana.quedan", { n: libres }),
      urgente: false,
    };
  } else if (!pasada && s.capacidad !== null && pct < 40) {
    aviso = { texto: t("agendaVisual.semana.baja"), urgente: false };
  }

  const inicio = minutosLocal(s.inicia_en, s.zona_horaria);
  return {
    sesion: s,
    inicio,
    fin: inicio + duracionMin(s),
    hora: `${aHora(inicio)} – ${aHora(inicio + duracionMin(s))}`,
    nombre: s.oferta ?? "—",
    detalle: [s.instructor, s.sala].filter(Boolean).join(" · "),
    cupo:
      s.capacidad !== null ? `${s.ocupados}/${s.capacidad}` : `${s.ocupados}`,
    pct: Math.min(100, pct),
    aviso,
    pasada: pasada || cancelada,
    enCurso,
    fondo: tono.fondo,
    tinta: tono.tinta,
  };
}

// Lo que se ve: de 07:00 a 21:00, o más si alguna clase empieza antes o acaba después.
const rango = computed(() => {
  let ini = 7 * 60;
  let fin = 21 * 60;
  for (const s of props.sesiones) {
    const inicio = minutosLocal(s.inicia_en, s.zona_horaria);
    ini = Math.min(ini, Math.floor(inicio / 60) * 60);
    fin = Math.max(fin, Math.ceil((inicio + duracionMin(s)) / 60) * 60);
  }
  return { ini, fin };
});
const alto = computed(
  () => ((rango.value.fin - rango.value.ini) / 60) * PX_HORA,
);
function y(min: number): number {
  return ((min - rango.value.ini) / 60) * PX_HORA;
}
const horas = computed(() => {
  const lista: { min: number; etiqueta: string }[] = [];
  for (let m = rango.value.ini; m < rango.value.fin; m += 60) {
    lista.push({ min: m, etiqueta: aHora(m) });
  }
  return lista;
});

interface Colocada extends Tarjeta {
  top: number;
  altura: number;
  izq: number;
  ancho: number;
}

/** Las que se enciman van lado a lado: cada grupo encadenado se reparte en carriles. */
function colocar(tarjetas: Tarjeta[]): Colocada[] {
  const orden = [...tarjetas].sort(
    (a, b) => a.inicio - b.inicio || b.fin - a.fin,
  );
  const salida: Colocada[] = [];
  let grupo: { t: Tarjeta; carril: number }[] = [];
  let finGrupo = -1;
  const cerrar = (): void => {
    const carriles = Math.max(1, ...grupo.map((g) => g.carril + 1));
    for (const g of grupo) {
      salida.push({
        ...g.t,
        top: y(g.t.inicio) + 2,
        altura: Math.max(26, ((g.t.fin - g.t.inicio) / 60) * PX_HORA - 4),
        izq: (g.carril / carriles) * 100,
        ancho: 100 / carriles,
      });
    }
    grupo = [];
  };
  for (const t of orden) {
    if (grupo.length > 0 && t.inicio >= finGrupo) {
      cerrar();
      finGrupo = -1;
    }
    const finales: number[] = [];
    for (const g of grupo) {
      finales[g.carril] = Math.max(finales[g.carril] ?? 0, g.t.fin);
    }
    let carril = finales.findIndex((f) => f <= t.inicio);
    if (carril === -1) {
      carril = finales.length;
    }
    grupo.push({ t, carril });
    finGrupo = Math.max(finGrupo, t.fin);
  }
  if (grupo.length > 0) {
    cerrar();
  }
  return salida;
}

const columnas = computed(() =>
  props.dias.map((d) => {
    const delDia = props.sesiones.filter(
      (s) => fechaLocal(s.inicia_en, s.zona_horaria) === d.iso,
    );
    const programadas = delDia.filter((s) => s.estado === "programada");
    const cap = programadas.reduce((a, s) => a + (s.capacidad ?? 0), 0);
    const ocup = programadas.reduce((a, s) => a + s.ocupados, 0);
    return {
      ...d,
      resumen:
        delDia.length === 0
          ? t("agendaVisual.semana.sinClasesDia")
          : t(
              "agendaVisual.semana.resumenDia",
              {
                n: programadas.length,
                pct: cap > 0 ? Math.round((ocup / cap) * 100) : 0,
              },
              programadas.length,
            ),
      tarjetas: colocar(delDia.map(tarjeta)),
    };
  }),
);

// Línea de «ahora» (solo en el día de hoy y dentro de lo que se ve).
const lineaAhora = computed<number | null>(() => {
  const hoy = props.dias.find((d) => d.esHoy);
  if (hoy === undefined) {
    return null;
  }
  const zona = props.sesiones[0]?.zona_horaria;
  const iso = ahora.value.toISOString();
  const min = zona
    ? minutosLocal(iso, zona)
    : ahora.value.getHours() * 60 + ahora.value.getMinutes();
  return min >= rango.value.ini && min <= rango.value.fin ? y(min) : null;
});
const horaAhora = computed(() => {
  const zona = props.sesiones[0]?.zona_horaria;
  const iso = ahora.value.toISOString();
  return aHora(
    zona
      ? minutosLocal(iso, zona)
      : ahora.value.getHours() * 60 + ahora.value.getMinutes(),
  );
});
</script>

<template>
  <div class="cs tu-card overflow-hidden">
    <div class="cs-scroll">
      <div class="cs-rejilla">
        <!-- Encabezado: el día y su resumen -->
        <div class="cs-esquina" aria-hidden="true"></div>
        <div
          v-for="d in columnas"
          :key="`h-${d.iso}`"
          class="cs-cabecera"
          :class="{ 'cs-hoy': d.esHoy }"
        >
          <span class="flex items-center justify-center gap-2">
            <span class="cs-dia-nombre">{{ d.nombre }}</span>
            <span class="cs-dia-num" :class="{ 'cs-dia-num-hoy': d.esHoy }">{{
              d.dia
            }}</span>
          </span>
          <span class="text-xs" :style="{ color: 'var(--texto-suave)' }">{{
            d.resumen
          }}</span>
        </div>

        <!-- Eje de horas -->
        <div class="cs-eje" :style="{ height: `${alto}px` }" aria-hidden="true">
          <span
            v-for="h in horas"
            :key="h.min"
            class="cs-hora"
            :style="{ top: `${y(h.min)}px` }"
            >{{ h.etiqueta }}</span
          >
          <span
            v-if="lineaAhora !== null"
            class="cs-ahora-pill"
            :style="{ top: `${lineaAhora - 9}px` }"
            >{{ horaAhora }}</span
          >
        </div>

        <!-- Un día por columna -->
        <div
          v-for="d in columnas"
          :key="`c-${d.iso}`"
          class="cs-columna"
          :class="{ 'cs-hoy': d.esHoy }"
          :style="{ height: `${alto}px` }"
        >
          <div
            v-for="h in horas"
            :key="`l-${h.min}`"
            class="cs-linea"
            :style="{ top: `${y(h.min)}px` }"
            aria-hidden="true"
          ></div>
          <div
            v-for="h in horas"
            :key="`m-${h.min}`"
            class="cs-linea cs-linea-media"
            :style="{ top: `${y(h.min + 30)}px` }"
            aria-hidden="true"
          ></div>
          <div
            v-if="d.esHoy && lineaAhora !== null"
            class="cs-ahora"
            :style="{ top: `${lineaAhora}px` }"
            aria-hidden="true"
          ></div>

          <button
            v-for="tj in d.tarjetas"
            :key="tj.sesion.id"
            type="button"
            class="cs-tarjeta"
            :class="{
              'cs-sel': tj.sesion.id === seleccionada,
              'cs-pasada': tj.pasada,
              'cs-en-curso': tj.enCurso,
              'cs-corta': tj.altura < 50,
            }"
            :style="{
              '--tf': tj.fondo,
              '--tt': tj.tinta,
              top: `${tj.top}px`,
              height: `${tj.altura}px`,
              left: `calc(${tj.izq}% + 4px)`,
              width: `calc(${tj.ancho}% - 8px)`,
            }"
            :aria-label="`${d.nombre} ${tj.hora}, ${tj.nombre}, ${tj.detalle}, ${tj.cupo}`"
            @click="emit('abrir', tj.sesion)"
          >
            <span class="cs-fila">
              <span class="cs-horario">{{ tj.hora }}</span>
              <span
                v-if="tj.aviso"
                class="cs-aviso"
                :class="{ 'cs-aviso-urgente': tj.aviso.urgente }"
                >{{ tj.aviso.texto }}</span
              >
            </span>
            <span class="cs-nombre">{{ tj.nombre }}</span>
            <span v-if="tj.altura >= 60" class="cs-fila cs-suave">
              <span class="cs-quien">
                <IconoNav nombre="miembros" :tam="12" class="shrink-0" />
                <span class="truncate">{{ tj.detalle }}</span>
              </span>
              <span class="cs-cupo">{{ tj.cupo }}</span>
            </span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cs-scroll {
  overflow-x: auto;
}
.cs-rejilla {
  display: grid;
  grid-template-columns: 4rem repeat(7, minmax(8.5rem, 1fr));
  min-width: fit-content;
}
.cs-esquina,
.cs-cabecera {
  border-bottom: 1px solid var(--borde);
}
.cs-cabecera {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.15rem;
  padding: 0.65rem 0.5rem;
  border-left: 1px solid var(--borde);
  text-align: center;
}
.cs-dia-nombre {
  font-size: 0.9rem;
  color: var(--texto);
}
.cs-dia-num {
  min-width: 1.85rem;
  height: 1.85rem;
  padding: 0 0.25rem;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.95rem;
  font-weight: 700;
}
.cs-dia-num-hoy {
  background: var(--acento);
  color: #fff;
}
.cs-hoy {
  background: color-mix(in srgb, var(--acento) 4%, var(--superficie));
}
.cs-eje {
  position: relative;
}
.cs-hora {
  position: absolute;
  right: 0.6rem;
  transform: translateY(-50%);
  font-size: 0.75rem;
  color: var(--texto-suave);
  font-variant-numeric: tabular-nums;
}
.cs-hora:first-child {
  transform: none;
}
.cs-ahora-pill {
  position: absolute;
  right: 0.3rem;
  padding: 0.05rem 0.4rem;
  border-radius: 0.35rem;
  background: var(--acento);
  color: #fff;
  font-size: 0.7rem;
  font-weight: 600;
}
.cs-columna {
  position: relative;
  border-left: 1px solid var(--borde);
}
.cs-linea {
  position: absolute;
  left: 0;
  right: 0;
  border-top: 1px solid var(--borde);
}
.cs-linea-media {
  border-top-style: dashed;
  opacity: 0.6;
}
.cs-ahora {
  position: absolute;
  left: 0;
  right: 0;
  border-top: 2px solid var(--acento);
  z-index: 2;
}
.cs-ahora::before {
  content: "";
  position: absolute;
  left: -0.3rem;
  top: -0.35rem;
  width: 0.6rem;
  height: 0.6rem;
  border-radius: 999px;
  background: var(--acento);
}
/* Tinte suave del servicio y su color en el borde izquierdo. */
.cs-tarjeta {
  position: absolute;
  z-index: 1;
  box-sizing: border-box;
  overflow: hidden;
  border: none;
  border-left: 3px solid var(--tt);
  border-radius: 0.5rem;
  padding: 0.35rem 0.5rem;
  background: color-mix(in srgb, var(--tt) 13%, var(--superficie));
  color: var(--texto);
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  cursor: pointer;
  transition:
    filter 0.15s ease,
    box-shadow 0.15s ease;
}
.cs-tarjeta:hover {
  filter: brightness(0.97);
  z-index: 3;
}
.cs-tarjeta:focus-visible {
  outline: 2px solid var(--acento);
  outline-offset: 2px;
}
.cs-sel {
  box-shadow: 0 0 0 2px var(--acento);
}
.cs-en-curso {
  box-shadow: 0 0 0 2px #d92d20;
}
.cs-pasada {
  opacity: 0.55;
}
.cs-corta {
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
  padding-top: 0.15rem;
  padding-bottom: 0.15rem;
}
.cs-corta .cs-fila {
  flex: none;
}
.cs-fila {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.35rem;
  min-width: 0;
}
.cs-horario {
  font-size: 0.68rem;
  font-weight: 600;
  color: color-mix(in srgb, var(--tt) 75%, var(--texto));
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
.cs-nombre {
  overflow: hidden;
  font-size: 0.82rem;
  font-weight: 700;
  line-height: 1.2;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.cs-suave {
  margin-top: auto;
  font-size: 0.7rem;
  color: var(--texto-suave);
}
.cs-quien {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  min-width: 0;
}
.cs-cupo {
  flex-shrink: 0;
  font-weight: 600;
  color: var(--texto);
  font-variant-numeric: tabular-nums;
}
.cs-aviso {
  font-size: 0.62rem;
  font-weight: 700;
  color: var(--tt);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cs-aviso-urgente {
  color: var(--error);
}
.dark .cs-tarjeta {
  background: color-mix(in srgb, var(--tf) 18%, var(--superficie));
  border-left-color: var(--tf);
}
</style>
