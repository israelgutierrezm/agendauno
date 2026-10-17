<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";

import AutomaticoBienestar from "@/components/AutomaticoBienestar.vue";
import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import InterruptorSiNo from "@/components/InterruptorSiNo.vue";
import LogoPlataforma from "@/components/LogoPlataforma.vue";
import MedicionNegocio from "@/components/MedicionNegocio.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import ReservasBienestar from "@/components/ReservasBienestar.vue";
import { api, camposConError, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useEditorFormulario } from "@/lib/editorFormulario";
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
  activa: boolean;
  // Las credenciales que pide el proveedor y las que ya se capturaron.
  llaves: string[];
  llaves_configuradas: string[];
  // Sin estas no se puede activar.
  llaves_obligatorias?: string[];
  // Check-in automático (ADR 0148): dónde avisa la plataforma y si ya está activo.
  automatico?: {
    aviso_url: string | null;
    listo: boolean;
    registrado_en: string | null;
  };
}
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
type Pestana = "bienestar" | "llaves" | "webhooks" | "medicion";

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
const pestana = ref<Pestana>(conBienestar.value ? "bienestar" : "llaves");
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

/**
 * Las llaves sin las que no se puede activar y que no están ni guardadas ni escritas:
 * mientras falte alguna, el interruptor no se enciende.
 */
function faltanLlaves(i: Integracion): string[] {
  const escritas = edicion[i.proveedor]?.llaves ?? {};
  return (i.llaves_obligatorias ?? []).filter(
    (llave) =>
      !i.llaves_configuradas.includes(llave) &&
      (escritas[llave] ?? "").trim() === "",
  );
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
    );
    const idx = integraciones.value.findIndex((i) => i.proveedor === proveedor);
    if (idx !== -1) {
      integraciones.value[idx] = data.data;
    }
    for (const k of Object.keys(ed.llaves)) {
      ed.llaves[k] = "";
    }
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
      edicion[i.proveedor] = {
        activa: i.activa,
        llaves: Object.fromEntries(i.llaves.map((k) => [k, ""])),
      };
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
    <EncabezadoSeccion
      :titulo="$t('conexiones.titulo')"
      icono="integraciones"
      :subtitulo="$t('adminUsabilidad.integraciones.ayuda')"
    >
      <template
        v-if="!cargando && (pestana === 'llaves' || pestana === 'webhooks')"
        #acciones
      >
        <button
          v-if="pestana === 'llaves'"
          class="tu-btn tu-btn-primario tu-btn-crear"
          type="button"
          data-prueba="nueva-llave"
          @click="abrirLlave"
        >
          {{ $t("conexiones.llave.nueva") }}
        </button>
        <button
          v-else
          class="tu-btn tu-btn-primario tu-btn-crear"
          type="button"
          data-prueba="nuevo-webhook"
          @click="abrirWebhook"
        >
          {{ $t("conexiones.webhook.nuevo") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <div class="tu-pestanas mt-6" role="group">
      <button
        v-if="conBienestar"
        type="button"
        :aria-pressed="pestana === 'bienestar'"
        @click="pestana = 'bienestar'"
      >
        {{ $t("conexiones.bienestar") }}
      </button>
      <button
        type="button"
        :aria-pressed="pestana === 'llaves'"
        @click="pestana = 'llaves'"
      >
        {{ $t("conexiones.llaves") }}
      </button>
      <button
        type="button"
        :aria-pressed="pestana === 'webhooks'"
        @click="pestana = 'webhooks'"
      >
        {{ $t("conexiones.webhooks") }}
      </button>
      <button
        type="button"
        :aria-pressed="pestana === 'medicion'"
        data-prueba="pestana-medicion"
        @click="pestana = 'medicion'"
      >
        {{ $t("medicion.negocio.pestana") }}
      </button>
    </div>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
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

        <button
          class="tu-btn tu-btn-primario mt-4 text-sm"
          :disabled="guardando === i.proveedor"
          @click="guardar(i.proveedor)"
        >
          {{
            guardando === i.proveedor
              ? $t("integraciones.guardando")
              : $t("integraciones.guardar")
          }}
        </button>
        <!-- Reservas desde la app de la plataforma (ADR 0149). -->
        <ReservasBienestar v-if="i.activa" :proveedor="i.proveedor" />
      </div>
    </div>

    <!-- Llaves de API -->
    <div v-if="!cargando && pestana === 'llaves'" class="mt-5 space-y-5">
      <div class="tu-card p-5 min-w-0">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("conexiones.llave.ayuda") }}
        </p>
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
              class="tu-btn tu-btn-fantasma text-sm"
              @click="copiar(secretoLlave)"
            >
              {{ $t("conexiones.copiar") }}
            </button>
          </div>
        </div>

        <EstadoVacio
          v-if="llaves.length === 0"
          class="mt-4 py-6"
          icono="integraciones"
          compacto
          :titulo="$t('conexiones.llave.vacio')"
        />
        <ul v-else class="mt-3">
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
              class="tu-enlace shrink-0"
              style="color: var(--error)"
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
            class="tu-btn tu-btn-fantasma"
            :disabled="creandoLlave"
            @click="editorLlave.cerrar"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            type="submit"
            form="integracion-llave"
            class="tu-btn tu-btn-primario text-sm"
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
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("conexiones.webhook.ayuda") }}
        </p>
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
              class="tu-btn tu-btn-fantasma text-sm"
              @click="copiar(secretoWebhook)"
            >
              {{ $t("conexiones.copiar") }}
            </button>
          </div>
        </div>

        <EstadoVacio
          v-if="webhooks.length === 0"
          class="mt-4 py-6"
          icono="integraciones"
          compacto
          :titulo="$t('conexiones.webhook.vacio')"
        />
        <ul v-else class="mt-3">
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
                class="tu-enlace"
                style="color: var(--error)"
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
              <p
                v-if="entregas.length === 0"
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
            class="tu-btn tu-btn-fantasma"
            :disabled="creandoWebhook"
            @click="editorWebhook.cerrar"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            type="submit"
            form="integracion-webhook"
            class="tu-btn tu-btn-primario text-sm"
            :disabled="creandoWebhook || nuevoWebhook.url.trim() === ''"
          >
            {{ $t("conexiones.webhook.crear") }}
          </button>
        </template>
      </ModalDialogo>
    </div>

    <!-- GA4 y Meta del negocio (ADR 0143) -->
    <MedicionNegocio v-if="!cargando && pestana === 'medicion'" class="mt-5" />
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
