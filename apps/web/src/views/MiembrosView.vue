<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";

import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import BarraListado from "@/components/BarraListado.vue";
import BotonImportar from "@/components/BotonImportar.vue";
import CamposDatosPersonales from "@/components/CamposDatosPersonales.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import PanelEditarMiembro, {
  type MiembroEditable,
} from "@/components/PanelEditarMiembro.vue";
import IconoNav from "@/components/IconoNav.vue";
import MenuFlotante from "@/components/MenuFlotante.vue";
import ModalMiembro from "@/components/ModalMiembro.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import PanelMiembro from "@/components/PanelMiembro.vue";
import SelectorColumnas from "@/components/SelectorColumnas.vue";
import TarjetaMiembro from "@/components/TarjetaMiembro.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useColumnasVisibles } from "@/lib/columnasListado";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import {
  creditosDe,
  cuandoReserva,
  diaCorto,
  estadoMembresia,
  type ResumenTarjeta,
} from "@/lib/resumenTarjeta";
import { esInstructor } from "@/lib/roles";
import { plural } from "@/lib/terminologia";
import { useVistaListado } from "@/lib/vistaListado";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

const { t } = useI18n();
const toast = useToastStore();

interface Miembro {
  id: string;
  nombre: string;
  segundo_nombre: string | null;
  primer_apellido: string | null;
  segundo_apellido: string | null;
  nombre_completo: string;
  email: string | null;
  tipo: string;
  activo: boolean;
  es_facturable: boolean;
  archivado: boolean;
  primera_vez: boolean | null;
  alta?: string | null;
  acceso_app?: boolean;
  // Ya se le invitó y no ha activado su cuenta: se reenvía a esta cuenta.
  invitacion_pendiente?: string | null;
  celular?: string | null;
  fecha_nacimiento?: string | null;
  genero?: string | null;
  sucursal?: { id: string; nombre: string } | null;
  // Para las tarjetas (`resumen=1`): membresía, visitas y adeudo.
  resumen?: ResumenTarjeta;
  // Baja lógica: cuándo y quién (solo en "Dados de baja").
  dado_de_baja_en?: string | null;
  dado_de_baja_por?: string | null;
}
interface Meta {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("miembros.gestionar"));
const puedeInvitar = computed(() => sesion.puede("usuarios.invitar"));

const tipo = ref<"miembro" | "instructor">("miembro");
const q = ref("");
const facturable = ref("");
const estado = ref("");
const archivado = ref("no");
const sucursalFiltro = ref("");
// Con una sucursal fija (la de la barra o la única), el filtro por sede sobra.
const { mostrarSelect: elegirSucursal } = useSucursalOperativa({
  filtro: sucursalFiltro,
});
const page = ref(1);
const perPage = ref(20);
const OPCIONES_POR_PAGINA = [10, 20, 50, 100];
// Por nombre (A–Z o Z–A) o, sin elegir, los más recientes primero.
const orden = ref<"" | "nombre" | "-nombre">("");
function ordenarPorNombre(): void {
  orden.value =
    orden.value === "" ? "nombre" : orden.value === "nombre" ? "-nombre" : "";
}

const sucursales = ref<{ id: string; nombre: string }[]>([]);
// El filtro por sede solo aplica con varias sucursales (R19).
const hayMultiSucursal = computed(() => sucursales.value.length > 1);

// Definición de filtros para la BarraListado (estilo Acadion). Solo para alumnos.
const filtrosDef = computed(() => {
  if (tipo.value !== "miembro") {
    return [];
  }
  const defs = [
    {
      clave: "estado",
      etiqueta: t("miembros.colEstado"),
      opciones: [
        { valor: "activo", texto: t("miembros.activo") },
        { valor: "inactivo", texto: t("miembros.suspendido") },
        { valor: "baja", texto: t("bajas.dadosDeBaja") },
      ],
    },
    ...(!sesion.esCitas
      ? [
          {
            clave: "facturable",
            etiqueta: t("miembros.editar.facturable"),
            opciones: [
              { valor: "si", texto: t("miembros.filtros.facturableSi") },
              { valor: "no", texto: t("miembros.filtros.facturableNo") },
            ],
          },
        ]
      : []),
    {
      clave: "archivado",
      etiqueta: t("miembros.editar.archivado"),
      opciones: [
        { valor: "si", texto: t("miembros.filtros.archivados") },
        { valor: "todos", texto: t("miembros.filtros.todos") },
      ],
    },
  ];
  if (hayMultiSucursal.value && elegirSucursal.value) {
    defs.push({
      clave: "sucursal_id",
      etiqueta: t("miembros.filtros.sede"),
      opciones: sucursales.value.map((s) => ({ valor: s.id, texto: s.nombre })),
    });
  }
  return defs;
});

const valoresFiltro = computed<Record<string, string>>(() => ({
  estado: estado.value,
  facturable: facturable.value,
  archivado: archivado.value,
  sucursal_id: sucursalFiltro.value,
}));

function cambioFiltro(clave: string, valor: string): void {
  if (clave === "estado") {
    estado.value = valor;
  } else if (clave === "facturable") {
    facturable.value = valor;
  } else if (clave === "archivado") {
    archivado.value = valor === "" ? "no" : valor;
  } else if (clave === "sucursal_id") {
    sucursalFiltro.value = valor;
  }
}

function limpiarFiltros(): void {
  estado.value = "";
  facturable.value = "";
  archivado.value = "no";
  sucursalFiltro.value = "";
}
// Hay una búsqueda o un filtro: una lista vacía es «nadie coincide», no «aún no
// hay nadie».
const filtrando = computed(
  () =>
    q.value.trim() !== "" ||
    estado.value !== "" ||
    facturable.value !== "" ||
    archivado.value !== "no" ||
    sucursalFiltro.value !== "",
);
function quitarBusquedaYFiltros(): void {
  q.value = "";
  limpiarFiltros();
}

const miembros = ref<Miembro[]>([]);
// Lista (tabla) o cuadrícula de tarjetas; se recuerda en este navegador.
const vista = useVistaListado("miembros");

// El detalle de una persona: un modal sobre la lista (su resumen, planes, visitas,
// pagos y notas), con su ficha completa y el cobro a un clic.
const detalle = ref<Miembro | null>(null);
const detalleAbierto = ref(false);
function abrirDetalle(m: Miembro): void {
  detalle.value = m;
  detalleAbierto.value = true;
}
function elegir(m: Miembro, e: MouseEvent): void {
  // Los enlaces y botones de la fila conservan su propio clic.
  if ((e.target as HTMLElement).closest("a, button, input, label")) {
    return;
  }
  abrirDetalle(m);
}

// Columnas que se pueden ocultar (el nombre siempre se ve). Se recuerdan aquí.
const columnasDisponibles = computed(() => [
  { clave: "app", texto: t("operacion.clientes.app") },
  ...(hayPlanes.value
    ? [
        { clave: "plan", texto: t("operacion.clientes.plan") },
        { clave: "saldo", texto: t("operacion.clientes.saldo") },
      ]
    : []),
  { clave: "estado", texto: t("operacion.clientes.estado") },
  { clave: "ultima", texto: t("detalleMiembro.tabla.ultima") },
  { clave: "proxima", texto: t("operacion.clientes.proxima") },
  { clave: "registro", texto: t("operacion.clientes.registro") },
  ...(hayMultiSucursal.value
    ? [{ clave: "sede", texto: t("detalleMiembro.tabla.sede") }]
    : []),
]);
const columnas = useColumnasVisibles(
  "miembros",
  ["app", "plan", "saldo", "estado", "ultima", "proxima", "registro", "sede"],
  ["app", "plan", "saldo", "estado", "ultima"],
);
function ver(clave: string): boolean {
  return (
    columnas.value.includes(clave) &&
    columnasDisponibles.value.some((c) => c.clave === clave)
  );
}

// «…» de cada fila: un solo menú, junto al botón que se tocó.
const menuFila = ref<{ m: Miembro; ancla: HTMLElement } | null>(null);
function abrirMenuFila(m: Miembro, e: MouseEvent): void {
  const ancla = e.currentTarget as HTMLElement;
  menuFila.value = menuFila.value?.m.id === m.id ? null : { m, ancla };
}
function accionFila(accion: (m: Miembro) => void): void {
  const m = menuFila.value?.m;
  menuFila.value = null;
  if (m) {
    accion(m);
  }
}

// Cobrarle: la venta rápida de Recepción, sobre la lista.
const puedeVender = computed(
  () => sesion.puede("ordenes.gestionar") && sesion.puede("productos.ver"),
);
const cobrandoA = ref<Miembro | null>(null);
function cobrar(m: Miembro): void {
  detalleAbierto.value = false;
  cobrandoA.value = m;
}
function alCerrarCobro(): void {
  cobrandoA.value = null;
  void cargar();
}

// Exportar lo que se ve (con la búsqueda y los filtros) a CSV.
const exportando = ref(false);
async function exportar(): Promise<void> {
  exportando.value = true;
  try {
    const { data } = await api.get<Blob>(`${base.value}/miembros/exportar`, {
      params: { ...filtrosConsulta(), resumen: undefined },
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = "clientes.csv";
    enlace.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    exportando.value = false;
  }
}
const meta = ref<Meta | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const mensaje = ref<string | null>(null);

const editando = ref<MiembroEditable | null>(null);
const invitandoId = ref<string | null>(null);
const invitados = ref<Set<string>>(new Set());

// La búsqueda, los filtros y el orden (los mismos para la lista y para exportar).
function filtrosConsulta(): Record<string, string | number | undefined> {
  return {
    tipo: tipo.value,
    q: q.value.trim() || undefined,
    facturable: facturable.value || undefined,
    estado: estado.value || undefined,
    archivado: archivado.value,
    sucursal_id: sucursalFiltro.value || undefined,
    orden: orden.value || undefined,
  };
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Miembro[]; meta: Meta }>(
      `${base.value}/miembros`,
      {
        params: {
          ...filtrosConsulta(),
          page: page.value,
          per_page: perPage.value,
          resumen: 1,
        },
      },
    );
    miembros.value = data.data;
    // El detalle abierto se queda con los datos al día (o se cierra si ya no está).
    if (detalle.value !== null) {
      const mismo = miembros.value.find((m) => m.id === detalle.value?.id);
      if (mismo) {
        detalle.value = mismo;
      } else if (estado.value !== "baja") {
        detalleAbierto.value = false;
      }
    }
    meta.value = data.meta;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// Los números de arriba (`GET /miembros/resumen`): solo para clientes.
interface ResumenClientes {
  total: number;
  con_plan: number;
  nuevos_mes: number;
  por_vencer: number;
  vencidas: number;
  con_adeudo: number;
  dias_por_vencer: number;
}
const resumenClientes = ref<ResumenClientes | null>(null);
// En citas, los planes son opcionales: no llenar la pantalla de columnas vacías.
const hayPlanes = computed(
  () =>
    !sesion.esCitas ||
    (resumenClientes.value?.con_plan ?? 0) > 0 ||
    miembros.value.some((m) => Boolean(m.resumen?.membresia.plan)),
);
async function cargarResumen(): Promise<void> {
  try {
    const { data } = await api.get<{ data: ResumenClientes }>(
      `${base.value}/miembros/resumen`,
    );
    resumenClientes.value = data.data;
  } catch {
    // Sin el resumen, la lista sigue igual.
    resumenClientes.value = null;
  }
}
const indicadores = computed<Indicador[]>(() => {
  const r = resumenClientes.value;
  if (r === null) {
    return [];
  }
  const lista: Indicador[] = [
    {
      clave: "total",
      etiqueta: t("miembros.resumen.total", {
        grupo: plural(sesion.terminologia.miembro).toLowerCase(),
      }),
      valor: String(r.total),
      icono: "miembros",
      tono: "azul",
    },
    {
      clave: "conPlan",
      etiqueta: t("miembros.resumen.conPlan"),
      valor: String(r.con_plan),
      icono: "etiqueta",
      tono: "verde",
    },
    {
      clave: "nuevos",
      etiqueta: t("miembros.resumen.nuevos"),
      valor: String(r.nuevos_mes),
      icono: "personas",
      tono: "morado",
    },
    {
      clave: "porVencer",
      etiqueta: t("miembros.resumen.porVencer", { dias: r.dias_por_vencer }),
      valor:
        r.vencidas > 0
          ? t("miembros.resumen.porVencerValor", {
              n: r.por_vencer,
              vencidas: r.vencidas,
            })
          : String(r.por_vencer),
      icono: "reloj",
      tono: "naranja",
      aviso: r.por_vencer + r.vencidas > 0,
    },
    {
      clave: "adeudo",
      etiqueta: t("miembros.resumen.adeudo"),
      valor: String(r.con_adeudo),
      icono: "dinero",
      tono: "rosa",
      aviso: r.con_adeudo > 0,
    },
  ];
  return hayPlanes.value
    ? lista
    : lista.filter((k) => k.clave !== "conPlan" && k.clave !== "porVencer");
});

function recargarDesde1(): void {
  page.value = 1;
  void cargar();
}

let tempQ: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
  clearTimeout(tempQ);
  tempQ = setTimeout(recargarDesde1, 300);
});
watch(
  [tipo, facturable, estado, archivado, sucursalFiltro, orden, perPage],
  recargarDesde1,
);

function irPagina(n: number): void {
  if (meta.value === null || n < 1 || n > meta.value.ultima_pagina) {
    return;
  }
  page.value = n;
  void cargar();
}

function nombreCompleto(m: Miembro): string {
  return (
    m.nombre_completo ||
    [m.nombre, m.primer_apellido].filter(Boolean).join(" ").trim()
  );
}

function abrirEditar(m: Miembro): void {
  editando.value = {
    id: m.id,
    nombre: m.nombre,
    segundo_nombre: m.segundo_nombre,
    primer_apellido: m.primer_apellido,
    segundo_apellido: m.segundo_apellido,
    email: m.email,
    activo: m.activo,
    es_facturable: m.es_facturable,
    archivado: m.archivado,
    fecha_nacimiento: m.fecha_nacimiento ?? null,
    genero: m.genero ?? null,
  };
}
function onGuardado(): void {
  editando.value = null;
  void cargar();
}
// Estado de la persona como píldora: activo en verde, suspendido en ámbar; archivado
// y dado de baja, en gris.
function textoEstado(m: Miembro): string {
  if (m.dado_de_baja_en) {
    return t("detalleMiembro.tabla.baja");
  }
  if (m.archivado) {
    return t("detalleMiembro.estado.archivado");
  }
  return m.activo ? t("miembros.activo") : t("miembros.suspendido");
}
function tonoEstado(m: Miembro): string {
  if (m.dado_de_baja_en || m.archivado) {
    return "var(--texto-suave)";
  }
  return m.activo ? "var(--exito)" : "var(--aviso)";
}
// Desde el detalle: editar sus datos (o darle de baja, que vive en la edición).
function editarDetalle(): void {
  if (detalle.value) {
    abrirEditar(detalle.value);
  }
}

function fechaCorta(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
    new Date(iso),
  );
}
function detalleBaja(m: Miembro): string {
  if (!m.dado_de_baja_en) {
    return "";
  }
  const fecha = fechaCorta(m.dado_de_baja_en);
  return m.dado_de_baja_por
    ? t("bajas.detallePor", { fecha, quien: m.dado_de_baja_por })
    : t("bajas.detalle", { fecha });
}

// Reactiva a alguien dado de baja (vuelve con su historial).
const reactivandoId = ref<string | null>(null);
async function reactivar(id: string, nombre: string): Promise<void> {
  reactivandoId.value = id;
  error.value = null;
  try {
    await api.post(`${base.value}/miembros/${id}/reactivar`, {});
    toast.exito(t("bajas.reactivado", { nombre }));
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
    toast.error(error.value);
  } finally {
    reactivandoId.value = null;
  }
}

async function invitar(m: Miembro): Promise<void> {
  if (!m.email) {
    return;
  }
  invitandoId.value = m.id;
  error.value = null;
  try {
    await api.post(`${base.value}/usuarios/invitar`, {
      nombre: nombreCompleto(m),
      email: m.email,
      rol: "miembro",
    });
    invitados.value = new Set(invitados.value).add(m.id);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    invitandoId.value = null;
  }
}
// Invitación sin activar: se reenvía el correo (no se crea otra cuenta).
async function reenviarInvitacion(m: Miembro): Promise<void> {
  if (!m.invitacion_pendiente) {
    return;
  }
  invitandoId.value = m.id;
  error.value = null;
  try {
    await api.post(`${base.value}/usuarios/${m.invitacion_pendiente}/reenviar`);
    toast.exito(t("operacion.clientes.reenviada", { email: m.email ?? "" }));
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    invitandoId.value = null;
  }
}

// --- Alta ---
const form = ref({
  nombre: "",
  segundo_nombre: "",
  primer_apellido: "",
  segundo_apellido: "",
  email: "",
  celular: "",
  fecha_nacimiento: "",
  genero: "",
  whatsapp: false,
  tipo: "miembro",
});
const guardando = ref(false);
const abiertoAlta = ref(false);
// Avisos por WhatsApp (ADR 0069): si el negocio los usa y el cliente dejó celular.
const ofrecerWhatsApp = computed(
  () =>
    sesion.estudio?.whatsapp_clientes === true &&
    form.value.celular.trim() !== "",
);
// El celular es de alguien dado de baja: el negocio decide (reactivarlo u otra persona).
const coincidencia = ref<{
  id: string;
  nombre: string;
  mensaje: string;
} | null>(null);

function abrirAlta(): void {
  form.value = {
    nombre: "",
    segundo_nombre: "",
    primer_apellido: "",
    segundo_apellido: "",
    email: "",
    celular: "",
    fecha_nacimiento: "",
    genero: "",
    whatsapp: false,
    tipo: tipo.value,
  };
  mensaje.value = null;
  error.value = null;
  coincidencia.value = null;
  abiertoAlta.value = true;
}
function cerrarAlta(): void {
  abiertoAlta.value = false;
}

async function crear(liberarCelular = false): Promise<void> {
  guardando.value = true;
  error.value = null;
  mensaje.value = null;
  coincidencia.value = null;
  try {
    const { data } = await api.post<{
      data: { reactivado?: boolean; nombre_completo?: string };
    }>(`${base.value}/miembros`, {
      nombre: form.value.nombre,
      segundo_nombre: form.value.segundo_nombre || null,
      primer_apellido: form.value.primer_apellido || null,
      segundo_apellido: form.value.segundo_apellido || null,
      email: form.value.email || null,
      celular: form.value.celular || null,
      fecha_nacimiento: form.value.fecha_nacimiento || null,
      genero: form.value.genero || null,
      ...(ofrecerWhatsApp.value && form.value.whatsapp
        ? { acepta_whatsapp: true }
        : {}),
      tipo: form.value.tipo,
      liberar_celular: liberarCelular || undefined,
    });
    const mismoTipo = form.value.tipo === tipo.value;
    // Con el correo de alguien dado de baja, se reactivó (con su historial).
    toast.exito(
      data.data.reactivado
        ? t("bajas.reactivado", { nombre: data.data.nombre_completo ?? "" })
        : t("miembros.creado"),
    );
    form.value = {
      nombre: "",
      segundo_nombre: "",
      primer_apellido: "",
      segundo_apellido: "",
      email: "",
      celular: "",
      fecha_nacimiento: "",
      genero: "",
      whatsapp: false,
      tipo: tipo.value,
    };
    abiertoAlta.value = false;
    if (mismoTipo) {
      recargarDesde1();
    }
  } catch (e) {
    const respuesta = axios.isAxiosError(e) ? e.response?.data : null;
    if (respuesta?.code === "PERSON_DEACTIVATED_MATCH") {
      coincidencia.value = {
        id: respuesta.meta.persona.id,
        nombre: respuesta.meta.persona.nombre,
        mensaje: respuesta.message,
      };
      return;
    }
    error.value = mensajeDeError(e);
    toast.error(error.value);
  } finally {
    guardando.value = false;
  }
}

async function reactivarCoincidencia(): Promise<void> {
  if (coincidencia.value === null) {
    return;
  }
  await reactivar(coincidencia.value.id, coincidencia.value.nombre);
  coincidencia.value = null;
  abiertoAlta.value = false;
}

watch(tipo, () => {
  form.value.tipo = tipo.value;
});
async function cargarSucursales(): Promise<void> {
  try {
    const { data } = await api.get<{ data: { id: string; nombre: string }[] }>(
      `${base.value}/sucursales`,
    );
    sucursales.value = data.data;
  } catch {
    // Sin permiso de sucursales o sin sedes: el filtro simplemente no aparece.
    sucursales.value = [];
  }
}

onMounted(() => {
  form.value.tipo = tipo.value;
  void cargarSucursales();
  void cargar();
  // Quien solo imparte ve a SUS alumnos (ADR 0095): los totales del negocio no son
  // suyos y la API no se los da.
  if (tipo.value === "miembro" && !esInstructor(sesion.usuario)) {
    void cargarResumen();
  }
});
</script>

<template>
  <section class="tu-pagina">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion
        :titulo="plural(sesion.terminologia.miembro)"
        :total="meta?.total ?? 0"
        :subtitulo="
          $t(
            sesion.esCitas
              ? 'operacion.admin.clientesCitas'
              : 'operacion.admin.clientesClases',
          )
        "
      />
      <BotonImportar
        v-if="puedeGestionar"
        ruta="importar"
        :texto="$t('nav.importar')"
      />
    </div>

    <!-- Cuántos hay y qué pide atención -->
    <TarjetasIndicadores
      v-if="tipo === 'miembro' && indicadores.length > 0"
      class="mt-6"
      :tarjetas="indicadores"
    />

    <div class="mt-6">
      <div class="min-w-0">
        <!-- Buscador + filtros + «Agregar» (estilo Acadion). -->
        <BarraListado
          v-model:busqueda="q"
          v-model:vista="vista"
          :filtros="filtrosDef"
          :valores="valoresFiltro"
          :placeholder="$t('miembros.buscar')"
          :puede-crear="puedeGestionar"
          :nuevo-texto="$t('miembros.crear')"
          @cambio-filtro="cambioFiltro"
          @limpiar="limpiarFiltros"
          @nuevo="abrirAlta"
        >
          <template #acciones>
            <SelectorColumnas
              v-if="vista !== 'cuadricula'"
              v-model="columnas"
              :columnas="columnasDisponibles"
            />
            <button
              v-if="puedeGestionar"
              type="button"
              class="tu-btn tu-btn-fantasma shrink-0 text-sm"
              data-prueba="exportar-miembros"
              :disabled="exportando"
              @click="exportar"
            >
              <IconoNav nombre="descargar" :tam="16" />
              {{ exportando ? $t("tabla.exportando") : $t("tabla.exportar") }}
            </button>
          </template>
        </BarraListado>

        <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
          {{ error }}
          <button type="button" class="tu-enlace ml-2" @click="cargar">
            {{ $t("comun.reintentar") }}
          </button>
        </p>
        <p
          v-if="cargando"
          class="tu-card mt-4 p-6 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>

        <template v-else-if="!error">
          <EstadoVacio
            v-if="miembros.length === 0 && filtrando"
            class="tu-card mt-4"
            icono="buscar"
            :titulo="$t('miembros.sinResultados')"
            data-prueba="sin-resultados"
          >
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              @click="quitarBusquedaYFiltros"
            >
              {{ $t("miembros.quitarBusqueda") }}
            </button>
          </EstadoVacio>
          <EstadoVacio
            v-else-if="miembros.length === 0"
            class="tu-card mt-4"
            icono="miembros"
            :titulo="$t('miembros.vacio')"
          />
          <div v-else class="mt-4 tu-card overflow-hidden">
            <!-- Cuadrícula: una tarjeta por persona (tocarla abre su detalle) -->
            <ul
              v-if="vista === 'cuadricula'"
              class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3"
            >
              <li v-for="m in miembros" :key="m.id">
                <div class="mb-tarjeta" @click="elegir(m, $event)">
                  <TarjetaMiembro
                    :nombre="m.nombre"
                    :nombre-completo="nombreCompleto(m)"
                    :email="m.email"
                    :celular="m.celular"
                    :sede="hayMultiSucursal ? m.sucursal?.nombre : null"
                    :activo="m.activo"
                    :nuevo="tipo === 'miembro' && m.primera_vez === true"
                    :resumen="m.dado_de_baja_en ? null : m.resumen"
                    :mostrar-plan="
                      !sesion.esCitas || Boolean(m.resumen?.membresia.plan)
                    "
                  >
                    <template #nombre>
                      <button
                        type="button"
                        class="mb-nombre"
                        @click="abrirDetalle(m)"
                      >
                        {{ nombreCompleto(m) }}
                      </button>
                    </template>
                  </TarjetaMiembro>
                  <p
                    v-if="m.dado_de_baja_en"
                    class="mt-2 text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ detalleBaja(m) }}
                  </p>
                </div>
              </li>
            </ul>
            <div v-else class="relative overflow-x-auto">
              <table class="tu-tabla" data-prueba="tabla-miembros">
                <thead>
                  <tr>
                    <th
                      class="whitespace-nowrap"
                      :aria-sort="
                        orden === 'nombre'
                          ? 'ascending'
                          : orden === '-nombre'
                            ? 'descending'
                            : 'none'
                      "
                    >
                      <button
                        type="button"
                        class="mb-ordenar"
                        data-prueba="ordenar-nombre"
                        :title="
                          orden === '-nombre'
                            ? $t('detalleMiembro.tabla.recientes')
                            : $t('tabla.ordenarPor', {
                                columna: $t('miembros.colNombre'),
                              })
                        "
                        @click="ordenarPorNombre"
                      >
                        {{ $t("miembros.colNombre") }}
                        <IconoNav
                          :nombre="
                            orden === 'nombre'
                              ? 'arriba'
                              : orden === '-nombre'
                                ? 'abajo'
                                : 'orden'
                          "
                          :tam="14"
                        />
                      </button>
                    </th>
                    <th
                      v-if="ver('app')"
                      class="whitespace-nowrap hidden lg:table-cell"
                    >
                      {{ $t("operacion.clientes.app") }}
                    </th>
                    <th
                      v-if="ver('plan')"
                      class="whitespace-nowrap hidden sm:table-cell"
                    >
                      {{ $t("operacion.clientes.plan") }}
                    </th>
                    <th
                      v-if="ver('saldo')"
                      class="whitespace-nowrap hidden md:table-cell"
                    >
                      {{ $t("operacion.clientes.saldo") }}
                    </th>
                    <th v-if="ver('estado')" class="whitespace-nowrap">
                      {{ $t("operacion.clientes.estado") }}
                    </th>
                    <th
                      v-if="ver('ultima')"
                      class="whitespace-nowrap hidden md:table-cell"
                    >
                      {{ $t("detalleMiembro.tabla.ultima") }}
                    </th>
                    <th
                      v-if="ver('proxima')"
                      class="whitespace-nowrap hidden lg:table-cell"
                    >
                      {{ $t("operacion.clientes.proxima") }}
                    </th>
                    <th
                      v-if="ver('registro')"
                      class="whitespace-nowrap hidden lg:table-cell"
                    >
                      {{ $t("operacion.clientes.registro") }}
                    </th>
                    <th
                      v-if="ver('sede')"
                      class="whitespace-nowrap hidden lg:table-cell"
                    >
                      {{ $t("detalleMiembro.tabla.sede") }}
                    </th>
                    <th class="w-px">
                      <span class="sr-only">{{
                        $t("detalleMiembro.acciones.menu")
                      }}</span>
                    </th>
                  </tr>
                </thead>
                <tbody class="tu-tabla-cuerpo">
                  <tr
                    v-for="m in miembros"
                    :key="m.id"
                    class="border-t cursor-pointer"
                    :style="{ borderColor: 'var(--borde)' }"
                    data-prueba="fila-miembro"
                    @click="elegir(m, $event)"
                  >
                    <td>
                      <div class="flex items-center gap-3">
                        <span class="hidden shrink-0 sm:block">
                          <AvatarIniciales :nombre="m.nombre" tam="md" />
                        </span>
                        <!-- En pantallas angostas el nombre se parte y el correo se
                             recorta: la tabla no se sale de la pantalla. -->
                        <span class="mb-persona">
                          <span class="flex flex-wrap items-center gap-x-2">
                            <button
                              type="button"
                              class="mb-nombre"
                              @click="abrirDetalle(m)"
                            >
                              {{ nombreCompleto(m) }}
                            </button>
                            <span
                              v-if="m.primera_vez"
                              class="mb-etiqueta-nuevo"
                              :title="$t('miembros.nuevoAyuda')"
                              >{{ $t("miembros.nuevo") }}</span
                            >
                          </span>
                          <span
                            v-if="m.email || m.celular"
                            class="block truncate text-xs"
                            :style="{ color: 'var(--texto-suave)' }"
                            :title="m.email ?? m.celular ?? undefined"
                            >{{ m.email ?? m.celular }}</span
                          >
                        </span>
                      </div>
                    </td>
                    <td
                      v-if="ver('app')"
                      class="whitespace-nowrap hidden lg:table-cell"
                    >
                      <span
                        class="block"
                        :style="{ color: 'var(--texto-suave)' }"
                        >{{
                          m.acceso_app
                            ? $t("operacion.clientes.conApp")
                            : invitados.has(m.id) || m.invitacion_pendiente
                              ? $t("operacion.clientes.invitacionPendiente")
                              : $t("operacion.clientes.sinApp")
                        }}</span
                      >
                      <button
                        v-if="
                          puedeInvitar &&
                          m.email &&
                          !m.acceso_app &&
                          m.invitacion_pendiente &&
                          !invitados.has(m.id)
                        "
                        class="tu-enlace text-xs"
                        type="button"
                        data-prueba="reenviar-invitacion"
                        :disabled="invitandoId === m.id"
                        @click.stop="reenviarInvitacion(m)"
                      >
                        {{
                          invitandoId === m.id
                            ? $t("operacion.clientes.reenviando")
                            : $t("operacion.clientes.reenviarCorto")
                        }}
                      </button>
                      <button
                        v-else-if="
                          puedeInvitar &&
                          m.email &&
                          !m.acceso_app &&
                          !invitados.has(m.id) &&
                          !m.dado_de_baja_en
                        "
                        class="tu-enlace text-xs"
                        type="button"
                        data-prueba="invitar-cuenta"
                        :title="$t('operacion.clientes.invitarAyuda')"
                        :disabled="invitandoId === m.id"
                        @click.stop="invitar(m)"
                      >
                        {{
                          invitandoId === m.id
                            ? $t("miembros.invitando")
                            : $t("operacion.clientes.invitarCorto")
                        }}
                      </button>
                    </td>
                    <td v-if="ver('plan')" class="hidden sm:table-cell">
                      <span class="flex max-w-[16rem] items-center gap-1.5">
                        <span class="truncate">{{
                          m.resumen?.membresia.plan ??
                          $t("tarjetas.sinMembresia")
                        }}</span>
                        <span
                          v-if="(m.resumen?.membresia.planes?.length ?? 0) > 1"
                          class="mb-mas"
                          data-prueba="mas-planes"
                          :title="
                            $t('operacion.clientes.otrosPlanes', {
                              lista: m
                                .resumen!.membresia.planes!.filter(
                                  (p) => p !== m.resumen!.membresia.plan,
                                )
                                .join(', '),
                            })
                          "
                          >+{{ m.resumen!.membresia.planes!.length - 1 }}</span
                        >
                      </span>
                      <span
                        v-if="
                          m.resumen?.membresia.plan &&
                          estadoMembresia(m.resumen.membresia, t)
                        "
                        class="block text-xs"
                        :style="{
                          color:
                            estadoMembresia(m.resumen.membresia, t)!.color ??
                            'var(--texto-suave)',
                        }"
                        >{{
                          estadoMembresia(m.resumen.membresia, t)!.texto
                        }}</span
                      >
                    </td>
                    <td
                      v-if="ver('saldo')"
                      class="whitespace-nowrap hidden md:table-cell tabular-nums"
                    >
                      {{ creditosDe(m.resumen?.membresia, t) ?? "—" }}
                    </td>
                    <!-- Estado como píldora: lo normal en verde, lo demás en su tono. -->
                    <td
                      v-if="ver('estado')"
                      class="whitespace-nowrap"
                      :title="
                        m.dado_de_baja_en
                          ? detalleBaja(m)
                          : $t('operacion.clientes.estadoAyuda')
                      "
                    >
                      <span
                        class="tu-pildora"
                        :style="{ '--tono': tonoEstado(m) }"
                        data-prueba="estado-miembro"
                        >{{ textoEstado(m) }}</span
                      >
                      <span
                        v-if="
                          !sesion.esCitas &&
                          !m.es_facturable &&
                          !m.dado_de_baja_en
                        "
                        class="block text-xs mt-1"
                        :style="{ color: 'var(--texto-suave)' }"
                        >{{ $t("detalleMiembro.tabla.noFacturable") }}</span
                      >
                    </td>
                    <td
                      v-if="ver('ultima')"
                      class="whitespace-nowrap hidden md:table-cell"
                      :title="m.resumen?.ultima?.clase ?? undefined"
                    >
                      <template v-if="m.resumen?.ultima">
                        <span class="block first-letter:uppercase">{{
                          cuandoReserva(
                            m.resumen.ultima.inicia_en,
                            m.resumen.ultima.zona_horaria,
                          )
                        }}</span>
                        <span
                          class="block max-w-[12rem] truncate text-xs"
                          :style="{ color: 'var(--texto-suave)' }"
                          >{{ m.resumen.ultima.clase }}</span
                        >
                      </template>
                      <span v-else :style="{ color: 'var(--texto-suave)' }"
                        >—</span
                      >
                    </td>
                    <td
                      v-if="ver('proxima')"
                      class="whitespace-nowrap hidden lg:table-cell"
                      :title="m.resumen?.proxima?.clase ?? undefined"
                    >
                      <template v-if="m.resumen?.proxima">
                        <span class="block first-letter:uppercase">{{
                          cuandoReserva(
                            m.resumen.proxima.inicia_en,
                            m.resumen.proxima.zona_horaria,
                          )
                        }}</span>
                        <span
                          class="block max-w-[12rem] truncate text-xs"
                          :style="{ color: 'var(--texto-suave)' }"
                          >{{ m.resumen.proxima.clase }}</span
                        >
                      </template>
                      <span v-else :style="{ color: 'var(--texto-suave)' }"
                        >—</span
                      >
                    </td>
                    <td
                      v-if="ver('registro')"
                      class="whitespace-nowrap hidden lg:table-cell"
                      :style="{ color: 'var(--texto-suave)' }"
                    >
                      {{ m.alta ? diaCorto(m.alta) : "—" }}
                    </td>
                    <td
                      v-if="ver('sede')"
                      class="whitespace-nowrap hidden lg:table-cell"
                      :style="{ color: 'var(--texto-suave)' }"
                    >
                      {{ m.sucursal?.nombre ?? "—" }}
                    </td>
                    <td class="text-right whitespace-nowrap">
                      <button
                        type="button"
                        class="tu-icono-btn"
                        aria-haspopup="menu"
                        :aria-expanded="menuFila?.m.id === m.id"
                        :aria-label="
                          $t('detalleMiembro.tabla.acciones', {
                            nombre: nombreCompleto(m),
                          })
                        "
                        data-prueba="menu-fila"
                        @click="abrirMenuFila(m, $event)"
                      >
                        <IconoNav nombre="puntos" :tam="18" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <PaginacionListado
              v-if="meta"
              :page="meta.page"
              :ultima-pagina="meta.ultima_pagina"
              :total="meta.total"
              :per-page="meta.per_page"
              :opciones-por-pagina="OPCIONES_POR_PAGINA"
              @ir="irPagina"
              @por-pagina="perPage = $event"
            />
          </div>
        </template>

        <!-- «…» de la fila elegida -->
        <MenuFlotante
          :abierto="menuFila !== null"
          :ancla="menuFila?.ancla ?? null"
          :ancho="208"
          @cerrar="menuFila = null"
        >
          <template v-if="menuFila">
            <button
              type="button"
              class="mb-menu"
              role="menuitem"
              @click="accionFila(abrirDetalle)"
            >
              {{ $t("detalleMiembro.acciones.verDetalle") }}
            </button>
            <RouterLink
              :to="{ name: 'ficha-miembro', params: { id: menuFila.m.id } }"
              class="mb-menu"
              role="menuitem"
              >{{ $t("detalleMiembro.acciones.fichaCompleta") }}</RouterLink
            >
            <template v-if="menuFila.m.dado_de_baja_en">
              <button
                v-if="puedeGestionar"
                type="button"
                class="mb-menu"
                role="menuitem"
                :disabled="reactivandoId === menuFila.m.id"
                @click="accionFila((m) => reactivar(m.id, nombreCompleto(m)))"
              >
                {{ $t("bajas.reactivar") }}
              </button>
            </template>
            <template v-else>
              <button
                v-if="puedeVender"
                type="button"
                class="mb-menu"
                role="menuitem"
                data-prueba="cobrar-fila"
                @click="accionFila(cobrar)"
              >
                {{ $t("detalleMiembro.acciones.cobrar") }}
              </button>
              <button
                v-if="puedeGestionar"
                type="button"
                class="mb-menu"
                role="menuitem"
                @click="accionFila(abrirEditar)"
              >
                {{ $t("detalleMiembro.acciones.editar") }}
              </button>
              <button
                v-if="
                  puedeInvitar &&
                  menuFila.m.email &&
                  !menuFila.m.acceso_app &&
                  !invitados.has(menuFila.m.id)
                "
                type="button"
                class="mb-menu"
                role="menuitem"
                @click="
                  accionFila((m) =>
                    m.invitacion_pendiente ? reenviarInvitacion(m) : invitar(m),
                  )
                "
              >
                {{
                  menuFila.m.invitacion_pendiente
                    ? $t("operacion.clientes.reenviar")
                    : $t("operacion.clientes.invitar")
                }}
              </button>
            </template>
          </template>
        </MenuFlotante>
      </div>
    </div>

    <!-- Alta de miembro (drawer lateral) -->
    <PanelLateral
      :abierto="abiertoAlta"
      :titulo="$t('miembros.nuevoTitulo')"
      @cerrar="cerrarAlta"
    >
      <form class="space-y-4" @submit.prevent="crear()">
        <div>
          <label class="tu-label" for="mn">{{ $t("miembros.nombre") }}</label>
          <input id="mn" v-model="form.nombre" class="tu-input" required />
        </div>
        <div>
          <label class="tu-label" for="mpa">{{
            $t("miembros.primerApellido")
          }}</label>
          <input id="mpa" v-model="form.primer_apellido" class="tu-input" />
        </div>
        <div>
          <label class="tu-label" for="me">{{ $t("miembros.email") }}</label>
          <input id="me" v-model="form.email" class="tu-input" type="email" />
        </div>
        <div>
          <label class="tu-label" for="mc">{{ $t("miembros.celular") }}</label>
          <input id="mc" v-model="form.celular" class="tu-input" type="tel" />
        </div>
        <CamposDatosPersonales
          id="ma"
          v-model:fecha="form.fecha_nacimiento"
          v-model:genero="form.genero"
        />
        <label v-if="ofrecerWhatsApp" class="flex items-start gap-2 text-sm">
          <input
            v-model="form.whatsapp"
            type="checkbox"
            class="mt-1"
            data-prueba="acepta-whatsapp"
          />
          <span>
            {{ $t("avisosWhatsApp.aceptaCliente") }}
            <span
              class="block text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("avisosWhatsApp.ayudaCliente") }}</span
            >
          </span>
        </label>
        <p v-if="mensaje" class="text-sm" :style="{ color: 'var(--exito)' }">
          {{ $t("miembros.creado") }}
        </p>
        <div
          v-if="coincidencia"
          class="rounded-lg border p-3 text-sm"
          role="status"
          :style="{ borderColor: 'var(--aviso)' }"
        >
          <p>{{ coincidencia.mensaje }}</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button
              type="button"
              class="tu-btn tu-btn-primario text-sm"
              :disabled="guardando || reactivandoId !== null"
              @click="reactivarCoincidencia"
            >
              {{ $t("bajas.reactivarlo", { nombre: coincidencia.nombre }) }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              :disabled="guardando"
              @click="crear(true)"
            >
              {{ $t("bajas.otraPersona") }}
            </button>
          </div>
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("bajas.otraPersonaAyuda") }}
          </p>
        </div>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            class="tu-btn tu-btn-fantasma"
            type="button"
            @click="cerrarAlta"
          >
            {{ $t("comun.cerrar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="guardando || form.nombre === ''"
            @click="crear()"
          >
            {{ guardando ? $t("miembros.creando") : $t("miembros.crear") }}
          </button>
        </div>
      </template>
    </PanelLateral>

    <PanelEditarMiembro
      v-if="editando"
      :miembro="editando"
      @cerrar="editando = null"
      @guardado="onGuardado"
    />

    <!-- El detalle de la persona (modal sobre la lista) -->
    <ModalMiembro
      :abierto="detalleAbierto"
      :persona="detalle"
      @cerrar="detalleAbierto = false"
      @editar="editarDetalle"
      @baja="editarDetalle"
      @cobrar="detalle && cobrar(detalle)"
    />

    <!-- Cobrarle: directo en la venta -->
    <PanelMiembro
      v-if="cobrandoA"
      :persona-id="cobrandoA.id"
      :nombre="nombreCompleto(cobrandoA)"
      venta
      @cerrar="alCerrarCobro"
    />
  </section>
</template>

<style scoped>
/* «+1»: tiene más planes vigentes (el detalle, al pasar el cursor). */
.mb-mas {
  flex-shrink: 0;
  padding: 0 0.35rem;
  border: 1px solid var(--borde);
  border-radius: 0.35rem;
  color: var(--texto-suave);
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
  cursor: default;
}
/* «Nuevo» (aún no asiste): etiqueta suave en ámbar. */
.mb-etiqueta-nuevo {
  padding: 0.05rem 0.45rem;
  border-radius: 0.4rem;
  background: color-mix(in srgb, var(--aviso) 14%, var(--superficie));
  color: var(--aviso);
  font-size: 0.7rem;
  font-weight: 600;
}
.mb-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}
.mb-punto {
  width: 0.5rem;
  height: 0.5rem;
  flex-shrink: 0;
  border-radius: 999px;
}
.tu-tabla-cuerpo tr {
  transition: background-color 0.12s ease;
}
.tu-tabla-cuerpo tr:hover {
  background: color-mix(in srgb, var(--primario) 5%, transparent);
}
/* Tarjeta de la vista en cuadrícula. */
.mb-tarjeta {
  display: block;
  height: 100%;
  padding: 1rem;
  border: 1px solid var(--borde);
  border-radius: 0.8rem;
  background: var(--superficie);
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    background-color 0.15s ease;
}
.mb-tarjeta:hover {
  border-color: color-mix(in srgb, var(--primario) 40%, var(--borde));
}
.mb-persona {
  min-width: 0;
  max-width: 10rem;
}
@media (min-width: 640px) {
  .mb-persona {
    max-width: 18rem;
    white-space: nowrap;
  }
  .mb-persona .mb-nombre {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
  }
}
/* El nombre abre el detalle: se ve como texto, se subraya al pasar. */
.mb-nombre {
  font-weight: 600;
  text-align: left;
}
.mb-nombre:hover {
  text-decoration: underline;
}
/* Encabezado que ordena: el texto y la flecha, sin aspecto de botón. */
.mb-ordenar {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font: inherit;
  color: inherit;
}
.mb-ordenar:hover {
  color: var(--texto);
}
.mb-menu {
  display: block;
  width: 100%;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  text-align: left;
  color: var(--texto);
  text-decoration: none;
}
.mb-menu:hover {
  background: var(--superficie-2);
}
</style>
