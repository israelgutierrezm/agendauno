<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import {
  colorProfesional,
  duracionMin,
  fechaLocal,
  iniciales,
  minutosLocal,
  nivelCupo,
  pctCupo,
  tonoServicio,
  aHora,
  type SesionAgenda,
} from "@/lib/agenda";

/**
 * Semana de CLASES en dos franjas (mañana / tarde) × días: cada clase es una tarjeta
 * con el color de su tipo, quién la imparte, la sala y una barra de cupo con su
 * semáforo (lleno, medio, bajo) y avisos (llena, en espera, quedan pocos).
 */
const props = defineProps<{
  dias: { iso: string; nombre: string; dia: number; esHoy: boolean }[];
  sesiones: SesionAgenda[];
  catalogo: string[];
  profesionales: { id: string; nombre: string }[];
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

const CORTE_TARDE = 14 * 60;

const COLOR_CUPO = {
  alto: "#079455",
  medio: "#0070FF",
  bajo: "#DC6803",
} as const;

interface Tarjeta {
  sesion: SesionAgenda;
  hora: string;
  nombre: string;
  detalle: string;
  iniciales: string;
  colorInstructor: string;
  cupo: string;
  pct: number;
  colorBarra: string;
  chip: { texto: string; fondo: string; tinta: string } | null;
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
  const iInstructor = props.profesionales.findIndex(
    (p) => p.id === s.instructor_id,
  );

  let chip: Tarjeta["chip"] = null;
  if (cancelada) {
    chip = {
      texto: t("agendaVisual.semana.cancelada"),
      fondo: "#FDE6E6",
      tinta: "#A11B1B",
    };
  } else if (enCurso) {
    chip = {
      texto: t("agendaVisual.semana.enCurso"),
      fondo: "#D92D20",
      tinta: "#FFFFFF",
    };
  } else if (!pasada && s.en_espera > 0) {
    chip = {
      texto: t("agendaVisual.semana.enEspera", { n: s.en_espera }),
      fondo: "#FFFFFF",
      tinta: "#5B21B6",
    };
  } else if (!pasada && libres === 0) {
    chip = {
      texto: t("agendaVisual.semana.llena"),
      fondo: "#FFFFFF",
      tinta: "#0F6B3E",
    };
  } else if (!pasada && libres !== null && libres <= 2) {
    chip = {
      texto: t("agendaVisual.semana.quedan", { n: libres }),
      fondo: "#FFFFFF",
      tinta: "#0B4FD1",
    };
  } else if (!pasada && s.capacidad !== null && pct < 40) {
    chip = {
      texto: t("agendaVisual.semana.baja"),
      fondo: "#FFFFFF",
      tinta: "#B54708",
    };
  }

  const inicio = minutosLocal(s.inicia_en, s.zona_horaria);
  return {
    sesion: s,
    hora: `${aHora(inicio)}–${aHora(inicio + duracionMin(s))}`,
    nombre: s.oferta ?? "—",
    detalle: [s.instructor?.split(" ")[0], s.sala].filter(Boolean).join(" · "),
    iniciales: iniciales(s.instructor),
    colorInstructor:
      iInstructor >= 0 ? colorProfesional(iInstructor) : "#667085",
    cupo:
      s.capacidad !== null ? `${s.ocupados}/${s.capacidad}` : `${s.ocupados}`,
    pct: Math.min(100, pct),
    colorBarra: COLOR_CUPO[nivelCupo(pct)],
    chip,
    pasada: pasada || cancelada,
    enCurso,
    fondo: tono.fondo,
    tinta: tono.tinta,
  };
}

const columnas = computed(() =>
  props.dias.map((d) => {
    const delDia = props.sesiones
      .filter((s) => fechaLocal(s.inicia_en, s.zona_horaria) === d.iso)
      .sort((a, b) => a.inicia_en.localeCompare(b.inicia_en));
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
      manana: delDia
        .filter((s) => minutosLocal(s.inicia_en, s.zona_horaria) < CORTE_TARDE)
        .map(tarjeta),
      tarde: delDia
        .filter((s) => minutosLocal(s.inicia_en, s.zona_horaria) >= CORTE_TARDE)
        .map(tarjeta),
    };
  }),
);

const franjas = computed(() => [
  {
    clave: "manana" as const,
    etiqueta: t("agendaVisual.semana.manana"),
    rango: t("agendaVisual.semana.rangoManana"),
    icono:
      "M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4",
    color: "#DC6803",
  },
  {
    clave: "tarde" as const,
    etiqueta: t("agendaVisual.semana.tarde"),
    rango: t("agendaVisual.semana.rangoTarde"),
    icono: "M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z",
    color: "#4A2A8F",
  },
]);
</script>

<template>
  <div class="cs tu-card overflow-hidden">
    <div class="cs-scroll">
      <div class="cs-rejilla">
        <div class="cs-esquina" aria-hidden="true"></div>
        <div
          v-for="d in columnas"
          :key="`h-${d.iso}`"
          class="cs-cabecera"
          :class="{ 'cs-hoy': d.esHoy }"
        >
          <span class="flex items-center gap-2">
            <span class="cs-dia-nombre">{{ d.nombre }}</span>
            <span class="cs-dia-num" :class="{ 'cs-dia-num-hoy': d.esHoy }">{{
              d.dia
            }}</span>
          </span>
          <span class="text-xs" :style="{ color: 'var(--texto-suave)' }">{{
            d.resumen
          }}</span>
        </div>

        <template v-for="f in franjas" :key="f.clave">
          <div class="cs-franja">
            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              :stroke="f.color"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
              aria-hidden="true"
            >
              <path :d="f.icono" />
            </svg>
            <span class="text-xs font-semibold">{{ f.etiqueta }}</span>
            <span
              class="text-[0.68rem]"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ f.rango }}</span
            >
          </div>
          <div
            v-for="d in columnas"
            :key="`${f.clave}-${d.iso}`"
            class="cs-celda"
            :class="{ 'cs-hoy': d.esHoy }"
          >
            <button
              v-for="tj in d[f.clave]"
              :key="tj.sesion.id"
              type="button"
              class="cs-tarjeta"
              :class="{
                'cs-sel': tj.sesion.id === seleccionada,
                'cs-pasada': tj.pasada,
                'cs-en-curso': tj.enCurso,
              }"
              :style="{ '--tf': tj.fondo, '--tt': tj.tinta }"
              :aria-label="`${d.nombre} ${tj.hora}, ${tj.nombre}, ${tj.detalle}, ${tj.cupo}`"
              @click="emit('abrir', tj.sesion)"
            >
              <span class="flex items-center gap-1.5 min-w-0">
                <span class="text-xs font-semibold flex-1">{{
                  tj.hora.slice(0, 5)
                }}</span>
                <span
                  v-if="tj.chip"
                  class="cs-chip"
                  :style="{ background: tj.chip.fondo, color: tj.chip.tinta }"
                  >{{ tj.chip.texto }}</span
                >
              </span>
              <span class="cs-nombre truncate">{{ tj.nombre }}</span>
              <span
                v-if="tj.detalle !== '' || tj.iniciales !== ''"
                class="flex items-center gap-1.5 min-w-0 text-[0.7rem] font-semibold"
              >
                <span
                  v-if="tj.iniciales !== ''"
                  class="cs-avatar"
                  :style="{ background: tj.colorInstructor }"
                  aria-hidden="true"
                  >{{ tj.iniciales }}</span
                >
                <span class="truncate cs-suave">{{ tj.detalle }}</span>
              </span>
              <span class="flex items-center gap-2">
                <span class="cs-barra" aria-hidden="true">
                  <span
                    :style="{
                      width: `${tj.pct}%`,
                      background: tj.pasada ? 'currentColor' : tj.colorBarra,
                    }"
                  ></span>
                </span>
                <span class="text-[0.7rem] font-semibold">{{ tj.cupo }}</span>
              </span>
            </button>
          </div>
        </template>
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
  grid-template-columns: 4.5rem repeat(7, minmax(8.75rem, 1fr));
  min-width: fit-content;
}
.cs-esquina,
.cs-cabecera {
  border-bottom: 1px solid var(--borde);
}
.cs-cabecera {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 0.1rem;
  padding: 0.6rem 0.75rem;
  border-left: 1px solid var(--borde);
}
.cs-dia-nombre {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--texto-suave);
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
  font-weight: 800;
}
.cs-dia-num-hoy {
  background: var(--acento);
  color: #fff;
}
.cs-hoy {
  background: color-mix(in srgb, var(--acento) 5%, var(--superficie));
}
.cs-franja {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.3rem;
  padding: 0.75rem 0.25rem;
  background: var(--superficie-2);
  border-bottom: 1px solid var(--borde);
}
.cs-celda {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.45rem 0.4rem;
  min-height: 6rem;
  border-left: 1px solid var(--borde);
  border-bottom: 1px solid var(--borde);
}
.cs-tarjeta {
  width: 100%;
  box-sizing: border-box;
  border: none;
  border-radius: 11px;
  padding: 0.5rem 0.55rem;
  background: var(--tf);
  color: var(--tt);
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  cursor: pointer;
  box-shadow: 0 1px 2px rgb(16 24 40 / 8%);
  transition:
    transform 0.1s ease,
    box-shadow 0.15s ease;
}
.cs-tarjeta:hover {
  transform: translateY(-1px);
  box-shadow: 0 6px 14px rgb(16 24 40 / 14%);
}
.cs-tarjeta:focus-visible {
  outline: 2px solid var(--acento);
  outline-offset: 2px;
}
.cs-sel {
  box-shadow:
    0 0 0 2px var(--acento),
    0 8px 18px color-mix(in srgb, var(--acento) 25%, transparent);
}
.cs-en-curso {
  box-shadow: 0 0 0 2px #d92d20;
}
.cs-pasada {
  opacity: 0.55;
}
.cs-nombre {
  font-size: 0.82rem;
  font-weight: 700;
  line-height: 1.2;
}
.cs-suave {
  opacity: 0.85;
}
.cs-chip {
  display: inline-flex;
  align-items: center;
  padding: 0.05rem 0.45rem;
  border-radius: 999px;
  font-size: 0.62rem;
  font-weight: 800;
  white-space: nowrap;
  flex-shrink: 0;
}
.cs-avatar {
  width: 1.15rem;
  height: 1.15rem;
  border-radius: 999px;
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.52rem;
  font-weight: 800;
  flex-shrink: 0;
}
.cs-barra {
  flex: 1;
  height: 6px;
  border-radius: 999px;
  background: rgb(255 255 255 / 75%);
  overflow: hidden;
}
.cs-barra > span {
  display: block;
  height: 100%;
  border-radius: 999px;
}
:global(.dark) .cs-tarjeta {
  background: color-mix(in srgb, var(--tf) 20%, var(--superficie));
  color: var(--tf);
}
:global(.dark) .cs-barra {
  background: rgb(255 255 255 / 12%);
}
</style>
