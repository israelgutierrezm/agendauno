<script setup lang="ts">
import BotonWhatsApp from "@/components/BotonWhatsApp.vue";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

import BarraListado from "@/components/BarraListado.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { whatsappDe } from "@/lib/captacion";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

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
  // Seguimiento (M09): a quién, por qué y con qué terminó.
  persona_id?: string | null;
  celular?: string | null;
  // Sin celular propio: su responsable (familias, ADR 0123).
  contacto?: { nombre: string; celular: string } | null;
  motivo?: string | null;
  resultado?: string | null;
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
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeReglas = computed(() => sesion.puede("automatizaciones.gestionar"));
const puedeEliminarReglas = computed(() =>
  sesion.puede("automatizaciones.eliminar"),
);
const puedeGestionar = computed(() => sesion.puede("tareas.gestionar"));

const tab = ref<"pendientes" | "reglas">("pendientes");
const error = ref<string | null>(null);

// ---- Tareas ----
type Vencimiento = "vencidas" | "hoy" | "semana" | "sin_fecha";
const VENCIMIENTOS: Vencimiento[] = ["vencidas", "hoy", "semana", "sin_fecha"];
interface MetaTareas {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
  conteos: Record<Vencimiento, number>;
}
const POR_PAGINA = 20;
const tareas = ref<Tarea[]>([]);
const pendientes = ref(0);
const meta = ref<MetaTareas | null>(null);
const pagina = ref(1);
const estadoFiltro = ref<"pendiente" | "completada">("pendiente");
const cargandoTareas = ref(true);
const accionando = ref(false);
const mostrarNueva = ref(false);
const nueva = ref({ titulo: "", detalle: "", vence_en: "" });

// Búsqueda y filtros. El inicio abre los seguimientos ya filtrados
// (`?tipo=seguimiento`; M08) y puede abrir un vencimiento (`?vencimiento=hoy`).
const consulta = useRoute()?.query ?? {};
const busqueda = ref("");
const filtros = ref<Record<"tipo" | "vencimiento" | "mias", string>>({
  tipo:
    typeof consulta.tipo === "string" &&
    ["seguimiento", "automatica", "manual"].includes(consulta.tipo)
      ? consulta.tipo
      : "",
  vencimiento:
    typeof consulta.vencimiento === "string" &&
    (VENCIMIENTOS as string[]).includes(consulta.vencimiento)
      ? consulta.vencimiento
      : "",
  mias: "",
});
const desde = ref("");
const hasta = ref("");
const hayFiltros = computed(
  () =>
    busqueda.value.trim() !== "" ||
    Object.values(filtros.value).some((v) => v !== "") ||
    desde.value !== "" ||
    hasta.value !== "",
);
const definicionFiltros = computed(() => [
  {
    clave: "tipo",
    etiqueta: t("operacion.bandeja.filtros.tipo"),
    opciones: (["seguimiento", "automatica", "manual"] as const).map((v) => ({
      valor: v,
      texto: t(`operacion.bandeja.tipos.${v}`),
    })),
  },
  ...(estadoFiltro.value === "pendiente"
    ? [
        {
          clave: "vencimiento",
          etiqueta: t("operacion.bandeja.filtros.vencimiento"),
          opciones: VENCIMIENTOS.map((v) => ({
            valor: v,
            texto: t(`operacion.bandeja.conteos.${v}`),
          })),
        },
      ]
    : []),
  ...(alcance.value === "equipo"
    ? [
        {
          clave: "mias",
          etiqueta: t("operacion.bandeja.filtros.mias"),
          tipo: "booleano" as const,
        },
      ]
    : []),
]);
function cambiarFiltro(clave: string, valor: string): void {
  if (clave === "tipo" || clave === "vencimiento" || clave === "mias") {
    filtros.value[clave] = valor;
    recargarDesdeElInicio();
  }
}
// Un indicador de vencimiento es un atajo de ese filtro: otro clic lo quita.
function elegirVencimiento(v: Vencimiento): void {
  cambiarFiltro("vencimiento", filtros.value.vencimiento === v ? "" : v);
}
function limpiarFiltros(): void {
  busqueda.value = "";
  filtros.value = { tipo: "", vencimiento: "", mias: "" };
  desde.value = "";
  hasta.value = "";
  recargarDesdeElInicio();
}
function elegirEstado(estado: "pendiente" | "completada"): void {
  if (estado === estadoFiltro.value) {
    return;
  }
  estadoFiltro.value = estado;
  filtros.value.vencimiento = "";
  recargarDesdeElInicio();
}
function recargarDesdeElInicio(): void {
  pagina.value = 1;
  void cargarTareas();
}
function irAPagina(n: number): void {
  pagina.value = n;
  void cargarTareas();
}
let esperaBusqueda: ReturnType<typeof setTimeout> | undefined;
watch(busqueda, () => {
  clearTimeout(esperaBusqueda);
  esperaBusqueda = setTimeout(recargarDesdeElInicio, 300);
});
watch([desde, hasta], () => {
  if (desde.value === "" || hasta.value === "" || hasta.value >= desde.value) {
    recargarDesdeElInicio();
  }
});
onBeforeUnmount(() => clearTimeout(esperaBusqueda));

// ---- Fechas en días del negocio (su zona horaria, ADR 0099) ----
const zona = computed(() => sesion.zonaHoraria || undefined);
function diaDelNegocio(fecha: Date): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: zona.value,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(fecha);
}
const hoy = diaDelNegocio(new Date());
/** Cuántos días del negocio faltan (negativo: ya pasaron). */
function diasHasta(iso: string): number {
  return Math.round(
    (Date.parse(`${diaDelNegocio(new Date(iso))}T00:00:00Z`) -
      Date.parse(`${hoy}T00:00:00Z`)) /
      86_400_000,
  );
}
function fechaCorta(iso: string): string {
  const fecha = new Date(iso);
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona.value,
    day: "numeric",
    month: "short",
    ...(diaDelNegocio(fecha).slice(0, 4) !== hoy.slice(0, 4)
      ? { year: "numeric" }
      : {}),
  }).format(fecha);
}
function fechaConHora(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    timeZone: zona.value,
    dateStyle: "full",
    timeStyle: "short",
  }).format(new Date(iso));
}
/** Cuándo vence (o cuándo se completó), con el tono de su punto. */
function cuandoDe(tarea: Tarea): { texto: string; tono: string } {
  if (tarea.estado === "completada") {
    return {
      texto: tarea.completada_en
        ? t("operacion.bandeja.completadaEl", {
            fecha: fechaCorta(tarea.completada_en),
          })
        : t("operacion.bandeja.completada"),
      tono: "var(--exito)",
    };
  }
  if (tarea.vence_en === null) {
    return { texto: t("tareas.sinVencimiento"), tono: "var(--texto-suave)" };
  }
  const dias = diasHasta(tarea.vence_en);
  const fecha = fechaCorta(tarea.vence_en);
  if (dias < 0) {
    return {
      texto:
        dias === -1
          ? t("operacion.bandeja.vence.ayer")
          : t("operacion.bandeja.vence.vencio", { fecha }),
      tono: "var(--error)",
    };
  }
  if (dias === 0) {
    return { texto: t("operacion.bandeja.vence.hoy"), tono: "var(--aviso)" };
  }
  return {
    texto:
      dias === 1
        ? t("operacion.bandeja.vence.manana")
        : t("operacion.bandeja.vence.el", { fecha }),
    tono: "var(--primario)",
  };
}
// Las pendientes, agrupadas por cuándo vencen (ya vienen en ese orden).
type Grupo = Vencimiento | "despues" | "completadas";
const grupos = computed<{ clave: Grupo; tareas: Tarea[] }[]>(() => {
  if (estadoFiltro.value === "completada") {
    return [{ clave: "completadas", tareas: tareas.value }];
  }
  const lista: { clave: Grupo; tareas: Tarea[] }[] = [];
  for (const tarea of tareas.value) {
    const dias = tarea.vence_en === null ? null : diasHasta(tarea.vence_en);
    const clave: Grupo =
      dias === null
        ? "sin_fecha"
        : dias < 0
          ? "vencidas"
          : dias === 0
            ? "hoy"
            : dias <= 7
              ? "semana"
              : "despues";
    const ultimo = lista[lista.length - 1];
    if (ultimo?.clave === clave) {
      ultimo.tareas.push(tarea);
    } else {
      lista.push({ clave, tareas: [tarea] });
    }
  }
  return lista;
});
// Completar y regresar a pendientes se confirman: un clic de más no cambia nada.
async function alMarcar(tarea: Tarea): Promise<void> {
  if (tarea.estado !== "pendiente") {
    if (
      await confirmar(
        t("operacion.bandeja.confirmarReabrir", { titulo: tarea.titulo }),
        { aceptar: t("operacion.bandeja.reabrirAceptar") },
      )
    ) {
      await reabrir(tarea);
    }
  } else if (tarea.motivo) {
    // Un seguimiento se cierra con su resultado (el panel ya es la confirmación).
    abrirResultado(tarea);
  } else if (
    await confirmar(
      t("operacion.bandeja.confirmarCompletar", { titulo: tarea.titulo }),
      { aceptar: t("operacion.bandeja.completarAceptar") },
    )
  ) {
    await completar(tarea);
  }
}

// ---- Seguimiento: mensaje sugerido y resultado ----
const RESULTADOS = [
  "contactado",
  "renovo",
  "no_respondio",
  "no_desea_contacto",
  "requiere_atencion",
] as const;
type Resultado = (typeof RESULTADOS)[number];
// El servidor antepone esto al mensaje sugerido (SeguimientoTenant::PREFIJO_MENSAJE).
const PREFIJO_MENSAJE = "Mensaje sugerido (edítalo antes de enviarlo): ";
const conResultado = ref<Tarea | null>(null);
const resultado = ref<{ valor: Resultado; nota: string }>({
  valor: "contactado",
  nota: "",
});

/** El mensaje sugerido de un seguimiento, sin el aviso ni el resultado. */
function mensajeDe(tarea: Tarea): string | null {
  const d = tarea.detalle ?? "";
  return tarea.motivo && d.startsWith(PREFIJO_MENSAJE)
    ? d.slice(PREFIJO_MENSAJE.length).split("\n\n")[0]
    : null;
}
function whatsappCon(tarea: Tarea): string | null {
  const url = whatsappDe(tarea.celular ?? tarea.contacto?.celular ?? null);
  const mensaje = mensajeDe(tarea);
  return url === null
    ? null
    : mensaje !== null
      ? `${url}?text=${encodeURIComponent(mensaje)}`
      : url;
}
async function copiarMensaje(tarea: Tarea): Promise<void> {
  const mensaje = mensajeDe(tarea);
  if (mensaje === null) {
    return;
  }
  try {
    await navigator.clipboard.writeText(mensaje);
    toast.exito(t("operacion.seguimiento.copiado"));
  } catch {
    toast.error(t("operacion.seguimiento.noSeCopio"));
  }
}
function abrirResultado(tarea: Tarea): void {
  resultado.value = { valor: "contactado", nota: "" };
  conResultado.value = tarea;
}
async function guardarResultado(): Promise<void> {
  const tarea = conResultado.value;
  if (tarea === null) {
    return;
  }
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/tareas/${tarea.id}/resultado`, {
      resultado: resultado.value.valor,
      nota:
        resultado.value.nota.trim() !== "" ? resultado.value.nota.trim() : null,
    });
    conResultado.value = null;
    toast.exito(t("operacion.seguimiento.registrado"));
    await cargarTareas();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

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

// «mias»: sin permiso para las del equipo, solo ve y atiende las suyas.
const alcance = ref<"mias" | "equipo">("equipo");

let peticion = 0;
async function cargarTareas(): Promise<void> {
  const mia = ++peticion;
  cargandoTareas.value = true;
  error.value = null;
  const q = busqueda.value.trim();
  try {
    const { data } = await api.get<{
      data: Tarea[];
      pendientes: number;
      alcance?: "mias" | "equipo";
      meta?: MetaTareas;
    }>(`${base.value}/tareas`, {
      params: {
        estado: estadoFiltro.value,
        page: pagina.value,
        per_page: POR_PAGINA,
        ...(q !== "" ? { q } : {}),
        ...(filtros.value.tipo !== "" ? { tipo: filtros.value.tipo } : {}),
        ...(filtros.value.vencimiento !== "" &&
        estadoFiltro.value === "pendiente"
          ? { vencimiento: filtros.value.vencimiento }
          : {}),
        ...(filtros.value.mias !== "" ? { mias: 1 } : {}),
        ...(desde.value !== "" ? { desde: desde.value } : {}),
        ...(hasta.value !== "" ? { hasta: hasta.value } : {}),
      },
    });
    // Solo la respuesta de la última búsqueda: una anterior que llegue tarde no la pisa.
    if (mia !== peticion) {
      return;
    }
    tareas.value = data.data;
    pendientes.value = data.pendientes;
    alcance.value = data.alcance ?? "equipo";
    meta.value = data.meta ?? null;
  } catch (e) {
    if (mia === peticion) {
      error.value = mensajeDeError(e);
    }
  } finally {
    if (mia === peticion) {
      cargandoTareas.value = false;
    }
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

async function completar(tarea: Tarea): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/tareas/${tarea.id}/completar`, {});
    toast.exito(t("operacion.bandeja.completadaAviso"));
    await cargarTareas();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function reabrir(tarea: Tarea): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/tareas/${tarea.id}/reabrir`, {});
    toast.exito(t("operacion.bandeja.reabiertaAviso"));
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

onMounted(cargarTareas);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="
        alcance === 'mias' ? $t('tareas.tituloMias') : $t('tareas.titulo')
      "
    />

    <!-- Pestañas (las del sistema) -->
    <div class="tu-pestanas mt-6" role="tablist">
      <button
        type="button"
        role="tab"
        :aria-selected="tab === 'pendientes'"
        :aria-pressed="tab === 'pendientes'"
        @click="cambiarTab('pendientes')"
      >
        {{ $t("operacion.bandeja.tab")
        }}<span v-if="pendientes > 0" class="tu-badge ml-2">{{
          pendientes
        }}</span>
      </button>
      <button
        v-if="puedeReglas"
        type="button"
        role="tab"
        :aria-selected="tab === 'reglas'"
        :aria-pressed="tab === 'reglas'"
        @click="cambiarTab('reglas')"
      >
        {{ $t("tareas.tabReglas") }}
      </button>
    </div>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <!-- ===== Pestaña: Pendientes ===== -->
    <div v-if="tab === 'pendientes'" class="mt-6 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div
          class="tu-segmentado"
          role="group"
          :aria-label="$t('operacion.bandeja.estado')"
        >
          <button
            type="button"
            :aria-pressed="estadoFiltro === 'pendiente'"
            data-prueba="ver-pendientes"
            @click="elegirEstado('pendiente')"
          >
            {{ $t("operacion.bandeja.pendientes") }}
          </button>
          <button
            type="button"
            :aria-pressed="estadoFiltro === 'completada'"
            data-prueba="ver-completadas"
            @click="elegirEstado('completada')"
          >
            {{ $t("operacion.bandeja.completadas") }}
          </button>
        </div>
      </div>

      <!-- Cuántas pendientes por vencimiento: también son atajos de ese filtro -->
      <div
        v-if="estadoFiltro === 'pendiente' && meta"
        class="ta-conteos"
        data-prueba="conteos-tareas"
      >
        <button
          v-for="v in VENCIMIENTOS"
          :key="v"
          type="button"
          class="ta-conteo"
          :class="`ta-conteo-${v}`"
          :aria-pressed="filtros.vencimiento === v"
          :data-prueba="`conteo-${v}`"
          @click="elegirVencimiento(v)"
        >
          <span class="ta-conteo-numero">{{ meta.conteos[v] }}</span>
          <span class="ta-conteo-texto">{{
            $t(`operacion.bandeja.conteos.${v}`)
          }}</span>
        </button>
      </div>

      <BarraListado
        v-model:busqueda="busqueda"
        :filtros="definicionFiltros"
        :valores="filtros"
        :placeholder="$t('operacion.bandeja.buscar')"
        :puede-crear="puedeGestionar"
        :nuevo-texto="$t('tareas.nueva')"
        @cambio-filtro="cambiarFiltro"
        @limpiar="limpiarFiltros"
        @nuevo="mostrarNueva = true"
      >
        <template #acciones>
          <fieldset class="ta-rango">
            <legend class="sr-only">
              {{
                estadoFiltro === "pendiente"
                  ? $t("operacion.bandeja.rangoVence")
                  : $t("operacion.bandeja.rangoCompletada")
              }}
            </legend>
            <span class="ta-rango-etiqueta" aria-hidden="true">{{
              estadoFiltro === "pendiente"
                ? $t("operacion.bandeja.rangoVence")
                : $t("operacion.bandeja.rangoCompletada")
            }}</span>
            <input
              v-model="desde"
              type="date"
              class="tu-input"
              :max="hasta || undefined"
              :aria-label="$t('operacion.bandeja.desde')"
              data-prueba="tareas-desde"
            />
            <span aria-hidden="true">–</span>
            <input
              v-model="hasta"
              type="date"
              class="tu-input"
              :min="desde || undefined"
              :aria-label="$t('operacion.bandeja.hasta')"
              data-prueba="tareas-hasta"
            />
          </fieldset>
        </template>
      </BarraListado>

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

      <!-- La bandeja: agrupada por cuándo vence, con su página -->
      <div class="tu-card overflow-hidden" :aria-busy="cargandoTareas">
        <ul
          v-if="cargandoTareas && tareas.length === 0"
          class="ta-lista"
          :aria-label="$t('operacion.bandeja.cargando')"
        >
          <li v-for="n in 4" :key="n" class="ta-fila" aria-hidden="true">
            <span class="ta-check ta-esqueleto" />
            <span class="grid flex-1 gap-2">
              <span class="ta-esqueleto h-4 w-2/5" />
              <span class="ta-esqueleto h-3 w-3/5" />
            </span>
          </li>
        </ul>

        <EstadoVacio
          v-else-if="tareas.length === 0 && hayFiltros"
          icono="buscar"
          :titulo="$t('operacion.bandeja.sinResultados')"
          compacto
        >
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            @click="limpiarFiltros"
          >
            {{ $t("operacion.bandeja.quitarFiltros") }}
          </button>
        </EstadoVacio>
        <EstadoVacio
          v-else-if="tareas.length === 0"
          icono="tareas"
          :titulo="
            estadoFiltro === 'pendiente'
              ? $t('operacion.bandeja.todoAlDia')
              : $t('tareas.sinCompletadas')
          "
          :texto="
            estadoFiltro === 'pendiente'
              ? $t('operacion.bandeja.sinPendientesTexto')
              : undefined
          "
          :mas="estadoFiltro === 'pendiente' && puedeGestionar"
          compacto
        />

        <div v-else :class="{ 'ta-recargando': cargandoTareas }">
          <section
            v-for="g in grupos"
            :key="g.clave"
            :aria-label="
              g.clave === 'completadas'
                ? $t('operacion.bandeja.completadas')
                : $t(`operacion.bandeja.grupos.${g.clave}`)
            "
          >
            <h2
              v-if="g.clave !== 'completadas'"
              class="ta-grupo"
              :class="`ta-grupo-${g.clave}`"
            >
              {{ $t(`operacion.bandeja.grupos.${g.clave}`) }}
            </h2>
            <ul class="ta-lista">
              <li
                v-for="tarea in g.tareas"
                :key="tarea.id"
                class="ta-fila"
                :class="{ 'ta-hecha': tarea.estado === 'completada' }"
                data-prueba="tarea"
              >
                <button
                  v-if="puedeGestionar"
                  type="button"
                  class="ta-check"
                  :class="
                    tarea.estado === 'completada'
                      ? 'ta-check-regresar'
                      : 'ta-check-completar'
                  "
                  :aria-label="
                    tarea.estado === 'completada'
                      ? $t('operacion.bandeja.reabrirTarea', {
                          titulo: tarea.titulo,
                        })
                      : $t('operacion.bandeja.marcarCompletada', {
                          titulo: tarea.titulo,
                        })
                  "
                  :title="
                    tarea.estado === 'completada'
                      ? $t('operacion.bandeja.regresar')
                      : $t('operacion.bandeja.completar')
                  "
                  :disabled="accionando"
                  data-prueba="marcar-tarea"
                  @click="alMarcar(tarea)"
                >
                  <!-- En reposo, la hecha lleva su palomita; al pasar el cursor, el
                       círculo dice qué hará: completar (verde) o regresar (ámbar). -->
                  <IconoNav
                    v-if="tarea.estado === 'completada'"
                    class="ta-icono-reposo"
                    nombre="hecho"
                    :tam="14"
                  />
                  <IconoNav
                    class="ta-icono-accion"
                    :nombre="
                      tarea.estado === 'completada' ? 'deshacer' : 'hecho'
                    "
                    :tam="14"
                  />
                </button>
                <span v-else class="ta-check ta-check-fijo" aria-hidden="true">
                  <IconoNav
                    v-if="tarea.estado === 'completada'"
                    nombre="hecho"
                    :tam="14"
                  />
                </span>

                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="ta-titulo">{{ tarea.titulo }}</span>
                    <span v-if="tarea.motivo" class="tu-badge">{{
                      $t("operacion.seguimiento.etiqueta")
                    }}</span>
                    <span v-else-if="tarea.automatica" class="tu-badge">{{
                      $t("tareas.automatica")
                    }}</span>
                  </div>
                  <p
                    v-if="tarea.detalle"
                    class="ta-detalle"
                    :title="tarea.detalle"
                  >
                    {{ tarea.detalle }}
                  </p>
                  <div class="ta-meta">
                    <span
                      class="tu-estado"
                      :style="{ '--tono': cuandoDe(tarea).tono }"
                      :title="
                        tarea.vence_en
                          ? fechaConHora(tarea.vence_en)
                          : undefined
                      "
                      data-prueba="cuando-tarea"
                      >{{ cuandoDe(tarea).texto }}</span
                    >
                    <RouterLink
                      v-if="tarea.persona && tarea.persona_id"
                      class="tu-enlace"
                      :to="{
                        name: 'ficha-miembro',
                        params: { id: tarea.persona_id },
                      }"
                      >{{ tarea.persona }}</RouterLink
                    >
                    <span v-else-if="tarea.persona">{{ tarea.persona }}</span>
                    <span v-if="alcance === 'equipo' && tarea.responsable">{{
                      $t("operacion.bandeja.asignada", {
                        nombre: tarea.responsable,
                      })
                    }}</span>
                    <span v-if="tarea.resultado">{{
                      $t(`operacion.seguimiento.resultados.${tarea.resultado}`)
                    }}</span>
                  </div>
                  <!-- Seguimiento pendiente: escribirle con el mensaje sugerido -->
                  <div
                    v-if="tarea.motivo && tarea.estado === 'pendiente'"
                    class="mt-2 flex flex-wrap items-center gap-3 text-sm"
                  >
                    <button
                      v-if="mensajeDe(tarea)"
                      class="tu-enlace"
                      type="button"
                      @click="copiarMensaje(tarea)"
                    >
                      {{ $t("operacion.seguimiento.copiar") }}
                    </button>
                    <BotonWhatsApp
                      v-if="whatsappCon(tarea)"
                      :href="whatsappCon(tarea) ?? ''"
                      :texto="$t('operacion.seguimiento.whatsapp')"
                      compacto
                    />
                    <span
                      v-if="!tarea.celular && tarea.contacto"
                      :style="{ color: 'var(--texto-suave)' }"
                      data-prueba="contacto-responsable"
                      >{{
                        $t("operacion.seguimiento.via", {
                          nombre: tarea.contacto.nombre,
                        })
                      }}</span
                    >
                  </div>
                </div>

                <button
                  v-if="
                    puedeGestionar &&
                    tarea.estado === 'pendiente' &&
                    tarea.motivo
                  "
                  class="tu-btn tu-btn-fantasma shrink-0"
                  type="button"
                  :disabled="accionando"
                  @click="abrirResultado(tarea)"
                >
                  {{ $t("operacion.seguimiento.registrar") }}
                </button>
              </li>
            </ul>
          </section>
        </div>

        <PaginacionListado
          v-if="meta && meta.ultima_pagina > 1"
          :page="meta.page"
          :ultima-pagina="meta.ultima_pagina"
          :total="meta.total"
          :per-page="meta.per_page"
          @ir="irAPagina"
        />
      </div>

      <!-- Con qué terminó un seguimiento (M09) -->
      <PanelLateral
        :abierto="conResultado !== null"
        :titulo="$t('operacion.seguimiento.panelTitulo')"
        @cerrar="conResultado = null"
      >
        <form
          v-if="conResultado"
          class="grid gap-4"
          @submit.prevent="guardarResultado"
        >
          <p class="text-sm font-medium">{{ conResultado.titulo }}</p>
          <fieldset class="grid gap-2">
            <legend class="tu-label">
              {{ $t("operacion.seguimiento.resultado") }}
            </legend>
            <label
              v-for="r in RESULTADOS"
              :key="r"
              class="flex items-center gap-2 text-sm"
            >
              <input
                v-model="resultado.valor"
                type="radio"
                name="resultado-seguimiento"
                :value="r"
              />
              {{ $t(`operacion.seguimiento.resultados.${r}`) }}
            </label>
            <p
              v-if="resultado.valor === 'no_desea_contacto'"
              class="text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("operacion.seguimiento.ayudaNoDesea") }}
            </p>
          </fieldset>
          <div>
            <label class="tu-label" for="s-nota">{{
              $t("operacion.seguimiento.nota")
            }}</label>
            <textarea
              id="s-nota"
              v-model="resultado.nota"
              class="tu-input"
              rows="3"
              maxlength="500"
            />
          </div>
        </form>

        <template #pie>
          <div class="flex justify-end gap-2">
            <button
              class="tu-btn tu-btn-fantasma"
              type="button"
              @click="conResultado = null"
            >
              {{ $t("tareas.cancelar") }}
            </button>
            <button
              class="tu-btn tu-btn-primario"
              type="button"
              :disabled="accionando"
              @click="guardarResultado"
            >
              {{ $t("operacion.seguimiento.guardar") }}
            </button>
          </div>
        </template>
      </PanelLateral>
    </div>

    <!-- ===== Pestaña: Reglas ===== -->
    <div v-else-if="tab === 'reglas' && puedeReglas" class="mt-6">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("tareas.reglas.subtitulo") }}
        </p>
        <button
          class="tu-btn tu-btn-primario tu-btn-crear"
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
      <EstadoVacio
        v-else-if="reglas.length === 0"
        class="mt-10"
        icono="tareas"
        :titulo="$t('tareas.reglas.sinReglas')"
      />
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
                v-if="puedeEliminarReglas"
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

<style scoped>
/* La bandeja de tareas: indicadores por vencimiento, grupos y filas. */
.ta-conteos {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}
@media (min-width: 768px) {
  .ta-conteos {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
.ta-conteo {
  --tono: var(--texto-suave);
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.15rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
  text-align: left;
  transition:
    border-color 0.15s,
    background-color 0.15s;
}
.ta-conteo:hover {
  border-color: var(--tono);
}
.ta-conteo[aria-pressed="true"] {
  border-color: var(--tono);
  background: color-mix(in srgb, var(--tono) 9%, var(--superficie));
}
.ta-conteo-vencidas {
  --tono: var(--error);
}
.ta-conteo-hoy {
  --tono: var(--aviso);
}
.ta-conteo-semana {
  --tono: var(--primario);
}
.ta-conteo-numero {
  font-size: 1.5rem;
  font-weight: 600;
  line-height: 1.1;
  color: var(--texto);
}
.ta-conteo-texto {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.82rem;
  color: var(--texto-suave);
}
.ta-conteo-texto::before {
  content: "";
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: var(--tono);
}
.ta-rango {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.82rem;
  color: var(--texto-suave);
}
.ta-rango .tu-input {
  width: auto;
  min-height: 2.5rem;
  padding-block: 0.35rem;
  font-size: 0.85rem;
}
.ta-grupo {
  padding: 0.6rem 1rem;
  border-bottom: 1px solid var(--borde);
  background: var(--superficie-2);
  color: var(--texto-suave);
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
.ta-grupo-vencidas {
  color: var(--error);
}
section + section .ta-grupo {
  border-top: 1px solid var(--borde);
}
.ta-lista > li + li {
  border-top: 1px solid var(--borde);
}
.ta-fila {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  padding: 0.9rem 1rem;
}
@media (min-width: 640px) {
  .ta-fila {
    padding-inline: 1.25rem;
  }
}
.ta-check {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 1.35rem;
  height: 1.35rem;
  margin-top: 0.1rem;
  border: 2px solid color-mix(in srgb, var(--texto-suave) 55%, transparent);
  border-radius: 999px;
  color: var(--primario-contraste);
  transition:
    border-color 0.15s,
    background-color 0.15s;
}
/* El ícono de la acción aparece al pasar el cursor (o con el foco del teclado). */
.ta-icono-accion {
  display: none;
}
button.ta-check:is(:hover, :focus-visible):not(:disabled) .ta-icono-accion {
  display: block;
}
button.ta-check:is(:hover, :focus-visible):not(:disabled) .ta-icono-reposo {
  display: none;
}
/* Completar: verde con palomita. */
button.ta-check-completar:is(:hover, :focus-visible):not(:disabled) {
  border-color: var(--exito);
  background: var(--exito);
  color: #fff;
}
.ta-hecha .ta-check {
  border-color: var(--exito);
  background: var(--exito);
  color: #fff;
}
/* Regresar a pendientes: el color de alerta con la flecha de regresar. */
button.ta-check-regresar:is(:hover, :focus-visible):not(:disabled) {
  border-color: var(--aviso);
  background: var(--aviso);
  color: var(--fondo);
}
/* En pantallas táctiles no hay cursor: la palomita se insinúa siempre. */
@media (hover: none) {
  .ta-check-completar .ta-icono-accion {
    display: block;
    opacity: 0.35;
    color: var(--texto-suave);
  }
}
.ta-titulo {
  font-weight: 600;
  overflow-wrap: anywhere;
}
.ta-hecha .ta-titulo {
  color: var(--texto-suave);
  text-decoration: line-through;
}
.ta-detalle {
  display: -webkit-box;
  margin-top: 0.2rem;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  color: var(--texto-suave);
  font-size: 0.875rem;
  white-space: pre-line;
}
.ta-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem 1rem;
  margin-top: 0.4rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.ta-esqueleto {
  display: block;
  border: 0;
  border-radius: 0.4rem;
  background: color-mix(in srgb, var(--texto-suave) 16%, transparent);
  animation: ta-pulso 1.2s ease-in-out infinite;
}
.ta-check.ta-esqueleto {
  border-radius: 999px;
}
.ta-recargando {
  opacity: 0.55;
  transition: opacity 0.15s;
}
@keyframes ta-pulso {
  50% {
    opacity: 0.45;
  }
}
@media (prefers-reduced-motion: reduce) {
  .ta-esqueleto {
    animation: none;
  }
}
</style>
