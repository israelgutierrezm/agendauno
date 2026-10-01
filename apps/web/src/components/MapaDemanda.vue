<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";

/**
 * Demanda por horario (Reportes → Ocupación): cuándo se llena el negocio, por día de
 * la semana y hora. Dos vistas del mismo dato:
 * - mapa de calor: cada horario con su porcentaje en tres intensidades;
 * - burbujas: más grande y más intensa cuanto más demanda.
 * Y dos medidas: la ocupación (en citas, la de la agenda del equipo, ADR 0081) o la
 * lista de espera. Al tocar un horario se ve su detalle abajo.
 */
export interface CeldaDemanda {
  dia: number;
  hora: number;
  sesiones: number;
  capacidad: number;
  confirmadas: number;
  espera: number;
  ocupacion_pct: number | null;
  disponible_min?: number;
  agendado_min?: number;
  utilizacion_pct?: number | null;
}

const props = defineProps<{
  celdas: CeldaDemanda[];
  // En citas mide la agenda del equipo (horas agendadas entre disponibles).
  porAgenda: boolean;
  promedioPct: number | null;
}>();

const { t } = useI18n();

type Vista = "calor" | "burbujas";
type Medida = "ocupacion" | "espera";
const vista = ref<Vista>("calor");
const medida = ref<Medida>("ocupacion");

const DIAS = [1, 2, 3, 4, 5, 6, 7];
const etiquetasDias = computed(() => t("reportes.demanda.dias").split(","));
const horas = computed(() =>
  [...new Set(props.celdas.map((c) => c.hora))].sort((a, b) => a - b),
);
// Solo se ofrece la lista de espera si alguien esperó.
const hayEspera = computed(() => props.celdas.some((c) => c.espera > 0));
watch(hayEspera, (hay) => {
  if (!hay) {
    medida.value = "ocupacion";
  }
});

function celda(dia: number, hora: number): CeldaDemanda | undefined {
  return props.celdas.find((c) => c.dia === dia && c.hora === hora);
}
function ocupacion(c: CeldaDemanda): number | null {
  return props.porAgenda ? (c.utilizacion_pct ?? null) : c.ocupacion_pct;
}
const maxEspera = computed(() =>
  Math.max(0, ...props.celdas.map((c) => c.espera)),
);
/** El valor que se dibuja, de 0 a 100 (la espera, relativa al horario con más). */
function valor(c: CeldaDemanda): number | null {
  if (medida.value === "espera") {
    return maxEspera.value > 0 ? (c.espera / maxEspera.value) * 100 : 0;
  }
  return ocupacion(c);
}
function nivel(v: number | null): "vacio" | "baja" | "media" | "alta" {
  if (v === null) {
    return "vacio";
  }
  return v <= 40 ? "baja" : v <= 70 ? "media" : "alta";
}
function pct(v: number | null): string {
  return v === null ? "—" : `${Math.round(v)}%`;
}
function texto(c: CeldaDemanda): string {
  return medida.value === "espera" ? String(c.espera) : pct(ocupacion(c));
}
function aHoras(minutos: number): string {
  return `${new Intl.NumberFormat("es-MX", { maximumFractionDigits: 1 }).format(minutos / 60)} h`;
}
function detalle(c: CeldaDemanda): string {
  return props.porAgenda
    ? `${aHoras(c.agendado_min ?? 0)} / ${aHoras(c.disponible_min ?? 0)}`
    : `${c.confirmadas}/${c.capacidad}`;
}
function hora(h: number): string {
  return `${String(h).padStart(2, "0")}:00`;
}
// Diámetro de la burbuja: de 0.7rem (nada) a 2.6rem (lo más alto).
function diametro(c: CeldaDemanda): string {
  const v = Math.max(0, Math.min(100, valor(c) ?? 0));
  return `${(0.7 + (v / 100) * 1.9).toFixed(2)}rem`;
}

// Indicadores: promedio, la hora con más ocupación y cuántos horarios se llenaron.
const indicadores = computed<Indicador[]>(() => {
  // La hora pico: la de más ocupación; si empatan, la de más lista de espera.
  const porHora = new Map<
    number,
    { usado: number; total: number; espera: number }
  >();
  for (const c of props.celdas) {
    const actual = porHora.get(c.hora) ?? { usado: 0, total: 0, espera: 0 };
    actual.usado += props.porAgenda ? (c.agendado_min ?? 0) : c.confirmadas;
    actual.total += props.porAgenda ? (c.disponible_min ?? 0) : c.capacidad;
    actual.espera += c.espera;
    porHora.set(c.hora, actual);
  }
  let pico: number | null = null;
  let mejor = -1;
  let masEspera = -1;
  for (const [h, { usado, total, espera }] of [...porHora].sort(
    (a, b) => a[0] - b[0],
  )) {
    const proporcion = total > 0 ? usado / total : -1;
    if (proporcion > mejor || (proporcion === mejor && espera > masEspera)) {
      mejor = proporcion;
      masEspera = espera;
      pico = h;
    }
  }
  const conDato = props.celdas.filter((c) => ocupacion(c) !== null);
  const llenos = conDato.filter((c) => (ocupacion(c) ?? 0) >= 100).length;

  return [
    {
      clave: "promedio",
      etiqueta: t("reportes.demanda.promedio"),
      valor: pct(props.promedioPct),
      icono: "reportes",
      tono: "azul",
    },
    {
      clave: "pico",
      etiqueta: t("reportes.demanda.horaPico"),
      valor: pico !== null && mejor > 0 ? hora(pico) : "—",
      icono: "reloj",
      tono: "morado",
    },
    {
      clave: "llenos",
      etiqueta: t("reportes.demanda.llenos"),
      valor: t("reportes.demanda.deTotal", {
        n: llenos,
        total: conDato.length,
      }),
      icono: "personas",
      tono: "verde",
    },
  ];
});

// El horario que se detalla abajo: el que se toca o, al inicio, el más alto.
const elegida = ref<{ dia: number; hora: number } | null>(null);
const seleccionada = computed<CeldaDemanda | null>(() => {
  if (elegida.value !== null) {
    const c = celda(elegida.value.dia, elegida.value.hora);
    if (c !== undefined) {
      return c;
    }
  }
  return (
    [...props.celdas].sort(
      (a, b) => (valor(b) ?? -1) - (valor(a) ?? -1) || a.dia - b.dia,
    )[0] ?? null
  );
});
function elegir(c: CeldaDemanda): void {
  elegida.value = { dia: c.dia, hora: c.hora };
}
function esElegida(c: CeldaDemanda): boolean {
  return (
    seleccionada.value?.dia === c.dia && seleccionada.value?.hora === c.hora
  );
}
</script>

<template>
  <div class="md">
    <div class="md-cabeza">
      <div class="min-w-0">
        <h2 class="text-xl font-semibold">
          {{ $t("reportes.demanda.titulo") }}
        </h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{
            porAgenda
              ? $t("reportes.demanda.subtituloAgenda")
              : $t("reportes.demanda.subtitulo")
          }}
        </p>
      </div>
      <div class="md-controles">
        <div class="tu-segmentado" role="group" data-prueba="vista-demanda">
          <button
            v-for="v in ['calor', 'burbujas'] as const"
            :key="v"
            type="button"
            :aria-pressed="vista === v"
            @click="vista = v"
          >
            {{ $t(`reportes.demanda.vistas.${v}`) }}
          </button>
        </div>
        <div
          v-if="hayEspera"
          class="tu-segmentado"
          role="group"
          data-prueba="medida-demanda"
        >
          <button
            v-for="m in ['ocupacion', 'espera'] as const"
            :key="m"
            type="button"
            :aria-pressed="medida === m"
            @click="medida = m"
          >
            {{ $t(`reportes.demanda.medidas.${m}`) }}
          </button>
        </div>
      </div>
    </div>

    <TarjetasIndicadores class="mt-4" :tarjetas="indicadores" />

    <!-- Mapa de calor -->
    <div v-if="vista === 'calor'" class="md-marco mt-4 overflow-x-auto">
      <table class="md-tabla">
        <thead>
          <tr>
            <th class="md-hora-col">{{ $t("reportes.demanda.hora") }}</th>
            <th v-for="(d, i) in etiquetasDias" :key="i">{{ d }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in horas" :key="h">
            <td class="md-hora">{{ hora(h) }}</td>
            <td v-for="dia in DIAS" :key="dia">
              <button
                v-if="celda(dia, h)"
                type="button"
                class="md-celda"
                :class="[
                  `md-${nivel(valor(celda(dia, h)!))}`,
                  { 'md-elegida': esElegida(celda(dia, h)!) },
                ]"
                :data-detalle="detalle(celda(dia, h)!)"
                :data-prueba="`celda-${dia}-${h}`"
                @click="elegir(celda(dia, h)!)"
                @mouseenter="elegir(celda(dia, h)!)"
              >
                {{ texto(celda(dia, h)!)
                }}<span
                  v-if="medida === 'ocupacion' && celda(dia, h)!.espera > 0"
                  class="md-punto"
                  aria-hidden="true"
                ></span>
              </button>
              <span v-else class="md-celda md-vacio" aria-hidden="true">–</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Burbujas -->
    <div v-else class="md-marco mt-4 overflow-x-auto">
      <div class="md-burbujas" data-prueba="burbujas">
        <span></span>
        <span v-for="(d, i) in etiquetasDias" :key="i" class="md-dia">{{
          d
        }}</span>
        <template v-for="h in horas" :key="h">
          <span class="md-hora">{{ hora(h) }}</span>
          <span v-for="dia in DIAS" :key="dia" class="md-lugar">
            <button
              v-if="celda(dia, h)"
              type="button"
              class="md-burbuja"
              :class="[
                `md-b-${nivel(valor(celda(dia, h)!))}`,
                { 'md-elegida': esElegida(celda(dia, h)!) },
              ]"
              :style="{
                width: diametro(celda(dia, h)!),
                height: diametro(celda(dia, h)!),
              }"
              :aria-label="`${etiquetasDias[dia - 1]} ${hora(h)}: ${texto(celda(dia, h)!)}`"
              @click="elegir(celda(dia, h)!)"
              @mouseenter="elegir(celda(dia, h)!)"
            >
              <span
                v-if="celda(dia, h)!.espera > 0"
                class="md-punto-burbuja"
                aria-hidden="true"
              ></span>
            </button>
            <span v-else class="md-raya" aria-hidden="true">—</span>
          </span>
        </template>
      </div>
    </div>

    <!-- Leyenda -->
    <div class="md-leyenda">
      <span v-for="n in ['baja', 'media', 'alta'] as const" :key="n">
        <span
          class="md-muestra"
          :class="
            vista === 'calor' ? `md-${n}` : `md-b-${n} md-muestra-redonda`
          "
        ></span>
        {{
          $t(
            `reportes.demanda.niveles.${medida === "espera" ? `${n}Espera` : n}`,
          )
        }}
      </span>
      <span>
        <span class="md-muestra-punto"></span>
        {{ $t("reportes.demanda.puntoEspera") }}
      </span>
    </div>

    <!-- Detalle del horario elegido -->
    <div
      v-if="seleccionada"
      class="md-detalle tu-card"
      data-prueba="detalle-demanda"
    >
      <div class="min-w-0">
        <p class="font-semibold">
          {{ etiquetasDias[seleccionada.dia - 1] }} ·
          {{ hora(seleccionada.hora) }}
        </p>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{
            porAgenda
              ? $t("reportes.demanda.horasAgendadas", {
                  agendadas: aHoras(seleccionada.agendado_min ?? 0),
                  disponibles: aHoras(seleccionada.disponible_min ?? 0),
                })
              : $t("reportes.demanda.lugaresReservados", {
                  n: seleccionada.confirmadas,
                  total: seleccionada.capacidad,
                })
          }}<template v-if="seleccionada.espera > 0">
            ·
            {{
              $t("reportes.demanda.enEspera", {
                n: seleccionada.espera,
              })
            }}</template
          >
        </p>
      </div>
      <div class="text-right">
        <p class="text-2xl font-semibold tabular-nums">
          {{ pct(ocupacion(seleccionada)) }}
        </p>
        <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reportes.demanda.ocupacion") }}
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.md {
  --md-burbuja: #f2c230;
  --md-espera: #14b8a6;
}
.md-cabeza {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem 1.5rem;
}
.md-controles {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.md-marco {
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
  padding: 0.5rem 0.75rem;
}

/* Mapa de calor */
.md-tabla {
  width: 100%;
  min-width: 40rem;
  border-collapse: separate;
  border-spacing: 0.3rem 0.3rem;
  font-size: 0.82rem;
}
.md-tabla th {
  padding: 0.4rem 0;
  font-weight: 600;
  color: var(--texto-suave);
  text-align: center;
}
.md-tabla .md-hora-col {
  width: 4.5rem;
  text-align: left;
}
.md-hora {
  font-weight: 600;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
.md-celda {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.3rem;
  width: 100%;
  min-height: 2rem;
  border: 0;
  border-radius: 0.5rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
  transition:
    box-shadow 0.15s ease,
    transform 0.15s ease;
}
.md-vacio {
  cursor: default;
  background: var(--superficie-2);
  color: var(--texto-suave);
  font-weight: 400;
}
.md-baja {
  background: color-mix(in srgb, var(--primario) 14%, var(--superficie));
  color: var(--texto);
}
.md-media {
  background: color-mix(in srgb, var(--primario) 42%, var(--superficie));
  color: var(--texto);
}
.md-alta {
  background: var(--primario);
  color: var(--primario-contraste);
}
.md-celda.md-elegida {
  box-shadow:
    0 0 0 2px var(--superficie),
    0 0 0 4px var(--primario-fuerte);
}
.md-punto {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: var(--md-espera);
  box-shadow: 0 0 0 1.5px var(--superficie);
}
/* Lugares reservados al pasar el cursor. */
.md-celda[data-detalle]:hover::after {
  content: attr(data-detalle);
  position: absolute;
  bottom: calc(100% + 0.35rem);
  left: 50%;
  transform: translateX(-50%);
  z-index: 5;
  padding: 0.2rem 0.5rem;
  border-radius: 0.4rem;
  background: #1e2a3b;
  color: #fff;
  font-size: 0.72rem;
  white-space: nowrap;
  pointer-events: none;
}

/* Burbujas */
.md-burbujas {
  display: grid;
  grid-template-columns: 4.5rem repeat(7, minmax(3rem, 1fr));
  min-width: 34rem;
  align-items: center;
}
.md-dia {
  padding: 0.4rem 0;
  text-align: center;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--texto-suave);
}
.md-burbujas > .md-hora {
  font-size: 0.82rem;
  color: var(--texto-suave);
  font-weight: 500;
}
.md-burbujas > .md-hora,
.md-lugar {
  height: 3.1rem;
  border-top: 1px solid var(--borde);
}
.md-burbujas > .md-hora {
  display: flex;
  align-items: center;
}
.md-lugar {
  display: flex;
  align-items: center;
  justify-content: center;
}
.md-burbuja {
  position: relative;
  border: 0;
  border-radius: 999px;
  cursor: pointer;
  transition:
    width 0.25s ease,
    height 0.25s ease,
    box-shadow 0.15s ease;
}
.md-b-vacio,
.md-b-baja {
  background: color-mix(in srgb, var(--md-burbuja) 22%, var(--superficie));
}
.md-b-media {
  background: color-mix(in srgb, var(--md-burbuja) 55%, var(--superficie));
}
.md-b-alta {
  background: var(--md-burbuja);
}
.md-burbuja.md-elegida {
  box-shadow:
    0 0 0 2px var(--superficie),
    0 0 0 4px color-mix(in srgb, var(--md-burbuja) 70%, var(--texto));
}
.md-punto-burbuja {
  position: absolute;
  top: -0.1rem;
  right: -0.2rem;
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 999px;
  background: var(--md-espera);
  box-shadow: 0 0 0 1.5px var(--superficie);
}
.md-raya {
  color: var(--texto-suave);
  opacity: 0.6;
}

/* Leyenda y detalle */
.md-leyenda {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 1.5rem;
  margin-top: 0.85rem;
  font-size: 0.8rem;
  color: var(--texto-suave);
}
.md-leyenda > span {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}
.md-muestra {
  display: inline-block;
  width: 1.5rem;
  height: 0.9rem;
  border-radius: 0.3rem;
}
.md-muestra-redonda {
  width: 0.9rem;
  border-radius: 999px;
}
.md-muestra-punto {
  display: inline-block;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 999px;
  background: var(--md-espera);
}
.md-detalle {
  margin-top: 1rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
}
</style>
