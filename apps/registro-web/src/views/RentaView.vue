<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface FacturaCargo {
  id: string;
  estado: string;
  uuid: string | null;
}
interface Cargo {
  id: string;
  periodo: string;
  modo_cobro: string;
  alumnos_activos: number;
  monto_minor: number;
  moneda: string;
  estado: string;
  vence_en: string | null;
  pagado_en: string | null;
  factura: FacturaCargo | null;
}
interface Renta {
  modo_cobro: string;
  moneda: string;
  precio_por_alumno_minor: number;
  cuota_fija_minor: number;
  actual: {
    periodo: string;
    alumnos_activos: number;
    cargo_estimado_minor: number;
  };
  cargos: Cargo[];
}

interface RespuestaPago {
  estado: string;
  checkout?: { tipo?: string; url?: string };
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const renta = ref<Renta | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

const pagando = ref<string | null>(null);
const facturando = ref<string | null>(null);
const avisoPago = ref<string | null>(null);
const errorPago = ref<string | null>(null);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Renta }>(`${base.value}/renta`);
    renta.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function pagar(cargo: Cargo): Promise<void> {
  pagando.value = cargo.id;
  avisoPago.value = null;
  errorPago.value = null;
  try {
    const { data } = await api.post<{ data: RespuestaPago }>(
      `${base.value}/renta/cargos/${cargo.id}/pagar`,
      {},
    );
    const checkout = data.data.checkout ?? {};
    // Pasarelas de redirección (p. ej. Mercado Pago): se envía al checkout externo.
    if (
      checkout.tipo === "redirect" &&
      typeof checkout.url === "string" &&
      checkout.url !== ""
    ) {
      window.location.href = checkout.url;
      return;
    }
    // Cobro en línea iniciado (queda pendiente hasta que la pasarela confirme por webhook).
    avisoPago.value =
      data.data.estado === "pagado"
        ? t("renta.pago.confirmado")
        : t("renta.pago.iniciado");
    await cargar();
  } catch (e) {
    errorPago.value = mensajeDeError(e);
  } finally {
    pagando.value = null;
  }
}

async function facturar(cargo: Cargo): Promise<void> {
  facturando.value = cargo.id;
  avisoPago.value = null;
  errorPago.value = null;
  try {
    await api.post(`${base.value}/renta/cargos/${cargo.id}/factura`, {});
    avisoPago.value = t("renta.factura.timbrada");
    await cargar();
  } catch (e) {
    // El rechazo del timbre llega como 422 con la factura en error + motivo.
    if (
      axios.isAxiosError(e) &&
      e.response?.status === 422 &&
      e.response.data?.data?.estado === "error"
    ) {
      errorPago.value = e.response.data.data.motivo_error ?? mensajeDeError(e);
      await cargar();
    } else {
      errorPago.value = mensajeDeError(e);
    }
  } finally {
    facturando.value = null;
  }
}

async function descargarFactura(
  cargo: Cargo,
  formato: "pdf" | "xml",
): Promise<void> {
  if (cargo.factura === null) {
    return;
  }
  try {
    const { data } = await api.get<Blob>(
      `${base.value}/renta/facturas/${cargo.factura.id}/${formato}`,
      {
        responseType: "blob",
      },
    );
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = `factura-${cargo.factura.uuid ?? cargo.factura.id}.${formato}`;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    errorPago.value = mensajeDeError(e);
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      icono="renta"
      :titulo="$t('renta.titulo')"
      :subtitulo="$t('renta.subtitulo')"
    />

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else-if="renta">
      <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <!-- Modo de cobro -->
        <div class="tu-card p-5">
          <h2 class="font-bold">{{ $t("renta.modo.titulo") }}</h2>
          <p class="mt-2 text-lg font-extrabold">
            {{
              renta.modo_cobro === "fijo"
                ? $t("renta.modo.fijo")
                : $t("renta.modo.activos")
            }}
          </p>
          <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{
              renta.modo_cobro === "fijo"
                ? $t("renta.modo.cuota", {
                    monto: dinero(renta.cuota_fija_minor, renta.moneda),
                  })
                : $t("renta.modo.porAlumno", {
                    monto: dinero(renta.precio_por_alumno_minor, renta.moneda),
                  })
            }}
          </p>
        </div>

        <!-- Periodo en curso -->
        <div class="tu-card p-5">
          <h2 class="font-bold">
            {{ $t("renta.actual.titulo") }} · {{ renta.actual.periodo }}
          </h2>
          <div class="mt-2 flex items-end justify-between">
            <div>
              <div class="text-3xl font-extrabold">
                {{ dinero(renta.actual.cargo_estimado_minor, renta.moneda) }}
              </div>
              <div
                class="text-xs mt-1"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("renta.actual.estimado") }}
              </div>
            </div>
            <div class="text-right">
              <div class="text-xl font-bold">
                {{ renta.actual.alumnos_activos }}
              </div>
              <div
                class="text-xs mt-1"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("renta.actual.activos") }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Historial de cargos -->
      <h2 class="mt-8 font-bold text-lg">{{ $t("renta.historial") }}</h2>
      <p
        v-if="renta.cargos.length === 0"
        class="mt-3 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("renta.sinCargos") }}
      </p>
      <div v-else class="mt-3 tu-card overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
              <th class="px-4 py-2 font-medium">
                {{ $t("renta.colPeriodo") }}
              </th>
              <th class="px-4 py-2 font-medium text-right hidden sm:table-cell">
                {{ $t("renta.colAlumnos") }}
              </th>
              <th class="px-4 py-2 font-medium text-right">
                {{ $t("renta.colMonto") }}
              </th>
              <th class="px-4 py-2 font-medium">{{ $t("renta.colEstado") }}</th>
              <th class="px-4 py-2 font-medium hidden sm:table-cell">
                {{ $t("renta.colVence") }}
              </th>
              <th class="px-4 py-2 font-medium text-right">
                {{ $t("renta.colAccion") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="c in renta.cargos"
              :key="c.id"
              class="border-t"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <td class="px-4 py-2 font-semibold">{{ c.periodo }}</td>
              <td class="px-4 py-2 text-right hidden sm:table-cell">
                {{ c.modo_cobro === "fijo" ? "—" : c.alumnos_activos }}
              </td>
              <td class="px-4 py-2 text-right font-semibold">
                {{ dinero(c.monto_minor, c.moneda) }}
              </td>
              <td class="px-4 py-2">
                <span
                  class="tu-badge"
                  :class="
                    c.estado === 'pagado' ? 'tu-badge-exito' : 'tu-badge-aviso'
                  "
                >
                  {{ $t(`renta.estados.${c.estado}`) }}
                </span>
              </td>
              <td
                class="px-4 py-2 hidden sm:table-cell"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ c.vence_en ?? "—" }}
              </td>
              <td class="px-4 py-2 text-right">
                <!-- Pendiente: pagar la renta -->
                <button
                  v-if="c.estado === 'pendiente'"
                  type="button"
                  class="tu-btn tu-btn-primario whitespace-nowrap"
                  :disabled="pagando === c.id"
                  @click="pagar(c)"
                >
                  {{
                    pagando === c.id ? $t("renta.pagando") : $t("renta.pagar")
                  }}
                </button>
                <!-- Pagado y timbrado: descargar CFDI -->
                <span
                  v-else-if="c.factura && c.factura.estado === 'timbrada'"
                  class="inline-flex gap-2 justify-end"
                >
                  <button
                    type="button"
                    class="tu-btn tu-btn-fantasma whitespace-nowrap"
                    @click="descargarFactura(c, 'pdf')"
                  >
                    {{ $t("renta.factura.pdf") }}
                  </button>
                  <button
                    type="button"
                    class="tu-btn tu-btn-fantasma whitespace-nowrap"
                    @click="descargarFactura(c, 'xml')"
                  >
                    {{ $t("renta.factura.xml") }}
                  </button>
                </span>
                <!-- Pagado sin factura (o con error): emitir/reintentar -->
                <button
                  v-else
                  type="button"
                  class="tu-btn tu-btn-fantasma whitespace-nowrap"
                  :disabled="facturando === c.id"
                  @click="facturar(c)"
                >
                  {{
                    facturando === c.id
                      ? $t("renta.factura.procesando")
                      : c.factura?.estado === "error"
                        ? $t("renta.factura.reintentar")
                        : $t("renta.factura.facturar")
                  }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="avisoPago" class="mt-3 text-sm" style="color: var(--exito)">
        {{ avisoPago }}
      </p>
      <p v-if="errorPago" class="mt-3 text-sm" style="color: var(--error)">
        {{ errorPago }}
      </p>
      <p class="mt-3 text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("renta.pagoNota") }}
      </p>
    </template>
  </section>
</template>
