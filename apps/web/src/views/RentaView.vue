<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisosAgendaUno from "@/components/AvisosAgendaUno.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useRetornoPago } from "@/lib/retornoPago";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface LineaDesglose {
  concepto: string;
  detalle: string;
  importe_minor: number;
}
interface Desglose {
  lineas: LineaDesglose[];
  subtotal_minor: number;
  iva_porcentaje: number;
  iva_minor: number;
  total_minor: number;
  prorrateo?: { dias_cobrables: number; dias_periodo: number };
}
interface FacturaCargo {
  id: string;
  estado: string;
  uuid: string | null;
}
interface Cargo {
  id: string;
  periodo: string;
  modo_cobro: string;
  metrica: string;
  cantidad: number;
  desglose: Desglose | null;
  monto_minor: number;
  moneda: string;
  estado: string;
  vence_en: string | null;
  pagado_en: string | null;
  factura: FacturaCargo | null;
}
interface Uso {
  periodo: string;
  metrica: string;
  cantidad: number;
  detalle: { personas_fuera_de_cita?: number };
  desglose: Desglose;
  cargo_estimado_minor: number;
}
interface Renta {
  modo_cobro: string;
  moneda: string;
  cuota_fija_minor: number;
  trial_termina_en: string | null;
  /** ¿Puede recibir la factura de la renta? Si no, se le da un recibo sin valor fiscal. */
  factura_renta_posible?: boolean;
  actual: Uso;
  cargos: Cargo[];
}
interface QuienCuenta {
  metrica: string;
  descripcion: string | null;
  cantidad: number;
  quienes: {
    id: string;
    nombre: string;
    sesiones?: number;
  }[];
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

const quien = ref<QuienCuenta | null>(null);
const cargandoQuien = ref(false);
const expandido = ref<string | null>(null);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
function fecha(iso: string): string {
  const [a, m, d] = iso.slice(0, 10).split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "long" }).format(
    new Date(a, m - 1, d),
  );
}

// Sin factura posible (otra moneda u otro país, o la plataforma aún no factura) no se
// ofrece «Facturar»: de cada cargo pagado se descarga un recibo sin valor fiscal.
const facturaPosible = computed(
  () =>
    renta.value?.factura_renta_posible !== false &&
    sesion.estudio?.facturacion_disponible !== false,
);

const enPrueba = computed(() => {
  const fin = renta.value?.trial_termina_en;
  return fin != null && fin >= new Date().toISOString().slice(0, 10);
});
const ayudaModo = computed(() => {
  const r = renta.value;
  if (r === null) {
    return "";
  }
  if (r.modo_cobro === "fijo") {
    return t("cobro.modo.ayudaFijo", {
      monto: dinero(r.cuota_fija_minor, r.moneda),
    });
  }
  // Por profesional o por alumno: según la modalidad del negocio (ADR 0104).
  return sesion.esCitas
    ? t("cobro.modo.ayudaCitas")
    : t("cobro.modo.ayudaClases");
});

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

async function alternarQuien(): Promise<void> {
  if (quien.value !== null) {
    quien.value = null;
    return;
  }
  cargandoQuien.value = true;
  try {
    const { data } = await api.get<{ data: QuienCuenta }>(
      `${base.value}/renta/quien-cuenta`,
    );
    quien.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargandoQuien.value = false;
  }
}

async function pagar(cargo: Cargo): Promise<void> {
  if (
    !(await confirmar(
      t("confirmaciones.pagarRenta", {
        monto: dinero(cargo.monto_minor, cargo.moneda),
      }),
      { aceptar: t("confirmaciones.pagar") },
    ))
  ) {
    return;
  }
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
  if (
    !(await confirmar(t("confirmaciones.facturaRenta"), {
      aceptar: t("confirmaciones.timbrar"),
    }))
  ) {
    return;
  }
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

async function descargar(ruta: string, nombre: string): Promise<void> {
  errorPago.value = null;
  try {
    const { data } = await api.get<Blob>(`${base.value}${ruta}`, {
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = nombre;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    errorPago.value = mensajeDeError(e);
  }
}

async function descargarFactura(
  cargo: Cargo,
  formato: "pdf" | "xml",
): Promise<void> {
  if (cargo.factura === null) {
    return;
  }
  await descargar(
    `/renta/facturas/${cargo.factura.id}/${formato}`,
    `factura-${cargo.factura.uuid ?? cargo.factura.id}.${formato}`,
  );
}

async function descargarRecibo(cargo: Cargo): Promise<void> {
  await descargar(
    `/renta/cargos/${cargo.id}/recibo`,
    `recibo-agendauno-${cargo.periodo}.pdf`,
  );
}

// Al volver de la página de pago de la renta: aviso y recarga tras la confirmación.
const retornoPago = useRetornoPago();

onMounted(() => {
  void cargar();
  if (retornoPago.value === "exito") {
    avisoPago.value = t("pagoEnLinea.rentaExito");
    window.setTimeout(() => void cargar(), 4000);
  } else if (retornoPago.value === "cancelado") {
    errorPago.value = t("pagoEnLinea.cancelado");
  }
});
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion :titulo="$t('renta.titulo')" />

    <!-- Suspendido por renta vencida: qué pasa y cómo reactivarlo (ADR 0073). -->
    <div
      v-if="sesion.suspendido"
      class="rt-suspendido mt-4"
      role="alert"
      data-prueba="suspendido"
    >
      <p class="font-medium">{{ $t("renta.suspendidoTitulo") }}</p>
      <p class="mt-1 text-sm">{{ $t("renta.suspendidoAyuda") }}</p>
    </div>

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
      <div class="mt-6 grid gap-4 lg:grid-cols-5">
        <!-- Cómo te cobramos -->
        <div class="tu-card p-5 lg:col-span-2">
          <h2 class="font-medium">{{ $t("cobro.modo.titulo") }}</h2>
          <p class="mt-2 text-lg font-semibold">
            {{
              renta.modo_cobro === "fijo"
                ? $t("cobro.modo.fijo")
                : $t(`cobro.modo.${sesion.modalidad}`)
            }}
          </p>
          <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ ayudaModo }}
          </p>
          <p class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("cobro.modo.mesVencido") }}
          </p>
          <p
            v-if="enPrueba && renta.trial_termina_en"
            class="mt-3 text-sm rounded-lg px-3 py-2 font-semibold"
            :style="{ background: 'var(--exito-suave)', color: 'var(--exito)' }"
          >
            {{
              $t("cobro.modo.pruebaHasta", {
                fecha: fecha(renta.trial_termina_en),
              })
            }}
          </p>
        </div>

        <!-- Mes en curso con su desglose -->
        <div class="tu-card p-5 lg:col-span-3">
          <h2 class="font-medium">
            {{ $t("cobro.actual.titulo") }} · {{ renta.actual.periodo }}
          </h2>
          <div class="mt-2 flex items-end justify-between gap-4 flex-wrap">
            <div>
              <div class="text-3xl font-semibold">
                {{ dinero(renta.actual.cargo_estimado_minor, renta.moneda) }}
              </div>
              <div
                class="text-xs mt-1"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("cobro.actual.estimado") }}
              </div>
            </div>
            <div class="text-right">
              <div class="text-2xl font-semibold">
                {{ renta.actual.cantidad }}
              </div>
              <div class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                {{ $t(`cobro.actual.${renta.actual.metrica}`) }}
              </div>
              <div
                v-if="(renta.actual.detalle.personas_fuera_de_cita ?? 0) > 0"
                class="text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{
                  $t("cobro.actual.fueraDeCita", {
                    n: renta.actual.detalle.personas_fuera_de_cita,
                  })
                }}
              </div>
            </div>
          </div>

          <dl
            v-if="renta.actual.desglose.lineas.length > 0"
            class="rt-desglose mt-4"
          >
            <template v-for="(l, i) in renta.actual.desglose.lineas" :key="i">
              <dt>
                <span class="font-semibold">{{ l.concepto }}</span>
                <span class="block text-xs rt-suave">{{ l.detalle }}</span>
              </dt>
              <dd>{{ dinero(l.importe_minor, renta.moneda) }}</dd>
            </template>
            <dt class="rt-suave">{{ $t("cobro.desglose.subtotal") }}</dt>
            <dd class="rt-suave">
              {{ dinero(renta.actual.desglose.subtotal_minor, renta.moneda) }}
            </dd>
            <template v-if="renta.actual.desglose.iva_porcentaje > 0">
              <dt class="rt-suave">
                {{
                  $t("cobro.desglose.iva", {
                    pct: renta.actual.desglose.iva_porcentaje,
                  })
                }}
              </dt>
              <dd class="rt-suave">
                {{ dinero(renta.actual.desglose.iva_minor, renta.moneda) }}
              </dd>
            </template>
            <dt class="font-semibold">{{ $t("cobro.desglose.total") }}</dt>
            <dd class="font-semibold">
              {{ dinero(renta.actual.desglose.total_minor, renta.moneda) }}
            </dd>
          </dl>
          <p
            v-else
            class="mt-4 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("cobro.actual.sinCargo") }}
          </p>
          <p
            v-if="renta.actual.desglose.prorrateo"
            class="mt-2 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{
              $t("cobro.desglose.prorrateo", {
                dias: renta.actual.desglose.prorrateo.dias_cobrables,
                total: renta.actual.desglose.prorrateo.dias_periodo,
              })
            }}
          </p>

          <!-- Transparencia: a quién se contó -->
          <button
            v-if="renta.modo_cobro !== 'fijo'"
            type="button"
            class="tu-enlace text-sm mt-4"
            :disabled="cargandoQuien"
            @click="alternarQuien"
          >
            {{ quien ? $t("cobro.quien.ocultar") : $t("cobro.quien.ver") }}
          </button>
          <div v-if="quien" class="mt-3">
            <p class="text-sm font-medium">{{ $t("cobro.quien.titulo") }}</p>
            <p
              v-if="quien.descripcion"
              class="text-xs mt-1"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ quien.descripcion }}
            </p>
            <p
              v-if="quien.quienes.length === 0"
              class="text-sm mt-2"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("cobro.quien.nadie") }}
            </p>
            <ul v-else class="mt-2 flex flex-wrap gap-2">
              <li
                v-for="p in quien.quienes"
                :key="p.id"
                class="tu-badge"
                :style="{
                  background: 'var(--superficie-2)',
                  color: 'var(--texto)',
                }"
              >
                {{ p.nombre }}
                <span v-if="p.sesiones !== undefined" class="rt-suave">
                  · {{ $t("cobro.quien.sesiones", { n: p.sesiones }) }}</span
                >
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Historial de cargos -->
      <h2 class="mt-8 font-medium text-lg">{{ $t("renta.historial") }}</h2>
      <p
        v-if="renta.cargos.length === 0"
        class="mt-3 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("renta.sinCargos") }}
      </p>
      <div v-else class="mt-3 tu-card overflow-hidden">
        <table class="tu-tabla">
          <thead>
            <tr>
              <th>
                {{ $t("renta.colPeriodo") }}
              </th>
              <th class="text-right hidden sm:table-cell">
                {{ $t("cobro.historial.uso") }}
              </th>
              <th class="text-right">
                {{ $t("renta.colMonto") }}
              </th>
              <th>{{ $t("renta.colEstado") }}</th>
              <th class="hidden sm:table-cell">
                {{ $t("renta.colVence") }}
              </th>
              <th class="text-right">
                {{ $t("renta.colAccion") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <template v-for="c in renta.cargos" :key="c.id">
              <tr>
                <td class="font-semibold">
                  {{ c.periodo }}
                  <button
                    v-if="c.desglose && c.desglose.lineas.length > 0"
                    type="button"
                    class="tu-enlace text-xs ml-2 font-normal"
                    @click="expandido = expandido === c.id ? null : c.id"
                  >
                    {{
                      expandido === c.id
                        ? $t("cobro.desglose.ocultar")
                        : $t("cobro.desglose.ver")
                    }}
                  </button>
                </td>
                <td class="text-right hidden sm:table-cell">
                  {{
                    c.modo_cobro === "fijo"
                      ? "—"
                      : `${c.cantidad} ${$t(`cobro.actual.${c.metrica}`)}`
                  }}
                </td>
                <td class="text-right font-semibold">
                  {{ dinero(c.monto_minor, c.moneda) }}
                </td>
                <td>
                  <span
                    class="tu-badge"
                    :class="{
                      'tu-badge-exito': c.estado === 'pagado',
                      'tu-badge-aviso': c.estado === 'pendiente',
                    }"
                  >
                    {{ $t(`cobro.estados.${c.estado}`) }}
                  </span>
                </td>
                <td
                  class="hidden sm:table-cell"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ c.estado === "sin_cargo" ? "—" : (c.vence_en ?? "—") }}
                </td>
                <td class="text-right">
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
                  <!-- Sin cargo: nada que pagar ni facturar -->
                  <span
                    v-else-if="c.estado === 'sin_cargo'"
                    :style="{ color: 'var(--texto-suave)' }"
                    >—</span
                  >
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
                  <!-- Sin factura posible: recibo sin valor fiscal. -->
                  <button
                    v-else-if="!facturaPosible"
                    type="button"
                    class="tu-btn tu-btn-fantasma whitespace-nowrap"
                    data-prueba="recibo"
                    @click="descargarRecibo(c)"
                  >
                    {{ $t("renta.recibo.descargar") }}
                  </button>
                  <!-- Pagado sin factura (o con error): emitir/reintentar -->
                  <button
                    v-else
                    type="button"
                    class="tu-btn tu-btn-fantasma whitespace-nowrap"
                    data-prueba="facturar"
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
              <tr v-if="expandido === c.id && c.desglose">
                <td colspan="6" class="px-4 pb-4">
                  <dl class="rt-desglose">
                    <template v-for="(l, i) in c.desglose.lineas" :key="i">
                      <dt>
                        <span class="font-semibold">{{ l.concepto }}</span>
                        <span class="block text-xs rt-suave">{{
                          l.detalle
                        }}</span>
                      </dt>
                      <dd>{{ dinero(l.importe_minor, c.moneda) }}</dd>
                    </template>
                    <template v-if="c.desglose.iva_porcentaje > 0">
                      <dt class="rt-suave">
                        {{
                          $t("cobro.desglose.iva", {
                            pct: c.desglose.iva_porcentaje,
                          })
                        }}
                      </dt>
                      <dd class="rt-suave">
                        {{ dinero(c.desglose.iva_minor, c.moneda) }}
                      </dd>
                    </template>
                  </dl>
                  <p v-if="c.desglose.prorrateo" class="mt-1 text-xs rt-suave">
                    {{
                      $t("cobro.desglose.prorrateo", {
                        dias: c.desglose.prorrateo.dias_cobrables,
                        total: c.desglose.prorrateo.dias_periodo,
                      })
                    }}
                  </p>
                </td>
              </tr>
            </template>
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
        {{ facturaPosible ? $t("renta.pagoNota") : $t("renta.recibo.nota") }}
      </p>

      <!-- Avisos de AgendaUno al dueño: correo y WhatsApp (ADR 0072). -->
      <AvisosAgendaUno class="mt-8" />
    </template>
  </section>
</template>

<style scoped>
/* Sin `margin`: lo dan sus utilidades (mt-4); un margin aquí las anularía. */
.rt-desglose {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.5rem 1rem;
  padding: 0.9rem 1rem;
  border-radius: 0.75rem;
  background: var(--superficie-2);
  font-size: 0.875rem;
}
.rt-desglose dd {
  margin: 0;
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.rt-suave {
  color: var(--texto-suave);
}
.rt-suspendido {
  padding: 0.9rem 1rem;
  border: 1px solid var(--error);
  border-radius: 0.75rem;
  background: var(--superficie);
}
</style>
