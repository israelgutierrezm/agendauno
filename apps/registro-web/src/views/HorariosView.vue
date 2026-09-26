<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";

import BloqueosAgenda from "@/components/BloqueosAgenda.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Sucursal {
  id: string;
  nombre: string;
  zona_horaria: string;
}
interface Proveedor {
  id: string;
  nombre: string;
}
interface Franja {
  hora_inicio: string;
  hora_fin: string;
}
interface HorarioApi {
  id: string;
  dia_semana: number;
  hora_inicio: string;
  hora_fin: string;
}
interface Slot {
  inicia: string;
  termina: string;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("agenda.gestionar"));

// Días de la semana en ISO (1 = lunes … 7 = domingo), como los espera el backend.
const DIAS = [1, 2, 3, 4, 5, 6, 7] as const;

const sucursales = ref<Sucursal[]>([]);
const proveedores = ref<Proveedor[]>([]);
const sucursalId = ref("");
const proveedorId = ref("");

// semana[dia] = franjas de atención de ese día.
type Semana = Record<number, Franja[]>;
function semanaVacia(): Semana {
  return { 1: [], 2: [], 3: [], 4: [], 5: [], 6: [], 7: [] };
}
const semana = ref<Semana>(semanaVacia());

const cargando = ref(true);
const cargandoHorario = ref(false);
const guardando = ref(false);
const error = ref<string | null>(null);
const okGuardado = ref(false);

const sucursalActual = computed(
  () => sucursales.value.find((s) => s.id === sucursalId.value) ?? null,
);
const listo = computed(
  () => sucursalId.value !== "" && proveedorId.value !== "",
);

// Una franja es válida si tiene inicio y fin y el fin es posterior al inicio.
const rangoInvalido = computed(() =>
  DIAS.some((d) =>
    semana.value[d].some(
      (f) =>
        f.hora_inicio === "" ||
        f.hora_fin === "" ||
        f.hora_fin <= f.hora_inicio,
    ),
  ),
);

async function cargarReferencias(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [s, p] = await Promise.all([
      api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`),
      api.get<{ data: Proveedor[] }>(`${base.value}/instructores`),
    ]);
    sucursales.value = s.data.data;
    proveedores.value = p.data.data;
    // Con una sola sucursal, la preseleccionamos para ahorrar un clic.
    if (sucursales.value.length === 1) {
      sucursalId.value = sucursales.value[0].id;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function cargarHorario(): Promise<void> {
  if (!listo.value) {
    return;
  }
  cargandoHorario.value = true;
  error.value = null;
  okGuardado.value = false;
  try {
    const { data } = await api.get<{ data: HorarioApi[] }>(
      `${base.value}/horarios-atencion`,
      {
        params: {
          instructor_id: proveedorId.value,
          sucursal_id: sucursalId.value,
        },
      },
    );
    const nueva = semanaVacia();
    for (const h of data.data) {
      (nueva[h.dia_semana] ?? (nueva[h.dia_semana] = [])).push({
        hora_inicio: h.hora_inicio.slice(0, 5),
        hora_fin: h.hora_fin.slice(0, 5),
      });
    }
    semana.value = nueva;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargandoHorario.value = false;
  }
}

function agregarFranja(dia: number): void {
  const franjas = semana.value[dia];
  const ultima = franjas[franjas.length - 1];
  // Encadena tras la última franja del día; si es la primera, jornada estándar.
  franjas.push(
    ultima !== undefined
      ? { hora_inicio: ultima.hora_fin, hora_fin: "" }
      : { hora_inicio: "09:00", hora_fin: "18:00" },
  );
  okGuardado.value = false;
}
function quitarFranja(dia: number, indice: number): void {
  semana.value[dia].splice(indice, 1);
  okGuardado.value = false;
}
// Copia las franjas de un día a los otros seis (jornada uniforme de lun–dom).
function copiarASemana(dia: number): void {
  const plantilla = semana.value[dia].map((f) => ({ ...f }));
  for (const d of DIAS) {
    if (d !== dia) {
      semana.value[d] = plantilla.map((f) => ({ ...f }));
    }
  }
  okGuardado.value = false;
}

async function guardar(): Promise<void> {
  if (!listo.value || rangoInvalido.value || !puedeGestionar.value) {
    return;
  }
  guardando.value = true;
  error.value = null;
  okGuardado.value = false;
  try {
    const horarios: Array<{
      dia_semana: number;
      hora_inicio: string;
      hora_fin: string;
    }> = [];
    for (const d of DIAS) {
      for (const f of semana.value[d]) {
        horarios.push({
          dia_semana: d,
          hora_inicio: f.hora_inicio,
          hora_fin: f.hora_fin,
        });
      }
    }
    await api.put(`${base.value}/horarios-atencion`, {
      instructor_id: proveedorId.value,
      sucursal_id: sucursalId.value,
      horarios,
    });
    okGuardado.value = true;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

// ---- Vista previa de huecos (prueba el motor de disponibilidad) ----
const preview = ref({ fecha: "", duracion: "60" });
const slots = ref<Slot[]>([]);
const previewCargando = ref(false);
const previewHecho = ref(false);

function horaLocal(iso: string): string {
  const zona = sucursalActual.value?.zona_horaria ?? "UTC";
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona,
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).format(new Date(iso));
}

async function verHuecos(): Promise<void> {
  if (!listo.value || preview.value.fecha === "") {
    return;
  }
  previewCargando.value = true;
  previewHecho.value = false;
  error.value = null;
  try {
    const { data } = await api.get<{ data: { slots: Slot[] } }>(
      `${base.value}/disponibilidad`,
      {
        params: {
          instructor_id: proveedorId.value,
          sucursal_id: sucursalId.value,
          fecha: preview.value.fecha,
          duracion_minutos: Number(preview.value.duracion) || 60,
        },
      },
    );
    slots.value = data.data.slots;
    previewHecho.value = true;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    previewCargando.value = false;
  }
}

// Al cambiar de sucursal o de persona, recarga su horario y reinicia la vista previa.
watch([sucursalId, proveedorId], () => {
  slots.value = [];
  previewHecho.value = false;
  if (listo.value) {
    cargarHorario();
  } else {
    semana.value = semanaVacia();
  }
});

onMounted(cargarReferencias);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('horarios.titulo')" />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <!-- Selectores: sucursal + persona -->
      <div class="mt-6 grid sm:grid-cols-2 gap-3">
        <div>
          <label class="tu-label" for="h-suc">{{
            $t("horarios.sucursal")
          }}</label>
          <select id="h-suc" v-model="sucursalId" class="tu-input">
            <option value="">{{ $t("horarios.elegirSucursal") }}</option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </div>
        <div>
          <label class="tu-label" for="h-prov">{{
            $t("horarios.proveedor")
          }}</label>
          <select
            id="h-prov"
            v-model="proveedorId"
            class="tu-input"
            :disabled="proveedores.length === 0"
          >
            <option value="">{{ $t("horarios.elegirProveedor") }}</option>
            <option v-for="p in proveedores" :key="p.id" :value="p.id">
              {{ p.nombre }}
            </option>
          </select>
        </div>
      </div>

      <p
        v-if="proveedores.length === 0"
        class="mt-3 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("horarios.sinProveedores") }}
      </p>

      <p
        v-if="!listo && proveedores.length > 0"
        class="mt-8 text-center text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("horarios.seleccionaAmbos") }}
      </p>

      <template v-if="listo">
        <p
          v-if="cargandoHorario"
          class="mt-6 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>

        <!-- Editor semanal -->
        <div v-else class="mt-6 space-y-2">
          <div v-for="d in DIAS" :key="d" class="tu-card p-4">
            <div class="flex items-center justify-between gap-3">
              <h3 class="font-semibold">{{ $t(`horarios.dias.${d}`) }}</h3>
              <div class="flex items-center gap-3">
                <button
                  v-if="puedeGestionar && semana[d].length > 0"
                  type="button"
                  class="tu-enlace text-xs"
                  @click="copiarASemana(d)"
                >
                  {{ $t("horarios.copiar") }}
                </button>
                <button
                  v-if="puedeGestionar"
                  type="button"
                  class="tu-enlace text-sm"
                  @click="agregarFranja(d)"
                >
                  {{ $t("horarios.agregarFranja") }}
                </button>
              </div>
            </div>

            <p
              v-if="semana[d].length === 0"
              class="mt-1 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("horarios.cerrado") }}
            </p>

            <div
              v-for="(f, i) in semana[d]"
              :key="i"
              class="mt-2 flex items-center gap-2 flex-wrap"
            >
              <label class="sr-only" :for="`d${d}-i${i}-ini`">{{
                $t("horarios.desde")
              }}</label>
              <input
                :id="`d${d}-i${i}-ini`"
                v-model="f.hora_inicio"
                type="time"
                class="tu-input w-auto"
                :disabled="!puedeGestionar"
                @change="okGuardado = false"
              />
              <span :style="{ color: 'var(--texto-suave)' }">–</span>
              <label class="sr-only" :for="`d${d}-i${i}-fin`">{{
                $t("horarios.hasta")
              }}</label>
              <input
                :id="`d${d}-i${i}-fin`"
                v-model="f.hora_fin"
                type="time"
                class="tu-input w-auto"
                :style="
                  f.hora_fin !== '' && f.hora_fin <= f.hora_inicio
                    ? { borderColor: 'var(--error)' }
                    : {}
                "
                :disabled="!puedeGestionar"
                @change="okGuardado = false"
              />
              <button
                v-if="puedeGestionar"
                type="button"
                class="tu-icono-btn"
                :aria-label="$t('horarios.quitarFranja')"
                @click="quitarFranja(d, i)"
              >
                ✕
              </button>
            </div>
          </div>

          <!-- Guardar -->
          <div class="flex items-center gap-3 pt-2">
            <button
              v-if="puedeGestionar"
              class="tu-btn tu-btn-primario"
              type="button"
              :disabled="guardando || rangoInvalido"
              @click="guardar"
            >
              {{
                guardando ? $t("horarios.guardando") : $t("horarios.guardar")
              }}
            </button>
            <span
              v-else
              class="text-sm"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("horarios.soloLectura") }}</span
            >
            <span
              v-if="rangoInvalido"
              class="text-sm"
              style="color: var(--error)"
              >{{ $t("horarios.rangoInvalido") }}</span
            >
            <span
              v-else-if="okGuardado"
              class="text-sm"
              :style="{ color: 'var(--exito)' }"
              >{{ $t("horarios.guardado") }}</span
            >
          </div>
        </div>

        <!-- Bloqueos (2.2): comida, vacaciones, ausencias o cierre de la sede -->
        <BloqueosAgenda
          :base="base"
          :proveedor-id="proveedorId"
          :proveedor-nombre="
            proveedores.find((p) => p.id === proveedorId)?.nombre ?? ''
          "
          :sucursal-id="sucursalId"
          :sucursal-nombre="sucursalActual?.nombre ?? ''"
          :zona="sucursalActual?.zona_horaria ?? 'America/Mexico_City'"
          :puede-gestionar="puedeGestionar"
        />

        <!-- Vista previa de huecos -->
        <div class="mt-8 tu-card p-6">
          <h2 class="font-light text-lg">
            {{ $t("horarios.preview.titulo") }}
          </h2>
          <p class="text-sm mt-1" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("horarios.preview.ayuda") }}
          </p>
          <form
            class="mt-3 flex flex-wrap items-end gap-3"
            @submit.prevent="verHuecos"
          >
            <div>
              <label class="tu-label" for="pv-fecha">{{
                $t("horarios.preview.fecha")
              }}</label>
              <input
                id="pv-fecha"
                v-model="preview.fecha"
                type="date"
                class="tu-input w-auto"
                required
              />
            </div>
            <div>
              <label class="tu-label" for="pv-dur">{{
                $t("horarios.preview.duracion")
              }}</label>
              <input
                id="pv-dur"
                v-model="preview.duracion"
                type="number"
                min="5"
                step="5"
                class="tu-input w-24"
              />
            </div>
            <button
              class="tu-btn tu-btn-fantasma"
              type="submit"
              :disabled="previewCargando || preview.fecha === ''"
            >
              {{
                previewCargando
                  ? $t("horarios.preview.viendo")
                  : $t("horarios.preview.ver")
              }}
            </button>
          </form>

          <template v-if="previewHecho">
            <p
              v-if="slots.length === 0"
              class="mt-4 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("horarios.preview.vacio") }}
            </p>
            <template v-else>
              <p class="mt-4 text-sm font-medium">
                {{ $t("horarios.preview.total", { n: slots.length }) }}
              </p>
              <div class="mt-2 flex flex-wrap gap-2">
                <span
                  v-for="s in slots"
                  :key="s.inicia"
                  class="px-3 py-1.5 rounded-lg text-sm font-medium"
                  :style="{
                    background: 'var(--primario-suave)',
                    color: 'var(--primario-fuerte)',
                  }"
                >
                  {{ horaLocal(s.inicia) }}
                </span>
              </div>
            </template>
          </template>
        </div>
      </template>
    </template>
  </section>
</template>
