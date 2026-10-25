<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import BitacoraPlataforma from "@/components/BitacoraPlataforma.vue";
import EmbudoPlataforma from "@/components/EmbudoPlataforma.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacioListado from "@/components/EstadoVacioListado.vue";
import LayoutPlataforma, {
  type GrupoPlataforma,
} from "@/components/LayoutPlataforma.vue";
import EquipoPlataforma from "@/components/EquipoPlataforma.vue";
import ErroresPlataforma from "@/components/ErroresPlataforma.vue";
import IconoNav from "@/components/IconoNav.vue";
import InteresadosPlataforma from "@/components/InteresadosPlataforma.vue";
import DemosPlataforma from "@/components/DemosPlataforma.vue";
import ModalidadPlataforma from "@/components/ModalidadPlataforma.vue";
import OperacionPlataforma from "@/components/OperacionPlataforma.vue";
import PaisPlataforma from "@/components/PaisPlataforma.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import ParametrosPlataforma from "@/components/ParametrosPlataforma.vue";
import PestanasSeccion from "@/components/PestanasSeccion.vue";
import TarifasPlataforma from "@/components/TarifasPlataforma.vue";
import TerminologiaNegocio from "@/components/TerminologiaNegocio.vue";
import WhatsAppNegocio, {
  type EstadoWhatsAppNegocio,
} from "@/components/WhatsAppNegocio.vue";
import ModulosNegocio, {
  type ModuloDelNegocio,
} from "@/components/ModulosNegocio.vue";
import ComercialPlataforma from "@/components/ComercialPlataforma.vue";
import SolicitudesWellhub from "@/components/SolicitudesWellhub.vue";
import MedicionPlataforma from "@/components/MedicionPlataforma.vue";
import CondonarCargo from "@/components/CondonarCargo.vue";
import type { PlanCitas } from "@/lib/suscripcion";
import PlanEstudioPlataforma from "@/components/PlanEstudioPlataforma.vue";
import WhatsAppPlataforma from "@/components/WhatsAppPlataforma.vue";
import { mensajeDeError } from "@/lib/api";
import { SESION_PLATAFORMA_VENCIDA } from "@/lib/clientePlataforma";
import { ISOTIPO_AGENDAUNO } from "@/lib/marca";
import { confirmar } from "@/lib/confirmar";
import {
  PRODUCTOS,
  PRODUCTOS_LISTA,
  productoDeModalidad,
} from "@/lib/producto";
import { useFiltrosEnUrl, usePestanaEnUrl } from "@/lib/filtrosEnUrl";
import { urlCanonicaEstudio } from "@/lib/tenant";
import type { DatosTerminologia } from "@/lib/terminologia";
import { useToastStore } from "@/stores/toast";

/**
 * Súper admin de la plataforma (AgendaUno y TurnoUno). Entra cada operador con su
 * cuenta (ADR 0126), no con la sesión de un negocio. Pestañas: los negocios (con su
 * producto y su ficha: estado, uso, cargos, contacto y acciones de soporte), los
 * cobros de la renta, las tarifas, la configuración global, el equipo de operadores y
 * la bitácora de lo que cada uno cambió (ADR 0132).
 */
interface Estudio {
  slug: string;
  nombre: string;
  estado: string;
  // ISO 3166-1 (MX): define la moneda, el IVA y la factura de su suscripción.
  pais?: string;
  // Suspendido solo por renta (se reactiva al pagar) o por la plataforma (ADR 0073).
  suspendido_por?: string | null;
  estado_facturacion: string;
  perfil: string;
  modalidad: "clases" | "citas";
  modo_cobro: string;
  cuota_fija_minor: number;
  /** La cuota pactada puede ser en dólares (en México se cobra en pesos, ADR 0107). */
  cuota_fija_moneda?: string;
  /** El plan de un negocio de citas (ADR 0107). */
  plan?: {
    nivel: string;
    profesionales: number | null;
    periodicidad: string;
    cubierto_hasta: string | null;
  } | null;
  domiciliado?: boolean;
  trial_termina_en: string | null;
  moneda: string;
  publicado: boolean;
  en_directorio: boolean;
  ciudad: string | null;
  creado_en: string | null;
  uso?: { cantidad: number; metrica: string } | null;
  // Lo que debe, por moneda: no se suman pesos con dólares (ADR 0132).
  adeudos?: { moneda: string; minor: number }[];
}
interface Cargo {
  id: string;
  periodo: string;
  // `renta`, `plan`, `ajuste` o `timbres` (ADR 0107).
  concepto?: string;
  monto_minor: number;
  moneda: string;
  estado: string;
  vence_en: string | null;
  pagado_en: string | null;
  factura: string | null;
  vencido?: boolean;
  estudio?: string | null;
  estudio_slug?: string | null;
  // Para suspenderlo desde Cobros → Vencidos (ADR 0072).
  estudio_estado?: string | null;
  // `renta`: se suspendió solo y se reactiva al pagar (ADR 0073).
  estudio_suspendido_por?: string | null;
  // Pendiente: el superadmin lo puede condonar (queda cancelado).
  condonable?: boolean;
}
interface FichaApi extends Omit<Estudio, "uso"> {
  contacto: {
    nombre: string;
    email: string | null;
    whatsapp: string | null;
    // Confirmó su número con un código al registrarse (ADR 0070).
    whatsapp_verificado?: boolean;
  };
  onboarding_completo: boolean;
  // Quién lo suspendió por última vez, cuándo y por qué (ADR 0132).
  ultima_suspension?: {
    fecha: string;
    operador: string | null;
    motivo: string | null;
  } | null;
  // Solo clases o solo citas (ADR 0104): se cambia mientras no tenga sesiones ni
  // reservas, como la moneda antes de cobrar.
  modalidad_cambiable?: boolean;
  // Sus avisos por WhatsApp a clientes: solo los activa el superadmin (ADR 0083).
  whatsapp_clientes?: EstadoWhatsAppNegocio;
  // Módulos que se encienden por negocio (ADR 0123): apagados por omisión.
  modulos?: ModuloDelNegocio[];
  uso: { periodo: string; metrica: string; cantidad: number }[];
  cargos: Cargo[];
  // Lo pactado con fecha de vigencia (ADR 0181).
  cobro?: {
    desde: string | null;
    primer_periodo_abierto: string;
    proximo: {
      desde: string | null;
      modo_cobro: string;
      cuota_fija_minor: number;
      cuota_fija_moneda: string;
    } | null;
  };
  // Su plan de citas (ADR 0107): se ve y se cambia desde aquí.
  plan_citas?: PlanCitas | null;
  // Últimos avisos de la plataforma al dueño (ADR 0071).
  avisos?: {
    id: number;
    tipo: string;
    canal: string;
    estado: string;
    fecha: string | null;
    // Lo que Meta avisa del WhatsApp (ADR 0074).
    entregado?: boolean;
    leido?: boolean;
  }[];
}
interface ResumenCobros {
  pendiente_minor: number;
  vencido_minor: number;
  cobrado_mes_minor: number;
  estudios_con_adeudo: number;
  moneda: string;
  // Lo cobrado en otras monedas (dólares, fuera de México; ADR 0107): aparte.
  otras_monedas?: {
    moneda: string;
    pendiente_minor: number;
    vencido_minor: number;
    cobrado_mes_minor: number;
  }[];
}
interface Pasarela {
  proveedor: string;
  activa: boolean;
  modo: string;
  llaves_configuradas: string[];
  disponible?: boolean;
  lista?: boolean;
}
type Pestana =
  | "estudios"
  | "cobros"
  | "tarifas"
  | "parametros"
  | "configuracion"
  | "operacion"
  | "errores"
  | "interesados"
  | "demos"
  | "embudo"
  | "medicion"
  | "equipo"
  | "bitacora";

const ESTADOS_FACT = [
  "trial",
  "active",
  "past_due",
  "grace_period",
  "suspended",
  "cancelled",
];
const LLAVES_PASARELA: Record<string, string[]> = {
  stripe: ["secret_key", "webhook_secret"],
  mercadopago: ["access_token", "webhook_secret"],
  openpay: ["merchant_id", "private_key", "webhook_user", "webhook_password"],
};

const { t } = useI18n();
const toast = useToastStore();
const CLAVE_TOKEN = "tu.plataforma.token";
const apiUrl = import.meta.env.VITE_API_URL ?? "http://localhost:8000";
const cliente = axios.create({
  baseURL: apiUrl,
  headers: { Accept: "application/json" },
});

const token = ref<string>(leer());
const tokenInput = ref("");
// Entrada en dos pasos (ADR 0126): correo, contraseña y token; luego el código.
const emailInput = ref("");
const passwordInput = ref("");
const codigoInput = ref("");
const desafio = ref<string | null>(null);
const correoDestino = ref("");
const codigoDesarrollo = ref<string | null>(null);
const operador = ref<string | null>(null);
const autenticado = ref(false);
const cargando = ref(false);
// Con un token guardado no se pinta la puerta mientras se comprueba (sin parpadeo).
const comprobando = ref(token.value !== "");
const error = ref<string | null>(null);
// La pestaña, en la dirección (`?pestana=cobros`): recargar o un enlace abre la misma.
const PESTANAS: Pestana[] = [
  "estudios",
  "cobros",
  "tarifas",
  "parametros",
  "configuracion",
  "operacion",
  "errores",
  "interesados",
  "demos",
  "embudo",
  "medicion",
  "equipo",
  "bitacora",
];
const pestana = usePestanaEnUrl<Pestana>(PESTANAS);

function encabezados(): { headers: Record<string, string> } {
  return { headers: { Authorization: `Bearer ${token.value}` } };
}

function dinero(minor: number, moneda = "MXN"): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  const f = iso.length === 10 ? new Date(`${iso}T12:00:00`) : new Date(iso);
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(f);
}

function periodo(p: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    month: "long",
    year: "numeric",
  }).format(new Date(`${p}-15T12:00:00`));
}

function cobroLegible(e: Estudio): string {
  if (e.modo_cobro === "fijo") {
    return `${dinero(e.cuota_fija_minor, e.cuota_fija_moneda ?? "MXN")} ${t("suscripcion.plan.porMes")}`;
  }
  if (e.plan) {
    const n = e.plan.profesionales ?? 1;
    return t(
      "suscripcion.plan.resumen",
      { nivel: t(`suscripcion.niveles.${e.plan.nivel}`), n },
      n,
    );
  }
  return t(`cobro.modalidad.${e.modalidad}`);
}

/** Punto de color del estado: verde activo, ámbar prueba, rojo el resto. */
function colorEstado(estado: string): string {
  if (estado === "active") {
    return "var(--exito)";
  }
  if (estado === "trialing" || estado === "provisioning") {
    return "var(--aviso)";
  }
  return "var(--error)";
}

// ---- Estudios ----
const estudios = ref<Estudio[]>([]);
const columnas = computed(() => [
  { clave: "nombre", etiqueta: t("plataformaAdmin.estudios.colEstudio") },
  { clave: "producto", etiqueta: t("plataformaAdmin.estudios.colProducto") },
  { clave: "estado", etiqueta: t("plataformaAdmin.estudios.colEstado") },
  { clave: "cobro", etiqueta: t("plataformaAdmin.estudios.colCobro") },
  { clave: "uso", etiqueta: t("plataformaAdmin.estudios.colUso") },
  { clave: "adeudo", etiqueta: t("plataformaAdmin.estudios.colAdeudo") },
  { clave: "acciones", etiqueta: "", acciones: true },
]);
// Por producto, estado y adeudo (ADR 0132), buscando y por páginas en el servidor:
// la plataforma puede tener miles de negocios.
const ESTADOS_ESTUDIO = ["trialing", "active", "suspended", "cancelled"];
// 15 por página: la lista cabe en la pantalla sin un desplazamiento largo.
const POR_PAGINA_ESTUDIOS = 15;
const filtroEstudios = ref({ producto: "", estado: "", adeudo: false });
const buscarEstudios = ref("");
const paginaEstudios = ref(1);
const hayFiltrosEstudios = computed(
  () =>
    buscarEstudios.value.trim() !== "" ||
    filtroEstudios.value.producto !== "" ||
    filtroEstudios.value.estado !== "" ||
    filtroEstudios.value.adeudo,
);
const metaEstudios = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
  total_negocios?: number;
} | null>(null);
function irPaginaEstudios(n: number): void {
  paginaEstudios.value = n;
  void cargarEstudios().catch((err: unknown) =>
    toast.error(mensajeDeError(err)),
  );
}
function filtrarEstudios(): void {
  irPaginaEstudios(1);
}
// «Restablecer» del listado vacío: sin búsqueda ni filtros.
function limpiarFiltrosEstudios(): void {
  filtroEstudios.value = { producto: "", estado: "", adeudo: false };
  if (buscarEstudios.value !== "") {
    // Su vigilante vuelve a pedir la lista (una sola vez).
    buscarEstudios.value = "";
  } else {
    filtrarEstudios();
  }
}
// La búsqueda y los filtros de Negocios, en la dirección (un enlace o recargar los
// conserva). Antes de vigilarlos: lo leído de la dirección no pide de más.
useFiltrosEnUrl({
  buscar: buscarEstudios,
  producto: computed({
    get: () => filtroEstudios.value.producto,
    set: (v: string) => {
      if (v === "" || (PRODUCTOS_LISTA as readonly string[]).includes(v)) {
        filtroEstudios.value.producto = v;
      }
    },
  }),
  estado: computed({
    get: () => filtroEstudios.value.estado,
    set: (v: string) => {
      if (v === "" || ESTADOS_ESTUDIO.includes(v)) {
        filtroEstudios.value.estado = v;
      }
    },
  }),
  adeudo: computed({
    get: () => (filtroEstudios.value.adeudo ? "1" : ""),
    set: (v: string) => {
      filtroEstudios.value.adeudo = v === "1";
    },
  }),
});
let esperaEstudios: ReturnType<typeof setTimeout> | undefined;
watch(buscarEstudios, () => {
  clearTimeout(esperaEstudios);
  esperaEstudios = setTimeout(filtrarEstudios, 300);
});

async function cargarEstudios(): Promise<void> {
  const { data } = await cliente.get<{
    data: Estudio[];
    meta?: typeof metaEstudios.value;
  }>("/api/v1/plataforma/estudios", {
    ...encabezados(),
    params: {
      q: buscarEstudios.value.trim() || undefined,
      producto: filtroEstudios.value.producto || undefined,
      estado: filtroEstudios.value.estado || undefined,
      adeudo: filtroEstudios.value.adeudo ? 1 : undefined,
      page: paginaEstudios.value,
      per_page: POR_PAGINA_ESTUDIOS,
    },
  });
  estudios.value = data.data;
  metaEstudios.value = data.meta ?? null;
}

// Ficha de un estudio (panel lateral).
const ficha = ref<FichaApi | null>(null);
const fichaAbierta = ref(false);
const accionando = ref(false);
const edit = ref({
  modo_cobro: "activos",
  cuota: "0",
  moneda: "MXN",
  estado_facturacion: "",
  // Desde qué mes rige (AAAA-MM; ADR 0181): por omisión, el primero sin cobrar.
  desde: "",
});
const diasPrueba = ref(15);
// Por qué se suspende (opcional): se escribe en la ficha, junto a las acciones.
const motivoSuspension = ref("");

async function abrirFicha(e: Pick<Estudio, "slug">): Promise<void> {
  fichaAbierta.value = true;
  if (ficha.value?.slug !== e.slug) {
    motivoSuspension.value = "";
  }
  ficha.value = null;
  try {
    const { data } = await cliente.get<{ data: FichaApi }>(
      `/api/v1/plataforma/estudios/${e.slug}`,
      encabezados(),
    );
    ficha.value = data.data;
    edit.value = {
      modo_cobro: data.data.modo_cobro,
      cuota: String(data.data.cuota_fija_minor / 100),
      moneda: data.data.cuota_fija_moneda ?? "MXN",
      estado_facturacion: data.data.estado_facturacion,
      desde: data.data.cobro?.primer_periodo_abierto ?? "",
    };
  } catch (err) {
    toast.error(mensajeDeError(err));
    fichaAbierta.value = false;
  }
}

async function accion(
  hacer: () => Promise<unknown>,
  exito: string,
): Promise<boolean> {
  if (ficha.value === null) {
    return false;
  }
  const slug = ficha.value.slug;
  accionando.value = true;
  try {
    await hacer();
    toast.exito(exito);
    await cargarEstudios();
    // Aunque ya no esté en la página o los filtros actuales, su ficha sigue abierta.
    await abrirFicha({ slug });
    return true;
  } catch (err) {
    toast.error(mensajeDeError(err));
    return false;
  } finally {
    accionando.value = false;
  }
}

// Tras cambiar su modalidad: cambian su cobro, su lista y su ficha.
async function recargarFicha(): Promise<void> {
  const slug = ficha.value?.slug;
  try {
    await cargarEstudios();
  } catch (err) {
    toast.error(mensajeDeError(err));
  }
  if (slug !== undefined) {
    await abrirFicha({ slug });
  }
}

// La cuota fija en centavos; vacía o ilegible, null (antes se guardaba como $0).
const errorCuota = ref(false);
function leerCuota(valor: string | number): number | null {
  const texto = String(valor).trim();
  const n = Number(texto);
  return texto !== "" && Number.isFinite(n) && n >= 0
    ? Math.round(n * 100)
    : null;
}
function guardarCobro(): void {
  const f = ficha.value;
  if (f === null) {
    return;
  }
  const cuota = leerCuota(edit.value.cuota);
  errorCuota.value = edit.value.modo_cobro === "fijo" && cuota === null;
  if (errorCuota.value) {
    document.querySelector<HTMLElement>("#cf")?.focus();
    return;
  }
  void accion(
    () =>
      cliente.put(
        `/api/v1/plataforma/estudios/${f.slug}`,
        {
          modo_cobro: edit.value.modo_cobro,
          // Sin cuota fija, la que ya tenía se conserva aunque el campo quedara vacío.
          cuota_fija_minor: cuota ?? f.cuota_fija_minor,
          cuota_fija_moneda: edit.value.moneda,
          vigente_desde: edit.value.desde || undefined,
          estado_facturacion: edit.value.estado_facturacion,
        },
        encabezados(),
      ),
    t("plataformaAdmin.ficha.guardado"),
  );
}

// Terminología del negocio (ADR 0049), para ajustarla desde soporte.
async function cargarTerminologia(): Promise<DatosTerminologia> {
  const { data } = await cliente.get<{ data: DatosTerminologia }>(
    `/api/v1/plataforma/estudios/${ficha.value?.slug}/terminologia`,
    encabezados(),
  );
  return data.data;
}
async function guardarTerminologia(
  valores: Record<string, string | null>,
): Promise<DatosTerminologia> {
  const { data } = await cliente.put<{ data: DatosTerminologia }>(
    `/api/v1/plataforma/estudios/${ficha.value?.slug}/terminologia`,
    { valores },
    encabezados(),
  );
  return data.data;
}

function extenderPrueba(): void {
  const f = ficha.value;
  if (f === null) {
    return;
  }
  void accion(
    () =>
      cliente.post(
        `/api/v1/plataforma/estudios/${f.slug}/extender-prueba`,
        { dias: diasPrueba.value },
        encabezados(),
      ),
    t("plataformaAdmin.ficha.extendida"),
  );
}

async function suspender(): Promise<void> {
  const f = ficha.value;
  if (
    f === null ||
    !(await confirmar(
      t("plataformaAdmin.ficha.confirmarSuspender", { estudio: f.nombre }),
      { peligro: true },
    ))
  ) {
    return;
  }
  const motivo = motivoSuspension.value.trim();
  const listo = await accion(
    () =>
      cliente.post(
        `/api/v1/plataforma/estudios/${f.slug}/suspender`,
        { motivo: motivo || null },
        encabezados(),
      ),
    t("plataformaAdmin.ficha.suspendido"),
  );
  if (listo) {
    motivoSuspension.value = "";
  }
}

// Suspender desde Cobros → Vencidos, sin abrir la ficha del negocio.
const suspendiendo = ref<string | null>(null);
async function suspenderPorRenta(c: Cargo): Promise<void> {
  if (
    !c.estudio_slug ||
    !(await confirmar(
      t("plataformaAdmin.ficha.confirmarSuspender", {
        estudio: c.estudio ?? c.estudio_slug,
      }),
      { peligro: true },
    ))
  ) {
    return;
  }
  suspendiendo.value = c.id;
  try {
    await cliente.post(
      `/api/v1/plataforma/estudios/${c.estudio_slug}/suspender`,
      {
        motivo: t("plataformaAdmin.cobros.motivoRenta", {
          periodo: periodo(c.periodo),
        }),
      },
      encabezados(),
    );
    toast.exito(t("plataformaAdmin.ficha.suspendido"));
    await cargarCobros();
  } catch (err) {
    toast.error(mensajeDeError(err));
  } finally {
    suspendiendo.value = null;
  }
}

function reactivar(): void {
  const f = ficha.value;
  if (f === null) {
    return;
  }
  void accion(
    () =>
      cliente.post(
        `/api/v1/plataforma/estudios/${f.slug}/reactivar`,
        {},
        encabezados(),
      ),
    t("plataformaAdmin.ficha.reactivado"),
  );
}

// Condonar un cargo pendiente (desde Cobros o desde la ficha): queda cancelado, con el
// motivo en la bitácora; si ya no debe nada vencido, el negocio se reactiva.
const condonando = ref<string | null>(null);
async function condonar(
  c: Cargo,
  motivo: string,
  recargar: () => Promise<void>,
): Promise<void> {
  if (
    !(await confirmar(
      t("plataformaAdmin.cobros.confirmarCondonar", {
        monto: dinero(c.monto_minor, c.moneda),
        estudio: c.estudio ?? ficha.value?.nombre ?? "",
      }),
      { aceptar: t("plataformaAdmin.cobros.condonar"), peligro: true },
    ))
  ) {
    return;
  }
  condonando.value = c.id;
  try {
    await cliente.post(
      `/api/v1/plataforma/cargos/${c.id}/condonar`,
      { motivo },
      encabezados(),
    );
    toast.exito(t("plataformaAdmin.cobros.condonado"));
    await recargar();
  } catch (err) {
    toast.error(mensajeDeError(err));
  } finally {
    condonando.value = null;
  }
}

// ---- Cobros ----
const cargos = ref<Cargo[]>([]);
const resumen = ref<ResumenCobros | null>(null);
const filtro = ref({ estado: "", periodo: "", concepto: "" });
// Por páginas (antes, solo los primeros 200) y buscando el negocio.
const buscarCobros = ref("");
const paginaCobros = ref(1);
const metaCobros = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
} | null>(null);
function irPaginaCobros(n: number): void {
  paginaCobros.value = n;
  void cargarCobros();
}
function filtrarCobros(): void {
  paginaCobros.value = 1;
  void cargarCobros();
}
// Los de Cobros también, con sus propias claves (no chocan con las de Negocios).
const ESTADOS_COBRO = [
  "",
  "pendiente",
  "pagado",
  "sin_cargo",
  "cancelado",
  "vencidos",
];
const CONCEPTOS_COBRO = ["", "renta", "plan", "ajuste", "timbres"];
useFiltrosEnUrl({
  buscarCobro: buscarCobros,
  estadoCobro: computed({
    get: () => filtro.value.estado,
    set: (v: string) => {
      if (ESTADOS_COBRO.includes(v)) {
        filtro.value.estado = v;
      }
    },
  }),
  concepto: computed({
    get: () => filtro.value.concepto,
    set: (v: string) => {
      if (CONCEPTOS_COBRO.includes(v)) {
        filtro.value.concepto = v;
      }
    },
  }),
  periodo: computed({
    get: () => filtro.value.periodo,
    set: (v: string) => {
      if (v === "" || /^\d{4}-\d{2}$/.test(v)) {
        filtro.value.periodo = v;
      }
    },
  }),
});
let esperaCobros: ReturnType<typeof setTimeout> | undefined;
watch(buscarCobros, () => {
  clearTimeout(esperaCobros);
  esperaCobros = setTimeout(filtrarCobros, 300);
});
const cargandoCobros = ref(false);
const errorCobros = ref<string | null>(null);

async function cargarCobros(): Promise<void> {
  cargandoCobros.value = true;
  errorCobros.value = null;
  try {
    const { data } = await cliente.get<{
      data: Cargo[];
      resumen: ResumenCobros;
      meta?: typeof metaCobros.value;
    }>("/api/v1/plataforma/cobros", {
      ...encabezados(),
      params: {
        // «Vencidos» no es un estado del cargo: por pagar y ya vencido.
        estado:
          filtro.value.estado !== "" && filtro.value.estado !== "vencidos"
            ? filtro.value.estado
            : undefined,
        vencidos: filtro.value.estado === "vencidos" ? 1 : undefined,
        periodo: filtro.value.periodo || undefined,
        concepto: filtro.value.concepto || undefined,
        q: buscarCobros.value.trim() || undefined,
        page: paginaCobros.value,
      },
    });
    cargos.value = data.data;
    resumen.value = data.resumen;
    metaCobros.value = data.meta ?? null;
  } catch (err) {
    errorCobros.value = mensajeDeError(err);
  } finally {
    cargandoCobros.value = false;
  }
}

function estadoCargo(c: Cargo): string {
  return c.vencido === true
    ? t("plataformaAdmin.cargos.vencido")
    : t(`plataformaAdmin.cargos.${c.estado}`);
}

function colorCargo(c: Cargo): string {
  if (c.estado === "pagado") {
    return "var(--exito)";
  }
  return c.vencido === true ? "var(--error)" : "var(--aviso)";
}

// ---- Configuración: FacturAPI, pasarelas y legales ----
// Un apartado a la vez, en la dirección (`?config=pasarelas`; no `pestana`, que es la
// sección). Se ocultan sin desmontarse: lo escrito en uno no se pierde al ir a otro.
type ApartadoConfig =
  | "facturacion"
  | "pasarelas"
  | "whatsapp"
  | "legales"
  | "comercial"
  | "bienestar";
const APARTADOS_CONFIG: [ApartadoConfig, string][] = [
  ["facturacion", "plataformaAdmin.configuracion.facturacion"],
  ["pasarelas", "plataforma.pasarelas.titulo"],
  ["whatsapp", "plataformaAdmin.whatsapp.titulo"],
  ["legales", "plataforma.legales.titulo"],
  ["comercial", "suscripcion.plataforma.comercial.titulo"],
  ["bienestar", "plataformaAdmin.configuracion.bienestar"],
];
const apartadoConfig = usePestanaEnUrl<ApartadoConfig>(
  APARTADOS_CONFIG.map(([clave]) => clave),
  { clave: "config" },
);
const opcionesConfig = computed(() =>
  APARTADOS_CONFIG.map(([clave, texto]) => ({ clave, texto: t(texto) })),
);
const facturapiConfigurada = ref(false);
const llaveInput = ref("");
// A dónde llegan las alertas y las rentas vencidas (ADR 0072).
const correoAlertas = ref("");
// En qué países se ofrecen Wellhub y TotalPass (ADR 0202): códigos de dos letras.
const paisesBienestar = ref({ wellhub: "MX", totalpass: "MX" });
// Documentos legales: el borrador (texto y responsable) y lo publicado. Guardar no
// cambia lo que ven los usuarios; publicar crea la versión siguiente.
interface Publicado {
  version: number;
  vigente_desde: string;
}
const DOCUMENTOS_LEGALES = [
  {
    tipo: "aviso_privacidad",
    id: "lg-aviso",
    etiqueta: "plataforma.legales.aviso",
    publicar: "operacion.legales.publicarAviso",
    sinPublicar: "operacion.legales.sinPublicar",
  },
  {
    tipo: "terminos",
    id: "lg-terminos",
    etiqueta: "plataforma.legales.terminos",
    publicar: "operacion.legales.publicarTerminos",
    sinPublicar: "operacion.legales.sinPublicar",
  },
  {
    // Informativa (ADR 0206): no se acepta al registrarse.
    tipo: "cookies",
    id: "lg-cookies",
    etiqueta: "operacion.legales.cookies",
    publicar: "operacion.legales.publicarCookies",
    sinPublicar: "operacion.legales.sinPublicarCookies",
  },
] as const;
type TipoLegal = (typeof DOCUMENTOS_LEGALES)[number]["tipo"];
const legales = ref({
  aviso_privacidad: "",
  terminos: "",
  cookies: "",
  responsable: { nombre: "", domicilio: "", contacto: "", area: "" },
});
const publicados = ref<Record<TipoLegal, Publicado | null>>({
  aviso_privacidad: null,
  terminos: null,
  cookies: null,
});
// El responsable se escribe una vez: plegado, salvo que le falte algún dato al cargar
// (no se pliega mientras se escribe).
const responsableAbierto = ref(true);
function fechaPublicado(p: Publicado): string {
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
    new Date(p.vigente_desde),
  );
}
const pasarelas = ref<Pasarela[]>([]);
const pasarelaDraft = ref<
  Record<
    string,
    { activa: boolean; modo: string; llaves: Record<string, string> }
  >
>({});
const guardando = ref<string | null>(null);

// La configuración se carga al entrar; mientras tanto, o si falla, no se muestran sus
// formularios (vacíos, guardarlos borraría lo que había).
const cargandoConfig = ref(true);
const errorConfig = ref(false);
async function recargarConfiguracion(): Promise<void> {
  cargandoConfig.value = true;
  errorConfig.value = false;
  try {
    await cargarConfiguracion();
  } catch (err) {
    errorConfig.value = true;
    toast.error(mensajeDeError(err));
  } finally {
    cargandoConfig.value = false;
  }
}

async function cargarConfiguracion(): Promise<void> {
  const [cfg, pas, leg] = await Promise.all([
    cliente.get<{
      data: {
        facturapi_configurada: boolean;
        correo_alertas?: string | null;
        paises_bienestar?: { wellhub: string[]; totalpass: string[] };
      };
    }>("/api/v1/plataforma/configuracion", encabezados()),
    cliente.get<{ data: Pasarela[] }>(
      "/api/v1/plataforma/pasarelas",
      encabezados(),
    ),
    cliente.get<{
      data: {
        aviso_privacidad: string | null;
        terminos: string | null;
        cookies?: string | null;
        responsable?: {
          nombre: string;
          domicilio: string;
          contacto: string;
          area: string;
        };
        publicados?: Partial<Record<TipoLegal, Publicado | null>>;
      };
    }>("/api/v1/plataforma/legales", encabezados()),
  ]);
  facturapiConfigurada.value = cfg.data.data.facturapi_configurada;
  correoAlertas.value = cfg.data.data.correo_alertas ?? "";
  const paises = cfg.data.data.paises_bienestar;
  if (paises) {
    paisesBienestar.value = {
      wellhub: paises.wellhub.join(", "),
      totalpass: paises.totalpass.join(", "),
    };
  }
  pasarelas.value = pas.data.data;
  legales.value = {
    aviso_privacidad: leg.data.data.aviso_privacidad ?? "",
    terminos: leg.data.data.terminos ?? "",
    cookies: leg.data.data.cookies ?? "",
    responsable: {
      nombre: leg.data.data.responsable?.nombre ?? "",
      domicilio: leg.data.data.responsable?.domicilio ?? "",
      contacto: leg.data.data.responsable?.contacto ?? "",
      area: leg.data.data.responsable?.area ?? "",
    },
  };
  responsableAbierto.value = Object.values(legales.value.responsable).some(
    (v) => v.trim() === "",
  );
  publicados.value = {
    aviso_privacidad: null,
    terminos: null,
    cookies: null,
    ...leg.data.data.publicados,
  };
  for (const p of pasarelas.value) {
    pasarelaDraft.value[p.proveedor] = {
      activa: p.activa,
      modo: p.modo,
      llaves: {},
    };
  }
}

async function guardarLlave(quitar = false): Promise<void> {
  // Guardar vacío la borraba sin avisar; quitarla detiene la facturación de todos
  // (ADR 0132).
  if (!quitar && llaveInput.value.trim() === "") {
    return;
  }
  if (
    quitar &&
    !(await confirmar(t("plataforma.facturapi.confirmarQuitar"), {
      peligro: true,
    }))
  ) {
    return;
  }
  guardando.value = "facturapi";
  try {
    const { data } = await cliente.put<{
      data: { facturapi_configurada: boolean };
    }>(
      "/api/v1/plataforma/configuracion",
      { facturapi_llave: quitar ? null : llaveInput.value.trim() },
      encabezados(),
    );
    facturapiConfigurada.value = data.data.facturapi_configurada;
    llaveInput.value = "";
    toast.exito(
      quitar
        ? t("plataforma.facturapi.quitada")
        : t("plataforma.facturapi.guardado"),
    );
  } catch (err) {
    toast.error(mensajeDeError(err));
  } finally {
    guardando.value = null;
  }
}

async function guardarCorreoAlertas(): Promise<void> {
  guardando.value = "correo";
  try {
    const { data } = await cliente.put<{
      data: { correo_alertas: string | null };
    }>(
      "/api/v1/plataforma/configuracion",
      { correo_alertas: correoAlertas.value.trim() || null },
      encabezados(),
    );
    correoAlertas.value = data.data.correo_alertas ?? "";
    toast.exito(t("plataformaAdmin.correoAlertas.guardado"));
  } catch (err) {
    toast.error(mensajeDeError(err));
  } finally {
    guardando.value = null;
  }
}

/** «MX, co» → ["MX", "CO"]. */
function codigosDePais(texto: string): string[] {
  return texto
    .split(/[\s,]+/)
    .map((c) => c.trim().toUpperCase())
    .filter((c) => /^[A-Z]{2}$/.test(c));
}

async function guardarPaisesBienestar(): Promise<void> {
  guardando.value = "paises";
  try {
    const { data } = await cliente.put<{
      data: { paises_bienestar: { wellhub: string[]; totalpass: string[] } };
    }>(
      "/api/v1/plataforma/configuracion",
      {
        paises_bienestar: {
          wellhub: codigosDePais(paisesBienestar.value.wellhub),
          totalpass: codigosDePais(paisesBienestar.value.totalpass),
        },
      },
      encabezados(),
    );
    paisesBienestar.value = {
      wellhub: data.data.paises_bienestar.wellhub.join(", "),
      totalpass: data.data.paises_bienestar.totalpass.join(", "),
    };
    toast.exito(t("plataformaAdmin.paisesBienestar.guardado"));
  } catch (err) {
    toast.error(mensajeDeError(err));
  } finally {
    guardando.value = null;
  }
}

// `estado` es la clave de «guardando» mientras se guarda: al publicar, el aro va en
// el botón de publicar desde el inicio, no en «Guardar borrador».
async function guardarLegales(
  avisar = true,
  estado = "legales",
): Promise<boolean> {
  guardando.value = estado;
  try {
    const r = legales.value.responsable;
    await cliente.put(
      "/api/v1/plataforma/legales",
      {
        aviso_privacidad: legales.value.aviso_privacidad || null,
        terminos: legales.value.terminos || null,
        cookies: legales.value.cookies || null,
        responsable: {
          nombre: r.nombre || null,
          domicilio: r.domicilio || null,
          contacto: r.contacto || null,
          area: r.area || null,
        },
      },
      encabezados(),
    );
    if (avisar) {
      toast.exito(t("operacion.legales.borradorGuardado"));
    }
    return true;
  } catch (err) {
    toast.error(mensajeDeError(err));
    return false;
  } finally {
    guardando.value = null;
  }
}

// Publicar guarda antes el borrador (se publica lo que se ve) y crea la versión.
async function publicarLegal(tipo: TipoLegal): Promise<void> {
  if (
    !(await confirmar(t(`operacion.legales.confirmar.${tipo}`))) ||
    !(await guardarLegales(false, `publicar-${tipo}`))
  ) {
    return;
  }
  guardando.value = `publicar-${tipo}`;
  try {
    const { data } = await cliente.post<{ data: Publicado }>(
      `/api/v1/plataforma/legales/${tipo}/publicar`,
      {},
      encabezados(),
    );
    publicados.value = { ...publicados.value, [tipo]: data.data };
    toast.exito(
      t("operacion.legales.publicado", { version: data.data.version }),
    );
  } catch (err) {
    toast.error(mensajeDeError(err));
  } finally {
    guardando.value = null;
  }
}

async function guardarPasarela(proveedor: string): Promise<void> {
  const draft = pasarelaDraft.value[proveedor];
  if (draft === undefined) {
    return;
  }
  guardando.value = proveedor;
  const enviar = (confirmado: boolean) =>
    cliente.put<{ data: Pasarela }>(
      `/api/v1/plataforma/pasarelas/${proveedor}`,
      {
        activa: draft.activa,
        modo: draft.modo,
        credenciales: draft.llaves,
        confirmar_cambio_modo: confirmado,
      },
      encabezados(),
    );
  try {
    let respuesta;
    try {
      respuesta = await enviar(false);
    } catch (err) {
      // Cambiar de prueba a real (o al revés) quita las tarjetas de todos los
      // negocios: el servidor pide confirmarlo (ADR 0132).
      if (
        !axios.isAxiosError(err) ||
        err.response?.data?.code !== "GATEWAY_MODE_CHANGE_UNCONFIRMED"
      ) {
        throw err;
      }
      if (
        !(await confirmar(String(err.response.data.message ?? ""), {
          peligro: true,
        }))
      ) {
        return;
      }
      respuesta = await enviar(true);
    }
    const { data } = respuesta;
    const i = pasarelas.value.findIndex((p) => p.proveedor === proveedor);
    if (i >= 0) {
      pasarelas.value[i] = data.data;
    }
    draft.llaves = {}; // las llaves son de solo escritura
    toast.exito(t("plataforma.pasarelas.guardado"));
  } catch (err) {
    toast.error(mensajeDeError(err));
  } finally {
    guardando.value = null;
  }
}

// ---- Acceso ----
// Cuántas demos vienen (se ve junto a «Demos» en el menú); la sección la actualiza.
const demosProximas = ref<number | null>(null);
async function cargarDemosProximas(): Promise<void> {
  try {
    const { data } = await cliente.get<{
      meta: { resumen: { proximas: number } };
    }>("/api/v1/plataforma/demos", encabezados());
    demosProximas.value = data.meta.resumen.proximas;
  } catch {
    // Sin la cuenta: la sección la muestra al abrirla.
  }
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [, yo] = await Promise.all([
      cargarEstudios(),
      cliente.get<{ data: { administrador: { nombre: string } | null } }>(
        "/api/v1/plataforma/yo",
        encabezados(),
      ),
    ]);
    operador.value = yo.data.data.administrador?.nombre ?? null;
    autenticado.value = true;
    void cargarDemosProximas();
    // La configuración no impide entrar: si falla, se ve en su pestaña (ADR 0132).
    void recargarConfiguracion();
  } catch (err) {
    autenticado.value = false;
    const estado = axios.isAxiosError(err) ? err.response?.status : undefined;
    if (estado === 401 || estado === 403) {
      // El token no sirve: se olvida.
      error.value = t("plataforma.tokenInvalido");
      token.value = "";
      borrar();
    } else {
      // Sin red o la API falló: el token se queda para reintentar.
      error.value = mensajeDeError(err);
    }
  } finally {
    cargando.value = false;
    comprobando.value = false;
  }
}

// Los cobros se piden al abrir su pestaña, ya con la sesión (también al entrar por un
// enlace o recargar); las demás pestañas cargan lo suyo al montarse.
watch(
  [pestana, autenticado],
  ([actual, dentro]) => {
    if (actual === "cobros" && dentro) {
      void cargarCobros();
    }
  },
  { immediate: true },
);
// El menú lateral del superadmin: las secciones agrupadas por tema, con su ícono.
const GRUPOS: { clave: string; secciones: [Pestana, string][] }[] = [
  {
    clave: "negocios",
    secciones: [
      ["estudios", "comercio"],
      ["interesados", "captacion"],
      ["demos", "agenda"],
      ["embudo", "oportunidades"],
    ],
  },
  {
    clave: "dinero",
    secciones: [
      ["cobros", "dinero"],
      ["tarifas", "etiqueta"],
    ],
  },
  {
    clave: "plataforma",
    secciones: [
      ["parametros", "ajustes"],
      ["configuracion", "pasarelas"],
      ["operacion", "operacion"],
      ["errores", "pulso"],
      ["medicion", "reportes"],
    ],
  },
  {
    clave: "acceso",
    secciones: [
      ["equipo", "usuarios"],
      ["bitacora", "lista"],
    ],
  },
];
function iconoPestana(p: Pestana): string {
  return (
    GRUPOS.flatMap((g) => g.secciones).find(([clave]) => clave === p)?.[1] ??
    "panel"
  );
}
function textoPestana(p: Pestana): string {
  return p === "medicion"
    ? t("medicion.plataforma.pestana")
    : t(`plataformaAdmin.pestanas.${p}`);
}
const gruposSecciones = computed<GrupoPlataforma[]>(() =>
  GRUPOS.map((g) => ({
    clave: g.clave,
    texto: t(`plataformaAdmin.grupos.${g.clave}`),
    secciones: g.secciones.map(([clave, icono]) => ({
      clave,
      icono,
      texto: textoPestana(clave),
      cuenta:
        clave === "estudios"
          ? (metaEstudios.value?.total_negocios ?? estudios.value.length)
          : clave === "demos" && (demosProximas.value ?? 0) > 0
            ? (demosProximas.value ?? undefined)
            : undefined,
    })),
  })),
);

// Paso 1: correo, contraseña y token. Si coinciden, llega un código por correo.
async function entrar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await cliente.post<{
      data: { desafio: string; correo: string; codigo: string | null };
    }>("/api/v1/plataforma/entrar", {
      email: emailInput.value.trim(),
      password: passwordInput.value,
      token: tokenInput.value.trim(),
    });
    desafio.value = data.data.desafio;
    correoDestino.value = data.data.correo;
    codigoDesarrollo.value = data.data.codigo;
    codigoInput.value = "";
    passwordInput.value = "";
  } catch (err) {
    error.value = mensajeDeError(err);
  } finally {
    cargando.value = false;
  }
}

// Paso 2: el código abre la sesión del operador (su propio token).
async function verificar(): Promise<void> {
  if (desafio.value === null) {
    return;
  }
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await cliente.post<{ data: { token: string } }>(
      "/api/v1/plataforma/entrar/verificar",
      { desafio: desafio.value, codigo: codigoInput.value.trim() },
    );
    token.value = data.data.token;
    guardar(token.value);
    desafio.value = null;
    tokenInput.value = "";
    await cargar();
  } catch (err) {
    error.value = mensajeDeError(err);
    cargando.value = false;
  }
}

function empezarDeNuevo(): void {
  desafio.value = null;
  codigoInput.value = "";
  codigoDesarrollo.value = null;
  error.value = null;
}

async function salir(): Promise<void> {
  // Cierra la sesión en el servidor; aunque falle, aquí se olvida.
  try {
    await cliente.post("/api/v1/plataforma/salir", {}, encabezados());
  } catch {
    // sin red: el token vence solo
  }
  olvidarSesion();
}

// Sale o se le vence la sesión: nada de lo que veía queda a la vista al volver a entrar.
function olvidarSesion(): void {
  token.value = "";
  operador.value = null;
  autenticado.value = false;
  estudios.value = [];
  metaEstudios.value = null;
  ficha.value = null;
  fichaAbierta.value = false;
  borrar();
}

// Una sesión que vence a media tarea (12 h) vuelve a la entrada en lugar de dejar la
// pantalla a medias (ADR 0132).
// Las secciones (Tarifas, Operación, Demos…) tienen su propio cliente y lo avisan con
// un evento (lib/clientePlataforma.ts).
function sesionVencida(): void {
  if (autenticado.value) {
    olvidarSesion();
    error.value = t("plataforma.tokenInvalido");
  }
}
cliente.interceptors.response.use(undefined, (err: unknown) => {
  if (axios.isAxiosError(err) && err.response?.status === 401) {
    sesionVencida();
  }
  return Promise.reject(err);
});
window.addEventListener(SESION_PLATAFORMA_VENCIDA, sesionVencida);
onUnmounted(() =>
  window.removeEventListener(SESION_PLATAFORMA_VENCIDA, sesionVencida),
);

onMounted(() => {
  if (token.value !== "") {
    void cargar();
  }
});

// El token abre las llaves de Stripe de la plataforma: vive solo mientras dure la
// pestaña (sessionStorage), no para siempre en el navegador.
function leer(): string {
  let guardado: string | null = null;
  try {
    guardado = sessionStorage.getItem(CLAVE_TOKEN);
  } catch {
    // sin sessionStorage: se pide de nuevo
  }
  // Antes se guardaba en localStorage: se mueve a la sesión y se borra de ahí.
  try {
    const anterior = localStorage.getItem(CLAVE_TOKEN);
    if (anterior !== null) {
      localStorage.removeItem(CLAVE_TOKEN);
      if (guardado === null) {
        guardado = anterior;
        guardar(anterior);
      }
    }
  } catch {
    // sin localStorage: nada que mover
  }
  return guardado ?? "";
}
function guardar(v: string): void {
  try {
    sessionStorage.setItem(CLAVE_TOKEN, v);
  } catch {
    // sin sessionStorage: se mantiene solo en memoria
  }
}
function borrar(): void {
  for (const almacen of ["sessionStorage", "localStorage"] as const) {
    try {
      window[almacen].removeItem(CLAVE_TOKEN);
    } catch {
      // ignora
    }
  }
}
</script>

<template>
  <!-- Dentro: el armazón del panel (menú lateral con las secciones, barra superior y
       la tarjeta), sin la cara pública. Salir es solo «Cerrar sesión». -->
  <LayoutPlataforma
    v-if="autenticado && !comprobando"
    v-model="pestana"
    :grupos="gruposSecciones"
    :operador="operador"
    @salir="salir"
  >
    <section class="tu-pagina max-w-6xl">
      <!-- El ícono y el título de la sección, dentro del contenido (no en la barra). -->
      <div class="pl-encabezado">
        <span class="pl-icono" aria-hidden="true">
          <IconoNav :nombre="iconoPestana(pestana)" :tam="22" />
        </span>
        <EncabezadoSeccion :titulo="textoPestana(pestana)" class="flex-1" />
      </div>

      <!-- Negocios: la búsqueda y los filtros arriba, en la misma tarjeta; 15 por
           página en el servidor, con una sola paginación al pie. -->
      <div
        v-if="pestana === 'estudios'"
        class="mt-5 tu-card overflow-hidden"
        data-prueba="lista-estudios"
      >
        <div class="tu-filtros">
          <label class="tu-buscar">
            <IconoNav nombre="buscar" :tam="16" />
            <input
              v-model="buscarEstudios"
              type="search"
              class="tu-input"
              :placeholder="$t('operacion.listadosTres.buscarNegocio')"
              :aria-label="$t('operacion.listadosTres.buscarNegocio')"
              data-prueba="buscar-estudios"
            />
          </label>
          <select
            v-model="filtroEstudios.producto"
            class="tu-input max-w-[12rem]"
            :aria-label="$t('plataformaAdmin.estudios.filtroProducto')"
            data-prueba="producto-estudios"
            @change="filtrarEstudios"
          >
            <option value="">
              {{ $t("plataformaAdmin.estudios.filtroProducto") }}
            </option>
            <option v-for="p in PRODUCTOS_LISTA" :key="p" :value="p">
              {{ PRODUCTOS[p].nombre }}
            </option>
          </select>
          <select
            v-model="filtroEstudios.estado"
            class="tu-input max-w-[12rem]"
            :aria-label="$t('plataformaAdmin.estudios.filtroEstado')"
            data-prueba="estado-estudios"
            @change="filtrarEstudios"
          >
            <option value="">
              {{ $t("plataformaAdmin.estudios.filtroEstado") }}
            </option>
            <option v-for="e in ESTADOS_ESTUDIO" :key="e" :value="e">
              {{ $t(`plataformaAdmin.estados.${e}`) }}
            </option>
          </select>
          <label class="flex items-center gap-2 text-sm">
            <input
              v-model="filtroEstudios.adeudo"
              type="checkbox"
              data-prueba="adeudo-estudios"
              @change="filtrarEstudios"
            />
            {{ $t("plataformaAdmin.estudios.filtroAdeudo") }}
          </label>
        </div>
        <!-- En el teléfono, cada negocio es una ficha (su nombre, el título). -->
        <!-- En el teléfono, fichas compactas: 15 negocios sin bajar pantallas de más. -->
        <div
          class="relative overflow-x-auto tu-tabla-adaptable tu-fichas-compactas"
        >
          <table class="tu-tabla">
            <thead>
              <tr>
                <th
                  v-for="c in columnas"
                  :key="c.clave"
                  scope="col"
                  class="whitespace-nowrap"
                >
                  {{ c.etiqueta }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="estudios.length === 0">
                <td :colspan="columnas.length" class="text-center">
                  <EstadoVacioListado
                    :filtrado="hayFiltrosEstudios"
                    :mensaje="$t('plataforma.estudios.vacio')"
                    @restablecer="limpiarFiltrosEstudios"
                  />
                </td>
              </tr>
              <tr v-for="e in estudios" :key="e.slug">
                <!-- En la tabla cada fila mide dos renglones: el nombre y el slug no se
                     parten (uno largo se corta con «…» y se lee completo al pasar el
                     puntero o en su ficha); el estado y su fecha, tampoco. -->
                <td :data-etiqueta="$t('plataformaAdmin.estudios.colEstudio')">
                  <span
                    class="block font-medium sm:truncate sm:max-w-[12rem]"
                    :title="e.nombre"
                    >{{ e.nombre }}</span
                  >
                  <span
                    class="tu-sub font-mono sm:truncate sm:max-w-[12rem]"
                    :title="e.slug"
                    >{{ e.slug }}</span
                  >
                </td>
                <td :data-etiqueta="$t('plataformaAdmin.estudios.colProducto')">
                  {{ PRODUCTOS[productoDeModalidad(e.modalidad)].nombre }}
                </td>
                <td :data-etiqueta="$t('plataformaAdmin.estudios.colEstado')">
                  <!-- Un solo bloque: en la ficha del teléfono, todo junto al valor. -->
                  <div class="sm:whitespace-nowrap">
                    <span
                      class="tu-estado"
                      :style="{ '--tono': colorEstado(e.estado) }"
                    >
                      {{ $t(`plataformaAdmin.estados.${e.estado}`) }}
                    </span>
                    <span
                      v-if="e.estado === 'trialing' && e.trial_termina_en"
                      class="tu-sub"
                      >{{
                        $t("plataformaAdmin.pruebaHasta", {
                          fecha: fecha(e.trial_termina_en),
                        })
                      }}</span
                    >
                  </div>
                </td>
                <td :data-etiqueta="$t('plataformaAdmin.estudios.colCobro')">
                  {{ cobroLegible(e) }}
                </td>
                <td :data-etiqueta="$t('plataformaAdmin.estudios.colUso')">
                  <span v-if="e.uso" class="tabular-nums">
                    {{ e.uso?.cantidad }}
                    <span :style="{ color: 'var(--texto-suave)' }">{{
                      $t(`cobro.actual.${e.uso?.metrica}`)
                    }}</span>
                  </span>
                  <span v-else :style="{ color: 'var(--texto-suave)' }">
                    {{ $t("plataformaAdmin.estudios.sinUso") }}
                  </span>
                </td>
                <td :data-etiqueta="$t('plataformaAdmin.estudios.colAdeudo')">
                  <span
                    v-if="(e.adeudos ?? []).some((a) => a.minor > 0)"
                    class="font-medium tabular-nums"
                    :style="{ color: 'var(--error)' }"
                    >{{
                      (e.adeudos ?? [])
                        .map((a) => dinero(a.minor, a.moneda))
                        .join(" · ")
                    }}</span
                  >
                  <span v-else :style="{ color: 'var(--texto-suave)' }">
                    {{ $t("plataformaAdmin.estudios.alCorriente") }}
                  </span>
                </td>
                <td class="tu-celda-acciones">
                  <button
                    class="tu-enlace"
                    type="button"
                    @click="abrirFicha(e)"
                  >
                    {{ $t("plataformaAdmin.estudios.ver") }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <PaginacionListado
          v-if="metaEstudios"
          :page="metaEstudios.page"
          :ultima-pagina="metaEstudios.ultima_pagina"
          :total="metaEstudios.total"
          :per-page="metaEstudios.per_page"
          @ir="irPaginaEstudios"
        />
      </div>

      <!-- Cobros -->
      <div v-if="pestana === 'cobros'" class="mt-5">
        <div v-if="resumen" class="grid gap-3 grid-cols-2 lg:grid-cols-4">
          <div class="tu-card p-4">
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("plataformaAdmin.cobros.porCobrar") }}
            </p>
            <p class="mt-1 text-xl font-semibold tabular-nums">
              {{ dinero(resumen.pendiente_minor, resumen.moneda) }}
            </p>
          </div>
          <div class="tu-card p-4">
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("plataformaAdmin.cobros.vencido") }}
            </p>
            <p
              class="mt-1 text-xl font-semibold tabular-nums"
              :style="{
                color:
                  resumen.vencido_minor > 0 ? 'var(--error)' : 'var(--texto)',
              }"
            >
              {{ dinero(resumen.vencido_minor, resumen.moneda) }}
            </p>
          </div>
          <div class="tu-card p-4">
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("plataformaAdmin.cobros.cobradoMes") }}
            </p>
            <p class="mt-1 text-xl font-semibold tabular-nums">
              {{ dinero(resumen.cobrado_mes_minor, resumen.moneda) }}
            </p>
          </div>
          <div class="tu-card p-4">
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("plataformaAdmin.cobros.conAdeudo") }}
            </p>
            <p class="mt-1 text-xl font-semibold tabular-nums">
              {{ resumen.estudios_con_adeudo }}
            </p>
          </div>
        </div>
        <!-- Lo que se cobra en otra moneda (fuera de México): no se suma a los pesos. -->
        <p
          v-for="o in resumen?.otras_monedas ?? []"
          :key="o.moneda"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="otra-moneda"
        >
          {{
            $t("suscripcion.plataforma.otraMoneda", {
              moneda: o.moneda,
              porCobrar: dinero(o.pendiente_minor, o.moneda),
              vencido: dinero(o.vencido_minor, o.moneda),
              cobrado: dinero(o.cobrado_mes_minor, o.moneda),
            })
          }}
        </p>

        <div class="mt-5 tu-card p-5">
          <div class="flex flex-wrap items-end gap-3">
            <input
              v-model="buscarCobros"
              type="search"
              class="tu-input w-64"
              :placeholder="$t('operacion.listadosTres.buscarNegocio')"
              :aria-label="$t('operacion.listadosTres.buscarNegocio')"
              data-prueba="buscar-cobros"
            />
            <select
              v-model="filtro.estado"
              class="tu-input max-w-[12rem]"
              :aria-label="$t('plataformaAdmin.cobros.colEstado')"
              @change="filtrarCobros"
            >
              <option value="">{{ $t("plataformaAdmin.cobros.todos") }}</option>
              <option
                v-for="e in ['pendiente', 'pagado', 'sin_cargo', 'cancelado']"
                :key="e"
                :value="e"
              >
                {{ $t(`plataformaAdmin.cargos.${e}`) }}
              </option>
              <option value="vencidos">
                {{ $t("plataformaAdmin.cobros.vencidos") }}
              </option>
            </select>
            <select
              v-model="filtro.concepto"
              class="tu-input max-w-[12rem]"
              :aria-label="$t('operacion.listadosTres.concepto')"
              data-prueba="concepto-cobros"
              @change="filtrarCobros"
            >
              <option value="">
                {{ $t("operacion.listadosTres.todosConceptos") }}
              </option>
              <option
                v-for="c in ['renta', 'plan', 'ajuste', 'timbres']"
                :key="c"
                :value="c"
              >
                {{ $t(`operacion.listadosTres.conceptos.${c}`) }}
              </option>
            </select>
            <input
              v-model="filtro.periodo"
              class="tu-input max-w-[12rem]"
              type="month"
              :aria-label="$t('plataformaAdmin.cobros.periodo')"
              @change="filtrarCobros"
            />
          </div>
          <div
            v-if="errorCobros"
            class="mt-4 flex flex-wrap items-center gap-3 text-sm"
            role="alert"
            data-prueba="cobros-error"
          >
            <span style="color: var(--error)">{{ errorCobros }}</span>
            <button
              type="button"
              class="tu-btn tu-btn-discreto"
              :disabled="cargandoCobros"
              @click="cargarCobros"
            >
              {{ $t("comun.reintentar") }}
            </button>
          </div>
          <p
            v-else-if="cargandoCobros"
            class="mt-4 text-sm"
            role="status"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("comun.cargando") }}
          </p>
          <p
            v-else-if="cargos.length === 0"
            class="mt-4 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("plataformaAdmin.cobros.vacio") }}
          </p>
          <ul v-else class="mt-3">
            <li v-for="c in cargos" :key="c.id" class="pl-fila text-sm">
              <div class="min-w-0">
                <p class="font-medium truncate">{{ c.estudio ?? "—" }}</p>
                <p
                  class="mt-0.5 text-xs first-letter:uppercase"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  <template v-if="c.concepto && c.concepto !== 'renta'">
                    {{ $t(`suscripcion.cobro.concepto.${c.concepto}`) }} ·
                  </template>
                  {{ periodo(c.periodo) }}
                  <template v-if="c.estado === 'pendiente' && c.vence_en">
                    ·
                    {{
                      $t("plataformaAdmin.ficha.vence", {
                        fecha: fecha(c.vence_en),
                      })
                    }}
                  </template>
                  <template v-if="c.pagado_en">
                    ·
                    {{
                      $t("plataformaAdmin.ficha.pagado", {
                        fecha: fecha(c.pagado_en),
                      })
                    }}
                  </template>
                  <template v-if="c.factura">
                    · {{ $t("plataformaAdmin.ficha.factura") }}
                    {{
                      $t(`plataformaAdmin.facturas.${c.factura}`).toLowerCase()
                    }}
                  </template>
                </p>
              </div>
              <span class="flex items-center gap-3 shrink-0">
                <span class="font-medium tabular-nums">{{
                  dinero(c.monto_minor, c.moneda)
                }}</span>
                <span
                  class="tu-estado text-xs font-medium"
                  :style="{ '--tono': colorCargo(c) }"
                  >{{ estadoCargo(c) }}</span
                >
                <template v-if="c.vencido && c.estudio_slug">
                  <span
                    v-if="c.estudio_estado === 'suspended'"
                    class="text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    data-prueba="ya-suspendido"
                    >{{
                      c.estudio_suspendido_por === "renta"
                        ? $t("plataformaAdmin.cobros.suspendidoPorRenta")
                        : $t("plataformaAdmin.cobros.suspendido")
                    }}</span
                  >
                  <button
                    v-else
                    type="button"
                    class="tu-btn tu-btn-peligro tu-btn-chico"
                    data-prueba="suspender-por-renta"
                    :disabled="suspendiendo === c.id"
                    @click="suspenderPorRenta(c)"
                  >
                    {{ $t("plataformaAdmin.ficha.suspender") }}
                  </button>
                </template>
              </span>
              <div v-if="c.condonable" class="pl-condonar">
                <CondonarCargo
                  :ocupado="condonando === c.id"
                  @condonar="(m) => condonar(c, m, cargarCobros)"
                />
              </div>
            </li>
          </ul>
          <PaginacionListado
            v-if="metaCobros && metaCobros.ultima_pagina > 1"
            :page="metaCobros.page"
            :ultima-pagina="metaCobros.ultima_pagina"
            :total="metaCobros.total"
            :per-page="metaCobros.per_page"
            @ir="irPaginaCobros"
          />
        </div>
      </div>

      <!-- Operación: versión, procesos, verificación, respaldos y alertas -->
      <OperacionPlataforma
        v-if="pestana === 'operacion'"
        class="mt-5"
        :api-url="apiUrl"
        :token="token"
      />

      <!-- Errores de la API, la web y la app (ADR 0080) -->
      <ErroresPlataforma
        v-if="pestana === 'errores'"
        class="mt-5"
        :api-url="apiUrl"
        :token="token"
      />

      <!-- Embudo de la plataforma: de la landing al primer pago (C08) -->
      <EmbudoPlataforma
        v-if="pestana === 'embudo'"
        class="mt-5"
        :api-url="apiUrl"
        :token="token"
      />

      <!-- GA4 y Meta del SaaS, por producto (ADR 0143) -->
      <MedicionPlataforma
        v-if="pestana === 'medicion'"
        class="mt-5"
        :api-url="apiUrl"
        :token="token"
      />

      <!-- Quién espera el lanzamiento de un producto (ADR 0108) -->
      <InteresadosPlataforma
        v-if="pestana === 'interesados'"
        class="mt-5"
        :api-url="apiUrl"
        :token="token"
      />
      <!-- Las demos de la landing: cuántas hay, quién las agendó y su horario
           (ADR 0193 y 0204). -->
      <DemosPlataforma
        v-if="pestana === 'demos'"
        class="mt-5"
        :api-url="apiUrl"
        :token="token"
        @proximas="(n) => (demosProximas = n)"
      />

      <!-- Equipo de operadores y bitácora (ADR 0132) -->
      <EquipoPlataforma
        v-if="pestana === 'equipo'"
        class="mt-5"
        :api-url="apiUrl"
        :token="token"
      />
      <BitacoraPlataforma
        v-if="pestana === 'bitacora'"
        class="mt-5"
        :api-url="apiUrl"
        :token="token"
      />

      <!-- Parámetros de plataforma (ADR 0042) -->
      <ParametrosPlataforma
        v-if="pestana === 'parametros'"
        class="mt-5"
        :api-url="apiUrl"
        :token="token"
      />

      <!-- Tarifas -->
      <TarifasPlataforma
        v-if="pestana === 'tarifas'"
        class="mt-5"
        :api-url="apiUrl"
        :token="token"
      />

      <!-- Configuración: un apartado a la vez (`?config=`), cada formulario con su
           Guardar a la vista. Se ocultan sin desmontarse: lo escrito se conserva. -->
      <div v-if="pestana === 'configuracion'">
        <PestanasSeccion
          v-model="apartadoConfig"
          :opciones="opcionesConfig"
          :etiqueta="$t('plataformaAdmin.configuracion.etiqueta')"
        />
        <p
          v-if="cargandoConfig"
          class="mt-5 text-sm"
          role="status"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="config-cargando"
        >
          {{ $t("plataformaAdmin.configuracion.cargando") }}
        </p>
        <div
          v-else-if="errorConfig"
          class="mt-5 tu-card flex flex-wrap items-center justify-between gap-3 p-5 text-sm"
          role="alert"
          data-prueba="config-error"
        >
          <span :style="{ color: 'var(--error)' }">{{
            $t("plataformaAdmin.configuracion.error")
          }}</span>
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            @click="recargarConfiguracion"
          >
            {{ $t("comun.reintentar") }}
          </button>
        </div>
        <template v-else>
          <!-- Facturación: la cuenta de FacturAPI y a dónde llegan las alertas. -->
          <div
            v-show="apartadoConfig === 'facturacion'"
            class="mt-5 space-y-5"
            data-prueba="config-facturacion"
          >
            <div class="tu-card p-5">
              <div class="flex items-center justify-between gap-3">
                <div>
                  <h2 class="tu-titulo-seccion">
                    {{ $t("plataforma.facturapi.titulo") }}
                  </h2>
                  <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                    {{ $t("plataforma.facturapi.subtitulo") }}
                  </p>
                </div>
                <span
                  class="tu-estado text-sm shrink-0"
                  :style="{
                    '--tono': facturapiConfigurada
                      ? 'var(--exito)'
                      : 'var(--texto-suave)',
                  }"
                  data-prueba="facturapi-estado"
                >
                  {{
                    facturapiConfigurada
                      ? $t("plataforma.facturapi.configurada")
                      : $t("plataforma.facturapi.noConfigurada")
                  }}
                </span>
              </div>
              <form
                class="mt-4 flex flex-col sm:flex-row gap-3 sm:items-end"
                @submit.prevent="guardarLlave()"
              >
                <div class="flex-1">
                  <label class="tu-label" for="lk">{{
                    $t("plataforma.facturapi.llave")
                  }}</label>
                  <input
                    id="lk"
                    v-model="llaveInput"
                    class="tu-input"
                    type="password"
                    autocomplete="off"
                    placeholder="sk_live_…"
                  />
                </div>
                <button
                  class="tu-btn tu-btn-primario"
                  type="submit"
                  :disabled="
                    guardando === 'facturapi' || llaveInput.trim() === ''
                  "
                  :aria-busy="guardando === 'facturapi' || undefined"
                >
                  {{ $t("plataforma.facturapi.guardar") }}
                </button>
                <!-- Quita la llave ya guardada: destructiva. -->
                <button
                  v-if="facturapiConfigurada"
                  class="tu-btn tu-btn-peligro"
                  type="button"
                  data-prueba="quitar-facturapi"
                  :disabled="guardando === 'facturapi'"
                  @click="guardarLlave(true)"
                >
                  {{ $t("plataforma.facturapi.quitar") }}
                </button>
              </form>
              <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("plataforma.facturapi.ayuda") }}
              </p>
            </div>

            <!-- Correo del superadministrador: alertas y rentas vencidas. -->
            <form
              class="tu-card p-5"
              data-prueba="correo-alertas"
              @submit.prevent="guardarCorreoAlertas"
            >
              <h2 class="tu-titulo-seccion">
                {{ $t("plataformaAdmin.correoAlertas.titulo") }}
              </h2>
              <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("plataformaAdmin.correoAlertas.ayuda") }}
              </p>
              <div class="mt-4 flex flex-col sm:flex-row gap-3 sm:items-end">
                <div class="flex-1">
                  <label class="tu-label" for="correo-alertas">{{
                    $t("plataformaAdmin.correoAlertas.correo")
                  }}</label>
                  <input
                    id="correo-alertas"
                    v-model="correoAlertas"
                    class="tu-input"
                    type="email"
                    autocomplete="email"
                  />
                </div>
                <button
                  class="tu-btn tu-btn-primario"
                  type="submit"
                  :disabled="guardando === 'correo'"
                  :aria-busy="guardando === 'correo' || undefined"
                >
                  {{ $t("plataformaAdmin.correoAlertas.guardar") }}
                </button>
              </div>
            </form>
          </div>

          <!-- Pasarelas con que se cobra la renta del SaaS: cada una con su Guardar. -->
          <div
            v-show="apartadoConfig === 'pasarelas'"
            class="mt-5"
            data-prueba="config-pasarelas"
          >
            <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("plataforma.pasarelas.subtitulo") }}
            </p>
            <div class="mt-3 grid gap-3 md:grid-cols-3">
              <div
                v-for="p in pasarelas"
                :key="p.proveedor"
                class="tu-card p-4"
              >
                <div
                  v-if="p.disponible === false"
                  class="flex items-center justify-between gap-2"
                >
                  <h3 class="font-medium">
                    {{ $t(`pasarelas.proveedores.${p.proveedor}`) }}
                  </h3>
                  <span
                    class="tu-estado text-xs shrink-0"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ $t("pasarelasEstado.proximamente") }}</span
                  >
                </div>
                <p
                  v-if="p.disponible === false"
                  class="mt-2 text-sm"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("pasarelasEstado.noDisponible") }}
                </p>
                <template v-else>
                  <p
                    v-if="p.activa && p.lista === false"
                    class="mb-2 text-xs"
                    role="status"
                    style="color: var(--aviso)"
                  >
                    {{ $t("pasarelasEstado.faltaLlave") }}
                  </p>
                  <div class="flex items-center justify-between gap-2">
                    <h3 class="font-medium">
                      {{ $t(`pasarelas.proveedores.${p.proveedor}`) }}
                    </h3>
                    <label class="flex items-center gap-1.5 text-xs">
                      <input
                        v-model="pasarelaDraft[p.proveedor].activa"
                        type="checkbox"
                      />
                      {{ $t("plataforma.pasarelas.activa") }}
                    </label>
                  </div>
                  <div class="mt-3">
                    <label class="tu-label" :for="`modo-${p.proveedor}`">{{
                      $t("plataforma.pasarelas.modo")
                    }}</label>
                    <select
                      :id="`modo-${p.proveedor}`"
                      v-model="pasarelaDraft[p.proveedor].modo"
                      class="tu-input"
                    >
                      <option value="test">
                        {{ $t("plataforma.pasarelas.test") }}
                      </option>
                      <option value="live">
                        {{ $t("plataforma.pasarelas.live") }}
                      </option>
                    </select>
                  </div>
                  <div
                    v-for="llave in LLAVES_PASARELA[p.proveedor] ?? []"
                    :key="llave"
                    class="mt-2"
                  >
                    <label class="tu-label" :for="`${p.proveedor}-${llave}`">{{
                      $t(`plataformaAdmin.llaves.${llave}`)
                    }}</label>
                    <input
                      :id="`${p.proveedor}-${llave}`"
                      v-model="pasarelaDraft[p.proveedor].llaves[llave]"
                      class="tu-input"
                      type="password"
                      autocomplete="off"
                      :placeholder="
                        p.llaves_configuradas.includes(llave)
                          ? $t('plataforma.pasarelas.configurada')
                          : ''
                      "
                    />
                  </div>
                  <!-- A dónde manda sus avisos la pasarela (se registra en su panel). -->
                  <div class="mt-2">
                    <label
                      class="tu-label"
                      :for="`${p.proveedor}-webhook-url`"
                      >{{ $t("plataforma.pasarelas.webhookUrl") }}</label
                    >
                    <input
                      :id="`${p.proveedor}-webhook-url`"
                      class="tu-input text-xs"
                      readonly
                      :value="`${apiUrl}/api/v1/webhooks/plataforma/${p.proveedor}`"
                      data-prueba="webhook-url"
                      @focus="($event.target as HTMLInputElement).select()"
                    />
                    <p
                      v-if="p.proveedor === 'stripe'"
                      class="mt-1 text-xs"
                      :style="{ color: 'var(--texto-suave)' }"
                    >
                      {{ $t("plataforma.pasarelas.webhookEventosStripe") }}
                    </p>
                  </div>
                  <button
                    class="tu-btn tu-btn-primario w-full mt-3"
                    type="button"
                    :disabled="guardando === p.proveedor"
                    :aria-busy="guardando === p.proveedor || undefined"
                    @click="guardarPasarela(p.proveedor)"
                  >
                    {{ $t("plataforma.pasarelas.guardar") }}
                  </button>
                </template>
              </div>
            </div>
          </div>

          <div
            v-show="apartadoConfig === 'whatsapp'"
            class="mt-5"
            data-prueba="config-whatsapp"
          >
            <WhatsAppPlataforma :api-url="apiUrl" :token="token" />
          </div>

          <!-- Documentos legales: lo publicado arriba; el responsable, plegado (se
             escribe una vez); Guardar y Publicar, fijos al pie. -->
          <div
            v-show="apartadoConfig === 'legales'"
            class="mt-5"
            data-prueba="config-legales"
          >
            <form
              class="tu-card p-5 grid gap-4"
              @submit.prevent="guardarLegales()"
            >
              <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("operacion.legales.ayuda") }}
              </p>
              <details
                class="tu-avanzado"
                :open="responsableAbierto"
                data-prueba="legales-responsable"
              >
                <summary>
                  {{ $t("operacion.legales.responsable") }}
                  <span
                    v-if="legales.responsable.nombre.trim() !== ''"
                    class="font-normal"
                    :style="{ color: 'var(--texto-suave)' }"
                    >· {{ legales.responsable.nombre }}</span
                  >
                </summary>
                <fieldset class="grid gap-3 sm:grid-cols-2">
                  <legend class="sr-only">
                    {{ $t("operacion.legales.responsable") }}
                  </legend>
                  <div>
                    <label class="tu-label" for="lg-resp-nombre">{{
                      $t("operacion.legales.nombre")
                    }}</label>
                    <input
                      id="lg-resp-nombre"
                      v-model="legales.responsable.nombre"
                      class="tu-input"
                    />
                  </div>
                  <div>
                    <label class="tu-label" for="lg-resp-contacto">{{
                      $t("operacion.legales.contacto")
                    }}</label>
                    <input
                      id="lg-resp-contacto"
                      v-model="legales.responsable.contacto"
                      type="email"
                      class="tu-input"
                    />
                  </div>
                  <div class="sm:col-span-2">
                    <label class="tu-label" for="lg-resp-domicilio">{{
                      $t("operacion.legales.domicilio")
                    }}</label>
                    <input
                      id="lg-resp-domicilio"
                      v-model="legales.responsable.domicilio"
                      class="tu-input"
                    />
                  </div>
                  <div class="sm:col-span-2">
                    <label class="tu-label" for="lg-resp-area">{{
                      $t("operacion.legales.area")
                    }}</label>
                    <input
                      id="lg-resp-area"
                      v-model="legales.responsable.area"
                      class="tu-input"
                    />
                  </div>
                </fieldset>
              </details>
              <!-- Cada documento con su versión publicada y su «Publicar» (un botón
                 principal por zona); el pie guarda el borrador de los dos. -->
              <div
                v-for="doc in DOCUMENTOS_LEGALES"
                :key="doc.tipo"
                :data-prueba="`legales-doc-${doc.tipo}`"
              >
                <label class="tu-label" :for="doc.id">{{
                  $t(doc.etiqueta)
                }}</label>
                <p
                  v-if="doc.tipo === 'aviso_privacidad'"
                  class="text-xs mb-1"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ $t("operacion.legales.marcadores") }}
                </p>
                <textarea
                  :id="doc.id"
                  v-model="legales[doc.tipo]"
                  class="tu-input"
                  rows="6"
                />
                <div
                  class="mt-2 flex flex-wrap items-center justify-between gap-3"
                >
                  <span
                    class="tu-estado text-sm"
                    :style="{
                      '--tono': publicados[doc.tipo]
                        ? 'var(--exito)'
                        : 'var(--aviso)',
                    }"
                    >{{
                      publicados[doc.tipo]
                        ? $t("operacion.legales.version", {
                            version: publicados[doc.tipo]!.version,
                            fecha: fechaPublicado(publicados[doc.tipo]!),
                          })
                        : $t(doc.sinPublicar)
                    }}</span
                  >
                  <button
                    class="tu-btn tu-btn-primario"
                    type="button"
                    :disabled="guardando !== null"
                    :aria-busy="
                      guardando === `publicar-${doc.tipo}` || undefined
                    "
                    :data-prueba="`legales-publicar-${doc.tipo}`"
                    @click="publicarLegal(doc.tipo)"
                  >
                    {{ $t(doc.publicar) }}
                  </button>
                </div>
              </div>
              <!-- Guardar el borrador siempre a la vista al bajar por los textos. -->
              <div class="tu-pie-acciones">
                <button
                  class="tu-btn tu-btn-secundario"
                  type="submit"
                  :disabled="guardando !== null"
                  :aria-busy="guardando === 'legales' || undefined"
                  data-prueba="legales-guardar"
                >
                  {{ $t("operacion.legales.guardarBorrador") }}
                </button>
              </div>
            </form>
          </div>

          <!-- Ventas, Banco de México y timbres (ADR 0107). -->
          <div
            v-show="apartadoConfig === 'comercial'"
            class="mt-5"
            data-prueba="config-comercial"
          >
            <ComercialPlataforma :api-url="apiUrl" :token="token" />
          </div>

          <!-- Wellhub y TotalPass: en qué países se ofrecen y los gimnasios que eligieron
             a AgendaUno en Wellhub sin traer el código del negocio (ADR 0202). -->
          <div
            v-show="apartadoConfig === 'bienestar'"
            class="mt-5 space-y-5"
            data-prueba="config-bienestar"
          >
            <form
              class="tu-card p-5"
              data-prueba="paises-bienestar"
              @submit.prevent="guardarPaisesBienestar"
            >
              <h2 class="tu-titulo-seccion">
                {{ $t("plataformaAdmin.paisesBienestar.titulo") }}
              </h2>
              <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("plataformaAdmin.paisesBienestar.ayuda") }}
              </p>
              <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div>
                  <label class="tu-label" for="paises-wellhub">Wellhub</label>
                  <input
                    id="paises-wellhub"
                    v-model="paisesBienestar.wellhub"
                    class="tu-input"
                    autocomplete="off"
                    placeholder="MX, CO"
                  />
                </div>
                <div>
                  <label class="tu-label" for="paises-totalpass"
                    >TotalPass</label
                  >
                  <input
                    id="paises-totalpass"
                    v-model="paisesBienestar.totalpass"
                    class="tu-input"
                    autocomplete="off"
                    placeholder="MX"
                  />
                </div>
              </div>
              <div class="mt-3 flex justify-end">
                <button
                  class="tu-btn tu-btn-primario"
                  type="submit"
                  :disabled="guardando === 'paises'"
                  :aria-busy="guardando === 'paises' || undefined"
                >
                  {{ $t("plataformaAdmin.paisesBienestar.guardar") }}
                </button>
              </div>
            </form>

            <SolicitudesWellhub :api-url="apiUrl" :token="token" />
          </div>
        </template>
      </div>

      <!-- Ficha del estudio -->
      <PanelLateral
        :abierto="fichaAbierta"
        :titulo="ficha?.nombre ?? ''"
        @cerrar="fichaAbierta = false"
      >
        <p
          v-if="ficha === null"
          class="p-5 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>
        <div v-else class="space-y-6 p-5 text-sm">
          <div class="flex flex-wrap items-center gap-3">
            <span
              class="tu-estado font-medium"
              :style="{ '--tono': colorEstado(ficha.estado) }"
              >{{ $t(`plataformaAdmin.estados.${ficha.estado}`) }}</span
            >
            <span
              v-if="ficha.suspendido_por === 'renta'"
              :style="{ color: 'var(--texto-suave)' }"
              data-prueba="suspendido-por-renta"
              >{{ $t("plataformaAdmin.cobros.suspendidoPorRenta") }}</span
            >
            <span
              v-if="ficha.estado === 'suspended' && ficha.ultima_suspension"
              class="basis-full text-sm"
              :style="{ color: 'var(--texto-suave)' }"
              data-prueba="ultima-suspension"
              >{{ $t("plataformaAdmin.ficha.ultimaSuspension") }}:
              {{
                $t("plataformaAdmin.ficha.ultimaSuspensionDetalle", {
                  fecha: fecha(ficha.ultima_suspension.fecha),
                  operador: ficha.ultima_suspension.operador ?? "—",
                })
              }}
              ·
              {{
                ficha.ultima_suspension.motivo ??
                $t("plataformaAdmin.ficha.sinMotivo")
              }}</span
            >
            <span :style="{ color: 'var(--texto-suave)' }">{{
              $t(`panel.estados.${ficha.estado_facturacion}`)
            }}</span>
            <a
              :href="
                urlCanonicaEstudio(
                  ficha.slug,
                  '/',
                  productoDeModalidad(ficha.modalidad),
                )
              "
              target="_blank"
              rel="noopener"
              class="tu-enlace ml-auto"
              >{{ $t("plataformaAdmin.ficha.abrirPagina") }}</a
            >
          </div>

          <!-- Negocio -->
          <section>
            <h3
              class="text-xs font-medium uppercase tracking-wide"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("plataformaAdmin.ficha.negocio") }}
            </h3>
            <dl class="mt-2 divide-y divide-[var(--borde)]">
              <div class="pl-dato">
                <dt>{{ $t("plataformaAdmin.ficha.tipo") }}</dt>
                <dd>{{ $t(`registro.perfiles.${ficha.perfil}`) }}</dd>
              </div>
              <div class="pl-dato">
                <dt>{{ $t("plataformaAdmin.ficha.ciudad") }}</dt>
                <dd>{{ ficha.ciudad ?? "—" }}</dd>
              </div>
              <div class="pl-dato">
                <dt>{{ $t("plataformaAdmin.ficha.alta") }}</dt>
                <dd>{{ fecha(ficha.creado_en) }}</dd>
              </div>
              <div class="pl-dato">
                <dt>{{ $t("plataformaAdmin.ficha.prueba") }}</dt>
                <dd>{{ fecha(ficha.trial_termina_en) }}</dd>
              </div>
              <div class="pl-dato">
                <dt>{{ $t("plataformaAdmin.ficha.directorio") }}</dt>
                <dd>
                  {{
                    ficha.en_directorio
                      ? $t("plataformaAdmin.ficha.enDirectorio")
                      : $t("plataformaAdmin.ficha.fueraDirectorio")
                  }}
                </dd>
              </div>
              <div class="pl-dato">
                <dt>{{ $t("plataformaAdmin.ficha.configuracionInicial") }}</dt>
                <dd>
                  {{
                    ficha.onboarding_completo
                      ? $t("plataformaAdmin.ficha.completa")
                      : $t("plataformaAdmin.ficha.pendiente")
                  }}
                </dd>
              </div>
            </dl>
          </section>

          <!-- Solo clases o solo citas: se cambia antes de que opere (ADR 0104) -->
          <ModalidadPlataforma
            :key="`modalidad-${ficha.slug}-${ficha.modalidad}`"
            :api-url="apiUrl"
            :token="token"
            :slug="ficha.slug"
            :nombre="ficha.nombre"
            :modalidad="ficha.modalidad"
            :cambiable="ficha.modalidad_cambiable ?? null"
            @cambiada="recargarFicha"
          />

          <!-- País: pasada la prueba, solo soporte lo cambia (ADR 0107) -->
          <PaisPlataforma
            :key="`pais-${ficha.slug}-${ficha.pais ?? 'MX'}`"
            :api-url="apiUrl"
            :token="token"
            :slug="ficha.slug"
            :nombre="ficha.nombre"
            :pais="ficha.pais ?? 'MX'"
            @cambiado="recargarFicha"
          />

          <!-- Contacto -->
          <section>
            <h3
              class="text-xs font-medium uppercase tracking-wide"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("plataformaAdmin.ficha.contacto") }}
            </h3>
            <div class="mt-2 space-y-1">
              <p class="font-medium">{{ ficha.contacto.nombre || "—" }}</p>
              <a
                v-if="ficha.contacto.email"
                :href="`mailto:${ficha.contacto.email}`"
                class="tu-enlace block"
                >{{ ficha.contacto.email }}</a
              >
              <a
                v-if="ficha.contacto.whatsapp"
                :href="`https://wa.me/${ficha.contacto.whatsapp.replace(/\D/g, '')}`"
                target="_blank"
                rel="noopener"
                class="tu-enlace block"
                >WhatsApp {{ ficha.contacto.whatsapp }}</a
              >
              <span
                v-if="ficha.contacto.whatsapp_verificado"
                class="tu-estado text-xs"
                :style="{
                  '--tono': 'var(--exito)',
                  color: 'var(--texto-suave)',
                }"
                data-prueba="whatsapp-verificado"
              >
                {{ $t("plataformaAdmin.ficha.whatsappVerificado") }}
              </span>
            </div>
          </section>

          <!-- Uso -->
          <section>
            <h3
              class="text-xs font-medium uppercase tracking-wide"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("plataformaAdmin.ficha.uso") }}
            </h3>
            <p
              v-if="ficha.uso.length === 0"
              class="mt-2"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("plataformaAdmin.estudios.sinUso") }}
            </p>
            <dl v-else class="mt-2 divide-y divide-[var(--borde)]">
              <div v-for="u in ficha.uso" :key="u.periodo" class="pl-dato">
                <dt class="first-letter:uppercase">{{ periodo(u.periodo) }}</dt>
                <dd class="tabular-nums">
                  {{ u.cantidad }} {{ $t(`cobro.actual.${u.metrica}`) }}
                </dd>
              </div>
            </dl>
          </section>

          <!-- Cargos -->
          <section>
            <h3
              class="text-xs font-medium uppercase tracking-wide"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("plataformaAdmin.ficha.cargos") }}
            </h3>
            <p
              v-if="ficha.cargos.length === 0"
              class="mt-2"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("plataformaAdmin.ficha.sinCargos") }}
            </p>
            <dl v-else class="mt-2 divide-y divide-[var(--borde)]">
              <div v-for="c in ficha.cargos" :key="c.id" class="pl-dato">
                <dt class="first-letter:uppercase">
                  <template v-if="c.concepto && c.concepto !== 'renta'">
                    {{ $t(`suscripcion.cobro.concepto.${c.concepto}`) }} ·
                  </template>
                  {{ periodo(c.periodo) }}
                </dt>
                <dd class="text-right">
                  <span class="tabular-nums">{{
                    dinero(c.monto_minor, c.moneda)
                  }}</span>
                  <span
                    class="tu-estado flex justify-end text-xs"
                    :style="{ '--tono': colorCargo(c) }"
                    >{{ estadoCargo(c) }}</span
                  >
                </dd>
                <div v-if="c.condonable" class="pl-condonar">
                  <CondonarCargo
                    :ocupado="condonando === c.id"
                    @condonar="(m) => condonar(c, m, recargarFicha)"
                  />
                </div>
              </div>
            </dl>
          </section>

          <!-- Avisos al dueño -->
          <section data-prueba="avisos-dueno">
            <h3
              class="text-xs font-medium uppercase tracking-wide"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("plataformaAdmin.ficha.avisos") }}
            </h3>
            <p
              v-if="(ficha.avisos ?? []).length === 0"
              class="mt-2"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("plataformaAdmin.ficha.sinAvisos") }}
            </p>
            <dl v-else class="mt-2 divide-y divide-[var(--borde)]">
              <div v-for="a in ficha.avisos" :key="a.id" class="pl-dato">
                <dt>
                  {{ $t(`plataformaAdmin.ficha.tiposAviso.${a.tipo}`) }}
                  <span
                    class="block text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ $t(`plataformaAdmin.ficha.canalesAviso.${a.canal}`) }} ·
                    {{ fecha(a.fecha) }}</span
                  >
                </dt>
                <dd
                  class="text-right text-xs"
                  :style="{
                    color:
                      a.estado === 'fallido'
                        ? 'var(--error)'
                        : 'var(--texto-suave)',
                  }"
                >
                  {{
                    a.leido
                      ? $t("comunicacionesAuto.estados.leido")
                      : a.entregado
                        ? $t("comunicacionesAuto.estados.entregado")
                        : $t(`comunicacionesAuto.estados.${a.estado}`)
                  }}
                </dd>
              </div>
            </dl>
          </section>

          <!-- WhatsApp con sus clientes (ADR 0083) -->
          <WhatsAppNegocio
            v-if="ficha.whatsapp_clientes"
            :key="`wa-${ficha.slug}`"
            :api-url="apiUrl"
            :token="token"
            :slug="ficha.slug"
            :nombre="ficha.nombre"
            :inicial="ficha.whatsapp_clientes"
          />

          <!-- Módulos por negocio (ADR 0123) -->
          <ModulosNegocio
            v-if="ficha.modulos && ficha.modulos.length > 0"
            :key="`mod-${ficha.slug}`"
            :api-url="apiUrl"
            :token="token"
            :slug="ficha.slug"
            :nombre="ficha.nombre"
            :inicial="ficha.modulos"
          />

          <!-- Facturación -->
          <form class="space-y-3" @submit.prevent="guardarCobro">
            <h3
              class="text-xs font-medium uppercase tracking-wide"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("plataformaAdmin.ficha.facturacion") }}
            </h3>
            <div class="grid gap-3 grid-cols-2">
              <div>
                <label class="tu-label" for="mc">{{
                  $t("plataforma.estudios.modoCobro")
                }}</label>
                <select id="mc" v-model="edit.modo_cobro" class="tu-input">
                  <option value="activos">
                    {{
                      ficha.modalidad === "citas"
                        ? $t("plataforma.estudios.modo.plan")
                        : $t("plataforma.estudios.modo.activos")
                    }}
                  </option>
                  <option value="fijo">
                    {{ $t("plataforma.estudios.modo.fijo") }}
                  </option>
                </select>
              </div>
              <div>
                <label class="tu-label" for="ef">{{
                  $t("plataforma.estudios.colFacturacion")
                }}</label>
                <select
                  id="ef"
                  v-model="edit.estado_facturacion"
                  class="tu-input"
                >
                  <option v-for="s in ESTADOS_FACT" :key="s" :value="s">
                    {{ $t(`panel.estados.${s}`) }}
                  </option>
                </select>
              </div>
              <template v-if="edit.modo_cobro === 'fijo'">
                <div>
                  <label class="tu-label" for="cf"
                    >{{ $t("plataforma.estudios.cuotaFija") }}
                    <span class="tu-obligatorio" aria-hidden="true"
                      >*</span
                    ></label
                  >
                  <input
                    id="cf"
                    v-model="edit.cuota"
                    type="number"
                    min="0"
                    step="0.01"
                    class="tu-input"
                    required
                    :aria-invalid="errorCuota ? 'true' : undefined"
                    :aria-describedby="errorCuota ? 'cf-error' : undefined"
                    @input="errorCuota = false"
                  />
                  <span
                    v-if="errorCuota"
                    id="cf-error"
                    class="tu-error-campo"
                    >{{ $t("plataformaAdmin.ficha.cuotaRequerida") }}</span
                  >
                </div>
                <div>
                  <label class="tu-label" for="cfm">{{
                    $t("suscripcion.plataforma.cuotaMoneda")
                  }}</label>
                  <select id="cfm" v-model="edit.moneda" class="tu-input">
                    <option value="USD">USD</option>
                    <option value="MXN">MXN</option>
                  </select>
                </div>
              </template>
              <div>
                <label class="tu-label" for="cvd">{{
                  $t("plataformaAdmin.ficha.vigenteDesde")
                }}</label>
                <input
                  id="cvd"
                  v-model="edit.desde"
                  type="month"
                  class="tu-input"
                  :min="ficha.cobro?.primer_periodo_abierto"
                  aria-describedby="cvd-ayuda"
                />
              </div>
            </div>
            <p
              id="cvd-ayuda"
              class="text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("plataformaAdmin.ficha.vigenteDesdeAyuda") }}
            </p>
            <p
              v-if="ficha.cobro?.proximo"
              class="text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                $t("plataformaAdmin.ficha.cobroProximo", {
                  mes: periodo(ficha.cobro.proximo.desde ?? ""),
                  cobro:
                    ficha.cobro.proximo.modo_cobro === "fijo"
                      ? dinero(
                          ficha.cobro.proximo.cuota_fija_minor,
                          ficha.cobro.proximo.cuota_fija_moneda,
                        )
                      : $t("plataformaAdmin.ficha.porUso"),
                })
              }}
            </p>
            <button
              class="tu-btn tu-btn-primario"
              type="submit"
              :disabled="accionando"
            >
              {{ $t("plataforma.estudios.guardar") }}
            </button>
          </form>

          <!-- Plan de un negocio de citas (ADR 0107) -->
          <PlanEstudioPlataforma
            v-if="ficha.plan_citas"
            :api-url="apiUrl"
            :token="token"
            :slug="ficha.slug"
            :plan="ficha.plan_citas"
            @cambiado="recargarFicha"
          />

          <!-- Cuenta: prueba y acceso -->
          <section class="space-y-3">
            <h3
              class="text-xs font-medium uppercase tracking-wide"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("plataformaAdmin.ficha.acciones") }}
            </h3>
            <div
              v-if="ficha.estado === 'trialing' || ficha.estado === 'active'"
              class="flex flex-wrap items-center gap-2"
            >
              <select
                v-model.number="diasPrueba"
                class="tu-input w-auto"
                :aria-label="$t('plataformaAdmin.ficha.extender')"
              >
                <option v-for="d in [7, 15, 30, 60]" :key="d" :value="d">
                  {{ $t("plataformaAdmin.ficha.dias", { n: d }) }}
                </option>
              </select>
              <button
                class="tu-btn tu-btn-secundario"
                type="button"
                :disabled="accionando"
                @click="extenderPrueba"
              >
                {{ $t("plataformaAdmin.ficha.extender") }}
              </button>
            </div>
            <div
              v-if="ficha.estado === 'trialing' || ficha.estado === 'active'"
              class="flex flex-wrap items-end gap-2"
              data-prueba="suspender-negocio"
            >
              <div class="min-w-0 flex-1 basis-48">
                <label class="tu-label" for="motivo-suspension">{{
                  $t("plataformaAdmin.ficha.motivo")
                }}</label>
                <input
                  id="motivo-suspension"
                  v-model="motivoSuspension"
                  class="tu-input"
                  maxlength="255"
                  autocomplete="off"
                />
              </div>
              <button
                class="tu-btn tu-btn-peligro"
                type="button"
                :disabled="accionando"
                @click="suspender"
              >
                {{ $t("plataformaAdmin.ficha.suspender") }}
              </button>
            </div>
            <button
              v-if="ficha.estado === 'suspended'"
              class="tu-btn tu-btn-primario"
              type="button"
              :disabled="accionando"
              @click="reactivar"
            >
              {{ $t("plataformaAdmin.ficha.reactivar") }}
            </button>
          </section>

          <!-- Cómo se llaman las cosas en este negocio -->
          <TerminologiaNegocio
            :key="ficha.slug"
            :cargar="cargarTerminologia"
            :guardar="guardarTerminologia"
          />
        </div>
      </PanelLateral>
    </section>
  </LayoutPlataforma>

  <!-- La puerta: sin el encabezado público (ni «Inicio» ni «Iniciar sesión»), para no
       salir por error con la sesión de la plataforma a medias. -->
  <main v-else class="pl-acceso">
    <p class="pl-marca">
      <img :src="ISOTIPO_AGENDAUNO" alt="" class="h-9 w-9 object-contain" />
      <span>{{ $t("plataformaAdmin.marca") }}</span>
    </p>
    <div class="w-full max-w-sm">
      <!-- Con un token guardado: se comprueba sin enseñar la puerta. -->
      <p
        v-if="comprobando"
        class="text-center text-sm"
        role="status"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>

      <!-- Paso 2: el código que llegó por correo -->
      <div
        v-else-if="!autenticado && desafio !== null"
        class="mx-auto max-w-sm tu-card p-6"
        data-prueba="paso-codigo"
      >
        <h1 class="font-semibold text-xl">
          {{ $t("plataforma.codigoTitulo") }}
        </h1>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("plataforma.codigoAyuda", { correo: correoDestino }) }}
        </p>
        <AvisoTono
          v-if="codigoDesarrollo"
          tono="aviso"
          class="mt-2"
          data-prueba="codigo-desarrollo"
        >
          {{ $t("plataforma.codigoDesarrollo", { codigo: codigoDesarrollo }) }}
        </AvisoTono>
        <form class="mt-4 space-y-3" @submit.prevent="verificar">
          <div>
            <label class="tu-label" for="pl-codigo">{{
              $t("plataforma.codigo")
            }}</label>
            <input
              id="pl-codigo"
              v-model="codigoInput"
              class="tu-input tracking-[0.3em] text-lg"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="6"
              pattern="[0-9]{6}"
              required
            />
          </div>
          <AvisoTono v-if="error" tono="peligro" role="alert">
            {{ error }}
          </AvisoTono>
          <button
            class="tu-btn tu-btn-primario w-full"
            type="submit"
            :disabled="cargando || codigoInput.trim().length !== 6"
          >
            {{ $t("plataforma.verificar") }}
          </button>
          <button
            class="tu-btn tu-btn-secundario w-full"
            type="button"
            :disabled="cargando"
            @click="empezarDeNuevo"
          >
            {{ $t("plataforma.empezarDeNuevo") }}
          </button>
        </form>
      </div>

      <!-- Paso 1: correo, contraseña y token de la plataforma -->
      <div v-else-if="!autenticado" class="mx-auto max-w-sm tu-card p-6">
        <h1 class="font-semibold text-xl">{{ $t("plataforma.titulo") }}</h1>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("plataforma.accesoAyuda") }}
        </p>
        <form class="mt-4 space-y-3" @submit.prevent="entrar">
          <div>
            <label class="tu-label" for="pl-correo">{{
              $t("plataforma.correo")
            }}</label>
            <input
              id="pl-correo"
              v-model="emailInput"
              class="tu-input"
              type="email"
              autocomplete="username"
              required
            />
          </div>
          <div>
            <label class="tu-label" for="pl-contrasena">{{
              $t("plataforma.contrasena")
            }}</label>
            <input
              id="pl-contrasena"
              v-model="passwordInput"
              class="tu-input"
              type="password"
              autocomplete="current-password"
              required
            />
          </div>
          <div>
            <label class="tu-label" for="tk">{{
              $t("plataforma.token")
            }}</label>
            <input
              id="tk"
              v-model="tokenInput"
              class="tu-input"
              type="password"
              autocomplete="off"
              required
            />
          </div>
          <AvisoTono v-if="error" tono="peligro" role="alert">
            {{ error }}
          </AvisoTono>
          <button
            class="tu-btn tu-btn-primario w-full"
            type="submit"
            :disabled="cargando"
          >
            {{ $t("plataforma.entrar") }}
          </button>
          <!-- Falló la red, no el token: se puede volver a intentar con el guardado. -->
          <button
            v-if="token !== ''"
            class="tu-btn tu-btn-discreto w-full"
            type="button"
            :disabled="cargando"
            @click="cargar"
          >
            {{ $t("comun.reintentar") }}
          </button>
        </form>
      </div>
    </div>
  </main>
</template>

<style scoped>
/* El título de cada sección, con su ícono a la izquierda (neutro, sin tinta). */
.pl-encabezado {
  display: flex;
  align-items: center;
  gap: 0.85rem;
}
.pl-icono {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  border: 1px solid var(--borde);
  border-radius: 0.8rem;
  background: var(--superficie);
  color: var(--texto-suave);
}
/* La puerta del superadmin: centrada, sin encabezado ni pie públicos. */
.pl-acceso {
  display: flex;
  min-height: 100vh;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1.5rem;
  padding: 2rem 1rem;
  background: var(--fondo);
}
.pl-marca {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  font-weight: 600;
}
.pl-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.pl-fila:first-child {
  border-top: 0;
}
.pl-dato {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.55rem 0;
}
.pl-dato dt {
  color: var(--texto-suave);
}
.pl-dato dd {
  text-align: right;
}
/* «Condonar» en su propia línea, a la derecha, bajo el cargo. */
.pl-condonar {
  display: flex;
  flex-basis: 100%;
  justify-content: flex-end;
}
</style>
