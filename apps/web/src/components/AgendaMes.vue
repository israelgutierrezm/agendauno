<script setup lang="ts">
import { computed } from "vue";

import { tonoServicio, type SesionAgenda } from "@/lib/agenda";

/**
 * Vista mensual de la agenda: el mes en semanas (lunes a domingo). Cada día muestra
 * sus primeras clases o citas (hora y nombre, con el color del servicio) y cuántas
 * más hay; tocar el día lleva a su vista de día. En pantallas angostas, solo cuántas.
 */
const props = defineProps<{
  /** Primer día del mes que se muestra. */
  mes: Date;
  sesiones: SesionAgenda[];
  catalogo: readonly string[];
  seleccionada?: string | null;
  /** Cuántas se listan por día antes de "+N más". */
  porDia?: number;
  /**
   * Las que se resaltan (p. ej. las reservas propias en el portal del alumno): con
   * esto, el color ya no es por servicio sino "mía" (primario) o "no mía" (gris).
   */
  destacadas?: ReadonlySet<string>;
  /** «Hoy» (AAAA-MM-DD) del negocio; sin él, el del navegador. */
  hoy?: string;
}>();

const emit = defineEmits<{
  abrir: [sesion: SesionAgenda];
  dia: [iso: string];
}>();

function pad2(n: number): string {
  return n < 10 ? `0${n}` : `${n}`;
}
function isoDe(d: Date): string {
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
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

const encabezados = computed(() => {
  // Una semana cualquiera que empieza en lunes, para los nombres de los días.
  const lunes = new Date(2024, 0, 1);
  return Array.from({ length: 7 }, (_, i) =>
    new Intl.DateTimeFormat("es-MX", { weekday: "short" }).format(
      new Date(lunes.getFullYear(), 0, 1 + i),
    ),
  );
});

// Sesiones por día local (de la sede de cada una), en orden.
const porFecha = computed(() => {
  const mapa = new Map<string, SesionAgenda[]>();
  for (const s of [...props.sesiones].sort((a, b) =>
    a.inicia_en.localeCompare(b.inicia_en),
  )) {
    const clave = fechaLocal(s.inicia_en, s.zona_horaria);
    mapa.set(clave, [...(mapa.get(clave) ?? []), s]);
  }
  return mapa;
});

const semanas = computed(() => {
  const primero = new Date(props.mes.getFullYear(), props.mes.getMonth(), 1);
  const ultimo = new Date(props.mes.getFullYear(), props.mes.getMonth() + 1, 0);
  const inicio = new Date(primero);
  inicio.setDate(primero.getDate() - ((primero.getDay() + 6) % 7));
  const hoy = props.hoy ?? isoDe(new Date());
  const filas = [];
  for (let d = new Date(inicio); d <= ultimo || d.getDay() !== 1;) {
    const semana = [];
    for (let i = 0; i < 7; i++) {
      const iso = isoDe(d);
      semana.push({
        iso,
        dia: d.getDate(),
        delMes: d.getMonth() === primero.getMonth(),
        esHoy: iso === hoy,
        sesiones: porFecha.value.get(iso) ?? [],
      });
      d.setDate(d.getDate() + 1);
    }
    filas.push(semana);
  }
  return filas;
});

const limite = computed(() => props.porDia ?? 3);
</script>

<template>
  <div class="tu-card overflow-hidden">
    <div
      class="grid grid-cols-7 border-b text-xs font-medium"
      :style="{ borderColor: 'var(--borde)', color: 'var(--texto-suave)' }"
    >
      <div
        v-for="(nombre, i) in encabezados"
        :key="i"
        class="px-2 py-2 capitalize"
      >
        {{ nombre }}
      </div>
    </div>
    <div
      v-for="(semana, s) in semanas"
      :key="s"
      class="grid grid-cols-7"
      :class="{ 'border-t': s > 0 }"
      :style="{ borderColor: 'var(--borde)' }"
    >
      <div
        v-for="(d, i) in semana"
        :key="d.iso"
        class="am-dia"
        :class="{ 'border-l': i > 0, 'am-fuera': !d.delMes }"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <button
          type="button"
          class="am-numero"
          :class="{ 'am-hoy': d.esHoy }"
          :aria-label="$t('agendaVisual.mes.verDia', { dia: d.dia })"
          @click="emit('dia', d.iso)"
        >
          {{ d.dia }}
        </button>

        <!-- Angosto: solo cuántas -->
        <p
          v-if="d.sesiones.length > 0"
          class="mt-1 text-xs sm:hidden"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ d.sesiones.length }}
        </p>

        <ul class="mt-1 hidden space-y-0.5 sm:block">
          <li v-for="x in d.sesiones.slice(0, limite)" :key="x.id">
            <button
              type="button"
              class="am-evento"
              :class="{
                'am-activo': x.id === seleccionada,
                'am-destacada': destacadas?.has(x.id),
              }"
              :style="{
                '--tinta': destacadas
                  ? destacadas.has(x.id)
                    ? 'var(--primario)'
                    : 'var(--texto-suave)'
                  : tonoServicio(x.oferta_id, catalogo, x.oferta).tinta,
                opacity: x.estado === 'cancelada' ? 0.5 : 1,
              }"
              :title="`${hora(x.inicia_en, x.zona_horaria)} · ${x.oferta ?? ''}${x.instructor ? ` · ${x.instructor}` : ''}`"
              @click="emit('abrir', x)"
            >
              <span class="am-hora">{{
                hora(x.inicia_en, x.zona_horaria)
              }}</span>
              <span class="truncate">{{ x.oferta }}</span>
            </button>
          </li>
          <li v-if="d.sesiones.length > limite">
            <button
              type="button"
              class="tu-enlace text-xs"
              @click="emit('dia', d.iso)"
            >
              {{
                $t("agendaVisual.mes.mas", { n: d.sesiones.length - limite })
              }}
            </button>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<style scoped>
.am-dia {
  min-height: 6.5rem;
  padding: 0.35rem;
  min-width: 0;
}
.am-fuera {
  background: color-mix(in srgb, var(--texto-suave) 4%, var(--superficie));
}
.am-fuera .am-numero {
  color: var(--texto-suave);
}
.am-numero {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.6rem;
  height: 1.6rem;
  padding: 0 0.3rem;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 600;
}
.am-numero:hover {
  background: var(--superficie-2);
}
.am-hoy {
  background: var(--primario);
  color: var(--primario-contraste);
}
.am-hoy:hover {
  background: var(--primario);
}
.am-evento {
  display: flex;
  width: 100%;
  align-items: baseline;
  gap: 0.3rem;
  min-width: 0;
  padding: 0.1rem 0.3rem;
  border-radius: 0.3rem;
  border-left: 3px solid var(--tinta);
  background: color-mix(in srgb, var(--tinta) 11%, transparent);
  font-size: 0.72rem;
  line-height: 1.3;
  text-align: left;
}
.am-evento:hover,
.am-activo {
  background: color-mix(in srgb, var(--tinta) 20%, transparent);
}
.am-destacada {
  font-weight: 600;
  background: color-mix(in srgb, var(--tinta) 24%, transparent);
}
.am-hora {
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
  color: var(--texto-suave);
}
</style>
