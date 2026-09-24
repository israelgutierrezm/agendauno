<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import TablaDatos from "@/components/TablaDatos.vue";
import TarifasPlataforma from "@/components/TarifasPlataforma.vue";

interface Estudio {
  slug: string;
  nombre: string;
  estado: string;
  estado_facturacion: string;
  modalidad: "clases" | "citas";
  modo_cobro: string;
  cuota_fija_minor: number;
  trial_termina_en: string | null;
  moneda: string;
  publicado: boolean;
  pais: string | null;
  ciudad: string | null;
  creado_en: string | null;
}

const ESTADOS_FACT = [
  "trial",
  "active",
  "past_due",
  "grace_period",
  "suspended",
  "cancelled",
];

const { t } = useI18n();
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

const estudios = ref<Estudio[]>([]);
const facturapiConfigurada = ref(false);
const llaveInput = ref("");
const guardando = ref(false);
const mensaje = ref<string | null>(null);

// Documentos legales (aviso de privacidad y términos) mostrados en el registro.
const legales = ref({ aviso_privacidad: "", terminos: "" });
const guardandoLegales = ref(false);
const mensajeLegales = ref<string | null>(null);

// Pasarelas de la plataforma (para cobrar la renta del SaaS).
interface Pasarela {
  proveedor: string;
  activa: boolean;
  modo: string;
  llaves_configuradas: string[];
}
const LLAVES_PASARELA: Record<string, string[]> = {
  stripe: ["secret_key", "webhook_secret"],
  mercadopago: ["access_token", "webhook_secret"],
  openpay: ["merchant_id", "private_key", "webhook_user", "webhook_password"],
};
const pasarelas = ref<Pasarela[]>([]);
// Borrador por proveedor: activa, modo y llaves nuevas (write-only).
const pasarelaDraft = ref<
  Record<
    string,
    { activa: boolean; modo: string; llaves: Record<string, string> }
  >
>({});
const guardandoPasarela = ref<string | null>(null);

const columnas = computed(() => [
  { clave: "slug", etiqueta: t("plataforma.estudios.colSlug") },
  { clave: "nombre", etiqueta: t("plataforma.estudios.colNombre") },
  {
    clave: "estado_facturacion",
    etiqueta: t("plataforma.estudios.colFacturacion"),
  },
  { clave: "cobro", etiqueta: t("plataforma.estudios.colCobro") },
  { clave: "acciones", etiqueta: "" },
]);

// Edición de facturación por tenant (cuota en unidades de la moneda). El cobro por
// uso sale de las tarifas versionadas de su modalidad.
const editando = ref<Estudio | null>(null);
const edit = ref({
  modo_cobro: "activos",
  cuota: "0",
  estado_facturacion: "trial",
});
const guardandoEstudio = ref(false);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
function cobroLegible(e: Estudio): string {
  return e.modo_cobro === "fijo"
    ? `${dinero(e.cuota_fija_minor, e.moneda)} / mes`
    : e.modalidad === "citas"
      ? t("cobro.modo.citas")
      : t("cobro.modo.clases");
}

function abrirEdicion(e: Estudio): void {
  editando.value = e;
  edit.value = {
    modo_cobro: e.modo_cobro,
    cuota: String(e.cuota_fija_minor / 100),
    estado_facturacion: e.estado_facturacion,
  };
}

async function guardarEstudio(): Promise<void> {
  if (editando.value === null) {
    return;
  }
  guardandoEstudio.value = true;
  error.value = null;
  try {
    await cliente.put(
      `/api/v1/plataforma/estudios/${editando.value.slug}`,
      {
        modo_cobro: edit.value.modo_cobro,
        cuota_fija_minor: Math.round(Number(edit.value.cuota) * 100),
        estado_facturacion: edit.value.estado_facturacion,
      },
      encabezados(),
    );
    editando.value = null;
    await cargar();
  } catch {
    error.value = t("plataforma.tokenInvalido");
  } finally {
    guardandoEstudio.value = false;
  }
}

function encabezados(): { headers: Record<string, string> } {
  return { headers: { Authorization: `Bearer ${token.value}` } };
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [est, cfg, pas, leg] = await Promise.all([
      cliente.get<{ data: Estudio[] }>(
        "/api/v1/plataforma/estudios",
        encabezados(),
      ),
      cliente.get<{ data: { facturapi_configurada: boolean } }>(
        "/api/v1/plataforma/configuracion",
        encabezados(),
      ),
      cliente.get<{ data: Pasarela[] }>(
        "/api/v1/plataforma/pasarelas",
        encabezados(),
      ),
      cliente.get<{
        data: { aviso_privacidad: string | null; terminos: string | null };
      }>("/api/v1/plataforma/legales", encabezados()),
    ]);
    estudios.value = est.data.data;
    facturapiConfigurada.value = cfg.data.data.facturapi_configurada;
    pasarelas.value = pas.data.data;
    legales.value = {
      aviso_privacidad: leg.data.data.aviso_privacidad ?? "",
      terminos: leg.data.data.terminos ?? "",
    };
    for (const p of pasarelas.value) {
      pasarelaDraft.value[p.proveedor] = {
        activa: p.activa,
        modo: p.modo,
        llaves: {},
      };
    }
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

async function guardarLlave(): Promise<void> {
  guardando.value = true;
  mensaje.value = null;
  error.value = null;
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
    mensaje.value = "ok";
  } catch {
    error.value = t("plataforma.tokenInvalido");
  } finally {
    guardando.value = false;
  }
}

async function guardarLegales(): Promise<void> {
  guardandoLegales.value = true;
  mensajeLegales.value = null;
  error.value = null;
  try {
    const { data } = await cliente.put<{
      data: { aviso_privacidad: string | null; terminos: string | null };
    }>(
      "/api/v1/plataforma/legales",
      {
        aviso_privacidad: legales.value.aviso_privacidad || null,
        terminos: legales.value.terminos || null,
      },
      encabezados(),
    );
    legales.value = {
      aviso_privacidad: data.data.aviso_privacidad ?? "",
      terminos: data.data.terminos ?? "",
    };
    mensajeLegales.value = "ok";
  } catch {
    error.value = t("plataforma.tokenInvalido");
  } finally {
    guardandoLegales.value = false;
  }
}

function llavesDe(proveedor: string): string[] {
  return LLAVES_PASARELA[proveedor] ?? [];
}

async function guardarPasarela(proveedor: string): Promise<void> {
  const draft = pasarelaDraft.value[proveedor];
  if (draft === undefined) {
    return;
  }
  guardandoPasarela.value = proveedor;
  error.value = null;
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
    draft.llaves = {}; // limpia las llaves escritas (write-only)
    mensaje.value = "pasarela";
  } catch {
    error.value = t("plataforma.tokenInvalido");
  } finally {
    guardandoPasarela.value = null;
  }
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
      <div class="flex items-start justify-between gap-4">
        <div>
          <h1 class="font-light text-2xl">{{ $t("plataforma.titulo") }}</h1>
          <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("plataforma.subtitulo") }}
          </p>
        </div>
        <button class="tu-btn tu-btn-fantasma" type="button" @click="salir">
          {{ $t("plataforma.salir") }}
        </button>
      </div>

      <!-- Cuenta FacturAPI -->
      <div class="mt-6 tu-card p-6">
        <div class="flex items-center justify-between gap-3">
          <div>
            <h2 class="font-light">{{ $t("plataforma.facturapi.titulo") }}</h2>
            <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("plataforma.facturapi.subtitulo") }}
            </p>
          </div>
          <span
            class="tu-badge"
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
              placeholder="sk_live_…"
            />
          </div>
          <button
            class="tu-btn tu-btn-primario"
            type="submit"
            :disabled="guardando"
          >
            {{
              guardando
                ? $t("plataforma.facturapi.guardando")
                : $t("plataforma.facturapi.guardar")
            }}
          </button>
        </form>
        <p class="mt-2 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("plataforma.facturapi.ayuda") }}
        </p>
        <p
          v-if="mensaje === 'ok'"
          class="mt-2 text-sm"
          :style="{ color: 'var(--exito)' }"
        >
          {{ $t("plataforma.facturapi.guardado") }}
        </p>
      </div>

      <!-- Documentos legales (aviso de privacidad y términos) -->
      <div class="mt-6 tu-card p-6">
        <h2 class="font-light">{{ $t("plataforma.legales.titulo") }}</h2>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("plataforma.legales.subtitulo") }}
        </p>
        <form class="mt-4 grid gap-4" @submit.prevent="guardarLegales">
          <div>
            <label class="tu-label" for="lg-aviso">{{
              $t("plataforma.legales.aviso")
            }}</label>
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
          <div class="flex items-center gap-3">
            <button
              class="tu-btn tu-btn-primario"
              type="submit"
              :disabled="guardandoLegales"
            >
              {{
                guardandoLegales
                  ? $t("plataforma.legales.guardando")
                  : $t("plataforma.legales.guardar")
              }}
            </button>
            <p
              v-if="mensajeLegales === 'ok'"
              class="text-sm"
              :style="{ color: 'var(--exito)' }"
            >
              {{ $t("plataforma.legales.guardado") }}
            </p>
          </div>
        </form>
      </div>

      <!-- Pasarelas de cobro de la plataforma -->
      <div class="mt-6">
        <h2 class="font-light text-lg">
          {{ $t("plataforma.pasarelas.titulo") }}
        </h2>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("plataforma.pasarelas.subtitulo") }}
        </p>
        <div class="mt-3 grid gap-3 sm:grid-cols-3">
          <div v-for="p in pasarelas" :key="p.proveedor" class="tu-card p-4">
            <div class="flex items-center justify-between gap-2">
              <span class="font-light">{{
                $t(`pasarelas.proveedores.${p.proveedor}`)
              }}</span>
              <label class="flex items-center gap-1.5 text-xs">
                <input
                  v-model="pasarelaDraft[p.proveedor].activa"
                  type="checkbox"
                />
                {{ $t("plataforma.pasarelas.activa") }}
              </label>
            </div>
            <div class="mt-2">
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
              v-for="llave in llavesDe(p.proveedor)"
              :key="llave"
              class="mt-2"
            >
              <label class="tu-label" :for="`${p.proveedor}-${llave}`">{{
                llave
              }}</label>
              <input
                :id="`${p.proveedor}-${llave}`"
                v-model="pasarelaDraft[p.proveedor].llaves[llave]"
                class="tu-input"
                type="password"
                :placeholder="
                  p.llaves_configuradas.includes(llave)
                    ? $t('plataforma.pasarelas.configurada')
                    : ''
                "
              />
            </div>
            <button
              class="tu-btn tu-btn-primario w-full mt-3"
              type="button"
              :disabled="guardandoPasarela === p.proveedor"
              @click="guardarPasarela(p.proveedor)"
            >
              {{
                guardandoPasarela === p.proveedor
                  ? $t("plataforma.pasarelas.guardando")
                  : $t("plataforma.pasarelas.guardar")
              }}
            </button>
          </div>
        </div>
        <p
          v-if="mensaje === 'pasarela'"
          class="mt-2 text-sm"
          :style="{ color: 'var(--exito)' }"
        >
          {{ $t("plataforma.pasarelas.guardado") }}
        </p>
      </div>

      <!-- Tarifas del SaaS por modalidad (versionadas) -->
      <TarifasPlataforma class="mt-8" :api-url="apiUrl" :token="token" />

      <!-- Estudios -->
      <h2 class="mt-8 font-light text-lg">
        {{ $t("plataforma.estudios.titulo") }} · {{ estudios.length }}
      </h2>
      <p
        v-if="cargando"
        class="mt-3 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>
      <TablaDatos
        v-else
        class="mt-3"
        :columnas="columnas"
        :filas="estudios"
        :buscar-en="['slug', 'nombre', 'ciudad']"
        :vacio="$t('plataforma.estudios.vacio')"
        clave-vista="plataforma-estudios"
      >
        <template #col-slug="{ valor }">
          <span class="font-mono text-sm">{{ valor }}</span>
        </template>
        <template #col-estado_facturacion="{ valor }">
          <span class="text-sm">{{ $t(`panel.estados.${valor}`) }}</span>
        </template>
        <template #col-cobro="{ fila }">
          <span class="text-sm">
            <span class="tu-badge">{{
              $t(`cobro.modalidad.${(fila as Estudio).modalidad}`)
            }}</span>
            {{ cobroLegible(fila as Estudio) }}
          </span>
        </template>
        <template #col-acciones="{ fila }">
          <button
            class="tu-enlace"
            type="button"
            @click="abrirEdicion(fila as Estudio)"
          >
            {{ $t("plataforma.estudios.editar") }}
          </button>
        </template>
      </TablaDatos>

      <!-- Editor de facturación de un tenant -->
      <div v-if="editando" class="mt-4 tu-card p-5">
        <div class="flex items-center justify-between gap-3">
          <h3 class="font-light">
            {{
              $t("plataforma.estudios.editarTitulo", {
                estudio: editando.nombre,
              })
            }}
          </h3>
          <button
            class="tu-icono-btn"
            :aria-label="$t('comun.cerrar')"
            @click="editando = null"
          >
            ✕
          </button>
        </div>
        <form
          class="mt-3 grid gap-3 sm:grid-cols-2"
          @submit.prevent="guardarEstudio"
        >
          <div>
            <label class="tu-label" for="mc">{{
              $t("plataforma.estudios.modoCobro")
            }}</label>
            <select id="mc" v-model="edit.modo_cobro" class="tu-input">
              <option value="activos">
                {{ $t("plataforma.estudios.modo.activos") }}
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
            <select id="ef" v-model="edit.estado_facturacion" class="tu-input">
              <option v-for="s in ESTADOS_FACT" :key="s" :value="s">
                {{ $t(`panel.estados.${s}`) }}
              </option>
            </select>
          </div>
          <p
            v-if="edit.modo_cobro === 'activos'"
            class="text-sm self-end"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              editando.modalidad === "citas"
                ? $t("cobro.modo.ayudaCitas")
                : $t("cobro.modo.ayudaClases")
            }}
          </p>
          <div v-else>
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
          <div class="sm:col-span-2 flex gap-2">
            <button
              class="tu-btn tu-btn-primario"
              type="submit"
              :disabled="guardandoEstudio"
            >
              {{ $t("plataforma.estudios.guardar") }}
            </button>
            <button
              class="tu-btn tu-btn-fantasma"
              type="button"
              @click="editando = null"
            >
              {{ $t("comun.cancelar") }}
            </button>
          </div>
        </form>
      </div>
    </template>
  </section>
</template>
