<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import ParametrosNegocio from "@/components/ParametrosNegocio.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Reglas que gobiernan la agenda: la política de cancelación (general y por
 * actividad), los días cerrados (sin clases ni citas) y las clases que se repiten
 * (plantillas que generan fechas).
 */
const { t } = useI18n();

interface Politica {
  id: string;
  actividad_id: string | null;
  actividad: string | null;
  horas_limite: number;
  penaliza_tarde: boolean;
  penaliza_no_show: boolean;
  // Faltas toleradas sin cobrar y en cuántos días se cuentan (ADR 0043).
  tolerancia_no_show: number;
  ventana_no_show_dias: number | null;
}
interface DiaCerrado {
  id: string;
  fecha: string;
  motivo: string | null;
}
interface Serie {
  id: string;
  oferta: string | null;
  sucursal: string | null;
  instructor: string | null;
  dias_semana: number[];
  hora_local: string;
  vigente_desde: string;
  vigente_hasta: string | null;
}
interface Oferta {
  actividad: string | null;
  actividad_id: string | null;
}
interface FormPolitica {
  actividad_id: string | null;
  horas_limite: number;
  penaliza_tarde: boolean;
  penaliza_no_show: boolean;
  // Faltas toleradas sin cobrar y en cuántos días se cuentan (ADR 0043).
  tolerancia_no_show: number;
  ventana_no_show_dias: number | null;
}

const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedePoliticas = computed(() => sesion.puede("estudio.gestionar"));

const cargando = ref(true);
const error = ref<string | null>(null);
const politicas = ref<Politica[]>([]);
const cerrados = ref<DiaCerrado[]>([]);
const series = ref<Serie[]>([]);
const actividades = ref<{ id: string; nombre: string }[]>([]);

const general = ref<FormPolitica>({
  actividad_id: null,
  horas_limite: 6,
  penaliza_tarde: true,
  penaliza_no_show: true,
  tolerancia_no_show: 0,
  ventana_no_show_dias: null,
});
const editando = ref<FormPolitica | null>(null);
const guardando = ref(false);
const nuevaActividad = ref("");

const nuevoDia = ref({ fecha: "", motivo: "" });
// Días de la ventana cuando la política no fija los suyos (los de la plataforma).
const ventanaPlataforma = ref(30);

const LETRAS = ["", "L", "M", "M", "J", "V", "S", "D"];

const hoy = new Date().toISOString().slice(0, 10);
const cerradosProximos = computed(() =>
  cerrados.value.filter((d) => d.fecha >= hoy),
);
const excepciones = computed(() =>
  politicas.value.filter((p) => p.actividad_id !== null),
);
const actividadesSinExcepcion = computed(() =>
  actividades.value.filter(
    (a) => !excepciones.value.some((p) => p.actividad_id === a.id),
  ),
);

function fecha(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(`${iso}T12:00:00`));
}

function dias(s: Serie): string {
  return [...s.dias_semana]
    .sort((a, b) => a - b)
    .map((d) => LETRAS[d])
    .join(" ");
}

function resumen(p: Politica): string {
  return [
    t("reglasAgenda.resumen", { h: p.horas_limite }),
    p.penaliza_tarde ? t("reglasAgenda.cobraTarde") : null,
    p.penaliza_no_show ? t("reglasAgenda.cobraNoShow") : null,
    p.penaliza_no_show && p.tolerancia_no_show > 0
      ? t("reglasAgenda.toleraN", {
          n: p.tolerancia_no_show,
          d: p.ventana_no_show_dias ?? ventanaPlataforma.value,
        })
      : null,
  ]
    .filter((x) => x !== null)
    .join(" · ");
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [p, c, s, o] = await Promise.all([
      api.get<{
        data: Politica[];
        por_defecto?: Omit<FormPolitica, "actividad_id">;
      }>(`${base.value}/politicas-cancelacion`),
      api.get<{ data: DiaCerrado[] }>(`${base.value}/excepciones-horario`),
      api.get<{ data: Serie[] }>(`${base.value}/plantillas-horario`),
      api.get<{ data: Oferta[] }>(`${base.value}/ofertas`),
    ]);
    politicas.value = p.data.data;
    cerrados.value = c.data.data;
    series.value = s.data.data;
    const unicas = new Map<string, string>();
    for (const of of o.data.data) {
      if (of.actividad_id !== null) {
        unicas.set(of.actividad_id, of.actividad ?? "—");
      }
    }
    actividades.value = [...unicas].map(([id, nombre]) => ({ id, nombre }));
    const defecto = p.data.por_defecto;
    if (defecto) {
      ventanaPlataforma.value = defecto.ventana_no_show_dias ?? 30;
    }
    const g = politicas.value.find((x) => x.actividad_id === null);
    if (g !== undefined) {
      general.value = {
        actividad_id: null,
        horas_limite: g.horas_limite,
        penaliza_tarde: g.penaliza_tarde,
        penaliza_no_show: g.penaliza_no_show,
        tolerancia_no_show: g.tolerancia_no_show,
        ventana_no_show_dias: g.ventana_no_show_dias,
      };
    } else if (defecto) {
      // Sin política propia: se parte de la que fija la plataforma.
      general.value = { actividad_id: null, ...defecto };
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function guardarPolitica(form: FormPolitica): Promise<void> {
  guardando.value = true;
  try {
    await api.put(`${base.value}/politicas-cancelacion`, form);
    toast.exito(t("reglasAgenda.guardada"));
    editando.value = null;
    nuevaActividad.value = "";
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}

function editarExcepcion(p: Politica): void {
  editando.value = {
    actividad_id: p.actividad_id,
    horas_limite: p.horas_limite,
    penaliza_tarde: p.penaliza_tarde,
    penaliza_no_show: p.penaliza_no_show,
    tolerancia_no_show: p.tolerancia_no_show,
    ventana_no_show_dias: p.ventana_no_show_dias,
  };
}

function agregarExcepcion(): void {
  if (nuevaActividad.value === "") {
    return;
  }
  editando.value = { ...general.value, actividad_id: nuevaActividad.value };
}

function nombreActividad(id: string | null): string {
  return actividades.value.find((a) => a.id === id)?.nombre ?? "—";
}

async function agregarDia(): Promise<void> {
  try {
    await api.post(`${base.value}/excepciones-horario`, {
      fecha: nuevoDia.value.fecha,
      motivo: nuevoDia.value.motivo || null,
    });
    nuevoDia.value = { fecha: "", motivo: "" };
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function quitarDia(d: DiaCerrado): Promise<void> {
  try {
    await api.delete(`${base.value}/excepciones-horario/${d.id}`);
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function dejarDeRepetir(s: Serie): Promise<void> {
  if (!(await confirmar(t("reglasAgenda.confirmarDejar"), { peligro: true }))) {
    return;
  }
  try {
    await api.delete(`${base.value}/plantillas-horario/${s.id}`);
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-4xl px-4 py-10">
    <EncabezadoSeccion :titulo="$t('reglasAgenda.titulo')" />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <!-- Cancelaciones -->
      <div class="mt-6 tu-card p-5">
        <h2 class="font-semibold">{{ $t("reglasAgenda.cancelaciones") }}</h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reglasAgenda.cancelacionesAyuda") }}
        </p>
        <p
          v-if="!puedePoliticas"
          class="mt-2 text-xs"
          :style="{ color: 'var(--aviso)' }"
        >
          {{ $t("reglasAgenda.soloLectura") }}
        </p>

        <form
          class="mt-4 flex flex-wrap items-end gap-4"
          @submit.prevent="guardarPolitica(general)"
        >
          <div>
            <label class="tu-label" for="ra-horas">{{
              $t("reglasAgenda.horas")
            }}</label>
            <input
              id="ra-horas"
              v-model.number="general.horas_limite"
              class="tu-input w-28"
              type="number"
              min="0"
              max="720"
              :disabled="!puedePoliticas"
            />
          </div>
          <label class="flex items-center gap-2 pb-2 text-sm">
            <input
              v-model="general.penaliza_tarde"
              type="checkbox"
              :disabled="!puedePoliticas"
            />
            {{ $t("reglasAgenda.penalizaTarde") }}
          </label>
          <label class="flex items-center gap-2 pb-2 text-sm">
            <input
              v-model="general.penaliza_no_show"
              type="checkbox"
              :disabled="!puedePoliticas"
            />
            {{ $t("reglasAgenda.penalizaNoShow") }}
          </label>
          <template v-if="general.penaliza_no_show">
            <div>
              <label class="tu-label" for="ra-tolerancia">{{
                $t("reglasAgenda.tolerancia")
              }}</label>
              <input
                id="ra-tolerancia"
                v-model.number="general.tolerancia_no_show"
                class="tu-input w-24"
                type="number"
                min="0"
                max="100"
                :disabled="!puedePoliticas"
              />
            </div>
            <div v-if="general.tolerancia_no_show > 0">
              <label class="tu-label" for="ra-ventana">{{
                $t("reglasAgenda.ventana")
              }}</label>
              <input
                id="ra-ventana"
                v-model.number="general.ventana_no_show_dias"
                class="tu-input w-24"
                type="number"
                min="1"
                max="365"
                :placeholder="String(ventanaPlataforma)"
                :disabled="!puedePoliticas"
              />
            </div>
          </template>
          <button
            v-if="puedePoliticas"
            type="submit"
            class="tu-btn tu-btn-primario text-sm"
            :disabled="guardando"
          >
            {{ $t("reglasAgenda.guardar") }}
          </button>
        </form>

        <template v-if="actividades.length > 0">
          <h3 class="mt-6 text-sm font-semibold">
            {{ $t("reglasAgenda.porActividad") }}
          </h3>
          <ul class="mt-1">
            <li v-for="p in excepciones" :key="p.id" class="ra-fila text-sm">
              <div class="min-w-0">
                <p class="font-medium truncate">{{ p.actividad ?? "—" }}</p>
                <p
                  class="mt-0.5 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ resumen(p) }}
                </p>
              </div>
              <button
                v-if="puedePoliticas"
                type="button"
                class="tu-enlace shrink-0"
                @click="editarExcepcion(p)"
              >
                {{ $t("documentosTabs.editar") }}
              </button>
            </li>
          </ul>

          <div
            v-if="puedePoliticas && actividadesSinExcepcion.length > 0"
            class="mt-3 flex items-center gap-2"
          >
            <select
              v-model="nuevaActividad"
              class="tu-input max-w-xs"
              :aria-label="$t('reglasAgenda.agregarActividad')"
              @change="agregarExcepcion"
            >
              <option value="">
                {{ $t("reglasAgenda.agregarActividad") }}
              </option>
              <option
                v-for="a in actividadesSinExcepcion"
                :key="a.id"
                :value="a.id"
              >
                {{ a.nombre }}
              </option>
            </select>
          </div>

          <form
            v-if="editando"
            class="mt-3 flex flex-wrap items-end gap-4 rounded-xl border p-4"
            :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
            @submit.prevent="guardarPolitica(editando)"
          >
            <p class="w-full text-sm font-medium">
              {{ nombreActividad(editando.actividad_id) }}
            </p>
            <div>
              <label class="tu-label" for="ra-horas-act">{{
                $t("reglasAgenda.horas")
              }}</label>
              <input
                id="ra-horas-act"
                v-model.number="editando.horas_limite"
                class="tu-input w-28"
                type="number"
                min="0"
                max="720"
              />
            </div>
            <label class="flex items-center gap-2 pb-2 text-sm">
              <input v-model="editando.penaliza_tarde" type="checkbox" />
              {{ $t("reglasAgenda.penalizaTarde") }}
            </label>
            <label class="flex items-center gap-2 pb-2 text-sm">
              <input v-model="editando.penaliza_no_show" type="checkbox" />
              {{ $t("reglasAgenda.penalizaNoShow") }}
            </label>
            <template v-if="editando.penaliza_no_show">
              <div>
                <label class="tu-label" for="ra-tolerancia-act">{{
                  $t("reglasAgenda.tolerancia")
                }}</label>
                <input
                  id="ra-tolerancia-act"
                  v-model.number="editando.tolerancia_no_show"
                  class="tu-input w-24"
                  type="number"
                  min="0"
                  max="100"
                />
              </div>
              <div v-if="editando.tolerancia_no_show > 0">
                <label class="tu-label" for="ra-ventana-act">{{
                  $t("reglasAgenda.ventana")
                }}</label>
                <input
                  id="ra-ventana-act"
                  v-model.number="editando.ventana_no_show_dias"
                  class="tu-input w-24"
                  type="number"
                  min="1"
                  max="365"
                  :placeholder="String(ventanaPlataforma)"
                />
              </div>
            </template>
            <div class="flex gap-2">
              <button
                type="submit"
                class="tu-btn tu-btn-primario text-sm"
                :disabled="guardando"
              >
                {{ $t("reglasAgenda.guardar") }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma text-sm"
                @click="
                  editando = null;
                  nuevaActividad = '';
                "
              >
                {{ $t("comun.cancelar") }}
              </button>
            </div>
          </form>
        </template>
      </div>

      <!-- Días cerrados -->
      <div class="mt-5 tu-card p-5">
        <h2 class="font-semibold">{{ $t("reglasAgenda.cerrados") }}</h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("reglasAgenda.cerradosAyuda") }}
        </p>
        <form
          class="mt-4 flex flex-wrap items-end gap-3"
          @submit.prevent="agregarDia"
        >
          <div>
            <label class="tu-label" for="ra-fecha">{{
              $t("reglasAgenda.fecha")
            }}</label>
            <input
              id="ra-fecha"
              v-model="nuevoDia.fecha"
              class="tu-input"
              type="date"
              :min="hoy"
              required
            />
          </div>
          <div class="min-w-[12rem] flex-1">
            <label class="tu-label" for="ra-motivo">{{
              $t("reglasAgenda.motivo")
            }}</label>
            <input
              id="ra-motivo"
              v-model="nuevoDia.motivo"
              class="tu-input"
              maxlength="255"
              :placeholder="$t('reglasAgenda.motivoPh')"
            />
          </div>
          <button
            type="submit"
            class="tu-btn tu-btn-fantasma text-sm"
            :disabled="nuevoDia.fecha === ''"
          >
            {{ $t("reglasAgenda.agregarDia") }}
          </button>
        </form>
        <p
          v-if="cerradosProximos.length === 0"
          class="mt-4 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("reglasAgenda.sinCerrados") }}
        </p>
        <ul v-else class="mt-3">
          <li v-for="d in cerradosProximos" :key="d.id" class="ra-fila text-sm">
            <div class="min-w-0">
              <p class="font-medium first-letter:uppercase">
                {{ fecha(d.fecha) }}
              </p>
              <p
                v-if="d.motivo"
                class="mt-0.5 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ d.motivo }}
              </p>
            </div>
            <button
              type="button"
              class="tu-enlace shrink-0"
              style="color: var(--error)"
              @click="quitarDia(d)"
            >
              {{ $t("reglasAgenda.quitar") }}
            </button>
          </li>
        </ul>
      </div>

      <!-- Clases que se repiten -->
      <div v-if="!sesion.esCitas || series.length > 0" class="mt-5 tu-card p-5">
        <h2 class="font-semibold">{{ $t("reglasAgenda.series") }}</h2>
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
        <ul v-else class="mt-3">
          <li v-for="s in series" :key="s.id" class="ra-fila text-sm">
            <div class="min-w-0">
              <p class="font-medium truncate">
                {{ s.oferta ?? "—" }} · {{ dias(s) }} {{ s.hora_local }}
              </p>
              <p
                class="mt-0.5 text-xs truncate"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{
                  [
                    s.sucursal,
                    s.instructor,
                    $t("reglasAgenda.desde", {
                      fecha: fecha(s.vigente_desde),
                    }),
                    s.vigente_hasta
                      ? $t("reglasAgenda.hasta", {
                          fecha: fecha(s.vigente_hasta),
                        })
                      : null,
                  ]
                    .filter(Boolean)
                    .join(" · ")
                }}
              </p>
            </div>
            <button
              type="button"
              class="tu-enlace shrink-0"
              style="color: var(--error)"
              @click="dejarDeRepetir(s)"
            >
              {{ $t("reglasAgenda.dejarDeRepetir") }}
            </button>
          </li>
        </ul>
      </div>

      <!-- Límites y tiempos del negocio (ADR 0042) -->
      <ParametrosNegocio v-if="puedePoliticas" />
    </template>
  </section>
</template>

<style scoped>
.ra-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.ra-fila:first-child {
  border-top: 0;
}
</style>
