<script setup lang="ts">
import { isAxiosError } from "axios";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { api, mensajeDeError } from "@/lib/api";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Por conciliar: lo del dinero que necesita que alguien lo revise (p. ej. una
 * devolución que la pasarela no confirmó). Una devolución sin confirmar se resuelve
 * diciendo si la pasarela la hizo o no (se ve en su panel), con una nota.
 *
 * Cada estado se distingue: revisando, nada por conciliar, no se pudo consultar (con
 * «Reintentar»: un fallo no se confunde con «no hay nada») y sin permiso. A quien no
 * ve la facturación no se le muestra la sección.
 *
 * Del pago automático (ADR 0134): una cancelación que la pasarela no confirma (se
 * cancela en su panel; se cierra sola al confirmarse), un cobro que llegó tras
 * cancelar o uno con otro importe (se devuelve, o solo el excedente, o se da por
 * pagada la renovación).
 */
interface Incidencia {
  id: string;
  tipo: string;
  detalle: string;
  fecha: string | null;
  persona: string | null;
  monto_minor: number | null;
  moneda: string | null;
  reembolso: {
    estado: string;
    monto_minor: number;
    moneda: string;
    proveedor: string | null;
    motivo_fallo: string | null;
  } | null;
  // Del pago automático (ADR 0134).
  domiciliacion?: {
    proveedor: string;
    referencia: string | null;
    estado: string;
  } | null;
  esperado_minor?: number | null;
  excedente_minor?: number | null;
  aplicable?: boolean;
}

const emit = defineEmits<{ cambio: [] }>();

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeResolver = computed(() => sesion.puede("pagos.reembolsar"));

const incidencias = ref<Incidencia[]>([]);
const puedeVer = computed(() => sesion.puede("facturacion.ver"));
const estado = ref<"cargando" | "listo" | "error" | "sin_permiso">("cargando");
const errorCarga = ref<string | null>(null);
const abierta = ref<string | null>(null);
const nota = ref("");
const guardando = ref(false);
const error = ref<string | null>(null);

// Sin moneda, la del negocio (ADR 0099); con los números de su país.
function dinero(minor: number, moneda: string | null): string {
  return dineroDelPais(minor, moneda || sesion.moneda, sesion.pais);
}

async function cargar(): Promise<void> {
  if (!puedeVer.value) {
    return;
  }
  // Al recargar tras resolver, la lista se queda mientras llega la nueva.
  if (estado.value !== "listo") {
    estado.value = "cargando";
  }
  try {
    const { data } = await api.get<{ data: Incidencia[] }>(
      `${base.value}/incidencias-cobro`,
    );
    incidencias.value = data.data;
    estado.value = "listo";
  } catch (e) {
    if (isAxiosError(e) && e.response?.status === 403) {
      estado.value = "sin_permiso";
      return;
    }
    // Lo que ya se veía se conserva; el aviso dice que no está al día.
    errorCarga.value = mensajeDeError(e);
    estado.value = "error";
  }
}

// Qué pasó, en una línea.
function titulo(i: Incidencia): string {
  if (i.reembolso) {
    return t("porConciliar.devolucion", {
      monto: dinero(i.reembolso.monto_minor, i.reembolso.moneda),
    });
  }
  const monto = i.monto_minor !== null ? dinero(i.monto_minor, i.moneda) : "";
  switch (i.tipo) {
    case "cancelacion_sin_confirmar": {
      const p = i.domiciliacion?.proveedor ?? "";
      const clave = `porConciliar.pasarelas.${p}`;
      return t("porConciliar.cancelacionSinConfirmar", {
        pasarela: te(clave) ? t(clave) : p,
      });
    }
    case "cobro_tras_cancelar":
      return t("porConciliar.cobroTrasCancelar", { monto });
    case "monto_distinto":
      return t("porConciliar.montoDistinto", {
        monto,
        esperado: dinero(i.esperado_minor ?? 0, i.moneda),
      });
    case "pago_duplicado":
      return t("porConciliar.pagoDuplicado", { monto });
    case "primera_vez_repetida":
      return t("porConciliar.primeraVezRepetida", { monto });
    case "contracargo":
      return t("porConciliar.contracargo", { monto });
    default:
      return t("porConciliar.pagoTardio", { monto });
  }
}

function abrir(i: Incidencia): void {
  abierta.value = abierta.value === i.id ? null : i.id;
  nota.value = "";
  error.value = null;
}

async function resolver(
  i: Incidencia,
  reembolso: "aprobado" | "fallido" | null,
  accion: "devolver" | "aplicar" | null = null,
): Promise<void> {
  if (nota.value.trim() === "") {
    error.value = t("porConciliar.faltaNota");
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/incidencias-cobro/${i.id}/resolver`, {
      resolucion: nota.value.trim(),
      reembolso,
      accion,
    });
    abierta.value = null;
    await cargar();
    emit("cambio");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

onMounted(cargar);
defineExpose({ cargar });
</script>

<template>
  <section v-if="puedeVer" class="mt-8" data-prueba="por-conciliar">
    <h2 class="font-medium text-lg">{{ $t("porConciliar.titulo") }}</h2>
    <p
      v-if="estado === 'cargando'"
      class="mt-1 text-sm"
      role="status"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("porConciliar.revisando") }}
    </p>
    <p
      v-else-if="estado === 'sin_permiso'"
      class="mt-1 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("porConciliar.sinPermiso") }}
    </p>
    <AvisoTono
      v-else-if="estado === 'error'"
      tono="peligro"
      class="mt-3"
      role="alert"
      data-prueba="error-conciliar"
    >
      {{ $t("porConciliar.noSeConsulto", { error: errorCarga ?? "" }) }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          data-prueba="reintentar-conciliar"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <p
      v-else-if="incidencias.length === 0"
      class="mt-1 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
      data-prueba="nada-por-conciliar"
    >
      {{ $t("porConciliar.nada") }}
    </p>
    <p
      v-if="incidencias.length > 0"
      class="mt-1 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("porConciliar.ayuda") }}
    </p>
    <ul
      v-if="incidencias.length > 0"
      class="mt-3 tu-card divide-y"
      :style="{ borderColor: 'var(--borde)' }"
    >
      <li v-for="i in incidencias" :key="i.id" class="px-4 py-3 text-sm">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="font-medium">
              {{ titulo(i) }}
              <template v-if="i.persona"> · {{ i.persona }}</template>
            </p>
            <p class="mt-0.5" :style="{ color: 'var(--texto-suave)' }">
              {{ i.detalle }}
            </p>
          </div>
          <button
            v-if="puedeResolver"
            type="button"
            class="tu-enlace shrink-0"
            :aria-expanded="abierta === i.id"
            @click="abrir(i)"
          >
            {{ $t("porConciliar.resolver") }}
          </button>
        </div>

        <div v-if="abierta === i.id" class="mt-3 space-y-2">
          <label class="tu-label" :for="`nota-${i.id}`">{{
            $t("porConciliar.nota")
          }}</label>
          <input
            :id="`nota-${i.id}`"
            v-model="nota"
            class="tu-input"
            maxlength="500"
            :placeholder="$t('porConciliar.notaEjemplo')"
          />
          <AvisoTono v-if="error" tono="peligro" role="alert">
            {{ error }}
          </AvisoTono>
          <div class="flex flex-wrap gap-2">
            <template
              v-if="
                i.reembolso &&
                i.reembolso.estado !== 'aprobado' &&
                i.reembolso.estado !== 'fallido'
              "
            >
              <button
                type="button"
                class="tu-btn tu-btn-primario"
                :disabled="guardando"
                @click="resolver(i, 'aprobado')"
              >
                {{ $t("porConciliar.siSeDevolvio") }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-secundario"
                :disabled="guardando"
                @click="resolver(i, 'fallido')"
              >
                {{ $t("porConciliar.noSeDevolvio") }}
              </button>
            </template>
            <template v-else>
              <!-- Un cobro con otro importe o tras cancelar paga la renovación. -->
              <button
                v-if="i.aplicable"
                type="button"
                class="tu-btn tu-btn-primario"
                :disabled="guardando"
                @click="resolver(i, null, 'aplicar')"
              >
                {{ $t("porConciliar.darPorPagada") }}
              </button>
              <button
                v-if="i.reembolso === null && i.monto_minor !== null"
                type="button"
                :class="
                  i.aplicable
                    ? 'tu-btn tu-btn-secundario'
                    : 'tu-btn tu-btn-primario'
                "
                :disabled="guardando"
                @click="resolver(i, null, 'devolver')"
              >
                {{
                  i.excedente_minor
                    ? $t("porConciliar.devolverExcedente", {
                        monto: dinero(i.excedente_minor, i.moneda),
                      })
                    : $t("porConciliar.devolverPago")
                }}
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-secundario"
                :disabled="guardando"
                @click="resolver(i, null)"
              >
                {{ $t("porConciliar.marcarResuelta") }}
              </button>
            </template>
          </div>
        </div>
      </li>
    </ul>
  </section>
</template>
