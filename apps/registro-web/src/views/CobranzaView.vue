<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Moroso {
  id: string;
  acuerdo: string | null;
  persona: { id: string; nombre: string } | null;
  estado: string;
  intentos: number;
  gracia_hasta: string;
  ultimo_motivo: string | null;
}
interface Pago {
  id: string;
  fecha: string | null;
  persona: string | null;
  monto_minor: number;
  moneda: string;
  estado: string;
  proveedor: string | null;
  metodo: string | null;
  reembolsado_minor: number;
  reembolsable_minor: number;
}
interface Suscripcion {
  id: string;
  persona: string | null;
  producto: string | null;
  precio_minor: number | null;
  moneda: string | null;
  proxima_cobro_en: string | null;
  estado: string;
  // Pago automático: la tarjeta con la que se cobra sola (y el último rechazo).
  pago_automatico: {
    marca: string | null;
    ultimos4: string | null;
    expira: string | null;
    error: string | null;
  } | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeRegularizar = computed(() => sesion.puede("ordenes.gestionar"));
const puedeReembolsar = computed(() => sesion.puede("pagos.reembolsar"));

const morosos = ref<Moroso[]>([]);
const pagos = ref<Pago[]>([]);
const suscripciones = ref<Suscripcion[]>([]);
const pagoAutomaticoDisponible = ref(false);
const avisoRenovacion = ref<string | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const accionando = ref<string | null>(null);

// Modal de reembolso.
const reembolsando = ref<Pago | null>(null);
const rMonto = ref("");
const rMotivo = ref("");
const rRevertir = ref(true);
// Pago en línea cuyo dinero el negocio ya devolvió por fuera (no se pide a la pasarela).
const rManual = ref(false);
const rProcesando = ref(false);
const PASARELAS_EN_LINEA = ["stripe", "openpay", "mercadopago"];
const avisoReembolso = ref<string | null>(null);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
function fechaHora(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(iso));
}
function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  // Fecha-solo (YYYY-MM-DD) en hora local para no restar un día; datetime tal cual.
  const d = iso.includes("T") ? new Date(iso) : new Date(`${iso}T00:00:00`);
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(d);
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [d, p, s] = await Promise.all([
      api.get<{ data: Moroso[] }>(`${base.value}/dunning`),
      api.get<{ data: Pago[] }>(`${base.value}/pagos`),
      api.get<{ data: Suscripcion[]; pago_automatico_disponible?: boolean }>(
        `${base.value}/suscripciones`,
      ),
    ]);
    morosos.value = d.data.data;
    pagos.value = p.data.data;
    suscripciones.value = s.data.data;
    pagoAutomaticoDisponible.value = s.data.pago_automatico_disponible === true;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function marca(m: string | null): string {
  return m ? m.charAt(0).toUpperCase() + m.slice(1) : "";
}

// Correo al alumno con el enlace para activar su pago automático.
async function invitarPagoAutomatico(s: Suscripcion): Promise<void> {
  accionando.value = s.id;
  error.value = null;
  avisoRenovacion.value = null;
  try {
    await api.post(
      `${base.value}/suscripciones/${s.id}/pago-automatico/solicitar`,
      {},
    );
    avisoRenovacion.value = t("pagoAutomatico.invitado");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = null;
  }
}

async function quitarPagoAutomatico(s: Suscripcion): Promise<void> {
  if (!window.confirm(t("pagoAutomatico.confirmarQuitarNegocio"))) {
    return;
  }
  accionando.value = s.id;
  error.value = null;
  try {
    await api.delete(`${base.value}/suscripciones/${s.id}/pago-automatico`);
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = null;
  }
}

async function regularizar(m: Moroso): Promise<void> {
  if (m.acuerdo === null) {
    return;
  }
  accionando.value = m.id;
  error.value = null;
  try {
    await api.post(`${base.value}/acuerdos/${m.acuerdo}/regularizar`, {});
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = null;
  }
}

// Reembolsos ya hechos de un pago (quién, cuánto, cuándo y por qué).
interface Reembolso {
  id: string;
  monto_minor: number;
  moneda: string;
  estado: string;
  motivo: string | null;
  revirtio_creditos: boolean;
  via?: string | null;
  actor: string | null;
  fecha: string | null;
}
const reembolsosDe = ref<string | null>(null);
const reembolsos = ref<Reembolso[]>([]);
async function verReembolsos(p: Pago): Promise<void> {
  if (reembolsosDe.value === p.id) {
    reembolsosDe.value = null;
    return;
  }
  try {
    const { data } = await api.get<{ data: Reembolso[] }>(
      `${base.value}/pagos/${p.id}/reembolsos`,
    );
    reembolsos.value = data.data;
    reembolsosDe.value = p.id;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

function abrirReembolso(p: Pago): void {
  reembolsando.value = p;
  rMonto.value = String(p.reembolsable_minor / 100);
  rMotivo.value = "";
  rRevertir.value = true;
  rManual.value = false;
}

async function reembolsar(): Promise<void> {
  const p = reembolsando.value;
  if (p === null || rMotivo.value.trim() === "") {
    return;
  }
  rProcesando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: { estado: string } }>(
      `${base.value}/pagos/${p.id}/reembolsos`,
      {
        monto_minor: Math.round(Number(rMonto.value) * 100),
        motivo: rMotivo.value.trim(),
        revertir_creditos: rRevertir.value,
        manual: rManual.value,
      },
    );
    avisoReembolso.value =
      data.data.estado === "pendiente" ? t("reembolsosPago.enProceso") : null;
    reembolsando.value = null;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    rProcesando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('cobranza.titulo')" />

    <p
      v-if="avisoReembolso"
      class="mt-4 text-sm"
      role="status"
      style="color: var(--aviso)"
    >
      {{ avisoReembolso }}
    </p>
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

    <template v-else>
      <!-- Morosos (dunning) -->
      <div class="mt-6 flex items-center gap-3">
        <h2 class="font-light text-lg">{{ $t("cobranza.morosos") }}</h2>
        <span
          class="tu-badge"
          :class="morosos.length > 0 ? 'tu-badge-aviso' : 'tu-badge-exito'"
          >{{ morosos.length }}</span
        >
      </div>
      <p
        v-if="morosos.length === 0"
        class="mt-3 tu-card p-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("cobranza.sinMorosos") }}
      </p>
      <div v-else class="mt-3 tu-card overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
              <th class="px-4 py-2 font-medium">
                {{ $t("cobranza.colAlumno") }}
              </th>
              <th class="px-4 py-2 font-medium">
                {{ $t("cobranza.colEstado") }}
              </th>
              <th class="px-4 py-2 font-medium text-right hidden sm:table-cell">
                {{ $t("cobranza.colIntentos") }}
              </th>
              <th class="px-4 py-2 font-medium hidden md:table-cell">
                {{ $t("cobranza.colGracia") }}
              </th>
              <th class="px-4 py-2 font-medium text-right"></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="m in morosos"
              :key="m.id"
              class="border-t"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <td class="px-4 py-2">
                <span class="font-semibold">{{
                  m.persona?.nombre ?? "—"
                }}</span>
                <span
                  v-if="m.ultimo_motivo"
                  class="block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ m.ultimo_motivo }}</span
                >
              </td>
              <td class="px-4 py-2">
                <span
                  class="tu-badge"
                  :style="
                    m.estado === 'suspendido'
                      ? {
                          background: 'var(--error-suave)',
                          color: 'var(--error)',
                        }
                      : {
                          background: 'var(--aviso-suave)',
                          color: 'var(--aviso)',
                        }
                  "
                >
                  {{ $t(`cobranza.estados.${m.estado}`) }}
                </span>
              </td>
              <td class="px-4 py-2 text-right hidden sm:table-cell">
                {{ m.intentos }}
              </td>
              <td
                class="px-4 py-2 hidden md:table-cell"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ fecha(m.gracia_hasta) }}
              </td>
              <td class="px-4 py-2 text-right">
                <button
                  v-if="puedeRegularizar && m.acuerdo"
                  class="tu-enlace text-sm"
                  type="button"
                  :disabled="accionando === m.id"
                  @click="regularizar(m)"
                >
                  {{
                    accionando === m.id
                      ? $t("cobranza.regularizando")
                      : $t("cobranza.regularizar")
                  }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagos / reembolsos -->
      <h2 class="mt-8 font-light text-lg">{{ $t("cobranza.pagos") }}</h2>
      <p
        v-if="pagos.length === 0"
        class="mt-3 tu-card p-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("cobranza.sinPagos") }}
      </p>
      <div v-else class="mt-3 tu-card overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
              <th class="px-4 py-2 font-medium">
                {{ $t("cobranza.colFecha") }}
              </th>
              <th class="px-4 py-2 font-medium">
                {{ $t("cobranza.colAlumno") }}
              </th>
              <th class="px-4 py-2 font-medium text-right">
                {{ $t("cobranza.colMonto") }}
              </th>
              <th class="px-4 py-2 font-medium">
                {{ $t("cobranza.colEstado") }}
              </th>
              <th class="px-4 py-2 font-medium text-right"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="p in pagos" :key="p.id">
              <tr class="border-t" :style="{ borderColor: 'var(--borde)' }">
                <td
                  class="px-4 py-2 whitespace-nowrap"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ fechaHora(p.fecha) }}
                </td>
                <td class="px-4 py-2 font-semibold">{{ p.persona ?? "—" }}</td>
                <td class="px-4 py-2 text-right">
                  {{ dinero(p.monto_minor, p.moneda) }}
                  <button
                    v-if="p.reembolsado_minor > 0"
                    type="button"
                    class="block ml-auto text-xs underline-offset-2 hover:underline"
                    :style="{ color: 'var(--texto-suave)' }"
                    :aria-expanded="reembolsosDe === p.id"
                    :title="
                      reembolsosDe === p.id
                        ? $t('reembolsosPago.ocultar')
                        : $t('reembolsosPago.ver')
                    "
                    @click="verReembolsos(p)"
                  >
                    −{{ dinero(p.reembolsado_minor, p.moneda) }}
                  </button>
                </td>
                <td class="px-4 py-2">
                  <span
                    class="tu-badge"
                    :class="
                      p.estado === 'aprobado'
                        ? 'tu-badge-exito'
                        : 'tu-badge-aviso'
                    "
                    >{{ $t(`cobranza.pagoEstados.${p.estado}`) }}</span
                  >
                </td>
                <td class="px-4 py-2 text-right">
                  <button
                    v-if="puedeReembolsar && p.reembolsable_minor > 0"
                    class="tu-enlace text-sm"
                    type="button"
                    @click="abrirReembolso(p)"
                  >
                    {{ $t("cobranza.reembolsar") }}
                  </button>
                </td>
              </tr>
              <tr v-if="reembolsosDe === p.id">
                <td colspan="5" class="px-4 pb-3">
                  <div
                    class="rounded-xl border px-4 text-xs"
                    :style="{
                      borderColor: 'var(--borde)',
                      background: 'var(--fondo)',
                    }"
                  >
                    <div
                      v-for="r in reembolsos"
                      :key="r.id"
                      class="flex items-center justify-between gap-3 border-t py-2 first:border-t-0"
                      :style="{ borderColor: 'var(--borde)' }"
                    >
                      <span class="min-w-0">
                        <span class="block font-medium">{{
                          r.motivo ?? "—"
                        }}</span>
                        <span
                          class="block"
                          :style="{ color: 'var(--texto-suave)' }"
                          >{{ fechaHora(r.fecha) }}
                          <template v-if="r.actor">
                            ·
                            {{
                              $t("reembolsosPago.por", { actor: r.actor })
                            }}</template
                          >
                          <template v-if="r.via">
                            ·
                            {{ $t(`reembolsosPago.via.${r.via}`) }}</template
                          >
                          <template v-if="r.revirtio_creditos">
                            ·
                            {{
                              $t("reembolsosPago.creditosRevertidos")
                            }}</template
                          ></span
                        >
                      </span>
                      <span class="flex items-center gap-2 shrink-0">
                        <span class="font-semibold tabular-nums">{{
                          dinero(r.monto_minor, r.moneda)
                        }}</span>
                        <span
                          class="tu-badge"
                          :class="
                            r.estado === 'aprobado'
                              ? 'tu-badge-exito'
                              : 'tu-badge-aviso'
                          "
                          >{{ $t(`reembolsosPago.estados.${r.estado}`) }}</span
                        >
                      </span>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <!-- Próximas renovaciones (cobro recurrente) -->
      <h2 class="mt-8 font-light text-lg">{{ $t("cobranza.renovaciones") }}</h2>
      <p
        v-if="avisoRenovacion"
        class="mt-2 text-sm"
        role="status"
        :style="{ color: 'var(--exito)' }"
      >
        {{ avisoRenovacion }}
      </p>
      <p
        v-if="suscripciones.length === 0"
        class="mt-3 tu-card p-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("cobranza.sinRenovaciones") }}
      </p>
      <div v-else class="mt-3 tu-card overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
              <th class="px-4 py-2 font-medium">
                {{ $t("cobranza.colAlumno") }}
              </th>
              <th class="px-4 py-2 font-medium hidden sm:table-cell">
                {{ $t("cobranza.colMembresia") }}
              </th>
              <th class="px-4 py-2 font-medium text-right">
                {{ $t("cobranza.colMonto") }}
              </th>
              <th class="px-4 py-2 font-medium">
                {{ $t("cobranza.colProxima") }}
              </th>
              <th class="px-4 py-2 font-medium">
                {{ $t("pagoAutomatico.colCobro") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="s in suscripciones"
              :key="s.id"
              class="border-t"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <td class="px-4 py-2 font-semibold">{{ s.persona ?? "—" }}</td>
              <td
                class="px-4 py-2 hidden sm:table-cell"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ s.producto ?? "—" }}
              </td>
              <td class="px-4 py-2 text-right">
                {{
                  s.precio_minor !== null
                    ? dinero(s.precio_minor, s.moneda ?? "MXN")
                    : "—"
                }}
              </td>
              <td class="px-4 py-2">
                {{ fecha(s.proxima_cobro_en) }}
                <span
                  v-if="s.estado !== 'activo'"
                  class="tu-badge ml-1"
                  :style="{
                    background: 'var(--error-suave)',
                    color: 'var(--error)',
                  }"
                  >{{ $t(`cobranza.estados.${s.estado}`, s.estado) }}</span
                >
              </td>
              <td class="px-4 py-2">
                <template v-if="s.pago_automatico">
                  <span class="tu-badge tu-badge-exito">{{
                    $t("pagoAutomatico.tarjeta", {
                      marca: marca(s.pago_automatico.marca),
                      ultimos4: s.pago_automatico.ultimos4 ?? "····",
                    })
                  }}</span>
                  <button
                    v-if="puedeRegularizar"
                    type="button"
                    class="tu-enlace ml-2 text-xs"
                    :disabled="accionando !== null"
                    @click="quitarPagoAutomatico(s)"
                  >
                    {{ $t("pagoAutomatico.quitar") }}
                  </button>
                  <p
                    v-if="s.pago_automatico.error"
                    class="text-xs"
                    style="color: var(--error)"
                  >
                    {{ s.pago_automatico.error }}
                  </p>
                </template>
                <template v-else>
                  <span :style="{ color: 'var(--texto-suave)' }">{{
                    $t("pagoAutomatico.pagoManual")
                  }}</span>
                  <button
                    v-if="puedeRegularizar && pagoAutomaticoDisponible"
                    type="button"
                    class="tu-enlace ml-2 text-xs"
                    :disabled="accionando !== null"
                    @click="invitarPagoAutomatico(s)"
                  >
                    {{ $t("pagoAutomatico.invitar") }}
                  </button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Modal de reembolso -->
    <div
      v-if="reembolsando"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
    >
      <div class="absolute inset-0 bg-black/50" @click="reembolsando = null" />
      <div
        class="relative w-full max-w-sm tu-card p-6"
        :style="{ background: 'var(--superficie)' }"
      >
        <div class="flex items-start justify-between gap-3">
          <h3 class="text-lg font-light">
            {{ $t("cobranza.reembolso.titulo") }}
          </h3>
          <button
            type="button"
            class="tu-icono-btn shrink-0"
            :aria-label="$t('comun.cerrar')"
            @click="reembolsando = null"
          >
            <span aria-hidden="true">✕</span>
          </button>
        </div>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ reembolsando.persona ?? "—" }} ·
          {{
            $t("cobranza.reembolso.reembolsable", {
              monto: dinero(
                reembolsando.reembolsable_minor,
                reembolsando.moneda,
              ),
            })
          }}
        </p>
        <form class="mt-4 space-y-3" @submit.prevent="reembolsar">
          <div>
            <label class="tu-label" for="rm">{{
              $t("cobranza.reembolso.monto")
            }}</label>
            <input
              id="rm"
              v-model="rMonto"
              class="tu-input"
              type="number"
              min="0"
              step="0.01"
              required
            />
          </div>
          <div>
            <label class="tu-label" for="rmt">{{
              $t("cobranza.reembolso.motivo")
            }}</label>
            <input
              id="rmt"
              v-model="rMotivo"
              class="tu-input"
              required
              :placeholder="$t('cobranza.reembolso.motivoPh')"
            />
          </div>
          <label class="flex items-center justify-between gap-3 text-sm">
            <span>
              {{ $t("cobranza.reembolso.revertir") }}
              <span
                class="block text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ $t("cobranza.reembolso.revertirAyuda") }}</span
              >
            </span>
            <input v-model="rRevertir" type="checkbox" class="h-5 w-5" />
          </label>
          <label
            v-if="PASARELAS_EN_LINEA.includes(reembolsando.proveedor ?? '')"
            class="flex items-center justify-between gap-3 text-sm"
          >
            <span>
              {{ $t("reembolsosPago.manual") }}
              <span
                class="block text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ $t("reembolsosPago.manualAyuda") }}</span
              >
            </span>
            <input v-model="rManual" type="checkbox" class="h-5 w-5" />
          </label>
          <button
            class="tu-btn tu-btn-primario w-full"
            type="submit"
            :disabled="rProcesando || rMotivo.trim() === ''"
          >
            {{
              rProcesando
                ? $t("cobranza.reembolso.procesando")
                : $t("cobranza.reembolso.confirmar")
            }}
          </button>
        </form>
      </div>
    </div>
  </section>
</template>
