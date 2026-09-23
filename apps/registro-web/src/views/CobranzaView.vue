<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

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
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeRegularizar = computed(() => sesion.puede("ordenes.gestionar"));
const puedeReembolsar = computed(() => sesion.puede("pagos.reembolsar"));

const morosos = ref<Moroso[]>([]);
const pagos = ref<Pago[]>([]);
const suscripciones = ref<Suscripcion[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const accionando = ref<string | null>(null);

// Modal de reembolso.
const reembolsando = ref<Pago | null>(null);
const rMonto = ref("");
const rMotivo = ref("");
const rRevertir = ref(true);
const rProcesando = ref(false);

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
      api.get<{ data: Suscripcion[] }>(`${base.value}/suscripciones`),
    ]);
    morosos.value = d.data.data;
    pagos.value = p.data.data;
    suscripciones.value = s.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
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

function abrirReembolso(p: Pago): void {
  reembolsando.value = p;
  rMonto.value = String(p.reembolsable_minor / 100);
  rMotivo.value = "";
  rRevertir.value = true;
}

async function reembolsar(): Promise<void> {
  const p = reembolsando.value;
  if (p === null || rMotivo.value.trim() === "") {
    return;
  }
  rProcesando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/pagos/${p.id}/reembolsos`, {
      monto_minor: Math.round(Number(rMonto.value) * 100),
      motivo: rMotivo.value.trim(),
      revertir_creditos: rRevertir.value,
    });
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
        <h2 class="font-bold text-lg">{{ $t("cobranza.morosos") }}</h2>
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
      <h2 class="mt-8 font-bold text-lg">{{ $t("cobranza.pagos") }}</h2>
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
            <tr
              v-for="p in pagos"
              :key="p.id"
              class="border-t"
              :style="{ borderColor: 'var(--borde)' }"
            >
              <td
                class="px-4 py-2 whitespace-nowrap"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ fechaHora(p.fecha) }}
              </td>
              <td class="px-4 py-2 font-semibold">{{ p.persona ?? "—" }}</td>
              <td class="px-4 py-2 text-right">
                {{ dinero(p.monto_minor, p.moneda) }}
                <span
                  v-if="p.reembolsado_minor > 0"
                  class="block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >−{{ dinero(p.reembolsado_minor, p.moneda) }}</span
                >
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
          </tbody>
        </table>
      </div>
      <!-- Próximas renovaciones (cobro recurrente) -->
      <h2 class="mt-8 font-bold text-lg">{{ $t("cobranza.renovaciones") }}</h2>
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
          <h3 class="text-lg font-bold">
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
