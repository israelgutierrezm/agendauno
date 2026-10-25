<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { crearClientePlataforma } from "@/lib/clientePlataforma";
import { erroresPorCampo, mensajeDeError } from "@/lib/api";
import { useCambiosPendientes } from "@/lib/cambiosPendientes";
import { useToastStore } from "@/stores/toast";

/**
 * Superadmin › Demos › Horario (ADR 0204): si la landing agenda demos, cuánto dura cada
 * una, cuántas caben a la misma hora, cuántos días hacia adelante y con cuánta
 * anticipación, el horario de cada día de la semana (uno o varios rangos) y los días
 * bloqueados (un feriado, vacaciones). Todo en la zona de ventas. Guardar envía todo
 * junto; salir con cambios sin guardar pregunta antes.
 */
const props = defineProps<{ apiUrl: string; token: string }>();
const emit = defineEmits<{ guardado: [] }>();

interface Rango {
  desde: string;
  hasta: string;
}
interface Bloqueo {
  fecha: string;
  motivo: string;
}
interface Limite {
  minimo: number;
  maximo: number;
}
type Campo = "minutos" | "por_horario" | "dias_adelante" | "horas_anticipacion";
interface Horario {
  activas: boolean;
  zona: string;
  minutos: number;
  por_horario: number;
  dias_adelante: number;
  horas_anticipacion: number;
  rangos: { dia: number; desde: string; hasta: string }[];
  bloqueos: { fecha: string; motivo: string | null }[];
  limites: Record<Campo, Limite>;
}

const { t, locale } = useI18n();
const toast = useToastStore();
const cliente = () =>
  crearClientePlataforma({
    baseURL: props.apiUrl,
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${props.token}`,
    },
  });

const CAMPOS: Campo[] = [
  "minutos",
  "por_horario",
  "dias_adelante",
  "horas_anticipacion",
];
// Lunes (1) a domingo (7), como los días ISO del API.
const DIAS = [1, 2, 3, 4, 5, 6, 7];

const cargando = ref(true);
const errorCarga = ref<string | null>(null);
const guardando = ref(false);
const errores = ref<Record<string, string>>({});
const zona = ref("");
const limites = ref<Record<Campo, Limite> | null>(null);
const activas = ref(false);
const numeros = ref<Record<Campo, number | string>>({
  minutos: 15,
  por_horario: 1,
  dias_adelante: 10,
  horas_anticipacion: 3,
});
const semana = ref<Record<number, Rango[]>>({});
const bloqueos = ref<Bloqueo[]>([]);
const nuevoBloqueo = ref<Bloqueo>({ fecha: "", motivo: "" });
// Lo guardado, para saber si hay cambios y para descartarlos.
const guardado = ref("");
let ultimo: Horario | null = null;

function aplicar(h: Horario): void {
  ultimo = h;
  zona.value = h.zona;
  limites.value = h.limites;
  activas.value = h.activas;
  numeros.value = {
    minutos: h.minutos,
    por_horario: h.por_horario,
    dias_adelante: h.dias_adelante,
    horas_anticipacion: h.horas_anticipacion,
  };
  semana.value = Object.fromEntries(
    DIAS.map((d) => [
      d,
      h.rangos
        .filter((r) => r.dia === d)
        .map((r) => ({ desde: r.desde, hasta: r.hasta })),
    ]),
  );
  bloqueos.value = h.bloqueos.map((b) => ({
    fecha: b.fecha,
    motivo: b.motivo ?? "",
  }));
  guardado.value = escrito();
}

// Lo que se envía (y con lo que se compara lo guardado).
function cuerpo() {
  return {
    activas: activas.value,
    ...Object.fromEntries(CAMPOS.map((c) => [c, Number(numeros.value[c])])),
    rangos: DIAS.flatMap((dia) =>
      (semana.value[dia] ?? []).map((r) => ({ dia, ...r })),
    ),
    bloqueos: bloqueos.value.map((b) => ({
      fecha: b.fecha,
      motivo: b.motivo.trim() || null,
    })),
  };
}
function escrito(): string {
  return JSON.stringify(cuerpo());
}
const hayCambios = computed(
  () =>
    !cargando.value && guardado.value !== "" && escrito() !== guardado.value,
);
useCambiosPendientes(() => hayCambios.value);

async function cargar(): Promise<void> {
  cargando.value = true;
  errorCarga.value = null;
  try {
    const { data } = await cliente().get<{ data: Horario }>(
      "/api/v1/plataforma/demos/horario",
    );
    aplicar(data.data);
  } catch (e) {
    errorCarga.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function nombreDia(dia: number): string {
  // 2024-01-01 fue lunes: el día ISO `dia` es esa fecha más `dia - 1`.
  return new Intl.DateTimeFormat(locale.value, {
    weekday: "long",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(2024, 0, dia)));
}
function fechaLarga(fecha: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    weekday: "short",
    day: "numeric",
    month: "short",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(`${fecha}T12:00:00Z`));
}

function agregarRango(dia: number): void {
  const rangos = semana.value[dia] ?? [];
  const ultimo = rangos[rangos.length - 1];
  rangos.push(
    ultimo
      ? { desde: ultimo.hasta, hasta: ultimo.hasta }
      : { desde: "10:00", hasta: "14:00" },
  );
  semana.value[dia] = rangos;
}
function quitarRango(dia: number, i: number): void {
  semana.value[dia]?.splice(i, 1);
}
// El horario del lunes, de lunes a viernes (lo más común).
function copiarLunes(): void {
  const lunes = semana.value[1] ?? [];
  for (const dia of [2, 3, 4, 5]) {
    semana.value[dia] = lunes.map((r) => ({ ...r }));
  }
}

const hoy = computed(() => {
  const ahora = new Date();
  const partes = new Intl.DateTimeFormat("en-CA", {
    timeZone: zona.value || undefined,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(ahora);
  return partes;
});
const errorBloqueo = ref<string | null>(null);
function bloquear(): void {
  errorBloqueo.value = null;
  const { fecha, motivo } = nuevoBloqueo.value;
  if (fecha === "" || fecha < hoy.value) {
    errorBloqueo.value = t("demosPlataforma.horario.bloqueoFecha");
    return;
  }
  if (bloqueos.value.some((b) => b.fecha === fecha)) {
    errorBloqueo.value = t("demosPlataforma.horario.bloqueoRepetido");
    return;
  }
  bloqueos.value = [...bloqueos.value, { fecha, motivo }].sort((a, b) =>
    a.fecha.localeCompare(b.fecha),
  );
  nuevoBloqueo.value = { fecha: "", motivo: "" };
}
function desbloquear(fecha: string): void {
  bloqueos.value = bloqueos.value.filter((b) => b.fecha !== fecha);
}

function minutosDe(hora: string): number {
  const [h, m] = hora.split(":").map(Number);
  return (h ?? 0) * 60 + (m ?? 0);
}
// Cuántas demos caben a la semana con este horario (las que se ofrecen como máximo).
const porSemana = computed(() => {
  const minutos = Number(numeros.value.minutos);
  if (!Number.isFinite(minutos) || minutos <= 0) {
    return 0;
  }
  const inicios = DIAS.reduce(
    (total, dia) =>
      total +
      (semana.value[dia] ?? []).reduce(
        (n, r) =>
          n +
          Math.max(
            0,
            Math.floor((minutosDe(r.hasta) - minutosDe(r.desde)) / minutos),
          ),
        0,
      ),
    0,
  );
  return inicios * Math.max(1, Number(numeros.value.por_horario) || 1);
});

// El error de un rango: el API los numera en el orden en que se envían.
function errorDeRango(dia: number, i: number): string | undefined {
  const antes = DIAS.filter((d) => d < dia).reduce(
    (n, d) => n + (semana.value[d] ?? []).length,
    0,
  );
  const k = antes + i;
  return (
    errores.value[`rangos.${k}.desde`] ??
    errores.value[`rangos.${k}.hasta`] ??
    errores.value[`rangos.${k}.dia`]
  );
}

async function guardar(): Promise<void> {
  guardando.value = true;
  errores.value = {};
  try {
    const { data } = await cliente().put<{ data: Horario }>(
      "/api/v1/plataforma/demos/horario",
      cuerpo(),
    );
    aplicar(data.data);
    toast.exito(t("demosPlataforma.horario.guardado"));
    emit("guardado");
  } catch (e) {
    errores.value = erroresPorCampo(e);
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}

function descartar(): void {
  if (ultimo !== null) {
    aplicar(ultimo);
  }
  errores.value = {};
}

onMounted(cargar);
</script>

<template>
  <div>
    <p
      v-if="cargando"
      class="text-sm"
      role="status"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <AvisoTono v-else-if="errorCarga" tono="peligro" role="alert">
      {{ errorCarga }}
      <button
        type="button"
        class="tu-btn tu-btn-secundario tu-btn-chico ml-2"
        @click="cargar"
      >
        {{ $t("comun.reintentar") }}
      </button>
    </AvisoTono>
    <form
      v-else
      class="space-y-5"
      data-prueba="horario-demos"
      @submit.prevent="guardar"
    >
      <!-- Si se agendan y cómo: lo que más se cambia, arriba. -->
      <section class="tu-card p-5">
        <label class="flex items-start gap-3">
          <input
            v-model="activas"
            type="checkbox"
            class="mt-1"
            data-prueba="demos-activas"
          />
          <span>
            <span class="block font-medium">{{
              $t("demosPlataforma.horario.activas")
            }}</span>
            <span class="tu-sub block text-sm">{{
              $t("demosPlataforma.horario.activasAyuda", { zona })
            }}</span>
          </span>
        </label>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div v-for="c in CAMPOS" :key="c">
            <label class="tu-label" :for="`demos-${c}`">{{
              $t(`demosPlataforma.horario.campos.${c}`)
            }}</label>
            <input
              :id="`demos-${c}`"
              v-model="numeros[c]"
              type="number"
              class="tu-input"
              step="1"
              :min="limites?.[c].minimo"
              :max="limites?.[c].maximo"
              :aria-invalid="errores[c] ? 'true' : undefined"
              required
            />
            <span v-if="errores[c]" class="tu-error-campo">{{
              errores[c]
            }}</span>
          </div>
        </div>
      </section>

      <!-- La semana: uno o varios horarios por día. -->
      <section class="tu-card p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
          <h2 class="tu-titulo-seccion">
            {{ $t("demosPlataforma.horario.semana") }}
          </h2>
          <span
            class="text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            data-prueba="demos-por-semana"
            >{{
              $t(
                "demosPlataforma.horario.porSemana",
                { n: porSemana },
                porSemana,
              )
            }}</span
          >
        </div>
        <span v-if="errores.rangos" class="tu-error-campo block">{{
          errores.rangos
        }}</span>
        <ul class="hd-semana mt-3">
          <li v-for="dia in DIAS" :key="dia" class="hd-dia" :data-dia="dia">
            <span class="hd-nombre font-medium capitalize">{{
              nombreDia(dia)
            }}</span>
            <div class="min-w-0 flex-1 space-y-2">
              <p
                v-if="(semana[dia] ?? []).length === 0"
                class="text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("demosPlataforma.horario.sinDemos") }}
              </p>
              <div
                v-for="(r, i) in semana[dia]"
                :key="i"
                class="flex flex-wrap items-center gap-2"
                :data-rango="`${dia}-${i}`"
              >
                <input
                  v-model="r.desde"
                  type="time"
                  step="300"
                  class="tu-input w-auto"
                  :aria-label="
                    $t('demosPlataforma.horario.desde', {
                      dia: nombreDia(dia),
                    })
                  "
                  :aria-invalid="errorDeRango(dia, i) ? 'true' : undefined"
                  required
                />
                <span :style="{ color: 'var(--texto-suave)' }">–</span>
                <input
                  v-model="r.hasta"
                  type="time"
                  step="300"
                  class="tu-input w-auto"
                  :aria-label="
                    $t('demosPlataforma.horario.hasta', {
                      dia: nombreDia(dia),
                    })
                  "
                  :aria-invalid="errorDeRango(dia, i) ? 'true' : undefined"
                  required
                />
                <button
                  type="button"
                  class="tu-btn tu-btn-discreto tu-btn-chico"
                  @click="quitarRango(dia, i)"
                >
                  {{ $t("demosPlataforma.horario.quitar") }}
                </button>
                <span
                  v-if="errorDeRango(dia, i)"
                  class="tu-error-campo w-full"
                  >{{ errorDeRango(dia, i) }}</span
                >
              </div>
            </div>
            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="tu-btn tu-btn-secundario tu-btn-chico"
                :data-prueba="`agregar-rango-${dia}`"
                @click="agregarRango(dia)"
              >
                {{ $t("demosPlataforma.horario.agregar") }}
              </button>
              <button
                v-if="dia === 1 && (semana[1] ?? []).length > 0"
                type="button"
                class="tu-btn tu-btn-discreto tu-btn-chico"
                data-prueba="copiar-lunes"
                @click="copiarLunes"
              >
                {{ $t("demosPlataforma.horario.copiarLunes") }}
              </button>
            </div>
          </li>
        </ul>
      </section>

      <!-- Días sin demos: un feriado, vacaciones, un viaje. -->
      <section class="tu-card p-5">
        <h2 class="tu-titulo-seccion">
          {{ $t("demosPlataforma.horario.bloqueos") }}
        </h2>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("demosPlataforma.horario.bloqueosAyuda") }}
        </p>
        <div class="mt-3 flex flex-wrap items-end gap-3">
          <div>
            <label class="tu-label" for="demos-bloqueo-fecha">{{
              $t("demosPlataforma.horario.dia")
            }}</label>
            <input
              id="demos-bloqueo-fecha"
              v-model="nuevoBloqueo.fecha"
              type="date"
              class="tu-input w-auto"
              :min="hoy"
            />
          </div>
          <div class="min-w-[12rem] flex-1">
            <label class="tu-label" for="demos-bloqueo-motivo">{{
              $t("demosPlataforma.horario.motivo")
            }}</label>
            <input
              id="demos-bloqueo-motivo"
              v-model="nuevoBloqueo.motivo"
              class="tu-input"
              maxlength="120"
              :placeholder="$t('demosPlataforma.horario.motivoPh')"
              @keydown.enter.prevent="bloquear"
            />
          </div>
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            data-prueba="bloquear-dia"
            @click="bloquear"
          >
            {{ $t("demosPlataforma.horario.bloquear") }}
          </button>
        </div>
        <span v-if="errorBloqueo" class="tu-error-campo block" role="alert">{{
          errorBloqueo
        }}</span>
        <ul v-if="bloqueos.length > 0" class="hd-bloqueos mt-3">
          <li
            v-for="(b, i) in bloqueos"
            :key="b.fecha"
            class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm"
            :data-bloqueo="b.fecha"
          >
            <span>
              <span class="font-medium capitalize">{{
                fechaLarga(b.fecha)
              }}</span>
              <span v-if="b.motivo" class="tu-sub block">{{ b.motivo }}</span>
              <span
                v-if="errores[`bloqueos.${i}.fecha`]"
                class="tu-error-campo block"
                >{{ errores[`bloqueos.${i}.fecha`] }}</span
              >
            </span>
            <button
              type="button"
              class="tu-btn tu-btn-discreto tu-btn-chico"
              @click="desbloquear(b.fecha)"
            >
              {{ $t("demosPlataforma.horario.desbloquear") }}
            </button>
          </li>
        </ul>
      </section>

      <div class="tu-pie-acciones">
        <span
          v-if="hayCambios"
          class="mr-auto text-sm"
          aria-live="polite"
          :style="{ color: 'var(--texto-suave)' }"
          >{{ $t("demosPlataforma.horario.sinGuardar") }}</span
        >
        <button
          v-if="hayCambios"
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="guardando"
          @click="descartar"
        >
          {{ $t("demosPlataforma.horario.descartar") }}
        </button>
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="guardando || !hayCambios"
          :aria-busy="guardando || undefined"
          data-prueba="guardar-horario-demos"
        >
          {{
            guardando
              ? $t("comun.guardando")
              : $t("demosPlataforma.horario.guardar")
          }}
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped>
/* Un día por renglón: su nombre, sus horarios y sus acciones; en el teléfono, apilado. */
.hd-dia {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.75rem 1rem;
  padding: 0.65rem 0;
}
.hd-dia + .hd-dia,
.hd-bloqueos > li + li {
  border-top: 1px solid var(--borde-fila);
}
.hd-nombre {
  width: 7rem;
  flex-shrink: 0;
  padding-top: 0.45rem;
}
</style>
