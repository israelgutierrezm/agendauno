<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Constructor de formularios dinámicos y sus respuestas. Se llenan desde el
 * expediente de cada persona; aquí se diseñan (nombre, preguntas, orden, vista
 * previa, archivar) y se consultan todas juntas. Con respuestas, cambiar sus
 * preguntas crea otra versión y cada respuesta se lee con las suyas (ADR 0150).
 */
const { t } = useI18n();

interface Campo {
  id: string;
  etiqueta: string;
  tipo: string;
  obligatorio: boolean;
  opciones: string[] | null;
  orden: number;
}
interface Formulario {
  id: string;
  nombre: string;
  descripcion: string | null;
  aplica_a: string;
  activo: boolean;
  campos: Campo[];
  version?: number;
  archivado?: boolean;
  respuestas?: number;
}
// Una pregunta como se guardó en la respuesta (la de entonces).
interface CampoRespondido {
  id: string;
  etiqueta: string;
  tipo: string;
  opciones: string[] | null;
}
interface CampoEditable {
  id?: string;
  etiqueta: string;
  tipo: string;
  obligatorio: boolean;
  opciones: string[] | null;
}
type Valor = string | number | boolean | null;
interface Respuesta {
  id: string;
  persona: string | null;
  persona_id: string | null;
  persona_tipo: "miembro" | "instructor" | null;
  usuario_id: string | null;
  respondido_en: string | null;
  valores: Record<string, Valor>;
  version?: number;
  campos?: CampoRespondido[] | null;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("formularios.gestionar"));
// Dos vistas de la misma pantalla (`?vista=`): el diseño de los formularios
// (Configuración › Documentos y privacidad) y la revisión de sus respuestas
// (Alumnos o Clientes › Respuestas de formularios).
const route = useRoute();
const soloRespuestas = computed(() => route.query.vista === "respuestas");

const formularios = ref<Formulario[]>([]);
const seleccionadoId = ref<string | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

const nuevo = ref({ nombre: "", aplica_a: "miembro" });
const creando = ref(false);
const nuevaFicha = ref(false);
const nuevaPregunta = ref(false);

const nuevoCampo = ref({
  etiqueta: "",
  tipo: "texto",
  obligatorio: false,
  opciones: "",
});
const agregandoCampo = ref(false);

const respuestas = ref<Respuesta[]>([]);
const versionActual = ref<number | null>(null);
// Las fichas archivadas aparte (se restauran desde ahí).
const verArchivadas = ref(false);
// Por páginas (antes, solo las últimas 200) y buscando por la persona.
const buscarRespuestas = ref("");
const paginaRespuestas = ref(1);
const metaRespuestas = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
} | null>(null);
const cargandoRespuestas = ref(false);
const abierta = ref<string | null>(null);

const seleccionado = computed(
  () => formularios.value.find((f) => f.id === seleccionadoId.value) ?? null,
);
function cantidadCampos(n: number): string {
  return t("adminUsabilidad.fichas.campos", { n }, n);
}

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(iso));
}

function valor(c: Pick<Campo, "tipo">, v: Valor | undefined): string {
  if (v === undefined || v === null || v === "") {
    return t("expediente.sinValor");
  }
  if (c.tipo === "booleano") {
    return v === true ? t("expediente.si") : t("expediente.no");
  }
  if (c.tipo === "fecha" && typeof v === "string") {
    return fecha(`${v}T12:00:00`);
  }
  return String(v);
}

/** El expediente de la persona: la ficha del alumno o la del profesional. */
function expediente(r: Respuesta): string | null {
  if (r.persona_tipo === "miembro" && r.persona_id !== null) {
    return `/miembros/${r.persona_id}?seccion=expediente`;
  }
  if (r.usuario_id !== null) {
    return `/instructores/${r.usuario_id}?seccion=expediente`;
  }
  return null;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const url = `${base.value}/formularios`;
    const f = verArchivadas.value
      ? await api.get<{ data: Formulario[] }>(url, {
          params: { archivados: 1 },
        })
      : await api.get<{ data: Formulario[] }>(url);
    formularios.value = f.data.data;
    if (
      soloRespuestas.value &&
      seleccionadoId.value === null &&
      formularios.value.length > 0
    ) {
      seleccionadoId.value = formularios.value[0].id;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function crearFormulario(): Promise<void> {
  if (!puedeGestionar.value || creando.value || !nuevo.value.nombre.trim())
    return;
  creando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: Formulario }>(
      `${base.value}/formularios`,
      {
        nombre: nuevo.value.nombre,
        aplica_a: nuevo.value.aplica_a,
      },
    );
    nuevo.value = { nombre: "", aplica_a: "miembro" };
    nuevaFicha.value = false;
    await cargar();
    seleccionadoId.value = data.data.id;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    creando.value = false;
  }
}

// Una pregunta de opción necesita al menos dos opciones (si no, nadie podría enviarla).
const opcionesNuevas = computed(() =>
  nuevoCampo.value.opciones
    .split(",")
    .map((o) => o.trim())
    .filter((o) => o !== ""),
);
const faltanOpciones = computed(
  () =>
    nuevoCampo.value.tipo === "seleccion" && opcionesNuevas.value.length < 2,
);

async function agregarCampo(): Promise<void> {
  if (
    seleccionado.value === null ||
    !puedeGestionar.value ||
    agregandoCampo.value
  ) {
    return;
  }
  agregandoCampo.value = true;
  error.value = null;
  try {
    const opciones =
      nuevoCampo.value.tipo === "seleccion" ? opcionesNuevas.value : null;
    // Editar una pregunta guarda la ficha completa; una nueva se agrega al final.
    if (editandoPregunta.value !== null) {
      const id = editandoPregunta.value;
      const ok = await guardarFicha(
        actuales().map((c) =>
          c.id === id
            ? {
                id,
                etiqueta: nuevoCampo.value.etiqueta.trim(),
                tipo: nuevoCampo.value.tipo,
                obligatorio: nuevoCampo.value.obligatorio,
                opciones,
              }
            : c,
        ),
      );
      if (ok) {
        editandoPregunta.value = null;
        nuevaPregunta.value = false;
      }
      return;
    }
    await api.post(
      `${base.value}/formularios/${seleccionado.value.id}/campos`,
      {
        etiqueta: nuevoCampo.value.etiqueta,
        tipo: nuevoCampo.value.tipo,
        obligatorio: nuevoCampo.value.obligatorio,
        opciones,
        orden: seleccionado.value.campos.length,
      },
    );
    nuevoCampo.value = {
      etiqueta: "",
      tipo: "texto",
      obligatorio: false,
      opciones: "",
    };
    nuevaPregunta.value = false;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    agregandoCampo.value = false;
  }
}

async function cargarRespuestas(): Promise<void> {
  respuestas.value = [];
  metaRespuestas.value = null;
  abierta.value = null;
  // Solo en la vista de respuestas: el diseño no las consulta.
  if (
    !soloRespuestas.value ||
    !puedeGestionar.value ||
    seleccionadoId.value === null
  ) {
    return;
  }
  cargandoRespuestas.value = true;
  try {
    const { data } = await api.get<{
      data: Respuesta[];
      meta?: typeof metaRespuestas.value;
    }>(`${base.value}/formularios/${seleccionadoId.value}/respuestas`, {
      params: {
        page: paginaRespuestas.value,
        ...(buscarRespuestas.value.trim() !== ""
          ? { q: buscarRespuestas.value.trim() }
          : {}),
      },
    });
    respuestas.value = data.data;
    metaRespuestas.value = data.meta ?? null;
    versionActual.value =
      (data.meta as { version_actual?: number } | undefined)?.version_actual ??
      null;
  } catch (e) {
    error.value = mensajeDeError(e, t("formulariosRespuestas.cargarError"));
  } finally {
    cargandoRespuestas.value = false;
  }
}
watch([seleccionadoId, soloRespuestas], () => {
  paginaRespuestas.value = 1;
  buscarRespuestas.value = "";
  void cargarRespuestas();
});
function irPaginaRespuestas(n: number): void {
  paginaRespuestas.value = n;
  void cargarRespuestas();
}
let esperaRespuestas: ReturnType<typeof setTimeout> | undefined;
function alBuscarRespuestas(): void {
  clearTimeout(esperaRespuestas);
  esperaRespuestas = setTimeout(() => {
    paginaRespuestas.value = 1;
    void cargarRespuestas();
  }, 300);
}
watch(soloRespuestas, (respuestas) => {
  seleccionadoId.value = respuestas
    ? (seleccionadoId.value ?? formularios.value[0]?.id ?? null)
    : null;
  nuevaPregunta.value = false;
});

// ---- Edición de la ficha (ADR 0150) ----
const guardandoFicha = ref(false);
const editandoPregunta = ref<string | null>(null);
const editandoDatos = ref(false);
const datosFicha = ref({ nombre: "", descripcion: "", aplica_a: "miembro" });
const vistaPrevia = ref(false);
const conRespuestas = computed(() => (seleccionado.value?.respuestas ?? 0) > 0);
function actuales(): CampoEditable[] {
  return (seleccionado.value?.campos ?? []).map((c) => ({
    id: c.id,
    etiqueta: c.etiqueta,
    tipo: c.tipo,
    obligatorio: c.obligatorio,
    opciones: c.opciones,
  }));
}
/** Guarda la ficha completa (datos y preguntas en orden) y la actualiza aquí. */
async function guardarFicha(
  campos: CampoEditable[],
  datos: Partial<Pick<Formulario, "nombre" | "descripcion" | "aplica_a">> = {},
): Promise<boolean> {
  const f = seleccionado.value;
  if (f === null || guardandoFicha.value) {
    return false;
  }
  guardandoFicha.value = true;
  error.value = null;
  try {
    const { data } = await api.put<{ data: Formulario }>(
      `${base.value}/formularios/${f.id}`,
      {
        nombre: datos.nombre ?? f.nombre,
        descripcion:
          datos.descripcion !== undefined ? datos.descripcion : f.descripcion,
        aplica_a: datos.aplica_a ?? f.aplica_a,
        campos: campos.map((c) => ({
          id: c.id,
          etiqueta: c.etiqueta,
          tipo: c.tipo,
          obligatorio: c.obligatorio,
          opciones: c.tipo === "seleccion" ? c.opciones : null,
        })),
      },
    );
    const i = formularios.value.findIndex((x) => x.id === f.id);
    if (i >= 0) {
      formularios.value[i] = data.data;
    }
    return true;
  } catch (e) {
    error.value = mensajeDeError(e);
    return false;
  } finally {
    guardandoFicha.value = false;
  }
}
function mover(i: number, delta: number): void {
  const lista = actuales();
  const j = i + delta;
  if (j < 0 || j >= lista.length) {
    return;
  }
  [lista[i], lista[j]] = [lista[j]!, lista[i]!];
  void guardarFicha(lista);
}
async function quitarPregunta(c: Campo): Promise<void> {
  const aviso = conRespuestas.value
    ? t("operacion.fichas.quitarConRespuestas", { pregunta: c.etiqueta })
    : t("operacion.fichas.quitarConfirmar", { pregunta: c.etiqueta });
  if (!(await confirmar(aviso, { peligro: true }))) {
    return;
  }
  void guardarFicha(actuales().filter((x) => x.id !== c.id));
}
function editarPregunta(c: Campo): void {
  error.value = null;
  editandoPregunta.value = c.id;
  nuevoCampo.value = {
    etiqueta: c.etiqueta,
    tipo: c.tipo,
    obligatorio: c.obligatorio,
    opciones: (c.opciones ?? []).join(", "),
  };
  nuevaPregunta.value = true;
}
function nuevaPreguntaVacia(): void {
  error.value = null;
  editandoPregunta.value = null;
  nuevoCampo.value = {
    etiqueta: "",
    tipo: "texto",
    obligatorio: false,
    opciones: "",
  };
  nuevaPregunta.value = true;
}
// Con respuestas, cambiar el tipo de una pregunta crea otra (la anterior se conserva).
const cambiaTipo = computed(() => {
  const c = seleccionado.value?.campos.find(
    (x) => x.id === editandoPregunta.value,
  );
  return (
    conRespuestas.value && c !== undefined && c.tipo !== nuevoCampo.value.tipo
  );
});
function abrirDatos(): void {
  const f = seleccionado.value;
  if (f === null) {
    return;
  }
  error.value = null;
  datosFicha.value = {
    nombre: f.nombre,
    descripcion: f.descripcion ?? "",
    aplica_a: f.aplica_a,
  };
  editandoDatos.value = true;
}
async function guardarDatos(): Promise<void> {
  const ok = await guardarFicha(actuales(), {
    nombre: datosFicha.value.nombre.trim(),
    descripcion: datosFicha.value.descripcion.trim() || null,
    aplica_a: datosFicha.value.aplica_a,
  });
  if (ok) {
    editandoDatos.value = false;
  }
}
// Archivar: deja de pedirse y sus respuestas se conservan; se restaura después.
async function archivar(f: Formulario): Promise<void> {
  if (
    !(await confirmar(
      t("operacion.fichas.archivarConfirmar", { nombre: f.nombre }),
      { aceptar: t("operacion.fichas.archivar") },
    ))
  ) {
    return;
  }
  try {
    await api.post(`${base.value}/formularios/${f.id}/archivar`);
    seleccionadoId.value = null;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}
async function restaurar(f: Formulario): Promise<void> {
  try {
    await api.post(`${base.value}/formularios/${f.id}/restaurar`);
    verArchivadas.value = false;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}
watch(verArchivadas, () => {
  seleccionadoId.value = null;
  void cargar();
});
// Cada respuesta con las preguntas con que se contestó (las de antes de las
// versiones, con las vigentes).
function camposDe(r: Respuesta): CampoRespondido[] {
  return r.campos && r.campos.length > 0
    ? r.campos
    : (seleccionado.value?.campos ?? []);
}

function gestionar(f: Formulario): void {
  seleccionadoId.value = f.id;
  nuevoCampo.value = {
    etiqueta: "",
    tipo: "texto",
    obligatorio: false,
    opciones: "",
  };
  error.value = null;
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      :titulo="
        soloRespuestas
          ? $t('nav.vistas.respuestas')
          : $t('adminUsabilidad.fichas.titulo')
      "
      :subtitulo="
        !soloRespuestas && !seleccionado
          ? $t('adminUsabilidad.fichas.listadoAyuda')
          : undefined
      "
      icono="formularios"
    >
      <template v-if="puedeGestionar" #acciones>
        <button
          v-if="!soloRespuestas"
          class="tu-btn tu-btn-primario tu-btn-crear"
          type="button"
          data-prueba="nueva-ficha"
          @click="
            error = null;
            nuevaFicha = true;
          "
        >
          {{ $t("formularios.nuevo") }}
        </button>
        <!-- Misma ruta, otra vista: el router las daría por «página actual». -->
        <RouterLink
          v-if="soloRespuestas"
          :to="{ name: 'formularios' }"
          aria-current-value="false"
          class="tu-btn tu-btn-fantasma"
          >{{ $t("formularios.disenar") }}</RouterLink
        >
        <RouterLink
          v-else
          :to="{ name: 'formularios', query: { vista: 'respuestas' } }"
          aria-current-value="false"
          class="tu-btn tu-btn-fantasma"
          >{{ $t("formularios.verRespuestas") }}</RouterLink
        >
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <div
      v-if="!cargando"
      class="mt-6 grid gap-6 grid-cols-[minmax(0,1fr)]"
      :class="{ 'md:grid-cols-[240px_minmax(0,1fr)]': soloRespuestas }"
    >
      <!-- Lista + nuevo -->
      <div :class="soloRespuestas ? 'tu-card p-4 h-max' : ''">
        <label
          v-if="puedeGestionar && !soloRespuestas && !seleccionado"
          class="mb-4 inline-flex items-center gap-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          <input
            v-model="verArchivadas"
            type="checkbox"
            data-prueba="ver-archivadas"
          />
          {{ $t("operacion.fichas.verArchivadas") }}
        </label>
        <EstadoVacio
          v-if="formularios.length === 0"
          class="py-6"
          icono="formularios"
          compacto
          :titulo="
            verArchivadas
              ? $t('operacion.fichas.sinArchivadas')
              : $t('formularios.vacio')
          "
        />
        <ul v-else-if="soloRespuestas" class="space-y-1">
          <li v-for="f in formularios" :key="f.id">
            <button
              type="button"
              class="w-full text-left rounded-lg px-3 py-2 text-sm font-medium"
              :style="{
                background:
                  f.id === seleccionadoId
                    ? 'var(--primario-suave)'
                    : 'transparent',
                color:
                  f.id === seleccionadoId
                    ? 'var(--primario-fuerte)'
                    : 'var(--texto)',
              }"
              :aria-pressed="f.id === seleccionadoId"
              @click="seleccionadoId = f.id"
            >
              {{ f.nombre }}
            </button>
          </li>
        </ul>

        <ul v-else-if="!seleccionado" class="ff-listado">
          <li
            v-for="f in formularios"
            :key="f.id"
            class="tu-card p-5 flex flex-col gap-4"
          >
            <div class="flex items-start gap-3">
              <span class="ff-icono" aria-hidden="true"
                ><IconoNav nombre="formularios" :tam="22"
              /></span>
              <div class="min-w-0 flex-1">
                <h2 class="font-semibold break-words">{{ f.nombre }}</h2>
                <p
                  v-if="f.descripcion"
                  class="mt-1 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ f.descripcion }}
                </p>
              </div>
              <span
                class="tu-badge"
                :class="f.activo ? 'tu-badge-exito' : ''"
                >{{
                  f.archivado
                    ? $t("operacion.fichas.archivada")
                    : $t(
                        f.activo
                          ? "adminUsabilidad.fichas.activa"
                          : "adminUsabilidad.fichas.inactiva",
                      )
                }}</span
              >
            </div>
            <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`formularios.${f.aplica_a}`) }} ·
              {{ cantidadCampos(f.campos.length) }}
            </p>
            <button
              v-if="f.archivado"
              type="button"
              class="tu-btn tu-btn-contorno tu-tono-neutral self-start mt-auto"
              :data-prueba="`restaurar-${f.id}`"
              @click="restaurar(f)"
            >
              {{ $t("operacion.fichas.restaurar") }}
            </button>
            <button
              v-else
              type="button"
              class="tu-btn tu-btn-fantasma self-start mt-auto"
              :aria-label="`${$t(puedeGestionar ? 'adminUsabilidad.fichas.gestionar' : 'adminUsabilidad.fichas.consultar')}: ${f.nombre}`"
              @click="gestionar(f)"
            >
              <IconoNav
                :nombre="puedeGestionar ? 'editar' : 'formularios'"
                :tam="16"
              />
              {{
                $t(
                  puedeGestionar
                    ? "adminUsabilidad.fichas.gestionar"
                    : "adminUsabilidad.fichas.consultar",
                )
              }}
            </button>
          </li>
        </ul>

        <ModalDialogo
          v-if="puedeGestionar && !soloRespuestas"
          :abierto="nuevaFicha"
          :titulo="$t('formularios.nuevo')"
          :descripcion="$t('adminUsabilidad.fichas.ayuda')"
          icono="formularios"
          tam="md"
          :ocupado="creando"
          @cerrar="nuevaFicha = false"
        >
          <form
            v-if="puedeGestionar && !soloRespuestas"
            class="space-y-4"
            @submit.prevent="crearFormulario"
          >
            <p
              v-if="error"
              role="alert"
              class="text-sm"
              :style="{ color: 'var(--error)' }"
            >
              {{ error }}
            </p>
            <label for="nombre-ficha" class="tu-label">{{
              $t("adminUsabilidad.fichas.nombre")
            }}</label>
            <input
              id="nombre-ficha"
              v-model="nuevo.nombre"
              class="tu-input"
              :placeholder="$t('adminUsabilidad.fichas.ejemplo')"
              required
            />
            <label for="destinatarios-ficha" class="tu-label">{{
              $t("adminUsabilidad.fichas.destinatarios")
            }}</label>
            <select
              id="destinatarios-ficha"
              v-model="nuevo.aplica_a"
              class="tu-input"
            >
              <option value="miembro">{{ $t("formularios.miembro") }}</option>
              <option value="instructor">
                {{ $t("formularios.instructor") }}
              </option>
              <option value="todos">{{ $t("formularios.todos") }}</option>
            </select>
            <div class="flex flex-wrap justify-end gap-2">
              <button
                type="button"
                class="tu-btn tu-btn-fantasma"
                :disabled="creando"
                @click="nuevaFicha = false"
              >
                {{ $t("comun.cancelar") }}
              </button>
              <button
                class="tu-btn tu-btn-primario"
                type="submit"
                :disabled="creando || nuevo.nombre.trim() === ''"
              >
                {{ $t("formularios.crear") }}
              </button>
            </div>
          </form>
        </ModalDialogo>
      </div>

      <!-- Detalle del formulario -->
      <div v-if="seleccionado" class="space-y-6">
        <!-- Constructor de campos (diseño) -->
        <div v-if="!soloRespuestas" class="tu-card p-6">
          <button
            type="button"
            class="tu-enlace mb-5"
            @click="
              seleccionadoId = null;
              nuevaPregunta = false;
            "
          >
            <IconoNav nombre="atras" :tam="16" />
            {{ $t("adminUsabilidad.fichas.volver") }}
          </button>
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
              <h2 class="font-semibold text-xl break-words">
                {{
                  $t(
                    puedeGestionar
                      ? "adminUsabilidad.fichas.gestionando"
                      : "adminUsabilidad.fichas.consultando",
                    {
                      nombre: seleccionado.nombre,
                    },
                  )
                }}
              </h2>
              <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{
                  $t(
                    puedeGestionar
                      ? "adminUsabilidad.fichas.editorAyuda"
                      : "adminUsabilidad.fichas.lecturaAyuda",
                  )
                }}
              </p>
              <p class="mt-2 text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{ $t(`formularios.${seleccionado.aplica_a}`) }} ·
                {{ cantidadCampos(seleccionado.campos.length)
                }}<template v-if="conRespuestas">
                  ·
                  {{
                    $t("operacion.fichas.versionRespuestas", {
                      version: seleccionado.version ?? 1,
                      n: seleccionado.respuestas ?? 0,
                    })
                  }}</template
                >
              </p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="tu-btn tu-btn-contorno tu-tono-neutral"
                data-prueba="vista-previa"
                @click="vistaPrevia = true"
              >
                {{ $t("operacion.fichas.vistaPrevia") }}
              </button>
              <template v-if="puedeGestionar">
                <button
                  type="button"
                  class="tu-btn tu-btn-contorno tu-tono-neutral"
                  data-prueba="editar-ficha"
                  @click="abrirDatos"
                >
                  {{ $t("operacion.fichas.editarDatos") }}
                </button>
                <button
                  type="button"
                  class="tu-btn tu-btn-contorno tu-tono-neutral"
                  data-prueba="archivar-ficha"
                  @click="archivar(seleccionado)"
                >
                  {{ $t("operacion.fichas.archivar") }}
                </button>
                <button
                  class="tu-btn tu-btn-primario tu-btn-crear"
                  type="button"
                  @click="nuevaPreguntaVacia"
                >
                  {{ $t("adminUsabilidad.fichas.nuevaPregunta") }}
                </button>
              </template>
            </div>
          </div>
          <AvisoTono
            v-if="puedeGestionar && conRespuestas"
            tono="info"
            class="mt-4"
            data-prueba="aviso-version"
          >
            {{
              $t(
                "operacion.fichas.avisoVersion",
                {
                  n: seleccionado.respuestas ?? 0,
                  siguiente: (seleccionado.version ?? 1) + 1,
                },
                seleccionado.respuestas ?? 0,
              )
            }}
          </AvisoTono>
          <p
            v-if="error && !nuevaPregunta && !editandoDatos"
            role="alert"
            class="mt-3 text-sm"
            :style="{ color: 'var(--error)' }"
          >
            {{ error }}
          </p>
          <ul
            v-if="seleccionado.campos.length > 0"
            class="mt-5 space-y-2 text-sm"
          >
            <li
              v-for="(c, i) in seleccionado.campos"
              :key="c.id"
              class="ff-pregunta flex items-center gap-2 flex-wrap"
              data-prueba="pregunta"
            >
              <span class="font-medium">{{ c.etiqueta }}</span>
              <span class="tu-badge">{{
                $t(`formularios.tipos.${c.tipo}`)
              }}</span>
              <span v-if="c.obligatorio" class="tu-badge tu-badge-aviso">{{
                $t("formularios.campos.obligatorio")
              }}</span>
              <span
                v-if="puedeGestionar"
                class="ml-auto flex items-center gap-1"
              >
                <button
                  type="button"
                  class="tu-icono-btn"
                  :aria-label="
                    $t('operacion.fichas.subir', { pregunta: c.etiqueta })
                  "
                  :disabled="i === 0 || guardandoFicha"
                  :data-prueba="`subir-${c.id}`"
                  @click="mover(i, -1)"
                >
                  <IconoNav nombre="arriba" :tam="14" />
                </button>
                <button
                  type="button"
                  class="tu-icono-btn"
                  :aria-label="
                    $t('operacion.fichas.bajar', { pregunta: c.etiqueta })
                  "
                  :disabled="
                    i === seleccionado.campos.length - 1 || guardandoFicha
                  "
                  :data-prueba="`bajar-${c.id}`"
                  @click="mover(i, 1)"
                >
                  <IconoNav nombre="abajo" :tam="14" />
                </button>
                <button
                  type="button"
                  class="tu-enlace text-sm ml-2"
                  :data-prueba="`editar-${c.id}`"
                  @click="editarPregunta(c)"
                >
                  {{ $t("operacion.fichas.editar") }}
                </button>
                <button
                  type="button"
                  class="tu-enlace text-sm ml-2"
                  :style="{ color: 'var(--error)' }"
                  :disabled="guardandoFicha"
                  :data-prueba="`quitar-${c.id}`"
                  @click="quitarPregunta(c)"
                >
                  {{ $t("operacion.fichas.quitar") }}
                </button>
              </span>
            </li>
          </ul>
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("formularios.campos.vacio") }}
          </p>

          <ModalDialogo
            v-if="puedeGestionar"
            :abierto="nuevaPregunta"
            :titulo="
              editandoPregunta
                ? $t('operacion.fichas.editarPregunta')
                : $t('adminUsabilidad.fichas.nuevaPregunta')
            "
            :descripcion="
              $t('adminUsabilidad.fichas.preguntaAyuda', {
                nombre: seleccionado.nombre,
              })
            "
            icono="formularios"
            :ocupado="agregandoCampo || guardandoFicha"
            @cerrar="nuevaPregunta = false"
          >
            <form
              v-if="puedeGestionar"
              class="grid sm:grid-cols-2 gap-4"
              @submit.prevent="agregarCampo"
            >
              <p
                v-if="error"
                role="alert"
                class="sm:col-span-2 text-sm"
                :style="{ color: 'var(--error)' }"
              >
                {{ error }}
              </p>
              <label>
                <span class="tu-label">{{
                  $t("adminUsabilidad.fichas.pregunta")
                }}</span>
                <input
                  v-model="nuevoCampo.etiqueta"
                  class="tu-input"
                  :placeholder="$t('formularios.campos.etiquetaPh')"
                  required
                />
              </label>
              <label>
                <span class="tu-label">{{
                  $t("adminUsabilidad.fichas.tipo")
                }}</span>
                <select v-model="nuevoCampo.tipo" class="tu-input">
                  <option
                    v-for="tp in [
                      'texto',
                      'textarea',
                      'numero',
                      'fecha',
                      'booleano',
                      'seleccion',
                    ]"
                    :key="tp"
                    :value="tp"
                  >
                    {{ $t(`formularios.tipos.${tp}`) }}
                  </option>
                </select>
              </label>
              <label
                v-if="nuevoCampo.tipo === 'seleccion'"
                class="sm:col-span-2"
              >
                <span class="tu-label">{{
                  $t("adminUsabilidad.fichas.opciones")
                }}</span>
                <input
                  v-model="nuevoCampo.opciones"
                  class="tu-input"
                  :placeholder="$t('formularios.campos.opciones')"
                  :aria-describedby="
                    faltanOpciones ? 'opciones-minimas' : undefined
                  "
                />
                <span
                  v-if="faltanOpciones"
                  id="opciones-minimas"
                  class="tu-hint mt-1 block"
                  data-prueba="opciones-minimas"
                  >{{ $t("operacion.formularios.dosOpciones") }}</span
                >
              </label>
              <label class="flex items-center gap-1.5 text-sm">
                <input v-model="nuevoCampo.obligatorio" type="checkbox" />
                {{ $t("formularios.campos.obligatorio") }}
              </label>
              <p
                v-if="cambiaTipo"
                class="sm:col-span-2 tu-hint"
                data-prueba="cambia-tipo"
              >
                {{ $t("operacion.fichas.cambiaTipo") }}
              </p>
              <div class="sm:col-span-2 flex justify-end gap-2">
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma"
                  :disabled="agregandoCampo"
                  @click="nuevaPregunta = false"
                >
                  {{ $t("comun.cancelar") }}
                </button>
                <button
                  class="tu-btn tu-btn-primario"
                  type="submit"
                  :disabled="
                    agregandoCampo ||
                    guardandoFicha ||
                    nuevoCampo.etiqueta.trim() === '' ||
                    faltanOpciones
                  "
                >
                  {{
                    editandoPregunta
                      ? $t("comun.guardar")
                      : $t("formularios.campos.agregar")
                  }}
                </button>
              </div>
            </form>
          </ModalDialogo>

          <!-- Nombre, descripción y a quién aplica -->
          <ModalDialogo
            v-if="puedeGestionar"
            :abierto="editandoDatos"
            :titulo="$t('operacion.fichas.editarDatos')"
            icono="formularios"
            tam="md"
            :ocupado="guardandoFicha"
            @cerrar="editandoDatos = false"
          >
            <form
              id="ff-datos"
              class="space-y-4"
              @submit.prevent="guardarDatos"
            >
              <p
                v-if="error"
                role="alert"
                class="text-sm"
                :style="{ color: 'var(--error)' }"
              >
                {{ error }}
              </p>
              <label class="block">
                <span class="tu-label">{{
                  $t("adminUsabilidad.fichas.nombre")
                }}</span>
                <input
                  v-model="datosFicha.nombre"
                  class="tu-input"
                  required
                  data-prueba="datos-nombre"
                />
              </label>
              <label class="block">
                <span class="tu-label">{{
                  $t("operacion.fichas.descripcion")
                }}</span>
                <textarea
                  v-model="datosFicha.descripcion"
                  class="tu-input"
                  rows="2"
                  maxlength="1000"
                />
              </label>
              <label class="block">
                <span class="tu-label">{{
                  $t("adminUsabilidad.fichas.destinatarios")
                }}</span>
                <select v-model="datosFicha.aplica_a" class="tu-input">
                  <option value="miembro">
                    {{ $t("formularios.miembro") }}
                  </option>
                  <option value="instructor">
                    {{ $t("formularios.instructor") }}
                  </option>
                  <option value="todos">{{ $t("formularios.todos") }}</option>
                </select>
              </label>
              <div class="flex justify-end gap-2">
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma"
                  :disabled="guardandoFicha"
                  @click="editandoDatos = false"
                >
                  {{ $t("comun.cancelar") }}
                </button>
                <button
                  class="tu-btn tu-btn-primario"
                  type="submit"
                  :disabled="guardandoFicha || datosFicha.nombre.trim() === ''"
                >
                  {{ $t("comun.guardar") }}
                </button>
              </div>
            </form>
          </ModalDialogo>

          <!-- Vista previa: como la ve quien la llena -->
          <ModalDialogo
            :abierto="vistaPrevia"
            :titulo="
              $t('operacion.fichas.vistaPreviaDe', {
                nombre: seleccionado.nombre,
              })
            "
            icono="formularios"
            tam="md"
            @cerrar="vistaPrevia = false"
          >
            <p
              v-if="seleccionado.descripcion"
              class="text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ seleccionado.descripcion }}
            </p>
            <div class="mt-3 grid gap-3" data-prueba="lista-vista-previa">
              <template v-for="c in seleccionado.campos" :key="c.id">
                <label
                  v-if="c.tipo === 'booleano'"
                  class="flex items-center gap-2 text-sm"
                >
                  <input type="checkbox" disabled />
                  {{ c.etiqueta
                  }}<span v-if="c.obligatorio" aria-hidden="true"> *</span>
                </label>
                <label v-else class="block">
                  <span class="tu-label"
                    >{{ c.etiqueta
                    }}<span v-if="c.obligatorio" aria-hidden="true">
                      *</span
                    ></span
                  >
                  <textarea
                    v-if="c.tipo === 'textarea'"
                    class="tu-input"
                    rows="2"
                    disabled
                  />
                  <select
                    v-else-if="c.tipo === 'seleccion'"
                    class="tu-input"
                    disabled
                  >
                    <option v-for="o in c.opciones ?? []" :key="o">
                      {{ o }}
                    </option>
                  </select>
                  <input
                    v-else
                    class="tu-input"
                    :type="
                      c.tipo === 'numero'
                        ? 'number'
                        : c.tipo === 'fecha'
                          ? 'date'
                          : 'text'
                    "
                    disabled
                  />
                </label>
              </template>
              <p
                v-if="seleccionado.campos.length === 0"
                class="text-sm"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("formularios.campos.vacio") }}
              </p>
            </div>
          </ModalDialogo>
        </div>

        <!-- Respuestas: se llenan desde el expediente de cada persona -->
        <div v-if="soloRespuestas" class="tu-card p-6">
          <h2 class="font-medium text-lg">
            {{ $t("formularios.respuestas.titulo") }}
            <span
              v-if="puedeGestionar && respuestas.length > 0"
              class="ml-1 font-normal tabular-nums"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ metaRespuestas?.total ?? respuestas.length }}</span
            >
          </h2>
          <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("formulariosRespuestas.comoLlenar") }}
          </p>
          <template v-if="puedeGestionar">
            <input
              v-model="buscarRespuestas"
              type="search"
              class="tu-input mt-4 max-w-sm"
              :placeholder="$t('operacion.listadosDos.buscarRespuestas')"
              :aria-label="$t('operacion.listadosDos.buscarRespuestas')"
              data-prueba="buscar-respuestas"
              @input="alBuscarRespuestas"
            />
            <p
              v-if="cargandoRespuestas"
              class="mt-4 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("comun.cargando") }}
            </p>
            <p
              v-else-if="respuestas.length === 0"
              class="mt-4 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                buscarRespuestas.trim() !== ""
                  ? $t("operacion.listadosDos.sinResultados")
                  : $t("formulariosRespuestas.vacio")
              }}
            </p>
            <ul v-else class="mt-3">
              <li v-for="r in respuestas" :key="r.id" class="form-fila">
                <div class="flex w-full items-center justify-between gap-3">
                  <button
                    type="button"
                    class="min-w-0 flex-1 text-left"
                    :aria-expanded="abierta === r.id"
                    @click="abierta = abierta === r.id ? null : r.id"
                  >
                    <span class="block font-medium truncate">{{
                      r.persona ?? "—"
                    }}</span>
                    <span
                      class="block text-xs"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{
                        $t("formulariosRespuestas.respondio", {
                          fecha: fecha(r.respondido_en),
                        })
                      }}<template
                        v-if="
                          r.version &&
                          versionActual &&
                          r.version < versionActual
                        "
                      >
                        ·
                        {{
                          $t("operacion.fichas.conVersion", { n: r.version })
                        }}</template
                      ></span
                    >
                  </button>
                  <RouterLink
                    v-if="expediente(r)"
                    :to="expediente(r) ?? ''"
                    class="tu-enlace shrink-0 text-sm"
                  >
                    {{ $t("formulariosRespuestas.abrirExpediente") }}
                  </RouterLink>
                </div>
                <dl
                  v-if="abierta === r.id"
                  class="mt-3 w-full rounded-xl border px-4 text-sm divide-y divide-[var(--borde)]"
                  :style="{
                    borderColor: 'var(--borde)',
                    background: 'var(--fondo)',
                  }"
                >
                  <div
                    v-for="c in camposDe(r)"
                    :key="c.id"
                    class="flex items-baseline justify-between gap-4 py-2"
                  >
                    <dt :style="{ color: 'var(--texto-suave)' }">
                      {{ c.etiqueta }}
                    </dt>
                    <dd class="text-right font-medium">
                      {{ valor(c, r.valores[c.id]) }}
                    </dd>
                  </div>
                </dl>
              </li>
            </ul>
            <PaginacionListado
              v-if="metaRespuestas && metaRespuestas.ultima_pagina > 1"
              :page="metaRespuestas.page"
              :ultima-pagina="metaRespuestas.ultima_pagina"
              :total="metaRespuestas.total"
              :per-page="metaRespuestas.per_page"
              @ir="irPaginaRespuestas"
            />
          </template>
        </div>
      </div>
      <div
        v-else-if="soloRespuestas"
        class="tu-card p-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("formularios.seleccionar") }}
      </div>
    </div>
  </section>
</template>

<style scoped>
.ff-listado {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 19rem), 1fr));
  gap: 1rem;
}
.ff-icono {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.75rem;
  color: var(--primario-fuerte);
  background: var(--primario-suave);
}
.ff-pregunta {
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: var(--superficie-2);
}
.form-fila {
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.form-fila:first-child {
  border-top: 0;
}
</style>
