<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Corte de caja: los movimientos de dinero de un rango de fechas con quién hizo cada
 * uno (cobros, devoluciones, ventas de mostrador y cancelaciones) y los totales por
 * método y por persona del equipo. Se descarga en CSV.
 */
interface Movimiento {
  fecha: string;
  tipo: "cobro" | "devolucion" | "venta" | "cancelacion";
  monto_minor: number;
  moneda: string;
  metodo: string | null;
  persona: string | null;
  concepto: string;
  quien: string;
  quien_id: string | null;
  referencia: string;
  detalle: string | null;
}
// Totales del rango completo (no dependen de cuántas filas se muestran), por moneda.
interface Totales {
  moneda?: string;
  cobrado_minor: number;
  devuelto_minor: number;
  neto_minor: number;
  // Compras del rango que siguen pendientes de pago.
  por_cobrar_minor?: number;
  por_metodo: Record<string, number>;
  por_usuario: {
    quien: string;
    cobrado_minor: number;
    devuelto_minor: number;
  }[];
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

// «Hoy» del negocio (su zona horaria), no el del navegador.
function hoy(): string {
  return hoyEnNegocio(sesion.zonaHoraria);
}

const desde = ref(hoy());
const hasta = ref(hoy());
const usuario = ref("");
const tipo = ref("");
const movimientos = ref<Movimiento[]>([]);
const totalesPorMoneda = ref<Totales[]>([]);
// La lista muestra los más recientes; los totales y el CSV incluyen todo el rango.
const truncado = ref<number | null>(null);
// Quienes aparecen en el rango (para filtrar por persona del equipo).
const personasEquipo = ref<{ id: string; nombre: string }[]>([]);
const cargando = ref(false);
const descargando = ref(false);
const error = ref<string | null>(null);

function dinero(minor: number, moneda = "MXN"): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
function fechaHora(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "short",
    timeStyle: "short",
  }).format(new Date(iso));
}

function parametros(): Record<string, string | undefined> {
  return {
    desde: desde.value,
    hasta: hasta.value,
    usuario: usuario.value || undefined,
    tipo: tipo.value || undefined,
  };
}

async function cargar(): Promise<void> {
  if (desde.value === "" || hasta.value === "") {
    return;
  }
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: Movimiento[];
      totales: Totales;
      totales_por_moneda?: Totales[];
      meta?: { truncado?: boolean; limite?: number };
    }>(`${base.value}/pagos/movimientos`, { params: parametros() });
    movimientos.value = data.data;
    totalesPorMoneda.value = data.totales_por_moneda ?? [data.totales];
    truncado.value = data.meta?.truncado ? (data.meta.limite ?? null) : null;
    if (usuario.value === "") {
      const vistos = new Map<string, string>();
      for (const m of data.data) {
        if (m.quien_id) {
          vistos.set(m.quien_id, m.quien);
        }
      }
      personasEquipo.value = [...vistos].map(([id, nombre]) => ({
        id,
        nombre,
      }));
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function descargar(): Promise<void> {
  descargando.value = true;
  try {
    const { data } = await api.get<Blob>(`${base.value}/pagos/movimientos`, {
      params: { ...parametros(), formato: "csv" },
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = `movimientos-${desde.value}-a-${hasta.value}.csv`;
    enlace.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    descargando.value = false;
  }
}

watch([desde, hasta, usuario, tipo], () => void cargar());
onMounted(cargar);
</script>

<template>
  <div>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <h2 class="font-medium text-lg">{{ $t("corteCaja.titulo") }}</h2>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma text-sm"
        :disabled="descargando || movimientos.length === 0"
        @click="descargar"
      >
        {{ $t("corteCaja.descargar") }}
      </button>
    </div>

    <div class="mt-3 flex flex-wrap items-end gap-3 text-sm">
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("corteCaja.desde") }}</span>
        <input v-model="desde" type="date" class="tu-input" />
      </label>
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("corteCaja.hasta") }}</span>
        <input v-model="hasta" type="date" class="tu-input" />
      </label>
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("corteCaja.quien") }}</span>
        <select v-model="usuario" class="tu-input">
          <option value="">{{ $t("corteCaja.todos") }}</option>
          <option v-for="p in personasEquipo" :key="p.id" :value="p.id">
            {{ p.nombre }}
          </option>
        </select>
      </label>
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("corteCaja.tipo") }}</span>
        <select v-model="tipo" class="tu-input">
          <option value="">{{ $t("corteCaja.todos") }}</option>
          <option value="cobro">{{ $t("corteCaja.tipos.cobro") }}</option>
          <option value="devolucion">
            {{ $t("corteCaja.tipos.devolucion") }}
          </option>
          <option value="venta">{{ $t("corteCaja.tipos.venta") }}</option>
          <option value="cancelacion">
            {{ $t("corteCaja.tipos.cancelacion") }}
          </option>
        </select>
      </label>
    </div>

    <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <p
      v-if="truncado !== null"
      class="mt-3 text-sm"
      role="status"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("corteCaja.truncado", { n: truncado }) }}
    </p>

    <!-- Totales: una franja por moneda (nunca se suman monedas distintas) -->
    <template
      v-for="totales in totalesPorMoneda"
      :key="totales.moneda ?? 'MXN'"
    >
      <p v-if="totalesPorMoneda.length > 1" class="mt-4 text-sm font-semibold">
        {{ $t("corteCaja.enMoneda", { moneda: totales.moneda }) }}
      </p>
      <div
        class="mt-3 tu-card grid grid-cols-2 sm:grid-cols-4 divide-x divide-[var(--borde)]"
      >
        <div class="p-4">
          <p class="tu-label">{{ $t("corteCaja.cobrado") }}</p>
          <p class="text-xl font-semibold tabular-nums">
            {{ dinero(totales.cobrado_minor, totales.moneda) }}
          </p>
        </div>
        <div class="p-4">
          <p class="tu-label">{{ $t("corteCaja.devuelto") }}</p>
          <p class="text-xl font-semibold tabular-nums">
            {{ dinero(totales.devuelto_minor, totales.moneda) }}
          </p>
        </div>
        <div class="p-4">
          <p class="tu-label">{{ $t("corteCaja.neto") }}</p>
          <p class="text-xl font-semibold tabular-nums">
            {{ dinero(totales.neto_minor, totales.moneda) }}
          </p>
        </div>
        <div class="p-4">
          <p class="tu-label">{{ $t("corteCaja.porCobrar") }}</p>
          <p class="text-xl font-semibold tabular-nums">
            {{ dinero(totales.por_cobrar_minor ?? 0, totales.moneda) }}
          </p>
        </div>
      </div>
      <div
        v-if="movimientos.length > 0"
        class="mt-3 grid gap-3 text-sm sm:grid-cols-2"
      >
        <div class="tu-card p-4">
          <p class="tu-label">{{ $t("corteCaja.porMetodo") }}</p>
          <ul class="mt-1 space-y-1">
            <li
              v-for="(monto, metodo) in totales.por_metodo"
              :key="metodo"
              class="flex justify-between gap-3"
            >
              <span>{{ metodo }}</span>
              <span class="tabular-nums">{{
                dinero(monto, totales.moneda)
              }}</span>
            </li>
          </ul>
        </div>
        <div class="tu-card p-4">
          <p class="tu-label">{{ $t("corteCaja.porPersona") }}</p>
          <ul class="mt-1 space-y-1">
            <li
              v-for="u in totales.por_usuario"
              :key="u.quien"
              class="flex justify-between gap-3"
            >
              <span>{{ u.quien }}</span>
              <span class="tabular-nums">
                {{ dinero(u.cobrado_minor, totales.moneda) }}
                <span
                  v-if="u.devuelto_minor > 0"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  · −{{ dinero(u.devuelto_minor, totales.moneda) }}</span
                >
              </span>
            </li>
          </ul>
        </div>
      </div>
    </template>

    <div class="mt-3 tu-card overflow-hidden">
      <p
        v-if="cargando"
        class="p-5 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>
      <p
        v-else-if="movimientos.length === 0"
        class="p-5 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("corteCaja.vacio") }}
      </p>
      <table v-else class="tu-tabla">
        <thead>
          <tr>
            <th>{{ $t("corteCaja.fecha") }}</th>
            <th>
              {{ $t("corteCaja.movimiento") }}
            </th>
            <th class="hidden sm:table-cell">
              {{ $t("corteCaja.quien") }}
            </th>
            <th class="text-right">
              {{ $t("corteCaja.monto") }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in movimientos" :key="`${m.tipo}-${m.referencia}`">
            <td
              class="whitespace-nowrap"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ fechaHora(m.fecha) }}
            </td>
            <td>
              <span class="font-medium">{{
                t(`corteCaja.tipos.${m.tipo}`)
              }}</span>
              <span :style="{ color: 'var(--texto-suave)' }">
                · {{ m.persona ? `${m.persona} · ` : "" }}{{ m.concepto }}</span
              >
              <span
                v-if="m.detalle && m.tipo !== 'cobro'"
                class="block text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ m.detalle }}</span
              >
              <span
                class="block text-xs sm:hidden"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ m.quien }}</span
              >
            </td>
            <td class="hidden sm:table-cell">{{ m.quien }}</td>
            <td
              class="text-right tabular-nums whitespace-nowrap"
              :style="{
                color:
                  m.monto_minor < 0
                    ? 'var(--error)'
                    : m.tipo === 'cancelacion'
                      ? 'var(--texto-suave)'
                      : undefined,
              }"
            >
              {{
                m.tipo === "cancelacion" ? "—" : dinero(m.monto_minor, m.moneda)
              }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
