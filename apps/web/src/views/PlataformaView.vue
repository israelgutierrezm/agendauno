<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import ErroresPlataforma from "@/components/ErroresPlataforma.vue";
import OperacionPlataforma from "@/components/OperacionPlataforma.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import ParametrosPlataforma from "@/components/ParametrosPlataforma.vue";
import TablaDatos from "@/components/TablaDatos.vue";
import TarifasPlataforma from "@/components/TarifasPlataforma.vue";
import TerminologiaNegocio from "@/components/TerminologiaNegocio.vue";
import WhatsAppNegocio, {
  type EstadoWhatsAppNegocio,
} from "@/components/WhatsAppNegocio.vue";
import WhatsAppPlataforma from "@/components/WhatsAppPlataforma.vue";
import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import type { DatosTerminologia } from "@/lib/terminologia";
import { useToastStore } from "@/stores/toast";

/**
 * Súper admin de AgendaUno (operador de la plataforma). Entra con un token propio,
 * no con la sesión de un estudio. Pestañas: los estudios (con su ficha: estado, uso,
 * cargos, contacto y acciones de soporte), los cobros de la renta, las tarifas y la
 * configuración global (FacturAPI, pasarelas y documentos legales).
 */
interface Estudio {
  slug: string;
  nombre: string;
  estado: string;
  // Suspendido solo por renta (se reactiva al pagar) o por la plataforma (ADR 0073).
  suspendido_por?: string | null;
  estado_facturacion: string;
  perfil: string;
  modalidad: "clases" | "citas";
  modo_cobro: string;
  cuota_fija_minor: number;
  trial_termina_en: string | null;
  moneda: string;
  publicado: boolean;
  en_directorio: boolean;
  ciudad: string | null;
  creado_en: string | null;
  uso?: { cantidad: number; metrica: string } | null;
  adeudo_minor?: number;
}
interface Cargo {
  id: string;
  periodo: string;
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
  // Sus avisos por WhatsApp a clientes: solo los activa el superadmin (ADR 0083).
  whatsapp_clientes?: EstadoWhatsAppNegocio;
  uso: { periodo: string; metrica: string; cantidad: number }[];
  cargos: Cargo[];
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
  | "errores";

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
const autenticado = ref(false);
const cargando = ref(false);
const error = ref<string | null>(null);
const pestana = ref<Pestana>("estudios");

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
  return e.modo_cobro === "fijo"
    ? `${dinero(e.cuota_fija_minor, e.moneda)} / mes`
    : t(`cobro.modalidad.${e.modalidad}`);
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
  { clave: "estado", etiqueta: t("plataformaAdmin.estudios.colEstado") },
  { clave: "cobro", etiqueta: t("plataformaAdmin.estudios.colCobro") },
  { clave: "uso", etiqueta: t("plataformaAdmin.estudios.colUso") },
  { clave: "adeudo_minor", etiqueta: t("plataformaAdmin.estudios.colAdeudo") },
  { clave: "acciones", etiqueta: "" },
]);

async function cargarEstudios(): Promise<void> {
  const { data } = await cliente.get<{ data: Estudio[] }>(
    "/api/v1/plataforma/estudios",
    encabezados(),
  );
  estudios.value = data.data;
}

// Ficha de un estudio (panel lateral).
const ficha = ref<FichaApi | null>(null);
const fichaAbierta = ref(false);
const accionando = ref(false);
const edit = ref({ modo_cobro: "activos", cuota: "0", estado_facturacion: "" });
const diasPrueba = ref(15);

async function abrirFicha(e: Estudio): Promise<void> {
  fichaAbierta.value = true;
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
      estado_facturacion: data.data.estado_facturacion,
    };
  } catch (err) {
    toast.error(mensajeDeError(err));
    fichaAbierta.value = false;
  }
}

async function accion(
  hacer: () => Promise<unknown>,
  exito: string,
): Promise<void> {
  if (ficha.value === null) {
    return;
  }
  const slug = ficha.value.slug;
  accionando.value = true;
  try {
    await hacer();
    toast.exito(exito);
    await cargarEstudios();
    const actual = estudios.value.find((e) => e.slug === slug);
    if (actual !== undefined) {
      await abrirFicha(actual);
    }
  } catch (err) {
    toast.error(mensajeDeError(err));
  } finally {
    accionando.value = false;
  }
}

function guardarCobro(): void {
  const f = ficha.value;
  if (f === null) {
    return;
  }
  void accion(
    () =>
      cliente.put(
        `/api/v1/plataforma/estudios/${f.slug}`,
        {
          modo_cobro: edit.value.modo_cobro,
          cuota_fija_minor: Math.round(Number(edit.value.cuota) * 100),
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
  const motivo = window.prompt(t("plataformaAdmin.ficha.motivo")) ?? "";
  void accion(
    () =>
      cliente.post(
        `/api/v1/plataforma/estudios/${f.slug}/suspender`,
        { motivo: motivo.trim() || null },
        encabezados(),
      ),
    t("plataformaAdmin.ficha.suspendido"),
  );
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

// ---- Cobros ----
const cargos = ref<Cargo[]>([]);
const resumen = ref<ResumenCobros | null>(null);
const filtro = ref({ estado: "", periodo: "" });

async function cargarCobros(): Promise<void> {
  try {
    const { data } = await cliente.get<{
      data: Cargo[];
      resumen: ResumenCobros;
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
      },
    });
    cargos.value = data.data;
    resumen.value = data.resumen;
  } catch (err) {
    toast.error(mensajeDeError(err));
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
const facturapiConfigurada = ref(false);
const llaveInput = ref("");
// A dónde llegan las alertas y las rentas vencidas (ADR 0072).
const correoAlertas = ref("");
// Documentos legales: el borrador (texto y responsable) y lo publicado. Guardar no
// cambia lo que ven los usuarios; publicar crea la versión siguiente.
interface Publicado {
  version: number;
  vigente_desde: string;
}
const legales = ref({
  aviso_privacidad: "",
  terminos: "",
  responsable: { nombre: "", domicilio: "", contacto: "", area: "" },
});
const publicados = ref<{
  aviso_privacidad: Publicado | null;
  terminos: Publicado | null;
}>({ aviso_privacidad: null, terminos: null });
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

async function cargarConfiguracion(): Promise<void> {
  const [cfg, pas, leg] = await Promise.all([
    cliente.get<{
      data: { facturapi_configurada: boolean; correo_alertas?: string | null };
    }>("/api/v1/plataforma/configuracion", encabezados()),
    cliente.get<{ data: Pasarela[] }>(
      "/api/v1/plataforma/pasarelas",
      encabezados(),
    ),
    cliente.get<{
      data: {
        aviso_privacidad: string | null;
        terminos: string | null;
        responsable?: {
          nombre: string;
          domicilio: string;
          contacto: string;
          area: string;
        };
        publicados?: {
          aviso_privacidad: Publicado | null;
          terminos: Publicado | null;
        };
      };
    }>("/api/v1/plataforma/legales", encabezados()),
  ]);
  facturapiConfigurada.value = cfg.data.data.facturapi_configurada;
  correoAlertas.value = cfg.data.data.correo_alertas ?? "";
  pasarelas.value = pas.data.data;
  legales.value = {
    aviso_privacidad: leg.data.data.aviso_privacidad ?? "",
    terminos: leg.data.data.terminos ?? "",
    responsable: {
      nombre: leg.data.data.responsable?.nombre ?? "",
      domicilio: leg.data.data.responsable?.domicilio ?? "",
      contacto: leg.data.data.responsable?.contacto ?? "",
      area: leg.data.data.responsable?.area ?? "",
    },
  };
  publicados.value = leg.data.data.publicados ?? {
    aviso_privacidad: null,
    terminos: null,
  };
  for (const p of pasarelas.value) {
    pasarelaDraft.value[p.proveedor] = {
      activa: p.activa,
      modo: p.modo,
      llaves: {},
    };
  }
}

async function guardarLlave(): Promise<void> {
  guardando.value = "facturapi";
  try {
    const { data } = await cliente.put<{
      data: { facturapi_configurada: boolean };
    }>(
      "/api/v1/plataforma/configuracion",
      { facturapi_llave: llaveInput.value || null },
      encabezados(),
    );
    facturapiConfigurada.value = data.data.facturapi_configurada;
    llaveInput.value = "";
    toast.exito(t("plataforma.facturapi.guardado"));
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

async function guardarLegales(avisar = true): Promise<boolean> {
  guardando.value = "legales";
  try {
    const r = legales.value.responsable;
    await cliente.put(
      "/api/v1/plataforma/legales",
      {
        aviso_privacidad: legales.value.aviso_privacidad || null,
        terminos: legales.value.terminos || null,
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
async function publicarLegal(
  tipo: "aviso_privacidad" | "terminos",
): Promise<void> {
  if (
    !(await confirmar(t(`operacion.legales.confirmar.${tipo}`))) ||
    !(await guardarLegales(false))
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
  try {
    const { data } = await cliente.put<{ data: Pasarela }>(
      `/api/v1/plataforma/pasarelas/${proveedor}`,
      { activa: draft.activa, modo: draft.modo, credenciales: draft.llaves },
      encabezados(),
    );
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
async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    await Promise.all([cargarEstudios(), cargarConfiguracion()]);
    autenticado.value = true;
  } catch {
    autenticado.value = false;
    error.value = t("plataforma.tokenInvalido");
    token.value = "";
    borrar();
  } finally {
    cargando.value = false;
  }
}

function irPestana(p: Pestana): void {
  pestana.value = p;
  if (p === "cobros") {
    void cargarCobros();
  }
}

function entrar(): void {
  token.value = tokenInput.value.trim();
  guardar(token.value);
  void cargar();
}

function salir(): void {
  token.value = "";
  autenticado.value = false;
  estudios.value = [];
  borrar();
}

onMounted(() => {
  if (token.value !== "") {
    void cargar();
  }
});

function leer(): string {
  try {
    return localStorage.getItem(CLAVE_TOKEN) ?? "";
  } catch {
    return "";
  }
}
function guardar(v: string): void {
  try {
    localStorage.setItem(CLAVE_TOKEN, v);
  } catch {
    // sin localStorage: se mantiene solo en memoria
  }
}
function borrar(): void {
  try {
    localStorage.removeItem(CLAVE_TOKEN);
  } catch {
    // ignora
  }
}
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-10">
    <!-- Puerta por token -->
    <div v-if="!autenticado" class="mx-auto max-w-sm tu-card p-6">
      <h1 class="font-light text-xl">{{ $t("plataforma.titulo") }}</h1>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("plataforma.tokenAyuda") }}
      </p>
      <form class="mt-4 space-y-3" @submit.prevent="entrar">
        <div>
          <label class="tu-label" for="tk">{{ $t("plataforma.token") }}</label>
          <input
            id="tk"
            v-model="tokenInput"
            class="tu-input"
            type="password"
            required
          />
        </div>
        <p v-if="error" class="text-sm" style="color: var(--error)">
          {{ error }}
        </p>
        <button
          class="tu-btn tu-btn-primario w-full"
          type="submit"
          :disabled="cargando"
        >
          {{ $t("plataforma.entrar") }}
        </button>
      </form>
    </div>

    <template v-else>
      <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 class="text-2xl font-semibold tracking-tight">
            {{ $t("plataforma.titulo") }}
          </h1>
          <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("plataforma.subtitulo") }}
          </p>
        </div>
        <button class="tu-btn tu-btn-fantasma" type="button" @click="salir">
          {{ $t("plataforma.salir") }}
        </button>
      </div>

      <div class="tu-segmentado mt-6" role="group">
        <button
          v-for="p in [
            'estudios',
            'cobros',
            'tarifas',
            'parametros',
            'configuracion',
            'operacion',
            'errores',
          ] as const"
          :key="p"
          type="button"
          :aria-pressed="pestana === p"
          @click="irPestana(p)"
        >
          {{ $t(`plataformaAdmin.pestanas.${p}`) }}
          <span
            v-if="p === 'estudios'"
            class="ml-1 tabular-nums"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ estudios.length }}</span
          >
        </button>
      </div>

      <!-- Estudios -->
      <TablaDatos
        v-if="pestana === 'estudios'"
        class="mt-5"
        :columnas="columnas"
        :filas="estudios"
        :buscar-en="['slug', 'nombre', 'ciudad']"
        :vacio="$t('plataforma.estudios.vacio')"
        clave-vista="plataforma-estudios"
      >
        <template #col-nombre="{ fila }">
          <span class="block font-medium">{{ (fila as Estudio).nombre }}</span>
          <span
            class="block font-mono text-xs"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ (fila as Estudio).slug }}</span
          >
        </template>
        <template #col-estado="{ fila }">
          <span class="inline-flex items-center gap-1.5 text-sm">
            <span
              class="h-1.5 w-1.5 rounded-full"
              :style="{ background: colorEstado((fila as Estudio).estado) }"
              aria-hidden="true"
            />
            {{ $t(`plataformaAdmin.estados.${(fila as Estudio).estado}`) }}
          </span>
          <span
            v-if="
              (fila as Estudio).estado === 'trialing' &&
              (fila as Estudio).trial_termina_en
            "
            class="block text-xs"
            :style="{ color: 'var(--texto-suave)' }"
            >{{
              $t("plataformaAdmin.pruebaHasta", {
                fecha: fecha((fila as Estudio).trial_termina_en),
              })
            }}</span
          >
        </template>
        <template #col-cobro="{ fila }">
          <span class="text-sm">{{ cobroLegible(fila as Estudio) }}</span>
        </template>
        <template #col-uso="{ fila }">
          <span v-if="(fila as Estudio).uso" class="text-sm tabular-nums">
            {{ (fila as Estudio).uso?.cantidad }}
            <span :style="{ color: 'var(--texto-suave)' }">{{
              $t(`cobro.actual.${(fila as Estudio).uso?.metrica}`)
            }}</span>
          </span>
          <span v-else class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("plataformaAdmin.estudios.sinUso") }}
          </span>
        </template>
        <template #col-adeudo_minor="{ fila }">
          <span
            v-if="((fila as Estudio).adeudo_minor ?? 0) > 0"
            class="text-sm font-medium tabular-nums"
            :style="{ color: 'var(--error)' }"
            >{{ dinero((fila as Estudio).adeudo_minor ?? 0) }}</span
          >
          <span v-else class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("plataformaAdmin.estudios.alCorriente") }}
          </span>
        </template>
        <template #col-acciones="{ fila }">
          <button
            class="tu-enlace"
            type="button"
            @click="abrirFicha(fila as Estudio)"
          >
            {{ $t("plataformaAdmin.estudios.ver") }}
          </button>
        </template>
      </TablaDatos>

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

        <div class="mt-5 tu-card p-5">
          <div class="flex flex-wrap items-end gap-3">
            <select
              v-model="filtro.estado"
              class="tu-input max-w-[12rem]"
              :aria-label="$t('plataformaAdmin.cobros.colEstado')"
              @change="cargarCobros"
            >
              <option value="">{{ $t("plataformaAdmin.cobros.todos") }}</option>
              <option
                v-for="e in ['pendiente', 'pagado', 'sin_cargo']"
                :key="e"
                :value="e"
              >
                {{ $t(`plataformaAdmin.cargos.${e}`) }}
              </option>
              <option value="vencidos">
                {{ $t("plataformaAdmin.cobros.vencidos") }}
              </option>
            </select>
            <input
              v-model="filtro.periodo"
              class="tu-input max-w-[12rem]"
              type="month"
              :aria-label="$t('plataformaAdmin.cobros.periodo')"
              @change="cargarCobros"
            />
          </div>
          <p
            v-if="cargos.length === 0"
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
                  class="inline-flex items-center gap-1.5 text-xs font-medium"
                  :style="{ color: colorCargo(c) }"
                >
                  <span
                    class="h-1.5 w-1.5 rounded-full"
                    :style="{ background: colorCargo(c) }"
                    aria-hidden="true"
                  />{{ estadoCargo(c) }}</span
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
                    class="tu-btn tu-btn-fantasma text-xs"
                    style="color: var(--error)"
                    data-prueba="suspender-por-renta"
                    :disabled="suspendiendo === c.id"
                    @click="suspenderPorRenta(c)"
                  >
                    {{ $t("plataformaAdmin.ficha.suspender") }}
                  </button>
                </template>
              </span>
            </li>
          </ul>
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

      <!-- Configuración -->
      <div v-if="pestana === 'configuracion'" class="mt-5 space-y-5">
        <div class="tu-card p-5">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h2 class="font-light text-lg">
                {{ $t("plataforma.facturapi.titulo") }}
              </h2>
              <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
                {{ $t("plataforma.facturapi.subtitulo") }}
              </p>
            </div>
            <span
              class="tu-badge shrink-0"
              :class="facturapiConfigurada ? 'tu-badge-exito' : ''"
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
            @submit.prevent="guardarLlave"
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
              :disabled="guardando === 'facturapi'"
            >
              {{ $t("plataforma.facturapi.guardar") }}
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
          <h2 class="font-light text-lg">
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
            >
              {{ $t("plataformaAdmin.correoAlertas.guardar") }}
            </button>
          </div>
        </form>

        <WhatsAppPlataforma :api-url="apiUrl" :token="token" />

        <div>
          <h2 class="font-light text-lg">
            {{ $t("plataforma.pasarelas.titulo") }}
          </h2>
          <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("plataforma.pasarelas.subtitulo") }}
          </p>
          <div class="mt-3 grid gap-3 md:grid-cols-3">
            <div v-for="p in pasarelas" :key="p.proveedor" class="tu-card p-4">
              <div
                v-if="p.disponible === false"
                class="flex items-center justify-between gap-2"
              >
                <h3 class="font-light">
                  {{ $t(`pasarelas.proveedores.${p.proveedor}`) }}
                </h3>
                <span class="tu-badge">{{
                  $t("pasarelasEstado.proximamente")
                }}</span>
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
                  <h3 class="font-light">
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
                <button
                  class="tu-btn tu-btn-primario w-full mt-3 text-sm"
                  type="button"
                  :disabled="guardando === p.proveedor"
                  @click="guardarPasarela(p.proveedor)"
                >
                  {{ $t("plataforma.pasarelas.guardar") }}
                </button>
              </template>
            </div>
          </div>
        </div>

        <form class="tu-card p-5 grid gap-4" @submit.prevent="guardarLegales()">
          <div>
            <h2 class="font-light text-lg">
              {{ $t("plataforma.legales.titulo") }}
            </h2>
            <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("operacion.legales.ayuda") }}
            </p>
          </div>
          <fieldset class="grid gap-3 sm:grid-cols-2">
            <legend class="tu-label">
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
          <div>
            <label class="tu-label" for="lg-aviso">{{
              $t("plataforma.legales.aviso")
            }}</label>
            <p class="text-xs mb-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("operacion.legales.marcadores") }}
            </p>
            <textarea
              id="lg-aviso"
              v-model="legales.aviso_privacidad"
              class="tu-input"
              rows="6"
            />
          </div>
          <div>
            <label class="tu-label" for="lg-terminos">{{
              $t("plataforma.legales.terminos")
            }}</label>
            <textarea
              id="lg-terminos"
              v-model="legales.terminos"
              class="tu-input"
              rows="6"
            />
          </div>
          <ul class="text-sm grid gap-1">
            <li
              v-for="tipo in ['aviso_privacidad', 'terminos'] as const"
              :key="tipo"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t(`operacion.legales.nombreDoc.${tipo}`) }}:
              <template v-if="publicados[tipo]">
                {{
                  $t("operacion.legales.version", {
                    version: publicados[tipo]!.version,
                    fecha: fechaPublicado(publicados[tipo]!),
                  })
                }}
              </template>
              <span v-else :style="{ color: 'var(--aviso)' }">{{
                $t("operacion.legales.sinPublicar")
              }}</span>
            </li>
          </ul>
          <div class="flex flex-wrap gap-2">
            <button
              class="tu-btn tu-btn-fantasma"
              type="submit"
              :disabled="guardando !== null"
            >
              {{ $t("operacion.legales.guardarBorrador") }}
            </button>
            <button
              class="tu-btn tu-btn-primario"
              type="button"
              :disabled="guardando !== null"
              @click="publicarLegal('aviso_privacidad')"
            >
              {{ $t("operacion.legales.publicarAviso") }}
            </button>
            <button
              class="tu-btn tu-btn-primario"
              type="button"
              :disabled="guardando !== null"
              @click="publicarLegal('terminos')"
            >
              {{ $t("operacion.legales.publicarTerminos") }}
            </button>
          </div>
        </form>
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
            <span class="inline-flex items-center gap-1.5 font-medium">
              <span
                class="h-1.5 w-1.5 rounded-full"
                :style="{ background: colorEstado(ficha.estado) }"
                aria-hidden="true"
              />{{ $t(`plataformaAdmin.estados.${ficha.estado}`) }}</span
            >
            <span
              v-if="ficha.suspendido_por === 'renta'"
              :style="{ color: 'var(--texto-suave)' }"
              data-prueba="suspendido-por-renta"
              >{{ $t("plataformaAdmin.cobros.suspendidoPorRenta") }}</span
            >
            <span :style="{ color: 'var(--texto-suave)' }">{{
              $t(`panel.estados.${ficha.estado_facturacion}`)
            }}</span>
            <a
              :href="`/estudio/${ficha.slug}`"
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
                class="pf-verificado text-xs"
                data-prueba="whatsapp-verificado"
              >
                <span class="pf-punto" aria-hidden="true"></span>
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
                <dt class="first-letter:uppercase">{{ periodo(c.periodo) }}</dt>
                <dd class="text-right">
                  <span class="tabular-nums">{{
                    dinero(c.monto_minor, c.moneda)
                  }}</span>
                  <span
                    class="block text-xs"
                    :style="{ color: colorCargo(c) }"
                    >{{ estadoCargo(c) }}</span
                  >
                </dd>
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
                    {{ $t(`cobro.modalidad.${ficha.modalidad}`) }}
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
              <div v-if="edit.modo_cobro === 'fijo'" class="col-span-2">
                <label class="tu-label" for="cf">{{
                  $t("plataforma.estudios.cuotaFija")
                }}</label>
                <input
                  id="cf"
                  v-model="edit.cuota"
                  type="number"
                  min="0"
                  step="0.01"
                  class="tu-input"
                />
              </div>
            </div>
            <button
              class="tu-btn tu-btn-primario text-sm"
              type="submit"
              :disabled="accionando"
            >
              {{ $t("plataforma.estudios.guardar") }}
            </button>
          </form>

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
                class="tu-btn tu-btn-fantasma text-sm"
                type="button"
                :disabled="accionando"
                @click="extenderPrueba"
              >
                {{ $t("plataformaAdmin.ficha.extender") }}
              </button>
            </div>
            <button
              v-if="ficha.estado === 'trialing' || ficha.estado === 'active'"
              class="tu-btn tu-btn-fantasma text-sm"
              style="color: var(--error)"
              type="button"
              :disabled="accionando"
              @click="suspender"
            >
              {{ $t("plataformaAdmin.ficha.suspender") }}
            </button>
            <button
              v-if="ficha.estado === 'suspended'"
              class="tu-btn tu-btn-primario text-sm"
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
    </template>
  </section>
</template>

<style scoped>
.pf-verificado {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--texto-suave);
}
.pf-punto {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: var(--exito);
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
</style>
