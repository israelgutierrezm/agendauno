<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import BloqueosAgenda, { type Bloqueo } from "@/components/BloqueosAgenda.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import SemanaHorario from "@/components/SemanaHorario.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import { confirmar } from "@/lib/confirmar";
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

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("agenda.gestionar"));

// Días de la semana en ISO (1 = lunes … 7 = domingo), como los espera el backend.
const DIAS = [1, 2, 3, 4, 5, 6, 7] as const;

const sucursales = ref<Sucursal[]>([]);
const proveedores = ref<Proveedor[]>([]);
const sucursalId = ref("");
// Con una sucursal fija (la de la barra o la única), su selector sobra.
const { mostrarSelect: elegirSucursal } = useSucursalOperativa({
  campo: sucursalId,
});
const proveedorId = ref("");

// semana[dia] = franjas de atención de ese día.
type Semana = Record<number, Franja[]>;
function semanaVacia(): Semana {
  return { 1: [], 2: [], 3: [], 4: [], 5: [], 6: [], 7: [] };
}
const semana = ref<Semana>(semanaVacia());
// Lo último cargado o guardado: para saber si hay cambios sin guardar.
const original = ref(JSON.stringify(semanaVacia()));
const sinGuardar = computed(
  () => JSON.stringify(semana.value) !== original.value,
);
// Cada carga lleva su número: una respuesta vieja (de una selección anterior que
// tardó más) no pisa a la actual.
let pedido = 0;
// De qué sucursal y persona es lo que está en el editor. Solo se muestra y se
// guarda si coincide con lo elegido: si la carga de otra persona falla, nunca
// queda a la vista (ni se guarda sobre ella) el horario de la anterior.
const cargadoPara = ref<string | null>(null);
const fallaHorario = ref(false);

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
const claveActual = computed(() =>
  listo.value ? `${sucursalId.value}|${proveedorId.value}` : null,
);
const horarioListo = computed(
  () => cargadoPara.value !== null && cargadoPara.value === claveActual.value,
);

/** El editor en blanco y sin dueño (antes de cargar, o si la carga falló). */
function vaciarEditor(): void {
  cargadoPara.value = null;
  semana.value = semanaVacia();
  original.value = JSON.stringify(semana.value);
}

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
  const mio = ++pedido;
  const clave = claveActual.value;
  vaciarEditor();
  cargandoHorario.value = true;
  fallaHorario.value = false;
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
    if (mio !== pedido) {
      return; // Ya se eligió otra sucursal o persona.
    }
    const nueva = semanaVacia();
    for (const h of data.data) {
      (nueva[h.dia_semana] ?? (nueva[h.dia_semana] = [])).push({
        hora_inicio: h.hora_inicio.slice(0, 5),
        hora_fin: h.hora_fin.slice(0, 5),
      });
    }
    semana.value = nueva;
    original.value = JSON.stringify(nueva);
    cargadoPara.value = clave;
  } catch (e) {
    if (mio === pedido) {
      vaciarEditor();
      fallaHorario.value = true;
      error.value = mensajeDeError(e);
    }
  } finally {
    if (mio === pedido) {
      cargandoHorario.value = false;
    }
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
  // Solo se guarda lo cargado para esta misma sucursal y persona (nunca mientras
  // carga ni tras una carga fallida).
  if (
    !horarioListo.value ||
    rangoInvalido.value ||
    !puedeGestionar.value ||
    cargandoHorario.value
  ) {
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
    original.value = JSON.stringify(semana.value);
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
// Con cambios sin guardar, primero pregunta; si no se descartan, vuelve a la
// selección anterior.
let revirtiendo = false;
watch([sucursalId, proveedorId], async (_nuevo, [sucAntes, provAntes]) => {
  if (revirtiendo) {
    revirtiendo = false;
    return;
  }
  if (
    sinGuardar.value &&
    !(await confirmar(t("operacion.horarios.descartar"), { peligro: true }))
  ) {
    revirtiendo = true;
    sucursalId.value = sucAntes;
    proveedorId.value = provAntes;
    return;
  }
  slots.value = [];
  previewHecho.value = false;
  if (listo.value) {
    void cargarHorario();
  } else {
    pedido++;
    fallaHorario.value = false;
    vaciarEditor();
  }
});

// ---- Vista semanal, editor de un día, copiar y restablecer ----
const vista = ref<"semana" | "lista">("semana");
const diaEditando = ref<number | null>(null);
function editarDia(dia: number): void {
  if (puedeGestionar.value) {
    if (semana.value[dia].length === 0) {
      agregarFranja(dia);
    }
    diaEditando.value = dia;
  }
}
function cerrarDia(): void {
  // Una franja a medias (sin fin) no se queda en el editor.
  const dia = diaEditando.value;
  if (dia !== null) {
    semana.value[dia] = semana.value[dia].filter(
      (f) => f.hora_inicio !== "" || f.hora_fin !== "",
    );
  }
  diaEditando.value = null;
}
function restablecer(): void {
  semana.value = JSON.parse(original.value) as Semana;
  okGuardado.value = false;
}

const copiando = ref(false);
const copiarDe = ref("");
const copiandoCarga = ref(false);
const avisoCopia = ref<string | null>(null);
const otrasPersonas = computed(() =>
  proveedores.value.filter((p) => p.id !== proveedorId.value),
);
async function copiarHorario(): Promise<void> {
  if (copiarDe.value === "" || !horarioListo.value) {
    return;
  }
  copiandoCarga.value = true;
  avisoCopia.value = null;
  try {
    const { data } = await api.get<{ data: HorarioApi[] }>(
      `${base.value}/horarios-atencion`,
      {
        params: {
          instructor_id: copiarDe.value,
          sucursal_id: sucursalId.value,
        },
      },
    );
    if (data.data.length === 0) {
      avisoCopia.value = t("horariosVisual.copiarVacio");
      return;
    }
    const nueva = semanaVacia();
    for (const h of data.data) {
      nueva[h.dia_semana]?.push({
        hora_inicio: h.hora_inicio.slice(0, 5),
        hora_fin: h.hora_fin.slice(0, 5),
      });
    }
    semana.value = nueva;
    okGuardado.value = false;
    copiando.value = false;
    avisoCopia.value = t("horariosVisual.copiado");
  } catch (e) {
    avisoCopia.value = mensajeDeError(e);
  } finally {
    copiandoCarga.value = false;
  }
}

function minutos(hhmm: string): number {
  const [h, m] = hhmm.split(":").map(Number);
  return (h ?? 0) * 60 + (m ?? 0);
}
const minutosSemana = computed(() =>
  DIAS.reduce(
    (total, d) =>
      total +
      semana.value[d].reduce(
        (s, f) =>
          f.hora_inicio !== "" && f.hora_fin > f.hora_inicio
            ? s + minutos(f.hora_fin) - minutos(f.hora_inicio)
            : s,
        0,
      ),
    0,
  ),
);
const diasActivos = computed(
  () => DIAS.filter((d) => semana.value[d].length > 0).length,
);

// Bloqueos que tocan a esta persona en esta sede (los carga BloqueosAgenda).
const bloqueos = ref<Bloqueo[]>([]);
const bloqueosProximos = computed(() => {
  const limite = Date.now() + 14 * 24 * 3600 * 1000;
  return bloqueos.value
    .filter(
      (b) =>
        (b.ambito === "profesional" && b.instructor_id === proveedorId.value) ||
        (b.ambito === "sede" && b.sucursal_id === sucursalId.value),
    )
    .filter((b) => new Date(b.desde).getTime() < limite)
    .sort((a, b) => a.desde.localeCompare(b.desde));
});
function fechaBloqueo(b: Bloqueo): { dia: string; horas: string } {
  const dia = new Intl.DateTimeFormat("es-MX", {
    timeZone: b.zona_horaria,
    weekday: "short",
    day: "numeric",
    month: "short",
  }).format(new Date(b.desde));
  if (b.todo_el_dia) {
    return { dia, horas: t("horariosVisual.todoElDia") };
  }
  const h = (iso: string): string =>
    new Intl.DateTimeFormat("es-MX", {
      timeZone: b.zona_horaria,
      hour: "2-digit",
      minute: "2-digit",
      hour12: false,
    }).format(new Date(iso));
  return { dia, horas: `${h(b.desde)} – ${h(b.hasta)}` };
}

const indicadores = computed<Indicador[]>(() => [
  {
    clave: "horas",
    etiqueta: t("horariosVisual.kpi.horas"),
    valor: t("horariosVisual.kpi.horasValor", {
      n: Math.round((minutosSemana.value / 60) * 10) / 10,
    }),
    icono: "reloj",
    tono: "morado",
  },
  {
    clave: "dias",
    etiqueta: t("horariosVisual.kpi.dias"),
    valor: t("horariosVisual.kpi.diasValor", { n: diasActivos.value }),
    icono: "agenda",
    tono: "verde",
  },
  {
    clave: "bloqueos",
    etiqueta: t("horariosVisual.kpi.bloqueos"),
    valor: String(bloqueosProximos.value.length),
    icono: "ausente",
    tono: "naranja",
    aviso: bloqueosProximos.value.length > 0,
  },
]);
const persona = computed(
  () => proveedores.value.find((p) => p.id === proveedorId.value) ?? null,
);
function irABloqueos(): void {
  document
    .getElementById("bloqueos")
    ?.scrollIntoView({ behavior: "smooth", block: "start" });
}

onMounted(cargarReferencias);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('horarios.titulo')"
      :subtitulo="$t('horariosVisual.subtitulo')"
    />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <!-- Sucursal y persona, con las acciones del horario -->
      <div class="hv-filtros mt-6">
        <div v-if="elegirSucursal">
          <label class="tu-label" for="h-suc">{{
            $t("horarios.sucursal")
          }}</label>
          <label class="tu-select-icono flex">
            <IconoNav nombre="ubicacion" :tam="16" />
            <select id="h-suc" v-model="sucursalId" class="tu-input">
              <option value="">{{ $t("horarios.elegirSucursal") }}</option>
              <option v-for="s in sucursales" :key="s.id" :value="s.id">
                {{ s.nombre }}
              </option>
            </select>
          </label>
        </div>
        <div>
          <label class="tu-label" for="h-prov">{{
            $t("horarios.proveedor")
          }}</label>
          <label class="tu-select-icono flex">
            <IconoNav nombre="personas" :tam="16" />
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
          </label>
        </div>
        <div v-if="listo && horarioListo" class="hv-acciones">
          <button
            v-if="puedeGestionar && otrasPersonas.length > 0"
            type="button"
            class="tu-btn tu-btn-fantasma"
            @click="
              copiando = true;
              copiarDe = '';
              avisoCopia = null;
            "
          >
            <IconoNav nombre="intercambio" :tam="16" />
            {{ $t("horariosVisual.copiar") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            @click="irABloqueos"
          >
            <IconoNav nombre="ausente" :tam="16" />
            {{ $t("horariosVisual.bloquear") }}
          </button>
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

        <!-- Falló la carga: nada de la selección anterior, solo reintentar -->
        <div
          v-else-if="fallaHorario"
          class="mt-6 tu-card p-5 text-sm"
          role="alert"
        >
          <p style="color: var(--error)">
            {{ $t("operacion.horarios.noSeCargo") }}
          </p>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma mt-3"
            @click="cargarHorario"
          >
            {{ $t("comun.reintentar") }}
          </button>
        </div>

        <template v-else-if="horarioListo">
          <p
            v-if="avisoCopia"
            class="mt-4 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            role="status"
          >
            {{ avisoCopia }}
          </p>

          <TarjetasIndicadores class="mt-5" :tarjetas="indicadores" />

          <div class="hv-principal mt-5">
            <!-- Horario semanal (o en lista, día por día) -->
            <div class="tu-card min-w-0 p-5">
              <header class="flex flex-wrap items-start justify-between gap-3">
                <span>
                  <h2 class="font-semibold">
                    {{ $t("horariosVisual.semanal") }}
                  </h2>
                  <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                    {{ $t("horariosVisual.semanalAyuda") }}
                  </p>
                </span>
                <div class="tu-segmentado" role="group">
                  <button
                    type="button"
                    :aria-pressed="vista === 'semana'"
                    @click="vista = 'semana'"
                  >
                    <IconoNav nombre="cuadricula" :tam="15" />
                    {{ $t("horariosVisual.vistaSemana") }}
                  </button>
                  <button
                    type="button"
                    :aria-pressed="vista === 'lista'"
                    @click="vista = 'lista'"
                  >
                    <IconoNav nombre="lista" :tam="15" />
                    {{ $t("horariosVisual.vistaLista") }}
                  </button>
                </div>
              </header>

              <div
                v-if="vista === 'semana'"
                class="relative mt-4 overflow-x-auto"
              >
                <SemanaHorario
                  :semana="semana"
                  :puede-gestionar="puedeGestionar"
                  @editar="editarDia"
                />
              </div>

              <div v-else class="mt-4 space-y-2">
                <div
                  v-for="d in DIAS"
                  :key="d"
                  class="rounded-xl border p-4"
                  :style="{ borderColor: 'var(--borde)' }"
                >
                  <div class="flex items-center justify-between gap-3">
                    <h3 class="font-medium">{{ $t(`horarios.dias.${d}`) }}</h3>
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
                    class="mt-2 flex flex-wrap items-center gap-2"
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
                      :aria-label="$t('horariosVisual.quitarFranja')"
                      @click="quitarFranja(d, i)"
                    >
                      <IconoNav nombre="cerrar" :tam="16" />
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Quién, sus próximos bloqueos y guardar -->
            <aside class="tu-card p-5 space-y-5">
              <div v-if="persona" class="flex items-center gap-3">
                <AvatarIniciales :nombre="persona.nombre" tam="lg" />
                <span class="min-w-0">
                  <span class="block truncate font-semibold">{{
                    persona.nombre
                  }}</span>
                  <span
                    class="block text-sm"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ sesion.terminologia.instructor }} ·
                    {{ sucursalActual?.nombre }}</span
                  >
                </span>
              </div>

              <div>
                <div class="flex items-center justify-between gap-2">
                  <h3 class="font-semibold">
                    {{ $t("horariosVisual.proximos") }}
                  </h3>
                  <button
                    type="button"
                    class="tu-enlace text-sm"
                    @click="irABloqueos"
                  >
                    {{ $t("horariosVisual.verTodos") }}
                  </button>
                </div>
                <p
                  v-if="bloqueosProximos.length === 0"
                  class="mt-2 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("horariosVisual.sinBloqueos") }}
                </p>
                <ul v-else class="mt-2 space-y-2">
                  <li
                    v-for="b in bloqueosProximos.slice(0, 3)"
                    :key="b.id"
                    class="flex items-start gap-3"
                  >
                    <span class="tu-icono-tono tu-tono-naranja">
                      <IconoNav nombre="agenda" :tam="16" />
                    </span>
                    <span class="min-w-0 text-sm">
                      <span class="block font-medium first-letter:uppercase">{{
                        fechaBloqueo(b).dia
                      }}</span>
                      <span
                        class="block"
                        :style="{ color: 'var(--texto-suave)' }"
                        >{{ fechaBloqueo(b).horas
                        }}<template v-if="b.motivo">
                          · {{ b.motivo }}</template
                        ></span
                      >
                    </span>
                  </li>
                </ul>
              </div>

              <div v-if="puedeGestionar" class="space-y-2">
                <button
                  class="tu-btn tu-btn-primario w-full"
                  type="button"
                  :disabled="
                    guardando ||
                    rangoInvalido ||
                    cargandoHorario ||
                    !horarioListo ||
                    !sinGuardar
                  "
                  @click="guardar"
                >
                  {{
                    guardando
                      ? $t("horarios.guardando")
                      : $t("horariosVisual.guardarCambios")
                  }}
                </button>
                <button
                  class="tu-btn tu-btn-fantasma w-full"
                  type="button"
                  :disabled="guardando || !sinGuardar"
                  @click="restablecer"
                >
                  {{ $t("horariosVisual.restablecer") }}
                </button>
                <p
                  v-if="rangoInvalido"
                  class="text-sm"
                  style="color: var(--error)"
                >
                  {{ $t("horarios.rangoInvalido") }}
                </p>
                <p
                  v-else-if="okGuardado"
                  class="text-sm"
                  :style="{ color: 'var(--exito)' }"
                >
                  {{ $t("horarios.guardado") }}
                </p>
              </div>
              <p
                v-else
                class="text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("horarios.soloLectura") }}
              </p>
            </aside>
          </div>
        </template>

        <!-- Bloqueos (2.2): comida, vacaciones, ausencias o cierre de la sede -->
        <BloqueosAgenda
          id="bloqueos"
          class="scroll-mt-24"
          :base="base"
          :proveedor-id="proveedorId"
          :proveedor-nombre="persona?.nombre ?? ''"
          :sucursal-id="sucursalId"
          :sucursal-nombre="sucursalActual?.nombre ?? ''"
          :zona="sucursalActual?.zona_horaria ?? 'America/Mexico_City'"
          :puede-gestionar="puedeGestionar"
          :puede-eliminar="sesion.puede('agenda.eliminar')"
          @cargados="bloqueos = $event"
        />

        <!-- Vista previa de huecos -->
        <div class="mt-8 tu-card p-6">
          <h2 class="font-semibold">
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

    <!-- Editar un día -->
    <ModalDialogo
      :abierto="diaEditando !== null"
      :titulo="
        diaEditando !== null
          ? $t('horariosVisual.editarDia', {
              dia: $t(`horarios.dias.${diaEditando}`).toLowerCase(),
            })
          : ''
      "
      @cerrar="cerrarDia"
    >
      <div v-if="diaEditando !== null" class="space-y-3">
        <p
          v-if="semana[diaEditando].length === 0"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("horarios.cerrado") }}
        </p>
        <div
          v-for="(f, i) in semana[diaEditando]"
          :key="i"
          class="flex flex-wrap items-center gap-2"
        >
          <label class="sr-only" :for="`m-ini-${i}`">{{
            $t("horarios.desde")
          }}</label>
          <input
            :id="`m-ini-${i}`"
            v-model="f.hora_inicio"
            type="time"
            class="tu-input w-auto"
            @change="okGuardado = false"
          />
          <span :style="{ color: 'var(--texto-suave)' }">–</span>
          <label class="sr-only" :for="`m-fin-${i}`">{{
            $t("horarios.hasta")
          }}</label>
          <input
            :id="`m-fin-${i}`"
            v-model="f.hora_fin"
            type="time"
            class="tu-input w-auto"
            :style="
              f.hora_fin !== '' && f.hora_fin <= f.hora_inicio
                ? { borderColor: 'var(--error)' }
                : {}
            "
            @change="okGuardado = false"
          />
          <button
            type="button"
            class="tu-icono-btn"
            :aria-label="$t('horariosVisual.quitarFranja')"
            @click="quitarFranja(diaEditando, i)"
          >
            <IconoNav nombre="cerrar" :tam="16" />
          </button>
        </div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 pt-1">
          <button
            type="button"
            class="tu-enlace text-sm"
            @click="agregarFranja(diaEditando)"
          >
            {{ $t("horarios.agregarFranja") }}
          </button>
          <button
            v-if="semana[diaEditando].length > 0"
            type="button"
            class="tu-enlace text-sm"
            @click="copiarASemana(diaEditando)"
          >
            {{ $t("horarios.copiar") }}
          </button>
        </div>
        <div class="flex justify-end pt-2">
          <button
            type="button"
            class="tu-btn tu-btn-primario"
            @click="cerrarDia"
          >
            {{ $t("horariosVisual.listo") }}
          </button>
        </div>
      </div>
    </ModalDialogo>

    <!-- Copiar el horario de otra persona (en esta sucursal) -->
    <ModalDialogo
      :abierto="copiando"
      :titulo="$t('horariosVisual.copiarTitulo')"
      @cerrar="copiando = false"
    >
      <form class="space-y-3" @submit.prevent="copiarHorario">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("horariosVisual.copiarAyuda") }}
        </p>
        <label class="block">
          <span class="tu-label">{{ $t("horariosVisual.copiarDe") }}</span>
          <select v-model="copiarDe" class="tu-input" required>
            <option value="" disabled>
              {{ $t("horarios.elegirProveedor") }}
            </option>
            <option v-for="p in otrasPersonas" :key="p.id" :value="p.id">
              {{ p.nombre }}
            </option>
          </select>
        </label>
        <p
          v-if="avisoCopia"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ avisoCopia }}
        </p>
        <div class="flex justify-end gap-2 pt-1">
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            @click="copiando = false"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            type="submit"
            class="tu-btn tu-btn-primario"
            :disabled="copiarDe === '' || copiandoCarga"
          >
            {{ $t("horariosVisual.copiarAplicar") }}
          </button>
        </div>
      </form>
    </ModalDialogo>
  </section>
</template>

<style scoped>
.hv-filtros {
  display: grid;
  gap: 0.75rem;
  align-items: end;
}
@media (min-width: 768px) {
  .hv-filtros {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .hv-acciones {
    grid-column: 1 / -1;
  }
}
@media (min-width: 1280px) {
  .hv-filtros {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
  }
  .hv-acciones {
    grid-column: auto;
  }
}
.hv-acciones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.hv-principal {
  display: grid;
  gap: 1.25rem;
}
@media (min-width: 1280px) {
  .hv-principal {
    grid-template-columns: minmax(0, 1fr) 19rem;
    align-items: start;
  }
}
</style>
