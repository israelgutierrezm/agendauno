<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";

import { useI18n } from "vue-i18n";

import AvisoRegion from "@/components/AvisoRegion.vue";
import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import InterruptorSiNo from "@/components/InterruptorSiNo.vue";
import LogoPlataforma from "@/components/LogoPlataforma.vue";
import { api, camposConError, mensajeDeError } from "@/lib/api";
import { useCambiosPendientes } from "@/lib/cambiosPendientes";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Pasarela {
  proveedor: string;
  activa: boolean;
  modo: string;
  // M07: si puede confirmar los pagos y si cobra de verdad.
  confirma_pagos?: boolean | null;
  en_prueba?: boolean | null;
  llaves_configuradas: string[];
  // Sin estas no se puede activar.
  llaves_requeridas?: string[];
  // ¿Existe de verdad? y ¿ya cobra?
  disponible?: boolean;
  lista?: boolean;
  // A dónde manda sus avisos la pasarela (se registra en su tablero).
  webhook_url?: string | null;
  // OpenPay: el código que manda al registrar el webhook.
  codigo_verificacion?: string | null;
  // Lo que de verdad pasó con la conexión (solo las de cobro en línea).
  estado_conexion?:
    | "sin_configurar"
    | "credenciales"
    | "activa_sin_comprobar"
    | "comprobada"
    | null;
  comprobada_en?: string | null;
  // Último pago aprobado con llaves o modo anteriores: historial, no prueba (R13).
  ultimo_exito_anterior_en?: string | null;
}

// Llaves que pide cada proveedor (para el formulario).
const LLAVES: Record<string, string[]> = {
  stripe: ["secret_key", "publishable_key", "webhook_secret"],
  openpay: [
    "merchant_id",
    "private_key",
    "public_key",
    "webhook_user",
    "webhook_password",
  ],
  mercadopago: ["access_token", "public_key", "webhook_secret"],
  ventanilla: [],
};

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const pasarelas = ref<Pasarela[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const guardando = ref<string | null>(null);
const guardado = ref<string | null>(null);
const copiada = ref<string | null>(null);

async function copiar(p: Pasarela): Promise<void> {
  if (!p.webhook_url) {
    return;
  }
  try {
    await navigator.clipboard.writeText(p.webhook_url);
    copiada.value = p.proveedor;
  } catch {
    copiada.value = null;
  }
}

// Modelo editable por proveedor: activa, modo y llaves nuevas (write-only).
const edicion = reactive<
  Record<
    string,
    { activa: boolean; modo: string; llaves: Record<string, string> }
  >
>({});

// Cambios sin guardar: alguna llave escrita, o activa/modo distintos de lo guardado.
useCambiosPendientes(() =>
  pasarelas.value.some((p) => {
    const ed = edicion[p.proveedor];
    return (
      ed !== undefined &&
      (ed.activa !== p.activa ||
        ed.modo !== p.modo ||
        Object.values(ed.llaves).some((v) => v.trim() !== ""))
    );
  }),
);

// Las llaves que la API rechazó, por pasarela.
const llavesConError = reactive<Record<string, string[]>>({});

/**
 * Las llaves sin las que no cobra y que no están ni guardadas ni escritas: mientras
 * falte alguna, el interruptor no se enciende.
 */
function faltanLlaves(p: Pasarela): string[] {
  const escritas = edicion[p.proveedor]?.llaves ?? {};
  return (p.llaves_requeridas ?? []).filter(
    (llave) =>
      !p.llaves_configuradas.includes(llave) &&
      (escritas[llave] ?? "").trim() === "",
  );
}

function nombresDeLlaves(llaves: string[]): string {
  return llaves
    .map((llave) => t(`adminUsabilidad.credenciales.${llave}`))
    .join(", ");
}

/** Su estado en una pastilla: cobrando, en pruebas, sin llaves o apagada. */
function estado(p: Pasarela): { tono: string; texto: string } {
  if (!p.activa) {
    return { tono: "neutral", texto: t("operacion.pasarelas.estado.apagada") };
  }
  if (p.lista === false) {
    return { tono: "aviso", texto: t("operacion.pasarelas.estado.faltan") };
  }
  return p.en_prueba
    ? { tono: "info", texto: t("operacion.pasarelas.estado.enPrueba") }
    : { tono: "exito", texto: t("operacion.pasarelas.estado.cobrando") };
}

// Con otra moneda que no sea el peso mexicano, las de cobro en línea no aplican; la
// ventanilla (depósito con comprobante) sí.
const enLineaDisponible = ref(true);
function configurable(p: Pasarela): boolean {
  return enLineaDisponible.value || p.proveedor === "ventanilla";
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: Pasarela[];
      meta?: { en_linea_disponible: boolean };
    }>(`${base.value}/pasarelas`);
    pasarelas.value = data.data;
    enLineaDisponible.value = data.meta?.en_linea_disponible !== false;
    for (const p of data.data) {
      edicion[p.proveedor] = {
        activa: p.activa,
        modo: p.modo,
        llaves: Object.fromEntries(
          (LLAVES[p.proveedor] ?? []).map((k) => [k, ""]),
        ),
      };
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function guardar(proveedor: string): Promise<void> {
  guardando.value = proveedor;
  guardado.value = null;
  error.value = null;
  llavesConError[proveedor] = [];
  try {
    const ed = edicion[proveedor];
    // Solo envia llaves con valor (merge en el backend).
    const credenciales: Record<string, string> = {};
    for (const [k, v] of Object.entries(ed.llaves)) {
      if (v.trim() !== "") {
        credenciales[k] = v;
      }
    }
    const { data } = await api.put<{ data: Pasarela }>(
      `${base.value}/pasarelas/${proveedor}`,
      {
        activa: ed.activa,
        modo: ed.modo,
        credenciales,
      },
    );
    // Refleja lo configurado y limpia los inputs (write-only).
    const idx = pasarelas.value.findIndex((p) => p.proveedor === proveedor);
    if (idx !== -1) {
      pasarelas.value[idx] = data.data;
    }
    for (const k of Object.keys(ed.llaves)) {
      ed.llaves[k] = "";
    }
    guardado.value = proveedor;
  } catch (e) {
    llavesConError[proveedor] = camposConError(e)
      .filter((campo) => campo.startsWith("credenciales."))
      .map((campo) => campo.slice("credenciales.".length));
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = null;
  }
}

function estaConfigurada(proveedor: string, llave: string): boolean {
  return (
    pasarelas.value
      .find((p) => p.proveedor === proveedor)
      ?.llaves_configuradas.includes(llave) ?? false
  );
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('pasarelas.titulo')"
      :subtitulo="$t('pasarelas.subtitulo')"
    />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
    </AvisoTono>

    <div v-if="!cargando" class="mt-6 space-y-4">
      <!-- Fuera de pesos mexicanos no se cobra en línea (ADR 0099). -->
      <AvisoRegion v-if="!enLineaDisponible" tipo="pasarelas" />
      <AvisoTono
        v-else
        tono="info"
        :titulo="$t('operacion.pasarelas.alcanceTitulo')"
        data-prueba="alcance-pasarelas"
      >
        {{ $t("operacion.pasarelas.alcance") }}
      </AvisoTono>
      <div
        v-for="p in pasarelas.filter(
          (x) => x.disponible === false || !configurable(x),
        )"
        :key="p.proveedor"
        class="tu-card p-6"
      >
        <div class="flex items-center gap-3">
          <LogoPlataforma :proveedor="p.proveedor" />
          <h2 class="min-w-0 flex-1 text-base font-semibold">
            {{ $t(`pasarelas.proveedores.${p.proveedor}`) }}
          </h2>
          <span
            v-if="p.disponible === false"
            class="tu-pastilla tu-tono-neutral"
            >{{ $t("pasarelasEstado.proximamente") }}</span
          >
        </div>
        <p class="mt-2 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{
            p.disponible === false
              ? $t("pasarelasEstado.noDisponible")
              : $t("region.avisos.soloPesos")
          }}
        </p>
      </div>
      <div
        v-for="p in pasarelas.filter(
          (x) => x.disponible !== false && configurable(x),
        )"
        :key="p.proveedor"
        class="tu-card p-6"
      >
        <div class="flex flex-wrap items-start gap-3">
          <LogoPlataforma :proveedor="p.proveedor" />
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-base font-semibold">
                {{ $t(`pasarelas.proveedores.${p.proveedor}`) }}
              </h2>
              <span
                class="tu-pastilla"
                :class="`tu-tono-${estado(p).tono}`"
                :data-prueba="`estado-${p.proveedor}`"
                >{{ estado(p).texto }}</span
              >
            </div>
            <p class="mt-0.5 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`operacion.pasarelas.descripcion.${p.proveedor}`) }}
            </p>
            <p
              v-if="p.estado_conexion"
              class="mt-1 text-sm tu-estado"
              :style="{
                '--tono':
                  p.estado_conexion === 'comprobada'
                    ? 'var(--exito)'
                    : p.estado_conexion === 'activa_sin_comprobar'
                      ? 'var(--aviso)'
                      : 'var(--texto-suave)',
              }"
              :data-prueba="`conexion-${p.proveedor}`"
            >
              {{
                $t(`pasarelasEstado.conexion.${p.estado_conexion}`, {
                  fecha: p.comprobada_en
                    ? new Date(p.comprobada_en).toLocaleDateString("es-MX", {
                        day: "numeric",
                        month: "short",
                        year: "numeric",
                      })
                    : "",
                })
              }}
            </p>
            <p
              v-if="
                p.estado_conexion === 'activa_sin_comprobar' &&
                p.ultimo_exito_anterior_en
              "
              class="tu-hint"
              :data-prueba="`conexion-anterior-${p.proveedor}`"
            >
              {{
                $t("pasarelasEstado.anterior", {
                  fecha: new Date(
                    p.ultimo_exito_anterior_en,
                  ).toLocaleDateString("es-MX", {
                    day: "numeric",
                    month: "short",
                    year: "numeric",
                  }),
                })
              }}
            </p>
          </div>
          <InterruptorSiNo
            v-model="edicion[p.proveedor].activa"
            class="shrink-0"
            :etiqueta="$t('pasarelas.activa')"
            :deshabilitado="
              !edicion[p.proveedor].activa && faltanLlaves(p).length > 0
            "
            :prueba="`activa-${p.proveedor}`"
          />
        </div>
        <!-- Qué falta para poder activarla -->
        <p
          v-if="!edicion[p.proveedor].activa && faltanLlaves(p).length > 0"
          class="tu-hint mt-3"
          :data-prueba="`faltan-llaves-${p.proveedor}`"
        >
          {{
            $t("operacion.pasarelas.faltanLlaves", {
              llaves: nombresDeLlaves(faltanLlaves(p)),
            })
          }}
        </p>
        <AvisoTono
          v-if="p.activa && p.lista === false"
          tono="aviso"
          class="mt-3"
          role="status"
        >
          {{ $t("pasarelasEstado.faltaLlave") }}
        </AvisoTono>
        <!-- M07: sin confirmación de pagos (secreto del webhook) y modo de prueba. -->
        <AvisoTono
          v-if="p.activa && p.confirma_pagos === false"
          tono="aviso"
          class="mt-3"
          role="status"
          data-prueba="sin-confirmacion"
        >
          {{ $t("pasarelasEstado.sinConfirmacion") }}
        </AvisoTono>
        <AvisoTono
          v-if="p.activa && p.en_prueba"
          tono="info"
          class="mt-3"
          role="status"
          data-prueba="en-prueba"
        >
          {{ $t("pasarelasEstado.enPrueba") }}
        </AvisoTono>

        <details class="pg-configurar" :open="p.activa">
          <summary>{{ $t("adminUsabilidad.configurarConexion") }}</summary>
          <div class="mt-3 grid sm:grid-cols-2 gap-3">
            <div>
              <label class="tu-label" :for="`modo-${p.proveedor}`">{{
                $t("pasarelas.modo")
              }}</label>
              <select
                :id="`modo-${p.proveedor}`"
                v-model="edicion[p.proveedor].modo"
                class="tu-input"
              >
                <option value="test">{{ $t("pasarelas.test") }}</option>
                <option value="live">{{ $t("pasarelas.live") }}</option>
              </select>
            </div>
          </div>

          <div v-if="p.webhook_url" class="mt-3 text-sm">
            <p class="tu-label">{{ $t("pasarelasEstado.webhook") }}</p>
            <div class="flex flex-wrap items-center gap-2">
              <code
                class="rounded px-2 py-1 text-xs break-all"
                :style="{ background: 'var(--superficie-2)' }"
                >{{ p.webhook_url }}</code
              >
              <button
                type="button"
                class="tu-btn tu-btn-secundario tu-btn-chico"
                @click="copiar(p)"
              >
                {{
                  copiada === p.proveedor
                    ? $t("pasarelasEstado.copiada")
                    : $t("pasarelasEstado.copiar")
                }}
              </button>
            </div>
            <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t(`pasarelasEstado.ayudaWebhook.${p.proveedor}`) }}
            </p>
            <AvisoTono
              v-if="p.codigo_verificacion"
              tono="aviso"
              class="mt-2"
              role="status"
            >
              {{
                $t("pasarelasEstado.codigoVerificacion", {
                  codigo: p.codigo_verificacion,
                })
              }}
            </AvisoTono>
          </div>

          <div
            v-if="LLAVES[p.proveedor].length > 0"
            class="mt-3 grid sm:grid-cols-2 gap-3"
          >
            <div v-for="llave in LLAVES[p.proveedor]" :key="llave">
              <label class="tu-label" :for="`${p.proveedor}-${llave}`">
                {{ $t(`adminUsabilidad.credenciales.${llave}`)
                }}<span
                  v-if="(p.llaves_requeridas ?? []).includes(llave)"
                  class="tu-obligatorio"
                  :title="$t('operacion.integraciones.obligatoria')"
                  >*</span
                >
                <code
                  class="block text-xs font-normal"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ llave }}</code
                >
                <span
                  v-if="estaConfigurada(p.proveedor, llave)"
                  class="tu-badge tu-badge-exito ml-1"
                  >{{ $t("pasarelas.configurada") }}</span
                >
              </label>
              <input
                :id="`${p.proveedor}-${llave}`"
                v-model="edicion[p.proveedor].llaves[llave]"
                class="tu-input"
                type="password"
                autocomplete="off"
                :aria-invalid="
                  (llavesConError[p.proveedor] ?? []).includes(llave) ||
                  undefined
                "
                :placeholder="
                  estaConfigurada(p.proveedor, llave)
                    ? '••••••'
                    : $t('pasarelas.nuevaLlave')
                "
              />
            </div>
          </div>
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("pasarelas.sinLlaves") }}
          </p>

          <div class="mt-4 flex items-center gap-3">
            <button
              class="tu-btn tu-btn-primario"
              :disabled="guardando === p.proveedor"
              @click="guardar(p.proveedor)"
            >
              {{
                guardando === p.proveedor
                  ? $t("pasarelas.guardando")
                  : $t("pasarelas.guardar")
              }}
            </button>
            <span
              v-if="guardado === p.proveedor"
              class="text-sm"
              :style="{ color: 'var(--exito)' }"
            >
              {{ $t("pasarelas.guardado") }}
            </span>
          </div>
        </details>
      </div>
    </div>
  </section>
</template>

<style scoped>
.pg-configurar {
  margin-top: 1rem;
  border-top: 1px solid var(--borde);
  padding-top: 0.75rem;
}
.pg-configurar summary {
  cursor: pointer;
  min-height: 2.75rem;
  padding: 0.65rem 0.75rem;
  border-radius: 0.6rem;
  background: var(--superficie-2);
  color: var(--primario-fuerte);
  font-size: 0.875rem;
  font-weight: 500;
}
</style>
