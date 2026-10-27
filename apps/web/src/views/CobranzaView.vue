<script setup lang="ts">
import axios from "axios";
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import CorregirCobro from "@/components/CorregirCobro.vue";
import CorteDeCaja from "@/components/CorteDeCaja.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import EstadoVencimiento, {
  type SituacionVencimiento,
} from "@/components/EstadoVencimiento.vue";
import PorConciliar from "@/components/PorConciliar.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import RangoFechas from "@/components/RangoFechas.vue";
import RegistrarPagoOrden, {
  type CuotaPorCobrar,
  type OrdenPorCobrar,
} from "@/components/RegistrarPagoOrden.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, erroresPorCampo, mensajeDeError } from "@/lib/api";
import { fechaEnUrl, opcionEnUrl, useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
import { dinero as dineroDelPais } from "@/lib/formato";
import { fechaLocal } from "@/lib/agenda";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { situacionDeFecha } from "@/lib/vencimiento";
import { confirmar } from "@/lib/confirmar";
import { useDescartarCambios } from "@/lib/editorFormulario";
import {
  puedeFacturarVentas,
  rutaFacturaDeVenta,
  rutaFacturarVenta,
} from "@/lib/facturarVenta";
import { claveSegunModalidad } from "@/lib/menu";
import { useSesionTenantStore } from "@/stores/sesionTenant";

// Lo que ya se debe (GET /cobranza/pendientes): compras sin pagar y citas o clases
// de pago que ya pasaron. Es el mismo criterio que el Inicio.
interface Pendiente {
  id: string;
  persona: { id: string; nombre: string } | null;
  concepto: string | null;
  total_minor: number;
  moneda: string;
  // Sus cuotas (B04), para exentarlas por separado.
  cuotas?: CuotaPorCobrar[];
  creada_en: string | null;
  sesion: {
    tipo: "clase" | "cita";
    profesional: string | null;
    inicia_en: string;
    zona_horaria: string | null;
    sucursal: string | null;
  } | null;
  // Una renovación: cuándo vence y si ya venció (con hoy del negocio).
  renovacion?: {
    vence_el: string;
    situacion: "proxima" | "hoy" | "vencida";
  } | null;
}
interface MetaPendientes {
  page: number;
  ultima_pagina: number;
  total: number;
  per_page: number;
  por_cobrar: { moneda: string; total_minor: number }[];
  proximas: number;
  // Renovaciones avisadas que aún no vencen: se pueden cobrar, pero no son un atraso.
  renovaciones_por_vencer?: number;
}
interface Moroso {
  id: string;
  acuerdo: string | null;
  persona: { id: string; nombre: string } | null;
  estado: string;
  intentos: number;
  gracia_hasta: string;
  // Escrito para el alumno: aquí no se muestra (se usa la clave del motivo).
  ultimo_motivo: string | null;
  // Por qué no se cobró, para quien administra (`cobranza.motivosMora.*`) y el detalle
  // (el motivo del banco o lo que escribió el equipo al registrar el fallo a mano).
  motivo_clave?: string | null;
  motivo_detalle?: string | null;
}
interface Pago {
  id: string;
  fecha: string | null;
  persona: string | null;
  monto_minor: number;
  moneda: string;
  estado: string;
  proveedor: string | null;
  metodo: string | null;
  // Con varias formas (ADR 0177): cuánto con cada una; su devolución elige por cuál.
  partes?: { metodo: string; monto_minor: number }[] | null;
  reembolsado_minor: number;
  reembolsable_minor: number;
  // Tiene devoluciones (aunque estén en curso o hayan fallado).
  con_reembolsos?: boolean;
  // Quién registró el cobro (en caja) o quién pagó en línea.
  registrado_por?: string | null;
  // Cobro en caja con error (ADR 0086/0087): su forma de caja y qué se puede hacer.
  metodo_caja?: string | null;
  corregible?: boolean;
  anulable?: boolean;
  // Aparte de su estado (ADR 0166): una incidencia abierta y cómo va el recibo.
  por_conciliar?: boolean;
  comprobante?: "enviado" | "fallido" | "incierto" | "pendiente" | null;
  // El banco del cliente lo disputó (ADR 0168).
  contracargo?: "abierto" | "ganado" | "perdido" | null;
  // Su venta y su factura, o si ya se puede facturar (ADR 0179).
  orden?: string | null;
  factura?: { id: string; uuid: string | null } | null;
  facturable?: boolean;
}
interface Suscripcion {
  id: string;
  persona: string | null;
  producto: string | null;
  precio_minor: number | null;
  moneda: string | null;
  proxima_cobro_en: string | null;
  estado: string;
  // Cómo va la renovación con hoy del negocio y los procesos de mora abiertos.
  situacion?: SituacionVencimiento | null;
  dias?: number;
  // Pago automático: la tarjeta con la que se cobra sola (y el último rechazo).
  pago_automatico: {
    marca: string | null;
    ultimos4: string | null;
    expira: string | null;
    error: string | null;
    // La paga su responsable con su tarjeta (familias, ADR 0129).
    paga?: string | null;
    // Lo que autorizó y el precio nuevo del plan, si cambió (ADR 0134).
    monto_minor?: number | null;
    precio_nuevo_minor?: number | null;
  } | null;
  // Se quitó y la pasarela aún no confirma que dejó de cobrar (ADR 0134).
  cancelando_pago_automatico?: boolean;
}

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
// En citas lo que da una compra son sesiones de un bono (ADR 0130): su variante.
function tm(clave: string): string {
  return t(claveSegunModalidad(clave, sesion.esCitas, te));
}
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeRegularizar = computed(() => sesion.puede("ordenes.gestionar"));
const puedeReembolsar = computed(() => sesion.puede("pagos.reembolsar"));
const puedeFacturar = computed(() => puedeFacturarVentas(sesion));

const pendientes = ref<Pendiente[]>([]);
const metaPendientes = ref<MetaPendientes | null>(null);
const paginaPendientes = ref(1);
const morosos = ref<Moroso[]>([]);
const pagos = ref<Pago[]>([]);
// Movimientos por páginas (el API busca, filtra y resume todo lo encontrado).
interface TotalMoneda {
  moneda: string;
  total_minor: number;
}
interface MetaMovimientos {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
  resumen: {
    cobros: number;
    cobrado: TotalMoneda[];
    reembolsado: TotalMoneda[];
    sin_aprobar: number;
  };
}
const metaPagos = ref<MetaMovimientos | null>(null);
const paginaPagos = ref(1);
const desdePagos = ref("");
const hastaPagos = ref("");
const suscripciones = ref<Suscripcion[]>([]);
const pagoAutomaticoDisponible = ref(false);
// Renovaciones y morosos por páginas (antes, las primeras 200 y todos), con búsqueda;
// el resumen del API (sin filtros) da los indicadores.
interface MetaLista {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
  resumen?: {
    total: number;
    automaticos?: number;
    // Renovaciones: las que aún no vencen, las vencidas sin cobrar y las en mora.
    proximas?: number;
    vencidas?: number;
    en_mora?: number;
  };
}
const metaSuscripciones = ref<MetaLista | null>(null);
const metaMorosos = ref<MetaLista | null>(null);
const buscarRenovaciones = ref("");
const filtroAutomatico = ref<"" | "si" | "no">("");
// Próximas o vencidas sin cobrar (sin filtro, todas).
const filtroSituacion = ref<"" | "proximas" | "vencidas">("");
const paginaRenovaciones = ref(1);
const buscarMorosos = ref("");
const paginaMorosos = ref(1);
// Los filtros de renovaciones y de mora van en la dirección, con claves propias (las
// de Movimientos, `q`, `estado`, `desde` y `hasta`, ya están tomadas). Antes de
// vigilarlos, para no cargar dos veces.
useFiltrosEnUrl({
  ren_q: buscarRenovaciones,
  automatico: opcionEnUrl(filtroAutomatico, ["", "si", "no"] as const),
  situacion: opcionEnUrl(filtroSituacion, [
    "",
    "proximas",
    "vencidas",
  ] as const),
  mora_q: buscarMorosos,
});
const conFiltrosRenovaciones = computed(
  () =>
    buscarRenovaciones.value.trim() !== "" ||
    filtroAutomatico.value !== "" ||
    filtroSituacion.value !== "",
);
async function cargarSuscripciones(): Promise<void> {
  const { data } = await api.get<{
    data: Suscripcion[];
    pago_automatico_disponible?: boolean;
    meta?: MetaLista;
  }>(`${base.value}/suscripciones`, {
    params: {
      page: paginaRenovaciones.value,
      ...(buscarRenovaciones.value.trim() !== ""
        ? { q: buscarRenovaciones.value.trim() }
        : {}),
      ...(filtroAutomatico.value !== ""
        ? { automatico: filtroAutomatico.value }
        : {}),
      ...(filtroSituacion.value !== ""
        ? { situacion: filtroSituacion.value }
        : {}),
    },
  });
  suscripciones.value = data.data;
  metaSuscripciones.value = data.meta ?? null;
  pagoAutomaticoDisponible.value = data.pago_automatico_disponible === true;
}
async function cargarMorosos(): Promise<void> {
  const { data } = await api.get<{ data: Moroso[]; meta?: MetaLista }>(
    `${base.value}/dunning`,
    {
      params: {
        page: paginaMorosos.value,
        ...(buscarMorosos.value.trim() !== ""
          ? { q: buscarMorosos.value.trim() }
          : {}),
      },
    },
  );
  morosos.value = data.data;
  metaMorosos.value = data.meta ?? null;
}
async function recargarLista(cargarla: () => Promise<void>): Promise<void> {
  try {
    await cargarla();
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}
function irPaginaRenovaciones(n: number): void {
  paginaRenovaciones.value = n;
  void recargarLista(cargarSuscripciones);
}
function irPaginaMorosos(n: number): void {
  paginaMorosos.value = n;
  void recargarLista(cargarMorosos);
}
let esperaRenovaciones: ReturnType<typeof setTimeout> | undefined;
watch([buscarRenovaciones, filtroAutomatico, filtroSituacion], () => {
  clearTimeout(esperaRenovaciones);
  esperaRenovaciones = setTimeout(() => {
    paginaRenovaciones.value = 1;
    void recargarLista(cargarSuscripciones);
  }, 300);
});
let esperaMorosos: ReturnType<typeof setTimeout> | undefined;
watch(buscarMorosos, () => {
  clearTimeout(esperaMorosos);
  esperaMorosos = setTimeout(() => {
    paginaMorosos.value = 1;
    void recargarLista(cargarMorosos);
  }, 300);
});
const avisoRenovacion = ref<string | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const vistaCargada = ref<VistaCobros | null>(null);
const accionando = ref<string | null>(null);

// Modal de reembolso.
const reembolsando = ref<Pago | null>(null);
const rMonto = ref("");
const rMotivo = ref("");
const rRevertir = ref(true);
// Pago en línea cuyo dinero el negocio ya devolvió por fuera (no se pide a la pasarela).
const rManual = ref(false);
const rProcesando = ref(false);
// Lo que no se pudo devolver, dentro del diálogo (no en la página de atrás): el error
// general arriba y cada error de campo junto a su campo.
const errorReembolso = ref<string | null>(null);
const erroresReembolso = ref<Record<string, string>>({});
const PASARELAS_EN_LINEA = ["stripe", "openpay", "mercadopago"];
const avisoReembolso = ref<string | null>(null);
// «Reconsultar este pago» (ADR 0227): devoluciones hechas en el panel de la pasarela.
const reconsultando = ref<string | null>(null);
function reconsultable(p: Pago): boolean {
  return (
    puedeReembolsar.value &&
    PASARELAS_EN_LINEA.includes(p.proveedor ?? "") &&
    (p.estado === "aprobado" || p.estado === "parcialmente_reembolsado")
  );
}
async function reconsultar(p: Pago): Promise<void> {
  if (reconsultando.value !== null) {
    return;
  }
  reconsultando.value = p.id;
  avisoReembolso.value = null;
  try {
    const { data } = await api.post<{ data: { registradas: number } }>(
      `${base.value}/pagos/${p.id}/reconsultar`,
    );
    avisoReembolso.value =
      data.data.registradas > 0
        ? t("reembolsosPago.reconsultaConDevoluciones", {
            n: data.data.registradas,
          })
        : t("reembolsosPago.reconsultaSinCambios");
    if (data.data.registradas > 0) {
      porConciliar.value?.cargar();
      await cargar();
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    reconsultando.value = null;
  }
}
const porConciliar = ref<InstanceType<typeof PorConciliar> | null>(null);
// El corte de caja expone su descarga para el encabezado de la vista.
const corteCaja = ref<InstanceType<typeof CorteDeCaja> | null>(null);

// Sin moneda, la del negocio (ADR 0099); con los números de su país.
function dinero(minor: number, moneda?: string | null): string {
  return dineroDelPais(minor, moneda || sesion.moneda, sesion.pais);
}
// En la zona horaria del negocio (ADR 0099), aunque quien mira esté en otra.
function fechaHora(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: sesion.zonaHoraria || undefined,
  }).format(new Date(iso));
}
function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  // Fecha-solo (YYYY-MM-DD) tal cual (en UTC, para no restar un día); un instante,
  // en la zona del negocio.
  if (!iso.includes("T")) {
    return new Intl.DateTimeFormat("es-MX", {
      dateStyle: "medium",
      timeZone: "UTC",
    }).format(new Date(`${iso}T00:00:00Z`));
  }
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeZone: sesion.zonaHoraria || undefined,
  }).format(new Date(iso));
}

/**
 * Cobros se divide en vistas (`?vista=`): por cobrar (morosos y cargos recurrentes),
 * movimientos (pagos y reembolsos), conciliación y caja. Cada una carga solo lo
 * suyo al abrirse; la conciliación y la caja cargan sus propios datos.
 */
type VistaCobros = "por-cobrar" | "movimientos" | "conciliacion" | "caja";
const VISTAS: VistaCobros[] = [
  "por-cobrar",
  "movimientos",
  "conciliacion",
  "caja",
];
const route = useRoute();
const vista = computed<VistaCobros>(() => {
  const v = route.query.vista;
  return typeof v === "string" && (VISTAS as string[]).includes(v)
    ? (v as VistaCobros)
    : "por-cobrar";
});
const TITULOS: Record<VistaCobros, string> = {
  "por-cobrar": "nav.vistas.porCobrar",
  movimientos: "nav.vistas.movimientos",
  conciliacion: "nav.vistas.conciliacion",
  caja: "nav.vistas.caja",
};

async function cargar(): Promise<void> {
  error.value = null;
  if (vista.value === "conciliacion" || vista.value === "caja") {
    cargando.value = false;
    return;
  }
  cargando.value = true;
  try {
    if (vista.value === "movimientos") {
      await cargarPagos();
    } else {
      const [pe] = await Promise.all([
        api.get<{ data: Pendiente[]; meta: MetaPendientes }>(
          `${base.value}/cobranza/pendientes`,
          { params: { page: paginaPendientes.value } },
        ),
        cargarMorosos(),
        cargarSuscripciones(),
      ]);
      pendientes.value = pe.data.data;
      metaPendientes.value = pe.data.meta;
      paginaPendientes.value = pe.data.meta.page;
    }
    vistaCargada.value = vista.value;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// Los movimientos de la página, con la búsqueda, el estado y las fechas elegidos.
let peticionPagos = 0;
async function cargarPagos(): Promise<void> {
  const mia = ++peticionPagos;
  const q = busqueda.value.trim();
  const { data } = await api.get<{ data: Pago[]; meta?: MetaMovimientos }>(
    `${base.value}/pagos`,
    {
      params: {
        page: paginaPagos.value,
        ...(q !== "" ? { q } : {}),
        ...(filtroEstado.value !== "" ? { estado: filtroEstado.value } : {}),
        ...(desdePagos.value !== "" ? { desde: desdePagos.value } : {}),
        ...(hastaPagos.value !== "" ? { hasta: hastaPagos.value } : {}),
      },
    },
  );
  if (mia === peticionPagos) {
    pagos.value = data.data;
    metaPagos.value = data.meta ?? null;
  }
}
async function recargarPagos(pagina = 1): Promise<void> {
  paginaPagos.value = pagina;
  error.value = null;
  try {
    await cargarPagos();
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

// Otra página de lo que se debe (sin recargar mora ni renovaciones).
async function irPendientes(n: number): Promise<void> {
  paginaPendientes.value = n;
  try {
    const { data } = await api.get<{
      data: Pendiente[];
      meta: MetaPendientes;
    }>(`${base.value}/cobranza/pendientes`, { params: { page: n } });
    pendientes.value = data.data;
    metaPendientes.value = data.meta;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

// Qué se debe, en una línea: la cita (con quién, cuándo) o la fecha de la compra.
function detallePendiente(p: Pendiente): string {
  if (p.sesion) {
    return [
      p.sesion.profesional
        ? t("cobranza.pendientes.con", { nombre: p.sesion.profesional })
        : null,
      new Intl.DateTimeFormat("es-MX", {
        timeZone: p.sesion.zona_horaria ?? undefined,
        weekday: "short",
        day: "numeric",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
        hour12: false,
      }).format(new Date(p.sesion.inicia_en)),
      p.sesion.sucursal,
    ]
      .filter(Boolean)
      .join(" · ");
  }
  return t("cobranza.pendientes.comprada", { fecha: fecha(p.creada_en) });
}

// Registrar el pago de lo que se debe (RegistrarPagoOrden): la orden elegida.
const cobrando = ref<OrdenPorCobrar | null>(null);
const avisoCobro = ref<string | null>(null);
function abrirCobro(p: Pendiente): void {
  avisoCobro.value = null;
  cobrando.value = {
    id: p.id,
    persona: p.persona?.nombre ?? null,
    concepto: p.concepto,
    total_minor: p.total_minor,
    moneda: p.moneda,
    cuotas: p.cuotas ?? [],
  };
}
async function alRegistrar(aviso: string): Promise<void> {
  avisoCobro.value = aviso;
  cobrando.value = null;
  await irPendientes(paginaPendientes.value);
}

function marca(m: string | null): string {
  return m ? m.charAt(0).toUpperCase() + m.slice(1) : "";
}

// Por qué alguien está en mora, dicho a quien administra (qué pasó y qué puede hacer).
// Nunca la frase que se le manda al alumno (`ultimo_motivo`); sin clave, el detalle
// que haya o «Cobro no realizado».
function claveMora(m: Moroso): string | null {
  const clave = m.motivo_clave
    ? `cobranza.motivosMora.${m.motivo_clave}`
    : null;
  return clave !== null && te(clave) ? clave : null;
}
function motivoMora(m: Moroso): string {
  const clave = claveMora(m);
  const detalle = m.motivo_detalle?.trim() || null;
  if (clave !== null) {
    return t(clave, { detalle: detalle ?? "" });
  }
  return detalle ?? t("cobranza.motivosMora.sinClave");
}
// Con clave, el detalle (p. ej. el motivo del banco) va debajo, en su línea.
function detalleMora(m: Moroso): string | null {
  return claveMora(m) !== null ? m.motivo_detalle?.trim() || null : null;
}

// La gracia ya pasó: se dice así (la suspensión llega en el siguiente intento).
function graciaVencida(fecha: string): boolean {
  return (
    situacionDeFecha(diaDeGracia(fecha), hoyEnNegocio(sesion.zonaHoraria))
      .situacion === "vencida"
  );
}
// `gracia_hasta` es un instante (UTC): su día es el del negocio, no el de UTC.
function diaDeGracia(instante: string): string {
  return fechaLocal(instante, sesion.zonaHoraria);
}

// Cómo va una renovación (próxima, hoy, vencida, en mora, suspendida o en pausa). Si
// el API no la manda, el estado de la suscripción y, sin él, la fecha con hoy del
// negocio (EstadoVencimiento la calcula).
function situacionRenovacion(s: Suscripcion): SituacionVencimiento | undefined {
  if (s.situacion) {
    return s.situacion;
  }
  const porEstado: Record<string, SituacionVencimiento> = {
    pausado: "pausada",
    suspendido: "suspendida",
    en_mora: "en_mora",
  };
  return porEstado[s.estado];
}

// Correo al alumno con el enlace para activar su pago automático.
async function invitarPagoAutomatico(s: Suscripcion): Promise<void> {
  accionando.value = s.id;
  error.value = null;
  avisoRenovacion.value = null;
  try {
    await api.post(
      `${base.value}/suscripciones/${s.id}/pago-automatico/solicitar`,
      {},
    );
    avisoRenovacion.value = t("pagoAutomatico.invitado");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = null;
  }
}

async function quitarPagoAutomatico(s: Suscripcion): Promise<void> {
  if (
    !(await confirmar(t("pagoAutomatico.confirmarQuitarNegocio"), {
      peligro: true,
    }))
  ) {
    return;
  }
  accionando.value = s.id;
  error.value = null;
  try {
    await api.delete(`${base.value}/suscripciones/${s.id}/pago-automatico`);
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = null;
  }
}

async function regularizar(m: Moroso): Promise<void> {
  if (m.acuerdo === null) {
    return;
  }
  if (
    !(await confirmar(
      t("confirmaciones.regularizar", { persona: m.persona?.nombre ?? "" }),
      { aceptar: t("confirmaciones.regularizarAceptar") },
    ))
  ) {
    return;
  }
  accionando.value = m.id;
  error.value = null;
  try {
    await api.post(`${base.value}/acuerdos/${m.acuerdo}/regularizar`, {});
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = null;
  }
}

// Reembolsos ya hechos de un pago (quién, cuánto, cuándo y por qué).
interface Reembolso {
  id: string;
  monto_minor: number;
  moneda: string;
  estado: string;
  motivo: string | null;
  revirtio_creditos: boolean;
  via?: string | null;
  // Por qué no se hizo (fallida) o por qué no se sabe aún (sin confirmar).
  motivo_fallo?: string | null;
  actor: string | null;
  fecha: string | null;
}
const reembolsosDe = ref<string | null>(null);
const reembolsos = ref<Reembolso[]>([]);
async function verReembolsos(p: Pago): Promise<void> {
  if (reembolsosDe.value === p.id) {
    reembolsosDe.value = null;
    return;
  }
  try {
    const { data } = await api.get<{ data: Reembolso[] }>(
      `${base.value}/pagos/${p.id}/reembolsos`,
    );
    reembolsos.value = data.data;
    reembolsosDe.value = p.id;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

// Una llave por intento: repetir el envío (doble clic) no devuelve dos veces.
const rLlave = ref("");

// Cobro que se está corrigiendo (forma de pago o anulación) en la lista.
const corrigiendo = ref<string | null>(null);
async function alCorregirCobro(): Promise<void> {
  corrigiendo.value = null;
  await cargar();
}

// De un cobro mixto: por qué forma sale la devolución (la primera, por omisión).
const rMetodo = ref("");
// Con el motivo o el monto escritos y sin devolver, cerrar o salir pregunta antes.
const conReembolso = computed({
  get: () => reembolsando.value !== null,
  set: (v: boolean) => {
    if (!v) reembolsando.value = null;
  },
});
const descartarReembolso = useDescartarCambios({
  abierto: conReembolso,
  valor: () => [
    rMonto.value,
    rMotivo.value,
    rRevertir.value,
    rManual.value,
    rMetodo.value,
  ],
  guardando: rProcesando,
});
function abrirReembolso(p: Pago): void {
  rLlave.value = crypto.randomUUID();
  rMetodo.value = p.partes?.[0]?.metodo ?? "";
  rMonto.value = String(p.reembolsable_minor / 100);
  rMotivo.value = "";
  rRevertir.value = true;
  rManual.value = false;
  errorReembolso.value = null;
  erroresReembolso.value = {};
  descartarReembolso.fijar();
  reembolsando.value = p;
}
function cerrarReembolso(): void {
  void descartarReembolso.cerrar();
}

async function reembolsar(): Promise<void> {
  const p = reembolsando.value;
  if (p === null || rMotivo.value.trim() === "" || rProcesando.value) {
    return;
  }
  rProcesando.value = true;
  errorReembolso.value = null;
  erroresReembolso.value = {};
  try {
    const { data } = await api.post<{
      data: {
        estado: string;
        monto_minor: number;
        moneda: string;
        motivo_fallo: string | null;
      };
    }>(
      `${base.value}/pagos/${p.id}/reembolsos`,
      {
        monto_minor: Math.round(Number(rMonto.value) * 100),
        motivo: rMotivo.value.trim(),
        revertir_creditos: rRevertir.value,
        manual: rManual.value,
        ...(p.partes && p.partes.length > 1 ? { metodo: rMetodo.value } : {}),
      },
      { headers: { "Idempotency-Key": rLlave.value } },
    );
    // La pasarela no la hizo: el diálogo sigue abierto con el motivo, y el siguiente
    // intento (p. ej. «ya lo devolví por otro medio») lleva otra llave.
    if (data.data.estado === "fallido") {
      rLlave.value = crypto.randomUUID();
      errorReembolso.value = data.data.motivo_fallo
        ? t("reembolsosPago.rechazada", { motivo: data.data.motivo_fallo })
        : t("reembolsosPago.rechazadaSinMotivo");
      return;
    }
    avisoReembolso.value =
      data.data.estado === "pendiente"
        ? t("reembolsosPago.enProceso")
        : data.data.estado === "incierto"
          ? t("reembolsosPago.sinConfirmar")
          : t("reembolsosPago.hecha", {
              monto: dinero(data.data.monto_minor, data.data.moneda),
            });
    porConciliar.value?.cargar();
    reembolsando.value = null;
    await cargar();
  } catch (e) {
    // Rechazada por el servidor (un 4xx con su motivo): el reintento lleva otra llave.
    // Sin respuesta (red), la misma: el servidor pudo haberla hecho.
    if (
      axios.isAxiosError(e) &&
      e.response !== undefined &&
      e.response.status < 500
    ) {
      rLlave.value = crypto.randomUUID();
    }
    erroresReembolso.value = erroresPorCampo(e);
    errorReembolso.value =
      Object.keys(erroresReembolso.value).length > 0
        ? t("comun.revisaCampos")
        : mensajeDeError(e);
    await nextTick();
    document
      .querySelector<HTMLElement>('#form-reembolso [aria-invalid="true"]')
      ?.focus();
  } finally {
    rProcesando.value = false;
  }
}

// ---- Indicadores y filtros (patrón de los listados) ----
const busqueda = ref("");
const filtroEstado = ref<"" | "aprobado" | "otros">("");
// Los filtros de los movimientos van en la dirección: un enlace o volver atrás los
// conserva. Antes de vigilarlos, para no cargar dos veces.
useFiltrosEnUrl({
  q: busqueda,
  estado: opcionEnUrl(filtroEstado, ["", "aprobado", "otros"] as const),
  desde: fechaEnUrl(desdePagos),
  hasta: fechaEnUrl(hastaPagos),
});
// La búsqueda espera a que se deje de escribir; el estado y las fechas recargan ya.
let esperaBusqueda: ReturnType<typeof setTimeout> | undefined;
watch(busqueda, () => {
  clearTimeout(esperaBusqueda);
  esperaBusqueda = setTimeout(() => void recargarPagos(), 300);
});
watch([filtroEstado, desdePagos, hastaPagos], () => {
  if (
    desdePagos.value === "" ||
    hastaPagos.value === "" ||
    hastaPagos.value >= desdePagos.value
  ) {
    void recargarPagos();
  }
});
onBeforeUnmount(() => clearTimeout(esperaBusqueda));
const conFiltrosPagos = computed(
  () =>
    busqueda.value.trim() !== "" ||
    filtroEstado.value !== "" ||
    desdePagos.value !== "" ||
    hastaPagos.value !== "",
);
// Lo encontrado, en CSV (con los mismos filtros).
const descargandoPagos = ref(false);
async function descargarPagos(): Promise<void> {
  descargandoPagos.value = true;
  const q = busqueda.value.trim();
  try {
    const { data } = await api.get<Blob>(`${base.value}/pagos`, {
      params: {
        formato: "csv",
        ...(q !== "" ? { q } : {}),
        ...(filtroEstado.value !== "" ? { estado: filtroEstado.value } : {}),
        ...(desdePagos.value !== "" ? { desde: desdePagos.value } : {}),
        ...(hastaPagos.value !== "" ? { hasta: hastaPagos.value } : {}),
      },
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = "movimientos.csv";
    enlace.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    descargandoPagos.value = false;
  }
}
function limpiarFiltrosPagos(): void {
  busqueda.value = "";
  filtroEstado.value = "";
  desdePagos.value = "";
  hastaPagos.value = "";
}
/** «$1,234.00 MXN · $20.00 USD»: cada moneda por separado, nunca sumadas. */
function porMoneda(lista: TotalMoneda[]): string {
  return lista.length > 0
    ? lista
        .map((m) => `${dinero(m.total_minor, m.moneda)} ${m.moneda}`)
        .join(" · ")
    : dinero(0, sesion.moneda);
}
const indicadores = computed<Indicador[]>(() => {
  if (vista.value === "movimientos") {
    // De todo lo encontrado (no solo de esta página), sin el filtro de estado.
    const r = metaPagos.value?.resumen;
    return [
      {
        clave: "cobros",
        etiqueta: t("cobranzaVisual.kpi.cobros"),
        valor: String(r?.cobros ?? 0),
        icono: "ventas",
      },
      {
        clave: "cobrado",
        etiqueta: t("cobranzaVisual.kpi.cobrado"),
        valor: porMoneda(r?.cobrado ?? []),
        icono: "dinero",
      },
      {
        clave: "reembolsado",
        etiqueta: t("cobranzaVisual.kpi.reembolsado"),
        valor: porMoneda(r?.reembolsado ?? []),
        icono: "intercambio",
      },
      {
        clave: "revisar",
        etiqueta: t("cobranzaVisual.kpi.sinAprobar"),
        valor: String(r?.sin_aprobar ?? 0),
        icono: "reloj",
        aviso: (r?.sin_aprobar ?? 0) > 0,
      },
    ];
  }
  if (vista.value === "por-cobrar") {
    // Del resumen del negocio (sin búsqueda ni páginas).
    const resumenRenovaciones = metaSuscripciones.value?.resumen;
    const automaticos =
      resumenRenovaciones?.automaticos ??
      suscripciones.value.filter((x) => x.pago_automatico !== null).length;
    const renovaciones =
      resumenRenovaciones?.total ?? suscripciones.value.length;
    // «Próximas» son las que aún no vencen; las vencidas sin cobrar, aparte.
    const proximas = resumenRenovaciones?.proximas ?? renovaciones;
    const vencidas = resumenRenovaciones?.vencidas;
    const enMora = metaMorosos.value?.resumen?.total ?? morosos.value.length;
    const debe = metaPendientes.value;
    const lista: Indicador[] = [
      {
        clave: "porCobrar",
        etiqueta: t("cobranzaVisual.kpi.porCobrar"),
        valor:
          debe && debe.por_cobrar.length > 0
            ? debe.por_cobrar
                .map((m) => dinero(m.total_minor, m.moneda))
                .join(" + ")
            : dinero(0, sesion.moneda),
        icono: "dinero",
        aviso: (debe?.total ?? 0) - (debe?.renovaciones_por_vencer ?? 0) > 0,
      },
      {
        clave: "mora",
        etiqueta: t("cobranzaVisual.kpi.enMora"),
        valor: String(enMora),
        icono: "facturas",
        aviso: enMora > 0,
      },
      {
        clave: "renovaciones",
        etiqueta: t("cobranzaVisual.kpi.renovaciones"),
        valor: String(proximas),
        icono: "reloj",
      },
      ...(vencidas !== undefined
        ? [
            {
              clave: "vencidas",
              etiqueta: t("cobranzaVisual.kpi.vencidas"),
              valor: String(vencidas),
              icono: "reloj",
              aviso: vencidas > 0,
            },
          ]
        : []),
      {
        clave: "automatico",
        etiqueta: t("cobranzaVisual.kpi.automatico"),
        valor: t("cobranzaVisual.deTotal", {
          n: automaticos,
          total: renovaciones,
        }),
        icono: "hecho",
      },
    ];
    return sesion.esCitas && renovaciones === 0
      ? lista.filter(
          (k) =>
            k.clave !== "renovaciones" &&
            k.clave !== "vencidas" &&
            k.clave !== "automatico",
        )
      : lista;
  }
  return [];
});
// Pendiente en ámbar, confirmado en verde, devoluciones en gris e incidencias en
// rojo: un rechazo no se ve como un pendiente más.
const TONO_PAGO: Record<string, string> = {
  pendiente: "var(--aviso)",
  aprobado: "var(--exito)",
  parcialmente_reembolsado: "var(--texto-suave)",
  reembolsado: "var(--texto-suave)",
  anulado: "var(--texto-suave)",
  rechazado: "var(--error)",
  fallido: "var(--error)",
};

watch(vista, cargar, { immediate: true });
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t(TITULOS[vista])"
      :subtitulo="$t(`operacion.admin.cobros.${vista}`)"
    >
      <!-- Corte de caja: su descarga es una acción de la vista (el corte no se titula). -->
      <template v-if="vista === 'caja' && corteCaja" #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="corteCaja?.descargando || !corteCaja?.hayMovimientos"
          :aria-busy="corteCaja?.descargando || undefined"
          data-prueba="descargar-corte"
          @click="corteCaja?.descargar()"
        >
          <IconoNav nombre="descargar" :tam="16" />
          {{ $t("corteCaja.descargar") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <AvisoTono v-if="avisoReembolso" tono="aviso" class="mt-4" role="status">
      {{ avisoReembolso }}
    </AvisoTono>
    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else-if="!error || vistaCargada === vista">
      <TarjetasIndicadores
        v-if="indicadores.length > 0"
        class="mt-6"
        :tarjetas="indicadores"
      />
      <template v-if="vista === 'por-cobrar'">
        <AvisoTono v-if="avisoCobro" tono="exito" class="mt-4" role="status">
          {{ avisoCobro }}
        </AvisoTono>
        <!-- Lo que ya se debe: compras sin pagar y citas o clases ya pasadas -->
        <h2 id="pendientes" class="tu-titulo-seccion mt-6">
          {{ $t("cobranza.pendientes.titulo") }}
        </h2>
        <p
          v-if="metaPendientes && metaPendientes.proximas > 0"
          class="mt-1 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{
            $t(
              sesion.esCitas
                ? "cobranza.pendientes.proximas"
                : "cobranzaClases.proximas",
              { n: metaPendientes.proximas },
              metaPendientes.proximas,
            )
          }}
        </p>
        <EstadoVacio
          v-if="pendientes.length === 0"
          class="tu-card mt-3"
          icono="hecho"
          :titulo="$t('cobranza.pendientes.vacio')"
        />
        <div v-else class="mt-3 tu-card" data-prueba="pendientes">
          <!-- En el teléfono, una ficha por adeudo con «Registrar pago» a la vista. -->
          <div class="relative overflow-x-auto tu-tabla-adaptable">
            <table class="tu-tabla">
              <thead>
                <tr>
                  <th>{{ $t("cobranza.pendientes.colCliente") }}</th>
                  <th class="text-right">{{ $t("cobranza.colMonto") }}</th>
                  <th>{{ $t("cobranza.pendientes.colConcepto") }}</th>
                  <th class="text-right"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in pendientes" :key="p.id">
                  <td :data-etiqueta="$t('cobranza.pendientes.colCliente')">
                    <RouterLink
                      v-if="p.persona"
                      :to="{
                        name: 'ficha-miembro',
                        params: { id: p.persona.id },
                      }"
                      class="font-medium hover:underline"
                      >{{ p.persona.nombre }}</RouterLink
                    >
                    <span v-else>—</span>
                  </td>
                  <td
                    :data-etiqueta="$t('cobranza.colMonto')"
                    class="text-right tabular-nums font-medium"
                  >
                    {{ dinero(p.total_minor, p.moneda) }}
                  </td>
                  <td :data-etiqueta="$t('cobranza.pendientes.colConcepto')">
                    <div>
                      <span class="block">{{ p.concepto ?? "—" }}</span>
                      <!-- Una renovación dice cuándo vence (o que ya venció). -->
                      <template v-if="p.renovacion">
                        <span class="tu-sub">{{
                          $t("cobranza.pendientes.renovacion")
                        }}</span>
                        <EstadoVencimiento
                          :fecha="p.renovacion.vence_el"
                          :situacion="p.renovacion.situacion"
                        />
                      </template>
                      <span
                        v-else
                        class="block text-xs first-letter:uppercase"
                        :style="{ color: 'var(--texto-suave)' }"
                        >{{ detallePendiente(p) }}</span
                      >
                    </div>
                  </td>
                  <td class="tu-celda-acciones">
                    <button
                      v-if="puedeRegularizar"
                      type="button"
                      class="tu-btn tu-btn-secundario tu-btn-chico"
                      data-prueba="registrar-pago"
                      @click="abrirCobro(p)"
                    >
                      {{ $t("cobranza.pendientes.registrar") }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <PaginacionListado
            v-if="metaPendientes && metaPendientes.ultima_pagina > 1"
            :page="metaPendientes.page"
            :ultima-pagina="metaPendientes.ultima_pagina"
            :total="metaPendientes.total"
            :per-page="metaPendientes.per_page"
            @ir="irPendientes"
          />
        </div>

        <!-- Morosos (dunning) -->
        <h2
          v-if="!sesion.esCitas || morosos.length > 0 || buscarMorosos !== ''"
          id="morosos"
          class="tu-titulo-seccion mt-8"
        >
          {{ $t("cobranza.morosos") }}
        </h2>
        <EstadoVacio
          v-if="morosos.length === 0 && buscarMorosos === '' && !sesion.esCitas"
          class="tu-card mt-3"
          icono="hecho"
          :titulo="$t('cobranza.sinMorosos')"
        />
        <div
          v-else-if="morosos.length > 0 || buscarMorosos !== ''"
          class="mt-3 tu-card"
        >
          <!-- La búsqueda, dentro de la tarjeta de lo que busca (con muchos en mora). -->
          <div
            v-if="
              (metaMorosos?.resumen?.total ?? 0) > 10 || buscarMorosos !== ''
            "
            class="tu-filtros"
          >
            <label class="tu-buscar">
              <IconoNav nombre="buscar" :tam="16" />
              <input
                v-model="buscarMorosos"
                type="search"
                class="tu-input"
                :placeholder="$t('operacion.listadosTres.buscarPersona')"
                :aria-label="$t('operacion.listadosTres.buscarPersona')"
                data-prueba="buscar-morosos"
              />
            </label>
          </div>
          <p v-if="morosos.length === 0" class="tu-sin-resultados">
            {{ $t("operacion.listadosDos.sinResultados") }}
          </p>
          <!-- En el teléfono, una ficha: intentos y gracia como campos, Regularizar abajo. -->
          <div v-else class="relative overflow-x-auto tu-tabla-adaptable">
            <table class="tu-tabla">
              <thead>
                <tr>
                  <th>
                    {{ $t("cobranza.colAlumno") }}
                  </th>
                  <th>
                    {{ $t("cobranza.colEstado") }}
                  </th>
                  <th class="text-right hidden sm:table-cell">
                    {{ $t("cobranza.colIntentos") }}
                  </th>
                  <th class="hidden md:table-cell">
                    {{ $t("cobranza.colGracia") }}
                  </th>
                  <th class="text-right"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in morosos" :key="m.id">
                  <td :data-etiqueta="$t('cobranza.colAlumno')">
                    <span class="font-semibold">{{
                      m.persona?.nombre ?? "—"
                    }}</span>
                    <!-- Qué pasó, para quien administra (no la frase del alumno). -->
                    <span
                      class="tu-sub font-normal"
                      data-prueba="motivo-mora"
                      >{{ motivoMora(m) }}</span
                    >
                    <span v-if="detalleMora(m)" class="tu-sub font-normal">{{
                      detalleMora(m)
                    }}</span>
                  </td>
                  <td :data-etiqueta="$t('cobranza.colEstado')">
                    <span
                      class="tu-estado"
                      :style="{
                        '--tono':
                          m.estado === 'suspendido'
                            ? 'var(--error)'
                            : 'var(--aviso)',
                      }"
                      >{{ $t(`cobranza.estados.${m.estado}`) }}</span
                    >
                  </td>
                  <td
                    :data-etiqueta="$t('cobranza.colIntentos')"
                    class="text-right hidden sm:table-cell"
                  >
                    {{ m.intentos }}
                  </td>
                  <td
                    :data-etiqueta="$t('cobranza.colGracia')"
                    class="hidden md:table-cell"
                  >
                    <!-- El plazo antes de suspender: cuánto falta o desde cuándo venció. -->
                    <EstadoVencimiento
                      v-if="m.gracia_hasta"
                      :fecha="diaDeGracia(m.gracia_hasta)"
                      :etiqueta="
                        graciaVencida(m.gracia_hasta)
                          ? $t('cobranza.graciaVencida')
                          : undefined
                      "
                    />
                    <template v-else>—</template>
                  </td>
                  <td class="tu-celda-acciones">
                    <button
                      v-if="puedeRegularizar && m.acuerdo"
                      class="tu-btn tu-btn-secundario tu-btn-chico"
                      type="button"
                      :disabled="accionando === m.id"
                      :aria-busy="accionando === m.id || undefined"
                      @click="regularizar(m)"
                    >
                      {{
                        accionando === m.id
                          ? $t("cobranza.regularizando")
                          : $t("cobranza.regularizar")
                      }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <PaginacionListado
            v-if="metaMorosos && metaMorosos.ultima_pagina > 1"
            :page="metaMorosos.page"
            :ultima-pagina="metaMorosos.ultima_pagina"
            :total="metaMorosos.total"
            :per-page="metaMorosos.per_page"
            @ir="irPaginaMorosos"
          />
        </div>
      </template>

      <!-- Por conciliar: lo del dinero que alguien debe revisar -->
      <PorConciliar
        v-if="vista === 'conciliacion' && sesion.puede('facturacion.ver')"
        ref="porConciliar"
        class="mt-6"
        @cambio="cargar"
      />

      <!-- Corte de caja: movimientos por fecha y por quién -->
      <CorteDeCaja
        v-if="vista === 'caja' && sesion.puede('facturacion.ver')"
        ref="corteCaja"
        class="mt-6"
      />

      <!-- Pagos / reembolsos -->
      <template v-if="vista === 'movimientos'">
        <EstadoVacio
          v-if="pagos.length === 0 && !conFiltrosPagos"
          class="tu-card mt-5"
          icono="dinero"
          :titulo="$t('cobranza.sinPagos')"
        />
        <div v-else class="mt-5 tu-card">
          <div class="tu-filtros">
            <label class="tu-buscar">
              <IconoNav nombre="buscar" :tam="16" />
              <input
                v-model="busqueda"
                type="search"
                class="tu-input"
                :placeholder="$t('cobranzaVisual.buscar')"
                :aria-label="$t('cobranzaVisual.buscar')"
                data-prueba="buscar-pago"
              />
            </label>
            <div class="tu-segmentado" role="group">
              <button
                v-for="f in ['', 'aprobado', 'otros'] as const"
                :key="f"
                type="button"
                :aria-pressed="filtroEstado === f"
                @click="filtroEstado = f"
              >
                {{ $t(`cobranzaVisual.filtro.${f || "todos"}`) }}
              </button>
            </div>
            <!-- Las fechas del cobro, en días del negocio, con atajos de periodo -->
            <RangoFechas
              v-model:desde="desdePagos"
              v-model:hasta="hastaPagos"
              :etiqueta="$t('cobranzaVisual.fechas')"
              prueba="pagos"
              atajos
              :zona="sesion.zonaHoraria"
            />
            <button
              v-if="conFiltrosPagos"
              type="button"
              class="tu-btn tu-btn-discreto"
              @click="limpiarFiltrosPagos"
            >
              {{ $t("tabla.limpiar") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-secundario ml-auto"
              :disabled="descargandoPagos || pagos.length === 0"
              data-prueba="descargar-pagos"
              @click="descargarPagos"
            >
              <IconoNav nombre="descargar" :tam="16" />
              {{
                descargandoPagos
                  ? $t("operacion.listados.descargando")
                  : $t("operacion.listados.descargar")
              }}
            </button>
          </div>
          <!-- En el teléfono, una ficha por cobro: quién, cuánto y sus acciones a la vista. -->
          <div class="relative overflow-x-auto tu-tabla-adaptable">
            <table class="tu-tabla">
              <thead>
                <tr>
                  <th>
                    {{ $t("cobranza.colAlumno") }}
                  </th>
                  <th class="text-right">
                    {{ $t("cobranza.colMonto") }}
                  </th>
                  <th>
                    {{ $t("cobranza.colFecha") }}
                  </th>
                  <th>
                    {{ $t("cobranza.colEstado") }}
                  </th>
                  <th class="text-right"></th>
                </tr>
              </thead>
              <tbody>
                <template v-for="p in pagos" :key="p.id">
                  <tr>
                    <td :data-etiqueta="$t('cobranza.colAlumno')">
                      <span class="font-semibold">{{ p.persona ?? "—" }}</span>
                      <span
                        v-if="p.registrado_por"
                        class="tu-sub font-normal"
                        >{{
                          $t("corteCaja.registro", { quien: p.registrado_por })
                        }}</span
                      >
                    </td>
                    <td
                      :data-etiqueta="$t('cobranza.colMonto')"
                      class="text-right"
                    >
                      <div>
                        {{ dinero(p.monto_minor, p.moneda) }}
                        <span
                          v-if="p.con_reembolsos && !puedeReembolsar"
                          class="block ml-auto text-xs"
                          :style="{ color: 'var(--texto-suave)' }"
                        >
                          −{{ dinero(p.reembolsado_minor, p.moneda) }}
                        </span>
                        <!-- Abre el detalle de las devoluciones (información). -->
                        <button
                          v-else-if="p.con_reembolsos"
                          type="button"
                          class="block ml-auto text-xs underline-offset-2 hover:underline"
                          :style="{ color: 'var(--texto-suave)' }"
                          :aria-expanded="reembolsosDe === p.id"
                          :title="
                            reembolsosDe === p.id
                              ? $t('reembolsosPago.ocultar')
                              : $t('reembolsosPago.ver')
                          "
                          @click="verReembolsos(p)"
                        >
                          −{{ dinero(p.reembolsado_minor, p.moneda) }}
                        </button>
                      </div>
                    </td>
                    <td
                      :data-etiqueta="$t('cobranza.colFecha')"
                      class="whitespace-nowrap"
                      :style="{ color: 'var(--texto-suave)' }"
                    >
                      {{ fechaHora(p.fecha) }}
                    </td>
                    <td :data-etiqueta="$t('cobranza.colEstado')">
                      <div>
                        <span
                          class="tu-estado"
                          :style="{
                            '--tono': TONO_PAGO[p.estado] ?? 'var(--aviso)',
                          }"
                          >{{ $t(`cobranza.pagoEstados.${p.estado}`) }}</span
                        >
                        <!-- Pagado no es lo mismo que conciliado ni que el recibo enviado. -->
                        <span
                          v-if="p.por_conciliar"
                          class="block text-xs"
                          :style="{ color: 'var(--aviso)' }"
                          data-prueba="pago-por-conciliar"
                          >{{ $t("cobranza.porConciliar") }}</span
                        >
                        <span
                          v-if="p.contracargo"
                          class="block text-xs"
                          :style="{
                            color:
                              p.contracargo === 'ganado'
                                ? 'var(--texto-suave)'
                                : 'var(--error)',
                          }"
                          data-prueba="pago-contracargo"
                          >{{
                            $t(`cobranza.contracargo.${p.contracargo}`)
                          }}</span
                        >
                        <span
                          v-if="p.comprobante"
                          class="block text-xs"
                          :style="{
                            color:
                              p.comprobante === 'fallido'
                                ? 'var(--error)'
                                : p.comprobante === 'incierto'
                                  ? 'var(--aviso)'
                                  : 'var(--texto-suave)',
                          }"
                          data-prueba="pago-comprobante"
                          >{{
                            $t(`cobranza.comprobante.${p.comprobante}`)
                          }}</span
                        >
                      </div>
                    </td>
                    <td class="tu-celda-acciones">
                      <span
                        class="inline-flex flex-wrap items-center justify-end gap-2"
                      >
                        <RouterLink
                          v-if="p.factura"
                          class="tu-enlace text-sm"
                          :to="rutaFacturaDeVenta(p.factura.uuid)"
                          data-prueba="pago-facturado"
                          >{{ $t("facturarVenta.facturada") }}</RouterLink
                        >
                        <RouterLink
                          v-else-if="puedeFacturar && p.facturable && p.orden"
                          class="tu-enlace text-sm"
                          :to="rutaFacturarVenta({ orden: p.orden })"
                          data-prueba="pago-facturar"
                          >{{ $t("facturarVenta.facturar") }}</RouterLink
                        >
                        <!-- Solo a quien puede hacer algo: corregir o anular. -->
                        <button
                          v-if="
                            (p.corregible &&
                              sesion.puede('ordenes.gestionar')) ||
                            (p.anulable && puedeReembolsar)
                          "
                          class="tu-btn tu-btn-secundario tu-btn-chico"
                          type="button"
                          :aria-expanded="corrigiendo === p.id"
                          @click="
                            corrigiendo = corrigiendo === p.id ? null : p.id
                          "
                        >
                          {{ $t("cobranza.corregir") }}
                        </button>
                        <!-- Preguntar a la pasarela por devoluciones hechas en su panel. -->
                        <button
                          v-if="reconsultable(p)"
                          class="tu-btn tu-btn-secundario tu-btn-chico"
                          type="button"
                          :disabled="reconsultando !== null"
                          :aria-busy="reconsultando === p.id"
                          data-prueba="pago-reconsultar"
                          @click="reconsultar(p)"
                        >
                          {{ $t("reembolsosPago.reconsultar") }}
                        </button>
                        <!-- Devolver dinero: destructiva (el diálogo pide monto y motivo). -->
                        <button
                          v-if="puedeReembolsar && p.reembolsable_minor > 0"
                          class="tu-btn tu-btn-peligro tu-btn-chico"
                          type="button"
                          @click="abrirReembolso(p)"
                        >
                          {{ $t("reembolsosPago.reembolsarPago") }}
                        </button>
                      </span>
                    </td>
                  </tr>
                  <!-- Cobro en caja con error: corregir la forma o anularlo -->
                  <tr v-if="corrigiendo === p.id">
                    <td colspan="5" class="tu-celda-detalle">
                      <CorregirCobro
                        :base="base"
                        :pago="{
                          id: p.id,
                          metodo: p.metodo_caja ?? null,
                          corregible: p.corregible === true,
                          anulable: p.anulable === true,
                        }"
                        @cambiado="alCorregirCobro"
                      />
                    </td>
                  </tr>
                  <tr v-if="reembolsosDe === p.id">
                    <td colspan="5" class="tu-celda-detalle">
                      <div
                        class="rounded-xl border px-4 text-xs"
                        :style="{
                          borderColor: 'var(--borde)',
                          background: 'var(--fondo)',
                        }"
                      >
                        <div
                          v-for="r in reembolsos"
                          :key="r.id"
                          class="flex items-center justify-between gap-3 border-t py-2 first:border-t-0"
                          :style="{ borderColor: 'var(--borde)' }"
                        >
                          <span class="min-w-0">
                            <span class="block font-medium">{{
                              r.motivo ?? "—"
                            }}</span>
                            <span
                              class="block"
                              :style="{ color: 'var(--texto-suave)' }"
                              >{{ fechaHora(r.fecha) }}
                              <template v-if="r.actor">
                                ·
                                {{
                                  $t("reembolsosPago.por", { actor: r.actor })
                                }}</template
                              >
                              <template v-if="r.via">
                                ·
                                {{
                                  $t(`reembolsosPago.via.${r.via}`)
                                }}</template
                              >
                              <template v-if="r.revirtio_creditos">
                                ·
                                {{
                                  tm("reembolsosPago.creditosRevertidos")
                                }}</template
                              ></span
                            >
                            <span
                              v-if="r.motivo_fallo"
                              class="block"
                              style="color: var(--error)"
                              >{{ r.motivo_fallo }}</span
                            >
                          </span>
                          <span class="flex items-center gap-2 shrink-0">
                            <span class="font-semibold tabular-nums">{{
                              dinero(r.monto_minor, r.moneda)
                            }}</span>
                            <span
                              class="tu-estado"
                              :style="{
                                '--tono':
                                  r.estado === 'aprobado'
                                    ? 'var(--exito)'
                                    : 'var(--aviso)',
                              }"
                              >{{
                                $t(`reembolsosPago.estados.${r.estado}`)
                              }}</span
                            >
                          </span>
                        </div>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
          <p v-if="pagos.length === 0" class="tu-sin-resultados">
            {{ $t("cobranzaVisual.sinResultados") }}
          </p>
          <PaginacionListado
            v-if="metaPagos && metaPagos.ultima_pagina > 1"
            :page="metaPagos.page"
            :ultima-pagina="metaPagos.ultima_pagina"
            :total="metaPagos.total"
            :per-page="metaPagos.per_page"
            @ir="recargarPagos"
          />
        </div>
      </template>

      <!-- Renovaciones (cobro recurrente): próximas y vencidas sin cobrar -->
      <template
        v-if="
          vista === 'por-cobrar' &&
          (!sesion.esCitas || (metaSuscripciones?.resumen?.total ?? 0) > 0)
        "
      >
        <h2 class="tu-titulo-seccion mt-8" data-prueba="titulo-renovaciones">
          {{ $t("cobranza.renovaciones") }}
        </h2>
        <AvisoTono
          v-if="avisoRenovacion"
          tono="exito"
          class="mt-2"
          role="status"
        >
          {{ avisoRenovacion }}
        </AvisoTono>
        <EstadoVacio
          v-if="
            suscripciones.length === 0 &&
            !conFiltrosRenovaciones &&
            (metaSuscripciones?.resumen?.total ?? 0) === 0
          "
          class="tu-card mt-3"
          icono="reloj"
          :titulo="$t('cobranza.sinRenovaciones')"
        />
        <div v-else class="mt-3 tu-card">
          <!-- Los filtros, dentro de la tarjeta de lo que filtran (y en la dirección). -->
          <div class="tu-filtros" data-prueba="filtros-renovaciones">
            <label class="tu-buscar">
              <IconoNav nombre="buscar" :tam="16" />
              <input
                v-model="buscarRenovaciones"
                type="search"
                class="tu-input"
                :placeholder="$t('operacion.listadosTres.buscarPersona')"
                :aria-label="$t('operacion.listadosTres.buscarPersona')"
                data-prueba="buscar-renovaciones"
              />
            </label>
            <div
              class="tu-segmentado"
              role="group"
              :aria-label="$t('cobranza.situacion.etiqueta')"
            >
              <button
                v-for="f in ['', 'proximas', 'vencidas'] as const"
                :key="f"
                type="button"
                :aria-pressed="filtroSituacion === f"
                :data-prueba="`renovaciones-${f || 'todas'}`"
                @click="filtroSituacion = f"
              >
                {{ $t(`cobranza.situacion.${f || "todas"}`) }}
              </button>
            </div>
            <select
              v-if="!sesion.esCitas"
              v-model="filtroAutomatico"
              class="tu-input max-w-[14rem]"
              :aria-label="$t('operacion.listadosTres.pagoAutomatico')"
              data-prueba="filtro-automatico"
            >
              <option value="">
                {{ $t("operacion.listadosTres.automaticoTodos") }}
              </option>
              <option value="si">
                {{ $t("operacion.listadosTres.automaticoSi") }}
              </option>
              <option value="no">
                {{ $t("operacion.listadosTres.automaticoNo") }}
              </option>
            </select>
          </div>
          <p v-if="suscripciones.length === 0" class="tu-sin-resultados">
            {{ $t("operacion.listadosDos.sinResultados") }}
          </p>
          <!-- En el teléfono, una ficha: qué se renueva, cuándo y con qué se cobra. -->
          <div v-else class="relative overflow-x-auto tu-tabla-adaptable">
            <table class="tu-tabla">
              <thead>
                <tr>
                  <th>
                    {{ $t("cobranza.colAlumno") }}
                  </th>
                  <th class="text-right">
                    {{ $t("cobranza.colMonto") }}
                  </th>
                  <th class="hidden sm:table-cell">
                    {{ $t("cobranza.colMembresia") }}
                  </th>
                  <th>
                    {{ $t("cobranza.colProxima") }}
                  </th>
                  <th>
                    {{ $t("pagoAutomatico.colCobro") }}
                  </th>
                  <th class="text-right"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in suscripciones" :key="s.id">
                  <td
                    :data-etiqueta="$t('cobranza.colAlumno')"
                    class="font-semibold"
                  >
                    {{ s.persona ?? "—" }}
                  </td>
                  <td
                    :data-etiqueta="$t('cobranza.colMonto')"
                    class="text-right tabular-nums"
                  >
                    {{
                      s.precio_minor !== null
                        ? dinero(s.precio_minor, s.moneda)
                        : "—"
                    }}
                  </td>
                  <td
                    :data-etiqueta="$t('cobranza.colMembresia')"
                    class="hidden sm:table-cell"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ s.producto ?? "—" }}
                  </td>
                  <td :data-etiqueta="$t('cobranza.colProxima')">
                    <!-- Próxima, hoy, vencida, en mora, suspendida o en pausa. -->
                    <EstadoVencimiento
                      v-if="s.proxima_cobro_en"
                      :fecha="s.proxima_cobro_en"
                      :situacion="situacionRenovacion(s)"
                    />
                    <template v-else>—</template>
                  </td>
                  <td :data-etiqueta="$t('pagoAutomatico.colCobro')">
                    <div v-if="s.pago_automatico">
                      <span
                        class="tu-estado"
                        :style="{ '--tono': 'var(--exito)' }"
                        >{{
                          $t("pagoAutomatico.tarjeta", {
                            marca: marca(s.pago_automatico.marca),
                            ultimos4: s.pago_automatico.ultimos4 ?? "····",
                          })
                        }}</span
                      >
                      <p
                        v-if="s.pago_automatico.paga"
                        class="text-xs"
                        :style="{ color: 'var(--texto-suave)' }"
                      >
                        {{
                          $t("pagoAutomatico.paga", {
                            nombre: s.pago_automatico.paga,
                          })
                        }}
                      </p>
                      <p
                        v-if="s.pago_automatico.error"
                        class="text-xs"
                        style="color: var(--error)"
                      >
                        {{ s.pago_automatico.error }}
                      </p>
                      <p
                        v-if="
                          s.pago_automatico.precio_nuevo_minor &&
                          s.pago_automatico.monto_minor
                        "
                        class="text-xs"
                        :style="{ color: 'var(--aviso)' }"
                      >
                        {{
                          $t("pagoAutomatico.precioNuevoNegocio", {
                            monto: dinero(
                              s.pago_automatico.monto_minor,
                              s.moneda,
                            ),
                            nuevo: dinero(
                              s.pago_automatico.precio_nuevo_minor,
                              s.moneda,
                            ),
                          })
                        }}
                      </p>
                    </div>
                    <div v-else>
                      <span
                        v-if="s.cancelando_pago_automatico"
                        class="tu-estado mr-2"
                        :style="{ '--tono': 'var(--aviso)' }"
                        >{{ $t("pagoAutomatico.cancelando") }}</span
                      >
                      <span :style="{ color: 'var(--texto-suave)' }">{{
                        $t("pagoAutomatico.pagoManual")
                      }}</span>
                    </div>
                  </td>
                  <!-- Quitar el pago automático (destructiva) o invitar a activarlo. -->
                  <td class="tu-celda-acciones">
                    <button
                      v-if="s.pago_automatico && puedeRegularizar"
                      type="button"
                      class="tu-btn tu-btn-peligro tu-btn-chico"
                      :disabled="accionando !== null"
                      :aria-busy="accionando === s.id || undefined"
                      @click="quitarPagoAutomatico(s)"
                    >
                      {{ $t("pagoAutomatico.quitar") }}
                    </button>
                    <button
                      v-else-if="
                        !s.pago_automatico &&
                        puedeRegularizar &&
                        pagoAutomaticoDisponible
                      "
                      type="button"
                      class="tu-btn tu-btn-secundario tu-btn-chico"
                      :disabled="accionando !== null"
                      :aria-busy="accionando === s.id || undefined"
                      @click="invitarPagoAutomatico(s)"
                    >
                      {{ $t("pagoAutomatico.invitar") }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <PaginacionListado
            v-if="metaSuscripciones && metaSuscripciones.ultima_pagina > 1"
            :page="metaSuscripciones.page"
            :ultima-pagina="metaSuscripciones.ultima_pagina"
            :total="metaSuscripciones.total"
            :per-page="metaSuscripciones.per_page"
            @ir="irPaginaRenovaciones"
          />
        </div>
      </template>
    </template>

    <!-- Modal de reembolso -->
    <ModalDialogo
      :abierto="reembolsando !== null"
      :titulo="$t('cobranza.reembolso.titulo')"
      :ocupado="rProcesando"
      @cerrar="cerrarReembolso"
    >
      <template v-if="reembolsando">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ reembolsando.persona ?? "—" }} ·
          {{
            $t("cobranza.reembolso.reembolsable", {
              monto: dinero(
                reembolsando.reembolsable_minor,
                reembolsando.moneda,
              ),
            })
          }}
        </p>
        <AvisoTono
          v-if="errorReembolso"
          tono="peligro"
          class="mt-4"
          role="alert"
          data-prueba="error-reembolso"
        >
          {{ errorReembolso }}
        </AvisoTono>
        <form
          id="form-reembolso"
          class="mt-4 grid gap-4 sm:grid-cols-2"
          @submit.prevent="reembolsar"
        >
          <div>
            <label class="tu-label" for="rm"
              >{{ $t("cobranza.reembolso.monto") }}
              <span class="tu-obligatorio" aria-hidden="true">*</span></label
            >
            <input
              id="rm"
              v-model="rMonto"
              class="tu-input"
              type="number"
              min="0.01"
              :max="reembolsando.reembolsable_minor / 100"
              step="0.01"
              required
              :aria-invalid="erroresReembolso.monto_minor ? 'true' : undefined"
              :aria-describedby="
                erroresReembolso.monto_minor ? 'rm-error' : undefined
              "
            />
            <span
              v-if="erroresReembolso.monto_minor"
              id="rm-error"
              class="tu-error-campo"
              >{{ erroresReembolso.monto_minor }}</span
            >
          </div>
          <div>
            <label class="tu-label" for="rmt"
              >{{ $t("cobranza.reembolso.motivo") }}
              <span class="tu-obligatorio" aria-hidden="true">*</span></label
            >
            <input
              id="rmt"
              v-model="rMotivo"
              class="tu-input"
              required
              :placeholder="$t('cobranza.reembolso.motivoPh')"
              :aria-invalid="erroresReembolso.motivo ? 'true' : undefined"
              :aria-describedby="
                erroresReembolso.motivo ? 'rmt-error' : undefined
              "
            />
            <span
              v-if="erroresReembolso.motivo"
              id="rmt-error"
              class="tu-error-campo"
              >{{ erroresReembolso.motivo }}</span
            >
          </div>
          <div
            v-if="reembolsando.partes && reembolsando.partes.length > 1"
            class="sm:col-span-2"
          >
            <label class="tu-label" for="rfp">{{
              $t("cobranza.reembolso.salePor")
            }}</label>
            <select
              id="rfp"
              v-model="rMetodo"
              class="tu-input"
              data-prueba="reembolso-forma"
            >
              <option
                v-for="p in reembolsando.partes"
                :key="p.metodo"
                :value="p.metodo"
              >
                {{
                  $t("formasDePago.detalle", {
                    forma: $t(`ventas.metodos.${p.metodo}`),
                    monto: dinero(p.monto_minor, reembolsando.moneda),
                  })
                }}
              </option>
            </select>
            <span v-if="erroresReembolso.metodo" class="tu-error-campo">{{
              erroresReembolso.metodo
            }}</span>
          </div>
          <label
            class="flex items-center justify-between gap-3 text-sm sm:col-span-2"
          >
            <span>
              {{ tm("cobranza.reembolso.revertir") }}
              <span
                class="block text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ tm("cobranza.reembolso.revertirAyuda") }}</span
              >
            </span>
            <input v-model="rRevertir" type="checkbox" class="h-5 w-5" />
          </label>
          <label
            v-if="PASARELAS_EN_LINEA.includes(reembolsando.proveedor ?? '')"
            class="flex items-center justify-between gap-3 text-sm sm:col-span-2"
          >
            <span>
              {{ $t("reembolsosPago.manual") }}
              <span
                class="block text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ $t("reembolsosPago.manualAyuda") }}</span
              >
            </span>
            <input v-model="rManual" type="checkbox" class="h-5 w-5" />
          </label>
        </form>
      </template>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="rProcesando"
          @click="cerrarReembolso"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <!-- La confirmación final de devolver dinero: sólida de peligro. -->
        <button
          class="tu-btn tu-btn-solido tu-tono-peligro"
          type="submit"
          form="form-reembolso"
          data-prueba="confirmar-reembolso"
          :disabled="rProcesando || rMotivo.trim() === ''"
          :aria-busy="rProcesando || undefined"
        >
          {{
            rProcesando
              ? $t("cobranza.reembolso.procesando")
              : $t("cobranza.reembolso.confirmar")
          }}
        </button>
      </template>
    </ModalDialogo>
    <!-- Registrar el pago de lo que se debe -->
    <RegistrarPagoOrden
      :base="base"
      :orden="cobrando"
      @cerrar="cobrando = null"
      @registrado="alRegistrar"
    />
  </section>
</template>
