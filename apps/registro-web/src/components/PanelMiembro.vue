<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Resumen {
  id: string;
  nombre_completo: string;
  email: string | null;
  tipo: string;
  activo: boolean;
  asistencias: number;
  primera_vez: boolean;
  saldo_creditos: number;
  saldo_unidades: number;
  membresia: { estado: string; valido_hasta: string | null };
  adeudo: boolean;
  documentos_pendientes: number;
  proxima_reserva: {
    clase: string | null;
    inicia_en: string;
    zona_horaria: string | null;
  } | null;
  alertas: string[];
}

const props = defineProps<{ personaId: string; nombre: string }>();
const emit = defineEmits<{ (e: "cerrar"): void }>();

const { t } = useI18n();
const sesionStore = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesionStore.slug}`);
const puedeVender = computed(() => sesionStore.puede("ordenes.gestionar"));

const resumen = ref<Resumen | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

// Venta rápida + cobro en ventanilla: crea la orden y la liquida (fulfillment).
interface Producto {
  id: string;
  nombre: string;
  precio_minor: number;
  moneda: string;
}
const vendiendo = ref(false);
const productos = ref<Producto[]>([]);
const productoSel = ref("");
const metodo = ref("efectivo");
const procesando = ref(false);
const avisoVenta = ref<string | null>(null);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

async function abrirVenta(): Promise<void> {
  vendiendo.value = true;
  avisoVenta.value = null;
  if (productos.value.length === 0) {
    try {
      const { data } = await api.get<{ data: Producto[] }>(
        `${base.value}/productos`,
      );
      productos.value = data.data;
    } catch (e) {
      error.value = mensajeDeError(e);
    }
  }
}

async function vender(): Promise<void> {
  if (productoSel.value === "") {
    return;
  }
  procesando.value = true;
  error.value = null;
  avisoVenta.value = null;
  try {
    const { data: orden } = await api.post<{ data: { id: string } }>(
      `${base.value}/ordenes`,
      {
        comprador_id: props.personaId,
        items: [{ producto_id: productoSel.value, cantidad: 1 }],
      },
    );
    await api.post(`${base.value}/ordenes/${orden.data.id}/liquidar`, {
      metodo: metodo.value,
    });
    avisoVenta.value = t("recepcion.miembro.vendido");
    vendiendo.value = false;
    productoSel.value = "";
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    procesando.value = false;
  }
}

// Chip ámbar para "por vencer"; roja para el resto de alertas.
function colorAlerta(codigo: string): string {
  return codigo === "membresia_por_vencer" ? "var(--aviso)" : "var(--error)";
}

function fecha(iso: string, zona: string | null): string {
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "short",
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: zona ?? undefined,
  }).format(new Date(iso));
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Resumen }>(
      `${base.value}/miembros/${props.personaId}/resumen`,
    );
    resumen.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

watch(() => props.personaId, cargar, { immediate: true });
</script>

<template>
  <div class="fixed inset-0 z-50 flex justify-end">
    <div class="absolute inset-0 bg-black/40" @click="emit('cerrar')" />
    <aside
      class="relative flex h-full w-full max-w-md flex-col overflow-y-auto"
      :style="{ background: 'var(--superficie)', boxShadow: 'var(--sombra)' }"
    >
      <header
        class="sticky top-0 z-10 border-b px-5 py-4"
        :style="{
          background: 'var(--superficie)',
          borderColor: 'var(--borde)',
        }"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-lg font-semibold truncate">
              {{ resumen?.nombre_completo ?? nombre }}
            </p>
            <p
              v-if="resumen?.email"
              class="text-sm truncate"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ resumen.email }}
            </p>
          </div>
          <button
            type="button"
            class="tu-icono-btn shrink-0"
            :aria-label="$t('recepcion.panel.cerrar')"
            @click="emit('cerrar')"
          >
            <IconoNav nombre="cerrar" :tam="18" />
          </button>
        </div>
      </header>

      <div class="flex-1 px-5 py-4">
        <p v-if="error" class="mb-3 text-sm" style="color: var(--error)">
          {{ error }}
        </p>
        <p
          v-if="cargando"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>

        <template v-else-if="resumen">
          <!-- Alertas -->
          <div class="flex flex-wrap gap-2">
            <span
              v-if="resumen.alertas.length === 0"
              class="tu-badge tu-badge-exito"
              >{{ $t("recepcion.miembro.sinAlertas") }}</span
            >
            <span
              v-for="a in resumen.alertas"
              :key="a"
              class="inline-flex items-center gap-1.5 text-sm font-medium"
              :style="{ color: colorAlerta(a) }"
            >
              <span
                class="h-1.5 w-1.5 rounded-full"
                :style="{ background: colorAlerta(a) }"
                aria-hidden="true"
              />{{ $t(`recepcion.alertas.${a}`) }}</span
            >
          </div>

          <!-- Datos operativos -->
          <dl class="mt-4 space-y-2.5 text-sm">
            <div class="flex items-center justify-between gap-3">
              <dt :style="{ color: 'var(--texto-suave)' }">
                {{ $t("recepcion.miembro.membresia") }}
              </dt>
              <dd class="text-right font-medium">
                {{ $t(`recepcion.membresia.${resumen.membresia.estado}`) }}
                <span
                  v-if="resumen.membresia.valido_hasta"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  · {{ resumen.membresia.valido_hasta }}</span
                >
              </dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt :style="{ color: 'var(--texto-suave)' }">
                {{ $t("recepcion.miembro.saldo") }}
              </dt>
              <dd class="text-right font-medium">
                {{
                  $t("recepcion.miembro.creditos", {
                    n: resumen.saldo_creditos,
                  })
                }}
              </dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt :style="{ color: 'var(--texto-suave)' }">
                {{ $t("recepcion.miembro.proxima") }}
              </dt>
              <dd class="text-right font-medium">
                <template v-if="resumen.proxima_reserva">
                  {{ resumen.proxima_reserva.clase ?? "—" }}
                  <span :style="{ color: 'var(--texto-suave)' }">
                    ·
                    {{
                      fecha(
                        resumen.proxima_reserva.inicia_en,
                        resumen.proxima_reserva.zona_horaria,
                      )
                    }}</span
                  >
                </template>
                <span v-else :style="{ color: 'var(--texto-suave)' }">{{
                  $t("recepcion.miembro.sinProxima")
                }}</span>
              </dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt :style="{ color: 'var(--texto-suave)' }">
                {{ $t("recepcion.miembro.asistencias") }}
              </dt>
              <dd class="text-right font-medium">
                {{ resumen.asistencias }}
                <span
                  v-if="resumen.primera_vez"
                  class="ml-1 text-xs font-medium"
                  :style="{ color: 'var(--aviso)' }"
                  >{{ $t("agenda.roster.primeraVez") }}</span
                >
              </dd>
            </div>
            <div
              v-if="resumen.documentos_pendientes > 0"
              class="flex items-center justify-between gap-3"
            >
              <dt :style="{ color: 'var(--texto-suave)' }">
                {{ $t("recepcion.miembro.documentos") }}
              </dt>
              <dd
                class="text-right font-medium"
                :style="{ color: 'var(--aviso)' }"
              >
                {{ resumen.documentos_pendientes }}
              </dd>
            </div>
          </dl>

          <RouterLink
            :to="{ name: 'ficha-miembro', params: { id: personaId } }"
            class="tu-enlace mt-4 inline-block text-sm"
            @click="emit('cerrar')"
            >{{ $t("recepcion.miembro.verFicha") }} →</RouterLink
          >

          <!-- Venta rápida + cobro en ventanilla -->
          <div
            v-if="puedeVender"
            class="mt-5 border-t pt-4"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <button
              v-if="!vendiendo"
              type="button"
              class="tu-btn tu-btn-primario w-full text-sm"
              @click="abrirVenta"
            >
              {{ $t("recepcion.miembro.vender") }}
            </button>
            <div v-else class="space-y-2">
              <select v-model="productoSel" class="tu-input">
                <option value="" disabled>
                  {{ $t("recepcion.miembro.elegirProducto") }}
                </option>
                <option v-for="p in productos" :key="p.id" :value="p.id">
                  {{ p.nombre }} — {{ dinero(p.precio_minor, p.moneda) }}
                </option>
              </select>
              <select v-model="metodo" class="tu-input">
                <option value="efectivo">
                  {{ $t("recepcion.miembro.efectivo") }}
                </option>
                <option value="transferencia">
                  {{ $t("recepcion.miembro.transferencia") }}
                </option>
              </select>
              <div class="flex gap-2">
                <button
                  type="button"
                  class="tu-btn tu-btn-primario flex-1 text-sm"
                  :disabled="procesando || productoSel === ''"
                  @click="vender"
                >
                  {{
                    procesando
                      ? $t("recepcion.miembro.cobrando")
                      : $t("recepcion.miembro.cobrar")
                  }}
                </button>
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma text-sm"
                  :disabled="procesando"
                  @click="vendiendo = false"
                >
                  {{ $t("comun.cancelar") }}
                </button>
              </div>
            </div>
            <p
              v-if="avisoVenta"
              class="mt-2 text-sm"
              style="color: var(--exito)"
            >
              {{ avisoVenta }}
            </p>
          </div>
        </template>
      </div>
    </aside>
  </div>
</template>
