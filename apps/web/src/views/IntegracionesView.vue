<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";

import AutomaticoBienestar from "@/components/AutomaticoBienestar.vue";
import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import InterruptorSiNo from "@/components/InterruptorSiNo.vue";
import KioscosNegocio from "@/components/KioscosNegocio.vue";
import LogoPlataforma from "@/components/LogoPlataforma.vue";
import MedicionNegocio from "@/components/MedicionNegocio.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PestanasSeccion from "@/components/PestanasSeccion.vue";
import ReservasBienestar from "@/components/ReservasBienestar.vue";
import FitpassOperacion from "@/components/FitpassOperacion.vue";
import { api, camposConError, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { usePestanaEnUrl } from "@/lib/filtrosEnUrl";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Integraciones del estudio: plataformas de bienestar (Wellhub, TotalPass), llaves
 * de API de solo lectura para otros sistemas y webhooks que avisan de eventos.
 * Los secretos se muestran una sola vez, al crearlos.
 */
const { t } = useI18n();

// Lo que hace la integración: «Lo que hace:» resaltado y el resto como mensaje.
const alcance = computed(() => {
  const texto = t("integraciones.alcance");
  const corte = texto.indexOf(":");
  return corte > 0 && corte < 30
    ? { cabeza: texto.slice(0, corte + 1), resto: texto.slice(corte + 1) }
    : { cabeza: "", resto: texto };
});

interface Integracion {
  proveedor: string;
  // De qué sede es esta conexión (null: del negocio entero) y las que hay: cada sede
  // es otro gimnasio en la plataforma, con sus llaves (ADR 0202).
  sede?: { id: string; nombre: string } | null;
  sedes?: {
    sucursal: { id: string; nombre: string } | null;
    activa: boolean;
  }[];
  activa: boolean;
  // Las credenciales que pide el proveedor y las que ya se capturaron.
  llaves: string[];
  llaves_configuradas: string[];
  // Sin estas no se puede activar.
  llaves_obligatorias?: string[];
  // Otros grupos de llaves que también bastan para activar.
  llaves_alternativas?: string[][];
  // La conexión por lo que de verdad pasó (como las pasarelas): sin llaves, con
  // llaves y apagada, activa sin comprobar o comprobada (la plataforma ya respondió).
  estado_conexion?: EstadoConexion;
  comprobada_en?: string | null;
  // Lo último que llegó con llaves anteriores: historial, no prueba (R13).
  ultimo_exito_anterior_en?: string | null;
  comprobada_por?: "checkin" | "aviso" | null;
  // Check-in automático (ADR 0148): dónde avisa la plataforma y si ya está activo.
  // El código que el negocio da a Wellhub al elegir a AgendaUno (ADR 0202).
  codigo_para_plataforma?: string | null;
  automatico?: {
    aviso_url: string | null;
    listo: boolean;
    registrado_en: string | null;
  };
}
type EstadoConexion =
  "sin_configurar" | "credenciales" | "activa_sin_comprobar" | "comprobada";
interface LlaveApi {
  id: string;
  nombre: string;
  prefijo: string;
  scopes: string[];
  activa: boolean;
  ultimo_uso_en: string | null;
}
interface Webhook {
  id: string;
  url: string;
  eventos: string[] | null;
  activo: boolean;
}
interface Entrega {
  id: string;
  evento_tipo: string;
  estado: "pendiente" | "entregado" | "fallido";
  http_status: number | null;
  intentos: number;
  ultimo_error: string | null;
  entregado_en: string | null;
}
// Medición: su GA4 y su Meta (ADR 0143).
type Pestana = "bienestar" | "llaves" | "webhooks" | "medicion" | "kiosco";

// Lo secreto se captura como contraseña; los códigos (Gym ID, gimnasio, plan), no.
const SECRETAS = new Set(["api_key", "webhook_secret", "place_api_key"]);

const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

// Las plataformas de bienestar (Wellhub, TotalPass) son de clases (ADR 0104) y solo
// operan con negocios en México.
const conBienestar = computed(
  () => !sesion.esCitas && sesion.estudio?.bienestar_posible === true,
);
// La pestaña va en la dirección (`?pestana=webhooks`): recargar o compartir el enlace
// abre la misma. Sin bienestar, la primera es Llaves; el kiosco, solo con permiso.
const pestanas = computed<Pestana[]>(() => [
  ...(conBienestar.value ? (["bienestar"] as const) : []),
  "llaves",
  "webhooks",
  "medicion",
  ...(sesion.puede("estudio.gestionar") ? (["kiosco"] as const) : []),
]);
const pestana = usePestanaEnUrl(pestanas);
const TEXTO_PESTANA: Record<Pestana, string> = {
  bienestar: "conexiones.bienestar",
  llaves: "conexiones.llaves",
  webhooks: "conexiones.webhooks",
  medicion: "medicion.negocio.pestana",
  kiosco: "kiosco.panel.titulo",
};
const opcionesPestana = computed(() =>
  pestanas.value.map((clave) => ({ clave, texto: t(TEXTO_PESTANA[clave]) })),
);
const cargando = ref(true);
const error = ref<string | null>(null);

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(iso));
}

async function copiar(texto: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(texto);
    toast.exito(t("conexiones.copiado"));
  } catch {
    // Sin permiso de portapapeles: el texto sigue a la vista para copiarlo a mano.
  }
}

// ---- Plataformas de bienestar ----
const integraciones = ref<Integracion[]>([]);
const guardando = ref<string | null>(null);
const edicion = reactive<
  Record<string, { activa: boolean; llaves: Record<string, string> }>
>({});
// Las llaves que la API rechazó, por plataforma.
const llavesConError = reactive<Record<string, string[]>>({});
// La sede cuya conexión se ve, por plataforma ("" = la del negocio entero).
const sedeDe = reactive<Record<string, string>>({});
const sedesNegocio = computed(() => sesion.usuario?.sucursales ?? []);

function tomarIntegracion(i: Integracion): void {
  const idx = integraciones.value.findIndex((x) => x.proveedor === i.proveedor);
  if (idx !== -1) {
    integraciones.value[idx] = i;
  }
  edicion[i.proveedor] = {
    activa: i.activa,
    llaves: Object.fromEntries(i.llaves.map((k) => [k, ""])),
  };
}

function paramsDeSede(proveedor: string): { sucursal?: string } {
  const sede = sedeDe[proveedor] ?? "";
  return sede !== "" ? { sucursal: sede } : {};
}

/** Las sedes con conexión, para leerlas de un vistazo. */
function sedesConectadas(i: Integracion): string {
  return (i.sedes ?? [])
    .map(
      (s) => s.sucursal?.nombre ?? t("operacion.integraciones.todoElNegocio"),
    )
    .join(", ");
}

async function elegirSede(proveedor: string, sede: string): Promise<void> {
  sedeDe[proveedor] = sede;
  llavesConError[proveedor] = [];
  try {
    const { data } = await api.get<{ data: Integracion }>(
      `${base.value}/integraciones/${proveedor}`,
      { params: paramsDeSede(proveedor) },
    );
    tomarIntegracion(data.data);
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function quitarSede(i: Integracion): Promise<void> {
  if (
    !(await confirmar(
      t("operacion.integraciones.confirmarQuitar", {
        sede: i.sede?.nombre ?? "",
        plataforma: t(`integraciones.proveedores.${i.proveedor}`),
      }),
      { peligro: true },
    ))
  ) {
    return;
  }
  try {
    await api.delete(`${base.value}/integraciones/${i.proveedor}`, {
      params: paramsDeSede(i.proveedor),
    });
    toast.exito(t("operacion.integraciones.quitada"));
    await elegirSede(i.proveedor, "");
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

/**
 * Las llaves sin las que no se puede activar y que no están ni guardadas ni escritas:
 * mientras falte alguna, el interruptor no se enciende.
 */
function faltanLlaves(i: Integracion): string[] {
  const escritas = edicion[i.proveedor]?.llaves ?? {};
  const falta = (llave: string): boolean =>
    !i.llaves_configuradas.includes(llave) &&
    (escritas[llave] ?? "").trim() === "";
  // Con un grupo completo basta (TotalPass: la llave de su sede sola).
  const alternativa = (i.llaves_alternativas ?? []).some(
    (grupo) => grupo.length > 0 && !grupo.some(falta),
  );
  return alternativa ? [] : (i.llaves_obligatorias ?? []).filter(falta);
}

// La conexión, con punto y texto: verde comprobada, ámbar activa sin comprobar.
const TONO_CONEXION: Record<EstadoConexion, string> = {
  sin_configurar: "var(--texto-suave)",
  credenciales: "var(--texto-suave)",
  activa_sin_comprobar: "var(--aviso)",
  comprobada: "var(--exito)",
};
// En la zona del negocio (ADR 0099) y con el año: la última prueba puede ser vieja.
function fechaEvidencia(iso: string | null | undefined): string {
  if (!iso) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: sesion.zonaHoraria || undefined,
  }).format(new Date(iso));
}
function textoConexion(i: Integracion): string {
  const estado = i.estado_conexion;
  if (estado === undefined) {
    return "";
  }
  if (estado === "comprobada") {
    return t(
      i.comprobada_por === "aviso"
        ? "adminUsabilidad.integraciones.conexion.comprobadaAviso"
        : "adminUsabilidad.integraciones.conexion.comprobadaCheckin",
      { fecha: fechaEvidencia(i.comprobada_en) },
    );
  }
  const texto = t(`adminUsabilidad.integraciones.conexion.${estado}`);
  // Con llaves anteriores sí respondió: se dice, sin darla por comprobada (R13).
  return estado === "activa_sin_comprobar" && i.ultimo_exito_anterior_en
    ? texto +
        t("adminUsabilidad.integraciones.conexion.anterior", {
          fecha: fechaEvidencia(i.ultimo_exito_anterior_en),
        })
    : texto;
}

function nombresDeLlaves(proveedor: string, llaves: string[]): string {
  return llaves
    .map((llave) => t(`integraciones.llaves.${proveedor}.${llave}`))
    .join(", ");
}

async function guardar(proveedor: string): Promise<void> {
  guardando.value = proveedor;
  llavesConError[proveedor] = [];
  try {
    const ed = edicion[proveedor];
    const credenciales: Record<string, string> = {};
    for (const [k, v] of Object.entries(ed.llaves)) {
      if (v.trim() !== "") {
        credenciales[k] = v;
      }
    }
    const { data } = await api.put<{ data: Integracion }>(
      `${base.value}/integraciones/${proveedor}`,
      { activa: ed.activa, credenciales },
      { params: paramsDeSede(proveedor) },
    );
    tomarIntegracion(data.data);
    toast.exito(t("integraciones.guardado"));
  } catch (e) {
    llavesConError[proveedor] = camposConError(e)
      .filter((campo) => campo.startsWith("credenciales."))
      .map((campo) => campo.slice("credenciales.".length));
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = null;
  }
}

function configurada(proveedor: string, llave: string): boolean {
  return (
    integraciones.value
      .find((i) => i.proveedor === proveedor)
      ?.llaves_configuradas.includes(llave) ?? false
  );
}

// ---- Llaves de API ----
const llaves = ref<LlaveApi[]>([]);
const scopesDisponibles = ref<string[]>([]);
const nuevaLlave = ref<{ nombre: string; scopes: string[] }>({
  nombre: "",
  scopes: [],
});
const secretoLlave = ref<string | null>(null);
const modalLlave = ref(false);
// El patrón común de los formularios: el error dentro del diálogo y junto a su campo,
// no se cierra mientras guarda y pregunta antes de descartar. La llave creada se
// confirma mostrando su secreto (una sola vez).
const editorLlave = useEditorFormulario({
  abierto: modalLlave,
  valor: () => nuevaLlave.value,
  campos: ["nombre", "scopes"],
  formulario: "#integracion-llave",
});
const creandoLlave = editorLlave.guardando;
const erroresLlave = editorLlave.errores;
const errorLlave = editorLlave.error;
function abrirLlave(): void {
  nuevaLlave.value = { nombre: "", scopes: [] };
  editorLlave.empezar();
  modalLlave.value = true;
}

async function crearLlave(): Promise<void> {
  const respuesta = await editorLlave.guardar(() =>
    api.post<{ data: LlaveApi & { secreto: string } }>(
      `${base.value}/llaves-api`,
      nuevaLlave.value,
    ),
  );
  if (respuesta === undefined) {
    return;
  }
  secretoLlave.value = respuesta.data.data.secreto;
  modalLlave.value = false;
  await cargarLlaves();
}

async function revocar(l: LlaveApi): Promise<void> {
  if (
    !(await confirmar(t("conexiones.llave.confirmarRevocar"), {
      peligro: true,
    }))
  ) {
    return;
  }
  try {
    await api.delete(`${base.value}/llaves-api/${l.id}`);
    await cargarLlaves();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function cargarLlaves(): Promise<void> {
  const { data } = await api.get<{ data: LlaveApi[]; scopes: string[] }>(
    `${base.value}/llaves-api`,
  );
  llaves.value = data.data;
  scopesDisponibles.value = data.scopes;
}

// ---- Webhooks ----
const webhooks = ref<Webhook[]>([]);
const eventosDisponibles = ref<string[]>([]);
const nuevoWebhook = ref<{ url: string; eventos: string[] }>({
  url: "",
  eventos: [],
});
const secretoWebhook = ref<string | null>(null);
const modalWebhook = ref(false);
const editorWebhook = useEditorFormulario({
  abierto: modalWebhook,
  valor: () => nuevoWebhook.value,
  campos: ["url", "eventos"],
  formulario: "#integracion-webhook",
});
const creandoWebhook = editorWebhook.guardando;
const erroresWebhook = editorWebhook.errores;
const errorWebhook = editorWebhook.error;
function abrirWebhook(): void {
  nuevoWebhook.value = { url: "", eventos: [] };
  editorWebhook.empezar();
  modalWebhook.value = true;
}
const entregasDe = ref<string | null>(null);
const entregas = ref<Entrega[]>([]);
// Por páginas (antes, solo las últimas 100) y por estado, con sus cuentas.
const estadoEntregas = ref<"" | "fallido" | "pendiente" | "entregado">("");
const paginaEntregas = ref(1);
const metaEntregas = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
  conteos: { pendiente: number; entregado: number; fallido: number };
} | null>(null);
async function cargarEntregas(id: string): Promise<void> {
  const { data } = await api.get<{
    data: Entrega[];
    meta?: typeof metaEntregas.value;
  }>(`${base.value}/webhooks-salientes/${id}/entregas`, {
    params: {
      page: paginaEntregas.value,
      ...(estadoEntregas.value !== "" ? { estado: estadoEntregas.value } : {}),
    },
  });
  entregas.value = data.data;
  metaEntregas.value = data.meta ?? null;
}
async function filtrarEntregas(
  estado: "" | "fallido" | "pendiente" | "entregado",
): Promise<void> {
  if (entregasDe.value === null) {
    return;
  }
  estadoEntregas.value = estado;
  paginaEntregas.value = 1;
  try {
    await cargarEntregas(entregasDe.value);
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}
async function irPaginaEntregas(n: number): Promise<void> {
  if (entregasDe.value === null) {
    return;
  }
  paginaEntregas.value = n;
  try {
    await cargarEntregas(entregasDe.value);
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function cargarWebhooks(): Promise<void> {
  const { data } = await api.get<{
    data: Webhook[];
    eventos_disponibles: string[];
  }>(`${base.value}/webhooks-salientes`);
  webhooks.value = data.data;
  eventosDisponibles.value = data.eventos_disponibles;
}

async function crearWebhook(): Promise<void> {
  const respuesta = await editorWebhook.guardar(() =>
    api.post<{ data: Webhook & { secreto: string } }>(
      `${base.value}/webhooks-salientes`,
      {
        url: nuevoWebhook.value.url,
        eventos:
          nuevoWebhook.value.eventos.length > 0
            ? nuevoWebhook.value.eventos
            : null,
      },
    ),
  );
  if (respuesta === undefined) {
    return;
  }
  secretoWebhook.value = respuesta.data.data.secreto;
  modalWebhook.value = false;
  await cargarWebhooks();
}

async function eliminarWebhook(w: Webhook): Promise<void> {
  if (
    !(await confirmar(t("conexiones.webhook.confirmarEliminar"), {
      peligro: true,
    }))
  ) {
    return;
  }
  try {
    await api.delete(`${base.value}/webhooks-salientes/${w.id}`);
    await cargarWebhooks();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function verEntregas(w: Webhook): Promise<void> {
  if (entregasDe.value === w.id) {
    entregasDe.value = null;
    return;
  }
  try {
    estadoEntregas.value = "";
    paginaEntregas.value = 1;
    await cargarEntregas(w.id);
    entregasDe.value = w.id;
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [bienestar] = await Promise.all([
      conBienestar.value
        ? api.get<{ data: Integracion[] }>(`${base.value}/integraciones`)
        : null,
      cargarLlaves(),
      cargarWebhooks(),
    ]);
    integraciones.value = bienestar?.data.data ?? [];
    for (const i of integraciones.value) {
      sedeDe[i.proveedor] = i.sede?.id ?? "";
      tomarIntegracion(i);
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <!-- El título no cambia con la pestaña: «Nueva llave» y «Nuevo webhook» van en
         la barra de su panel. -->
    <EncabezadoSeccion
      :titulo="$t('conexiones.titulo')"
      :subtitulo="$t('adminUsabilidad.integraciones.ayuda')"
    />

    <PestanasSeccion
      v-model="pestana"
      :opciones="opcionesPestana"
      :etiqueta="$t('conexiones.titulo')"
    />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <!-- Lo que no se pudo consultar: aquí, con Reintentar; las listas no dicen
         «aún no hay». -->
    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          :disabled="cargando"
          data-prueba="reintentar-integraciones"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>

    <!-- Plataformas de bienestar -->
    <div
      v-if="!cargando && conBienestar && pestana === 'bienestar'"
      class="mt-5 space-y-4"
    >
      <AvisoTono
        tono="info"
        :titulo="$t('integraciones.soloMexico')"
        data-prueba="alcance-bienestar"
      >
        <strong v-if="alcance.cabeza">{{ alcance.cabeza }}</strong
        >{{ alcance.resto }}
      </AvisoTono>
      <div
        v-for="i in integraciones"
        :key="i.proveedor"
        class="tu-card p-5"
        :data-prueba="`integracion-${i.proveedor}`"
      >
        <!-- Su distintivo, su nombre, qué valida y si está activa -->
        <div class="flex items-start gap-3">
          <LogoPlataforma :proveedor="i.proveedor" />
          <div class="min-w-0 flex-1">
            <h2 class="text-base font-semibold">
              {{ $t(`integraciones.proveedores.${i.proveedor}`) }}
            </h2>
            <p class="mt-0.5 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`integraciones.ayuda.${i.proveedor}`) }}
            </p>
            <p
              v-if="i.estado_conexion"
              class="mt-1 text-sm tu-estado"
              :style="{ '--tono': TONO_CONEXION[i.estado_conexion] }"
              :data-prueba="`conexion-${i.proveedor}`"
              :data-estado="i.estado_conexion"
            >
              {{ textoConexion(i) }}
            </p>
          </div>
          <InterruptorSiNo
            v-model="edicion[i.proveedor].activa"
            class="shrink-0"
            :etiqueta="$t('integraciones.activa')"
            :deshabilitado="
              !edicion[i.proveedor].activa && faltanLlaves(i).length > 0
            "
            :prueba="`activa-${i.proveedor}`"
          />
        </div>
        <!-- Con varias sedes, cada una es otro gimnasio en la plataforma: se elige
             de cuál es la conexión que se ve (ADR 0202). -->
        <div
          v-if="sedesNegocio.length > 1"
          class="mt-4 flex flex-wrap items-end gap-3"
          :data-prueba="`sedes-${i.proveedor}`"
        >
          <div class="min-w-48">
            <label class="tu-label" :for="`${i.proveedor}-sede`">{{
              $t("operacion.integraciones.sede")
            }}</label>
            <select
              :id="`${i.proveedor}-sede`"
              class="tu-input"
              :value="sedeDe[i.proveedor] ?? ''"
              @change="
                elegirSede(
                  i.proveedor,
                  ($event.target as HTMLSelectElement).value,
                )
              "
            >
              <option value="">
                {{ $t("operacion.integraciones.todoElNegocio") }}
              </option>
              <option v-for="s in sedesNegocio" :key="s.id" :value="s.id">
                {{ s.nombre }}
              </option>
            </select>
          </div>
          <button
            v-if="i.sede && i.llaves_configuradas.length > 0"
            type="button"
            class="tu-btn tu-btn-peligro tu-btn-chico"
            :data-prueba="`quitar-sede-${i.proveedor}`"
            @click="quitarSede(i)"
          >
            {{ $t("operacion.integraciones.quitarSede") }}
          </button>
          <p class="tu-hint w-full">
            {{
              $t("operacion.integraciones.sedeAyuda", {
                plataforma: $t(`integraciones.proveedores.${i.proveedor}`),
              })
            }}
            <template v-if="(i.sedes ?? []).length > 0">
              {{
                $t("operacion.integraciones.sedesConectadas", {
                  sedes: sedesConectadas(i),
                })
              }}
            </template>
          </p>
        </div>
        <!-- Qué falta para poder activarla -->
        <p
          v-if="!edicion[i.proveedor].activa && faltanLlaves(i).length > 0"
          class="tu-hint mt-3"
          :data-prueba="`faltan-llaves-${i.proveedor}`"
        >
          {{
            $t("operacion.integraciones.faltanLlaves", {
              llaves: nombresDeLlaves(i.proveedor, faltanLlaves(i)),
            })
          }}
        </p>
        <div class="mt-4 grid sm:grid-cols-2 gap-3">
          <div v-for="llave in i.llaves" :key="llave">
            <label class="tu-label" :for="`${i.proveedor}-${llave}`">
              {{ $t(`integraciones.llaves.${i.proveedor}.${llave}`)
              }}<span
                v-if="(i.llaves_obligatorias ?? []).includes(llave)"
                class="tu-obligatorio"
                :title="$t('operacion.integraciones.obligatoria')"
                >*</span
              >
              <span
                v-if="configurada(i.proveedor, llave)"
                class="tu-badge tu-badge-exito ml-1"
                >{{ $t("integraciones.configurada") }}</span
              >
            </label>
            <input
              :id="`${i.proveedor}-${llave}`"
              v-model="edicion[i.proveedor].llaves[llave]"
              class="tu-input"
              :type="SECRETAS.has(llave) ? 'password' : 'text'"
              autocomplete="off"
              :aria-invalid="
                (llavesConError[i.proveedor] ?? []).includes(llave) || undefined
              "
              :placeholder="
                configurada(i.proveedor, llave)
                  ? $t('operacion.integraciones.reemplazar')
                  : $t('operacion.integraciones.capturar')
              "
            />
          </div>
        </div>
        <AutomaticoBienestar
          :proveedor="i.proveedor"
          :automatico="i.automatico"
        />
        <p
          v-if="i.codigo_para_plataforma"
          class="mt-3 text-sm"
          data-prueba="codigo-para-plataforma"
        >
          {{
            $t("integraciones.codigoParaWellhub", {
              codigo: i.codigo_para_plataforma,
            })
          }}
        </p>

        <button
          type="button"
          class="tu-btn tu-btn-primario mt-4"
          :disabled="guardando === i.proveedor"
          :aria-busy="guardando === i.proveedor || undefined"
          @click="guardar(i.proveedor)"
        >
          {{
            guardando === i.proveedor
              ? $t("integraciones.guardando")
              : $t("integraciones.guardar")
          }}
        </button>
        <!-- Reservas desde la app de la plataforma (ADR 0149). -->
        <ReservasBienestar
          v-if="i.activa && i.proveedor !== 'fitpass'"
          :key="`${i.proveedor}-${i.sede?.id ?? ''}`"
          :proveedor="i.proveedor"
          :sucursal="i.sede?.id"
        />
        <!-- Fitpass sin API: horarios para capturar y asistencia por marcar (ADR 0202). -->
        <FitpassOperacion v-if="i.activa && i.proveedor === 'fitpass'" />
      </div>
    </div>

    <!-- Llaves de API -->
    <div v-if="!cargando && pestana === 'llaves'" class="mt-5 space-y-5">
      <div class="tu-card p-5 min-w-0">
        <!-- La barra del panel: qué es y su acción. -->
        <div class="flex flex-wrap items-start justify-between gap-3">
          <p
            class="min-w-0 flex-1 basis-64 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("conexiones.llave.ayuda") }}
          </p>
          <button
            class="tu-btn tu-btn-primario tu-btn-crear"
            type="button"
            data-prueba="nueva-llave"
            @click="abrirLlave"
          >
            {{ $t("conexiones.llave.nueva") }}
          </button>
        </div>
        <div
          v-if="secretoLlave"
          class="mt-4 rounded-xl border p-4"
          :style="{
            borderColor: 'var(--primario)',
            background: 'var(--primario-suave)',
          }"
        >
          <p class="text-sm font-semibold">
            {{ $t("conexiones.llave.creada") }}
          </p>
          <p class="mt-1 text-xs">{{ $t("conexiones.unaVez") }}</p>
          <div class="mt-2 flex items-center gap-2">
            <code class="min-w-0 flex-1 truncate text-sm">{{
              secretoLlave
            }}</code>
            <button
              type="button"
              class="tu-btn tu-btn-secundario"
              @click="copiar(secretoLlave)"
            >
              {{ $t("conexiones.copiar") }}
            </button>
          </div>
        </div>

        <EstadoVacio
          v-if="llaves.length === 0 && !error"
          class="mt-4 py-6"
          icono="integraciones"
          compacto
          :titulo="$t('conexiones.llave.vacio')"
        />
        <ul v-else-if="llaves.length > 0" class="mt-3">
          <li v-for="l in llaves" :key="l.id" class="int-fila text-sm">
            <div class="min-w-0">
              <p
                class="font-medium truncate"
                :style="l.activa ? {} : { color: 'var(--texto-suave)' }"
              >
                {{ l.nombre }}
                <code class="ml-1 text-xs font-normal">{{ l.prefijo }}…</code>
              </p>
              <p
                class="mt-0.5 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{
                  l.scopes
                    .map((s) => $t(`conexiones.llave.scopes.${s}`))
                    .join(", ")
                }}
                ·
                {{
                  l.ultimo_uso_en
                    ? $t("conexiones.llave.ultimoUso", {
                        fecha: fecha(l.ultimo_uso_en),
                      })
                    : $t("conexiones.llave.nuncaUsada")
                }}
              </p>
            </div>
            <button
              v-if="l.activa"
              type="button"
              class="tu-btn tu-btn-peligro tu-btn-chico shrink-0"
              @click="revocar(l)"
            >
              {{ $t("conexiones.llave.revocar") }}
            </button>
            <span v-else class="tu-badge shrink-0">{{
              $t("conexiones.llave.revocada")
            }}</span>
          </li>
        </ul>
      </div>

      <ModalDialogo
        :abierto="modalLlave"
        :titulo="$t('conexiones.llave.nueva')"
        icono="integraciones"
        tam="md"
        :descripcion="$t('adminUsabilidad.integraciones.llave')"
        :ocupado="creandoLlave"
        @cerrar="editorLlave.cerrar"
      >
        <form
          id="integracion-llave"
          class="space-y-4"
          @submit.prevent="crearLlave"
        >
          <AvisoTono v-if="errorLlave" tono="peligro" role="alert">
            {{ errorLlave }}
          </AvisoTono>
          <div>
            <label class="tu-label" for="ll-nombre">{{
              $t("conexiones.llave.nombre")
            }}</label>
            <input
              id="ll-nombre"
              v-bind="editorLlave.marcar('nombre', 'll-nombre-error')"
              v-model="nuevaLlave.nombre"
              class="tu-input"
              :placeholder="$t('conexiones.llave.nombrePh')"
              required
            />
            <span
              v-if="erroresLlave.nombre"
              id="ll-nombre-error"
              class="tu-error-campo"
              >{{ erroresLlave.nombre }}</span
            >
          </div>
          <fieldset>
            <legend class="tu-label">
              {{ $t("conexiones.llave.permisos") }}
            </legend>
            <label v-for="s in scopesDisponibles" :key="s" class="int-opcion">
              <input v-model="nuevaLlave.scopes" type="checkbox" :value="s" />
              {{ $t(`conexiones.llave.scopes.${s}`) }}
            </label>
            <span v-if="erroresLlave.scopes" class="tu-error-campo">{{
              erroresLlave.scopes
            }}</span>
          </fieldset>
        </form>
        <template #pie>
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="creandoLlave"
            @click="editorLlave.cerrar"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            type="submit"
            form="integracion-llave"
            class="tu-btn tu-btn-primario"
            :disabled="
              creandoLlave ||
              nuevaLlave.nombre.trim() === '' ||
              nuevaLlave.scopes.length === 0
            "
          >
            {{ $t("conexiones.llave.crear") }}
          </button>
        </template>
      </ModalDialogo>
    </div>

    <!-- Webhooks -->
    <div v-if="!cargando && pestana === 'webhooks'" class="mt-5 space-y-5">
      <div class="tu-card p-5 min-w-0">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <p
            class="min-w-0 flex-1 basis-64 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("conexiones.webhook.ayuda") }}
          </p>
          <button
            class="tu-btn tu-btn-primario tu-btn-crear"
            type="button"
            data-prueba="nuevo-webhook"
            @click="abrirWebhook"
          >
            {{ $t("conexiones.webhook.nuevo") }}
          </button>
        </div>
        <div
          v-if="secretoWebhook"
          class="mt-4 rounded-xl border p-4"
          :style="{
            borderColor: 'var(--primario)',
            background: 'var(--primario-suave)',
          }"
        >
          <p class="text-sm font-semibold">
            {{ $t("conexiones.webhook.creado") }}
          </p>
          <p class="mt-1 text-xs">
            {{ $t("conexiones.webhook.secreto") }}.
            {{ $t("conexiones.webhook.unaVez") }}
          </p>
          <div class="mt-2 flex items-center gap-2">
            <code class="min-w-0 flex-1 truncate text-sm">{{
              secretoWebhook
            }}</code>
            <button
              type="button"
              class="tu-btn tu-btn-secundario"
              @click="copiar(secretoWebhook)"
            >
              {{ $t("conexiones.copiar") }}
            </button>
          </div>
        </div>

        <EstadoVacio
          v-if="webhooks.length === 0 && !error"
          class="mt-4 py-6"
          icono="integraciones"
          compacto
          :titulo="$t('conexiones.webhook.vacio')"
        />
        <ul v-else-if="webhooks.length > 0" class="mt-3">
          <li v-for="w in webhooks" :key="w.id" class="int-fila text-sm">
            <div class="min-w-0">
              <p class="font-medium truncate">{{ w.url }}</p>
              <p
                class="mt-0.5 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{
                  w.eventos && w.eventos.length > 0
                    ? w.eventos
                        .map((e) => $t(`conexiones.webhook.tipos.${e}`))
                        .join(", ")
                    : $t("conexiones.webhook.todos")
                }}
              </p>
            </div>
            <span class="flex items-center gap-3 shrink-0">
              <button
                type="button"
                class="tu-enlace"
                :aria-expanded="entregasDe === w.id"
                @click="verEntregas(w)"
              >
                {{
                  entregasDe === w.id
                    ? $t("conexiones.webhook.ocultarEntregas")
                    : $t("conexiones.webhook.entregas")
                }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-peligro tu-btn-chico"
                @click="eliminarWebhook(w)"
              >
                {{ $t("conexiones.webhook.eliminar") }}
              </button>
            </span>
            <div
              v-if="entregasDe === w.id"
              class="w-full rounded-xl border px-4 text-xs"
              :style="{
                borderColor: 'var(--borde)',
                background: 'var(--fondo)',
              }"
            >
              <div
                v-if="
                  metaEntregas &&
                  metaEntregas.conteos.pendiente +
                    metaEntregas.conteos.entregado +
                    metaEntregas.conteos.fallido >
                    0
                "
                class="tu-segmentado my-2"
                role="group"
                data-prueba="estado-entregas"
              >
                <button
                  v-for="e in [
                    '',
                    'fallido',
                    'pendiente',
                    'entregado',
                  ] as const"
                  :key="e"
                  type="button"
                  :aria-pressed="estadoEntregas === e"
                  @click="filtrarEntregas(e)"
                >
                  {{
                    e === ""
                      ? $t("operacion.listadosDos.todas")
                      : $t(`conexiones.webhook.estados.${e}`)
                  }}
                  <span
                    v-if="e !== ''"
                    class="ml-1 tabular-nums"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ metaEntregas.conteos[e] }}</span
                  >
                </button>
              </div>
              <!-- Con un estado elegido, «ninguna así» no es «sin entregas». -->
              <EstadoVacio
                v-if="entregas.length === 0 && estadoEntregas !== ''"
                icono="buscar"
                compacto
                :titulo="$t('tabla.sinCoincidencias')"
                :texto="$t('tabla.ajustarBusqueda')"
                data-prueba="entregas-sin-coincidencias"
              >
                <button
                  type="button"
                  class="tu-btn tu-btn-discreto"
                  @click="filtrarEntregas('')"
                >
                  {{ $t("tabla.restablecer") }}
                </button>
              </EstadoVacio>
              <p
                v-else-if="entregas.length === 0"
                class="py-3"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("conexiones.webhook.sinEntregas") }}
              </p>
              <div
                v-for="en in entregas"
                :key="en.id"
                class="flex items-center justify-between gap-3 border-t py-2 first:border-t-0"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <span class="min-w-0 truncate">
                  {{ $t(`conexiones.webhook.tipos.${en.evento_tipo}`) }}
                  <span
                    v-if="en.ultimo_error"
                    class="block truncate"
                    style="color: var(--error)"
                    >{{ en.ultimo_error }}</span
                  >
                </span>
                <span
                  class="flex items-center gap-2 shrink-0"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  <span>{{
                    $t("conexiones.webhook.intentos", en.intentos)
                  }}</span>
                  <span
                    class="tu-badge"
                    :class="{
                      'tu-badge-exito': en.estado === 'entregado',
                      'tu-badge-aviso': en.estado === 'pendiente',
                    }"
                    >{{ $t(`conexiones.webhook.estados.${en.estado}`) }}
                    <template v-if="en.http_status">
                      · {{ en.http_status }}</template
                    ></span
                  >
                </span>
              </div>
              <PaginacionListado
                v-if="metaEntregas && metaEntregas.ultima_pagina > 1"
                :page="metaEntregas.page"
                :ultima-pagina="metaEntregas.ultima_pagina"
                :total="metaEntregas.total"
                :per-page="metaEntregas.per_page"
                @ir="irPaginaEntregas"
              />
            </div>
          </li>
        </ul>
      </div>

      <ModalDialogo
        :abierto="modalWebhook"
        :titulo="$t('conexiones.webhook.nuevo')"
        icono="integraciones"
        tam="md"
        :descripcion="$t('adminUsabilidad.integraciones.webhook')"
        :ocupado="creandoWebhook"
        @cerrar="editorWebhook.cerrar"
      >
        <form
          id="integracion-webhook"
          class="space-y-4"
          @submit.prevent="crearWebhook"
        >
          <AvisoTono v-if="errorWebhook" tono="peligro" role="alert">
            {{ errorWebhook }}
          </AvisoTono>
          <div>
            <label class="tu-label" for="wh-url">{{
              $t("conexiones.webhook.url")
            }}</label>
            <input
              id="wh-url"
              v-bind="editorWebhook.marcar('url', 'wh-url-error')"
              v-model="nuevoWebhook.url"
              class="tu-input"
              type="url"
              :placeholder="$t('conexiones.webhook.urlPh')"
              required
            />
            <span
              v-if="erroresWebhook.url"
              id="wh-url-error"
              class="tu-error-campo"
              >{{ erroresWebhook.url }}</span
            >
          </div>
          <fieldset>
            <legend class="tu-label">
              {{ $t("conexiones.webhook.eventos") }}
            </legend>
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("conexiones.webhook.todosAyuda") }}
            </p>
            <label
              v-for="ev in eventosDisponibles"
              :key="ev"
              class="int-opcion"
            >
              <input
                v-model="nuevoWebhook.eventos"
                type="checkbox"
                :value="ev"
              />
              {{ $t(`conexiones.webhook.tipos.${ev}`) }}
            </label>
            <span v-if="erroresWebhook.eventos" class="tu-error-campo">{{
              erroresWebhook.eventos
            }}</span>
          </fieldset>
        </form>
        <template #pie>
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="creandoWebhook"
            @click="editorWebhook.cerrar"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            type="submit"
            form="integracion-webhook"
            class="tu-btn tu-btn-primario"
            :disabled="creandoWebhook || nuevoWebhook.url.trim() === ''"
          >
            {{ $t("conexiones.webhook.crear") }}
          </button>
        </template>
      </ModalDialogo>
    </div>

    <!-- GA4 y Meta del negocio (ADR 0143) -->
    <MedicionNegocio v-if="!cargando && pestana === 'medicion'" class="mt-5" />
    <!-- Kiosco de llegada (ADR 0183) -->
    <KioscosNegocio v-if="!cargando && pestana === 'kiosco'" class="mt-5" />
  </section>
</template>

<style scoped>
.int-opcion {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-height: 2.75rem;
  padding: 0.65rem 0.8rem;
  margin-top: 0.45rem;
  border: 1px solid var(--borde);
  border-radius: 0.6rem;
  background: var(--superficie-2);
  font-size: 0.875rem;
  cursor: pointer;
}
.int-opcion:has(input:checked) {
  border-color: var(--primario);
  background: var(--primario-suave);
}
.int-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.int-fila:first-child {
  border-top: 0;
}
</style>
