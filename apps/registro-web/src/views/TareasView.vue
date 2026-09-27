<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const { t } = useI18n();

// Los marcadores van como parámetros: `{{ }}` en la plantilla sería interpolación.
const ayudaPlantilla = computed(() =>
  t("tareas.reglas.ayudaPlantilla", {
    a: "{{persona_nombre}}",
    b: "{{estado}}",
  }),
);

interface Tarea {
  id: string;
  titulo: string;
  detalle: string | null;
  estado: string;
  vence_en: string | null;
  persona: string | null;
  responsable: string | null;
  automatica: boolean;
  completada_en: string | null;
}
interface Regla {
  id: string;
  nombre: string;
  evento: string;
  condiciones: Record<string, string>;
  titulo_plantilla: string;
  detalle_plantilla: string | null;
  delay_minutos: number;
  activa: boolean;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeReglas = computed(() => sesion.puede("automatizaciones.gestionar"));

const tab = ref<"pendientes" | "reglas">("pendientes");
const error = ref<string | null>(null);

// ---- Tareas ----
const tareas = ref<Tarea[]>([]);
const pendientes = ref(0);
const estadoFiltro = ref<"pendiente" | "completada">("pendiente");
const cargandoTareas = ref(true);
const accionando = ref(false);
const mostrarNueva = ref(false);
const nueva = ref({ titulo: "", detalle: "", vence_en: "" });

// ---- Reglas ----
const reglas = ref<Regla[]>([]);
const eventos = ref<string[]>([]);
const cargandoReglas = ref(false);
const mostrarRegla = ref(false);
const editandoId = ref<string | null>(null);
const regla = ref({
  nombre: "",
  evento: "reserva.creada",
  condicionCampo: "",
  condicionValor: "",
  titulo_plantilla: "",
  detalle_plantilla: "",
  delay_minutos: 0,
  activa: true,
});

function etiquetaEvento(ev: string): string {
  return t(`tareas.eventos.${ev.replace(/\./g, "_")}`);
}

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Date(iso).toLocaleString("es-MX", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}

async function cargarTareas(): Promise<void> {
  cargandoTareas.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Tarea[]; pendientes: number }>(
      `${base.value}/tareas`,
      {
        params: { estado: estadoFiltro.value },
      },
    );
    tareas.value = data.data;
    pendientes.value = data.pendientes;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargandoTareas.value = false;
  }
}

async function crearTarea(): Promise<void> {
  if (nueva.value.titulo.trim() === "") {
    return;
  }
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/tareas`, {
      titulo: nueva.value.titulo,
      detalle: nueva.value.detalle !== "" ? nueva.value.detalle : null,
      vence_en: nueva.value.vence_en !== "" ? nueva.value.vence_en : null,
    });
    nueva.value = { titulo: "", detalle: "", vence_en: "" };
    mostrarNueva.value = false;
    await cargarTareas();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function completar(t: Tarea): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/tareas/${t.id}/completar`, {});
    await cargarTareas();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function reabrir(t: Tarea): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/tareas/${t.id}/reabrir`, {});
    await cargarTareas();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function cargarReglas(): Promise<void> {
  cargandoReglas.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: Regla[];
      catalogo: { eventos: string[] };
    }>(`${base.value}/automatizaciones`);
    reglas.value = data.data;
    eventos.value = data.catalogo.eventos;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargandoReglas.value = false;
  }
}

function nuevaRegla(): void {
  editandoId.value = null;
  regla.value = {
    nombre: "",
    evento: eventos.value[0] ?? "reserva.creada",
    condicionCampo: "",
    condicionValor: "",
    titulo_plantilla: "",
    detalle_plantilla: "",
    delay_minutos: 0,
    activa: true,
  };
  mostrarRegla.value = true;
}

function editarRegla(r: Regla): void {
  editandoId.value = r.id;
  const campo = Object.keys(r.condiciones)[0] ?? "";
  regla.value = {
    nombre: r.nombre,
    evento: r.evento,
    condicionCampo: campo,
    condicionValor: campo !== "" ? r.condiciones[campo] : "",
    titulo_plantilla: r.titulo_plantilla,
    detalle_plantilla: r.detalle_plantilla ?? "",
    delay_minutos: r.delay_minutos,
    activa: r.activa,
  };
  mostrarRegla.value = true;
}

async function guardarRegla(): Promise<void> {
  if (
    regla.value.nombre.trim() === "" ||
    regla.value.titulo_plantilla.trim() === ""
  ) {
    return;
  }
  accionando.value = true;
  error.value = null;
  const condiciones: Record<string, string> = {};
  if (
    regla.value.condicionCampo.trim() !== "" &&
    regla.value.condicionValor.trim() !== ""
  ) {
    condiciones[regla.value.condicionCampo.trim()] =
      regla.value.condicionValor.trim();
  }
  const carga = {
    nombre: regla.value.nombre,
    evento: regla.value.evento,
    condiciones,
    titulo_plantilla: regla.value.titulo_plantilla,
    detalle_plantilla:
      regla.value.detalle_plantilla !== ""
        ? regla.value.detalle_plantilla
        : null,
    delay_minutos: Number(regla.value.delay_minutos) || 0,
    activa: regla.value.activa,
  };
  try {
    if (editandoId.value !== null) {
      await api.put(
        `${base.value}/automatizaciones/${editandoId.value}`,
        carga,
      );
    } else {
      await api.post(`${base.value}/automatizaciones`, carga);
    }
    mostrarRegla.value = false;
    await cargarReglas();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function alternarActiva(r: Regla): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.put(`${base.value}/automatizaciones/${r.id}`, {
      nombre: r.nombre,
      evento: r.evento,
      condiciones: r.condiciones,
      titulo_plantilla: r.titulo_plantilla,
      detalle_plantilla: r.detalle_plantilla,
      delay_minutos: r.delay_minutos,
      activa: !r.activa,
    });
    await cargarReglas();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function eliminarRegla(r: Regla): Promise<void> {
  if (
    !(await confirmar(t("tareas.reglas.confirmarEliminar"), { peligro: true }))
  ) {
    return;
  }
  accionando.value = true;
  error.value = null;
  try {
    await api.delete(`${base.value}/automatizaciones/${r.id}`);
    await cargarReglas();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

function cambiarTab(t: "pendientes" | "reglas"): void {
  tab.value = t;
  if (t === "reglas" && reglas.value.length === 0) {
    void cargarReglas();
  }
}

function alternarEstado(): void {
  estadoFiltro.value =
    estadoFiltro.value === "pendiente" ? "completada" : "pendiente";
  void cargarTareas();
}

onMounted(cargarTareas);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('tareas.titulo')" />

    <!-- Tabs -->
    <div
      class="mt-6 flex gap-1 border-b"
      :style="{ borderColor: 'var(--borde)' }"
    >
      <button
        class="px-4 py-2 text-sm font-medium -mb-px border-b-2 transition"
        :style="{
          borderColor: tab === 'pendientes' ? 'var(--primario)' : 'transparent',
          color:
            tab === 'pendientes' ? 'var(--primario)' : 'var(--texto-suave)',
        }"
        @click="cambiarTab('pendientes')"
      >
        {{ $t("tareas.tabPendientes")
        }}<span v-if="pendientes > 0" class="tu-badge ml-2">{{
          pendientes
        }}</span>
      </button>
      <button
        v-if="puedeReglas"
        class="px-4 py-2 text-sm font-medium -mb-px border-b-2 transition"
        :style="{
          borderColor: tab === 'reglas' ? 'var(--primario)' : 'transparent',
          color: tab === 'reglas' ? 'var(--primario)' : 'var(--texto-suave)',
        }"
        @click="cambiarTab('reglas')"
      >
        {{ $t("tareas.tabReglas") }}
      </button>
    </div>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <!-- ===== Pestaña: Pendientes ===== -->
    <div v-if="tab === 'pendientes'" class="mt-6">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <button class="tu-enlace text-sm" type="button" @click="alternarEstado">
          {{
            estadoFiltro === "pendiente"
              ? $t("tareas.verCompletadas")
              : $t("tareas.verPendientes")
          }}
        </button>
        <button
          class="tu-btn tu-btn-primario"
          type="button"
          @click="mostrarNueva = true"
        >
          {{ $t("tareas.nueva") }}
        </button>
      </div>

      <!-- Nueva tarea (drawer lateral) -->
      <PanelLateral
        :abierto="mostrarNueva"
        :titulo="$t('tareas.nueva')"
        @cerrar="mostrarNueva = false"
      >
        <form class="grid gap-4" @submit.prevent="crearTarea">
          <div>
            <label class="tu-label" for="t-titulo">{{
              $t("tareas.campos.titulo")
            }}</label>
            <input
              id="t-titulo"
              v-model="nueva.titulo"
              class="tu-input"
              required
            />
          </div>
          <div>
            <label class="tu-label" for="t-detalle">{{
              $t("tareas.campos.detalle")
            }}</label>
            <input id="t-detalle" v-model="nueva.detalle" class="tu-input" />
          </div>
          <div>
            <label class="tu-label" for="t-vence">{{
              $t("tareas.campos.vence")
            }}</label>
            <input
              id="t-vence"
              v-model="nueva.vence_en"
              type="date"
              class="tu-input"
            />
          </div>
        </form>

        <template #pie>
          <div class="flex justify-end gap-2">
            <button
              class="tu-btn tu-btn-fantasma"
              type="button"
              @click="mostrarNueva = false"
            >
              {{ $t("tareas.cancelar") }}
            </button>
            <button
              class="tu-btn tu-btn-primario"
              type="button"
              :disabled="accionando || nueva.titulo.trim() === ''"
              @click="crearTarea"
            >
              {{ $t("tareas.guardar") }}
            </button>
          </div>
        </template>
      </PanelLateral>

      <p
        v-if="cargandoTareas"
        class="mt-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>
      <p
        v-else-if="tareas.length === 0"
        class="mt-10 text-center text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{
          estadoFiltro === "pendiente"
            ? $t("tareas.sinPendientes")
            : $t("tareas.sinCompletadas")
        }}
      </p>
      <ul v-else class="mt-4 space-y-2">
        <li
          v-for="tarea in tareas"
          :key="tarea.id"
          class="tu-card p-3 flex items-start justify-between gap-3"
        >
          <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span
                class="font-semibold"
                :class="{
                  'line-through opacity-60': tarea.estado === 'completada',
                }"
                >{{ tarea.titulo }}</span
              >
              <span v-if="tarea.automatica" class="tu-badge">{{
                $t("tareas.automatica")
              }}</span>
            </div>
            <p
              v-if="tarea.detalle"
              class="text-sm mt-1"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ tarea.detalle }}
            </p>
            <div
              class="flex items-center gap-1.5 mt-1 text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              <span v-if="tarea.persona">{{ tarea.persona }}</span>
              <span v-if="tarea.persona" aria-hidden="true">·</span>
              <span>{{
                tarea.vence_en
                  ? fecha(tarea.vence_en)
                  : $t("tareas.sinVencimiento")
              }}</span>
            </div>
          </div>
          <button
            v-if="tarea.estado === 'pendiente'"
            class="tu-btn tu-btn-fantasma shrink-0"
            type="button"
            :disabled="accionando"
            @click="completar(tarea)"
          >
            {{ $t("tareas.completar") }}
          </button>
          <button
            v-else
            class="tu-enlace shrink-0"
            type="button"
            :disabled="accionando"
            @click="reabrir(tarea)"
          >
            {{ $t("tareas.reabrir") }}
          </button>
        </li>
      </ul>
    </div>

    <!-- ===== Pestaña: Reglas ===== -->
    <div v-else-if="tab === 'reglas' && puedeReglas" class="mt-6">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("tareas.reglas.subtitulo") }}
        </p>
        <button
          class="tu-btn tu-btn-primario"
          type="button"
          @click="nuevaRegla"
        >
          {{ $t("tareas.reglas.nueva") }}
        </button>
      </div>

      <!-- Nueva / editar regla (drawer lateral) -->
      <PanelLateral
        :abierto="mostrarRegla"
        :titulo="
          editandoId !== null
            ? $t('tareas.reglas.editar')
            : $t('tareas.reglas.nueva')
        "
        @cerrar="mostrarRegla = false"
      >
        <form class="grid gap-4" @submit.prevent="guardarRegla">
          <div>
            <label class="tu-label" for="r-nombre">{{
              $t("tareas.reglas.nombre")
            }}</label>
            <input
              id="r-nombre"
              v-model="regla.nombre"
              class="tu-input"
              required
            />
          </div>
          <div>
            <label class="tu-label" for="r-evento">{{
              $t("tareas.reglas.evento")
            }}</label>
            <select id="r-evento" v-model="regla.evento" class="tu-input">
              <option v-for="ev in eventos" :key="ev" :value="ev">
                {{ etiquetaEvento(ev) }}
              </option>
            </select>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="tu-label" for="r-ccampo">{{
                $t("tareas.reglas.condicionCampo")
              }}</label>
              <input
                id="r-ccampo"
                v-model="regla.condicionCampo"
                class="tu-input"
                placeholder="estado"
              />
            </div>
            <div>
              <label class="tu-label" for="r-cvalor">{{
                $t("tareas.reglas.condicionValor")
              }}</label>
              <input
                id="r-cvalor"
                v-model="regla.condicionValor"
                class="tu-input"
                placeholder="confirmada"
              />
            </div>
          </div>
          <div>
            <label class="tu-label" for="r-titulo">{{
              $t("tareas.reglas.tituloTarea")
            }}</label>
            <input
              id="r-titulo"
              v-model="regla.titulo_plantilla"
              class="tu-input"
              required
            />
            <p class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ ayudaPlantilla }}
            </p>
          </div>
          <div>
            <label class="tu-label" for="r-detalle">{{
              $t("tareas.reglas.detalleTarea")
            }}</label>
            <input
              id="r-detalle"
              v-model="regla.detalle_plantilla"
              class="tu-input"
            />
          </div>
          <div>
            <label class="tu-label" for="r-delay">{{
              $t("tareas.reglas.retraso")
            }}</label>
            <input
              id="r-delay"
              v-model.number="regla.delay_minutos"
              type="number"
              min="0"
              class="tu-input"
            />
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="regla.activa" type="checkbox" />
            {{ $t("tareas.reglas.activa") }}
          </label>
        </form>

        <template #pie>
          <div class="flex justify-end gap-2">
            <button
              class="tu-btn tu-btn-fantasma"
              type="button"
              @click="mostrarRegla = false"
            >
              {{ $t("tareas.cancelar") }}
            </button>
            <button
              class="tu-btn tu-btn-primario"
              type="button"
              :disabled="accionando"
              @click="guardarRegla"
            >
              {{ $t("tareas.reglas.guardar") }}
            </button>
          </div>
        </template>
      </PanelLateral>

      <p
        v-if="cargandoReglas"
        class="mt-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>
      <p
        v-else-if="reglas.length === 0"
        class="mt-10 text-center text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("tareas.reglas.sinReglas") }}
      </p>
      <ul v-else class="mt-4 space-y-2">
        <li v-for="r in reglas" :key="r.id" class="tu-card p-3">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-semibold">{{ r.nombre }}</span>
                <span
                  class="tu-badge"
                  :class="r.activa ? 'tu-badge-exito' : ''"
                  >{{
                    r.activa
                      ? $t("tareas.reglas.activa")
                      : $t("tareas.reglas.inactiva")
                  }}</span
                >
              </div>
              <p class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
                {{ etiquetaEvento(r.evento) }} → {{ r.titulo_plantilla }}
              </p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <button
                class="tu-enlace"
                type="button"
                :disabled="accionando"
                @click="alternarActiva(r)"
              >
                {{
                  r.activa
                    ? $t("tareas.reglas.inactiva")
                    : $t("tareas.reglas.activa")
                }}
              </button>
              <button
                class="tu-enlace"
                type="button"
                :disabled="accionando"
                @click="editarRegla(r)"
              >
                {{ $t("tareas.reglas.editar") }}
              </button>
              <button
                class="tu-enlace"
                style="color: var(--error)"
                type="button"
                :disabled="accionando"
                @click="eliminarRegla(r)"
              >
                {{ $t("tareas.reglas.eliminar") }}
              </button>
            </div>
          </div>
        </li>
      </ul>
    </div>
  </section>
</template>
