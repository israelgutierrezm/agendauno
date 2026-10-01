<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Integraciones del estudio: plataformas de bienestar (Wellhub, TotalPass), llaves
 * de API de solo lectura para otros sistemas y webhooks que avisan de eventos.
 * Los secretos se muestran una sola vez, al crearlos.
 */
const { t } = useI18n();

interface Integracion {
  proveedor: string;
  activa: boolean;
  llaves_configuradas: string[];
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
type Pestana = "bienestar" | "llaves" | "webhooks";

const LLAVES = ["api_key", "base_url"];

const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const pestana = ref<Pestana>("bienestar");
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

async function guardar(proveedor: string): Promise<void> {
  guardando.value = proveedor;
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
const creandoLlave = ref(false);
const secretoLlave = ref<string | null>(null);

async function crearLlave(): Promise<void> {
  creandoLlave.value = true;
  try {
    const { data } = await api.post<{ data: LlaveApi & { secreto: string } }>(
      `${base.value}/llaves-api`,
      nuevaLlave.value,
    );
    secretoLlave.value = data.data.secreto;
    nuevaLlave.value = { nombre: "", scopes: [] };
    await cargarLlaves();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    creandoLlave.value = false;
  }
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
const creandoWebhook = ref(false);
const secretoWebhook = ref<string | null>(null);
const entregasDe = ref<string | null>(null);
const entregas = ref<Entrega[]>([]);

async function cargarWebhooks(): Promise<void> {
  const { data } = await api.get<{
    data: Webhook[];
    eventos_disponibles: string[];
  }>(`${base.value}/webhooks-salientes`);
  webhooks.value = data.data;
  eventosDisponibles.value = data.eventos_disponibles;
}

async function crearWebhook(): Promise<void> {
  creandoWebhook.value = true;
  try {
    const { data } = await api.post<{ data: Webhook & { secreto: string } }>(
      `${base.value}/webhooks-salientes`,
      {
        url: nuevoWebhook.value.url,
        eventos:
          nuevoWebhook.value.eventos.length > 0
            ? nuevoWebhook.value.eventos
            : null,
      },
    );
    secretoWebhook.value = data.data.secreto;
    nuevoWebhook.value = { url: "", eventos: [] };
    await cargarWebhooks();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    creandoWebhook.value = false;
  }
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
    const { data } = await api.get<{ data: Entrega[] }>(
      `${base.value}/webhooks-salientes/${w.id}/entregas`,
    );
    entregas.value = data.data;
    entregasDe.value = w.id;
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [{ data }] = await Promise.all([
      api.get<{ data: Integracion[] }>(`${base.value}/integraciones`),
      cargarLlaves(),
      cargarWebhooks(),
    ]);
    integraciones.value = data.data;
    for (const i of data.data) {
      edicion[i.proveedor] = {
        activa: i.activa,
        llaves: { api_key: "", base_url: "" },
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
  <section class="mx-auto max-w-5xl px-4 py-10">
    <EncabezadoSeccion :titulo="$t('conexiones.titulo')" />

    <div class="tu-pestanas mt-6" role="group">
      <button
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
    </div>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <!-- Plataformas de bienestar -->
    <div v-if="!cargando && pestana === 'bienestar'" class="mt-5 space-y-4">
      <div v-for="i in integraciones" :key="i.proveedor" class="tu-card p-5">
        <div class="flex items-center justify-between gap-3">
          <h2 class="font-semibold">
            {{ $t(`integraciones.proveedores.${i.proveedor}`) }}
          </h2>
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="edicion[i.proveedor].activa" type="checkbox" />
            {{ $t("integraciones.activa") }}
          </label>
        </div>

        <div class="mt-3 grid sm:grid-cols-2 gap-3">
          <div v-for="llave in LLAVES" :key="llave">
            <label class="tu-label" :for="`${i.proveedor}-${llave}`">
              {{
                llave === "api_key"
                  ? $t("integraciones.apiKey")
                  : $t("integraciones.baseUrl")
              }}
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
              :type="llave === 'api_key' ? 'password' : 'text'"
              autocomplete="off"
              :placeholder="
                configurada(i.proveedor, llave)
                  ? '••••••'
                  : $t('integraciones.nuevaLlave')
              "
            />
          </div>
        </div>

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
      </div>
    </div>

    <!-- Llaves de API -->
    <div
      v-if="!cargando && pestana === 'llaves'"
      class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]"
    >
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

      <form class="tu-card p-5 h-max space-y-3" @submit.prevent="crearLlave">
        <h2 class="font-semibold">{{ $t("conexiones.llave.nueva") }}</h2>
        <div>
          <label class="tu-label" for="ll-nombre">{{
            $t("conexiones.llave.nombre")
          }}</label>
          <input
            id="ll-nombre"
            v-model="nuevaLlave.nombre"
            class="tu-input"
            :placeholder="$t('conexiones.llave.nombrePh')"
            required
          />
        </div>
        <fieldset>
          <legend class="tu-label">
            {{ $t("conexiones.llave.permisos") }}
          </legend>
          <label
            v-for="s in scopesDisponibles"
            :key="s"
            class="flex items-center gap-2 py-0.5 text-sm"
          >
            <input v-model="nuevaLlave.scopes" type="checkbox" :value="s" />
            {{ $t(`conexiones.llave.scopes.${s}`) }}
          </label>
        </fieldset>
        <button
          type="submit"
          class="tu-btn tu-btn-primario w-full text-sm"
          :disabled="
            creandoLlave ||
            nuevaLlave.nombre.trim() === '' ||
            nuevaLlave.scopes.length === 0
          "
        >
          {{ $t("conexiones.llave.crear") }}
        </button>
      </form>
    </div>

    <!-- Webhooks -->
    <div
      v-if="!cargando && pestana === 'webhooks'"
      class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]"
    >
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
            </div>
          </li>
        </ul>
      </div>

      <form class="tu-card p-5 h-max space-y-3" @submit.prevent="crearWebhook">
        <h2 class="font-semibold">{{ $t("conexiones.webhook.nuevo") }}</h2>
        <div>
          <label class="tu-label" for="wh-url">{{
            $t("conexiones.webhook.url")
          }}</label>
          <input
            id="wh-url"
            v-model="nuevoWebhook.url"
            class="tu-input"
            type="url"
            :placeholder="$t('conexiones.webhook.urlPh')"
            required
          />
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
            class="flex items-center gap-2 py-0.5 text-sm"
          >
            <input v-model="nuevoWebhook.eventos" type="checkbox" :value="ev" />
            {{ $t(`conexiones.webhook.tipos.${ev}`) }}
          </label>
        </fieldset>
        <button
          type="submit"
          class="tu-btn tu-btn-primario w-full text-sm"
          :disabled="creandoWebhook || nuevoWebhook.url.trim() === ''"
        >
          {{ $t("conexiones.webhook.crear") }}
        </button>
      </form>
    </div>
  </section>
</template>

<style scoped>
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
