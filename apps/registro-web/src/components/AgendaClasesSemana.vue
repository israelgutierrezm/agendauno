<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

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
 * Semana de CLASES en dos franjas (mañana / tarde) × días: cada clase es una tarjeta
 * con el color de su tipo (el único color de la agenda), quién la imparte, la sala,
 * una barra de cupo y, solo si hace falta, un aviso en texto (llena, en espera…).
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

const CORTE_TARDE = 14 * 60;

interface Tarjeta {
  sesion: SesionAgenda;
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
    hora: `${aHora(inicio)}–${aHora(inicio + duracionMin(s))}`,
    nombre: s.oferta ?? "—",
    detalle: [s.instructor?.split(" ")[0], s.sala].filter(Boolean).join(" · "),
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
  },
  {
    clave: "tarde" as const,
    etiqueta: t("agendaVisual.semana.tarde"),
    rango: t("agendaVisual.semana.rangoTarde"),
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
            <span class="text-xs font-medium">{{ f.etiqueta }}</span>
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
                  v-if="tj.aviso"
                  class="cs-aviso"
                  :class="{ 'cs-aviso-urgente': tj.aviso.urgente }"
                  >{{ tj.aviso.texto }}</span
                >
              </span>
              <span class="cs-nombre truncate">{{ tj.nombre }}</span>
              <span
                v-if="tj.detalle !== ''"
                class="truncate text-[0.7rem] cs-suave"
                >{{ tj.detalle }}</span
              >
              <span class="flex items-center gap-2">
                <span class="cs-barra" aria-hidden="true">
                  <span :style="{ width: `${tj.pct}%` }"></span>
                </span>
                <span class="text-[0.7rem] font-medium tabular-nums">{{
                  tj.cupo
                }}</span>
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
  font-size: 0.75rem;
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
  font-weight: 600;
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
  gap: 0.15rem;
  padding: 0.75rem 0.25rem;
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
  transition: filter 0.15s ease;
}
.cs-tarjeta:hover {
  filter: brightness(0.97);
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
.cs-nombre {
  font-size: 0.82rem;
  font-weight: 600;
  line-height: 1.2;
}
.cs-suave {
  opacity: 0.85;
}
.cs-aviso {
  font-size: 0.66rem;
  font-weight: 600;
  white-space: nowrap;
  flex-shrink: 0;
}
.cs-aviso-urgente {
  color: var(--error);
}
.cs-barra {
  flex: 1;
  height: 4px;
  border-radius: 999px;
  background: rgb(255 255 255 / 75%);
  overflow: hidden;
}
.cs-barra > span {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: currentColor;
}
:global(.dark) .cs-tarjeta {
  background: color-mix(in srgb, var(--tf) 20%, var(--superficie));
  color: var(--tf);
}
:global(.dark) .cs-barra {
  background: rgb(255 255 255 / 12%);
}
</style>
