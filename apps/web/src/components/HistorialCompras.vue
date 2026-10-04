<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
import { cuandoCorto, dinero, type Orden } from "@/lib/miCuenta";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Historial de compras y cobros del portal (GET /mi/ordenes sin lo pendiente,
 * paginado): lo que debe se ve aparte, completo. Cada cobro dice qué se pagó; si es
 * una cita, el servicio, con quién y cuándo.
 */
interface Meta {
  page: number;
  ultima_pagina: number;
  total: number;
  per_page: number;
}
const POR_PAGINA = 10;

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const ordenes = ref<Orden[]>([]);
const meta = ref<Meta | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

let pedido = 0;
async function cargar(pagina = 1): Promise<void> {
  const mio = ++pedido;
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Orden[]; meta?: Meta }>(
      `${base.value}/mi/ordenes`,
      {
        params: { page: pagina, per_page: POR_PAGINA, excluir_pendientes: 1 },
      },
    );
    if (mio !== pedido) {
      return;
    }
    ordenes.value = data.data;
    meta.value = data.meta ?? null;
  } catch (e) {
    if (mio === pedido) {
      error.value = mensajeDeError(e);
    }
  } finally {
    if (mio === pedido) {
      cargando.value = false;
    }
  }
}

function fecha(iso: string | null | undefined): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        day: "numeric",
        month: "short",
        year: "numeric",
      }).format(new Date(iso))
    : "";
}
// Pagada en verde; cancelada o devuelta, en gris.
function tono(estado: string): string {
  return estado === "pagada" ? "var(--exito)" : "var(--texto-suave)";
}
// Segunda línea: la cita (con quién y cuándo) o la fecha de la compra.
function detalle(o: Orden): string {
  if (o.sesion) {
    return [
      o.sesion.profesional
        ? t("portal.pagos.con", { nombre: o.sesion.profesional })
        : null,
      cuandoCorto(o.sesion.inicia_en, o.sesion.zona_horaria),
      o.sesion.sucursal,
    ]
      .filter(Boolean)
      .join(" · ");
  }
  return fecha(o.pagada_en ?? o.fecha);
}

defineExpose({ cargar });
onMounted(() => void cargar());
</script>

<template>
  <div class="tu-card overflow-hidden" data-prueba="historial-compras">
    <h2 class="px-5 pt-5 font-semibold">{{ $t("portal.pagos.historial") }}</h2>
    <p v-if="error" class="px-5 py-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-else-if="cargando && ordenes.length === 0"
      class="px-5 py-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <p
      v-else-if="ordenes.length === 0"
      class="px-5 py-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("portal.pagos.sinHistorial") }}
    </p>
    <ul v-else class="mt-3">
      <li v-for="o in ordenes" :key="o.id" class="hc-fila">
        <span class="min-w-0 flex-1">
          <span class="block truncate font-medium">{{
            o.concepto || "—"
          }}</span>
          <span
            class="block truncate text-sm first-letter:uppercase"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ detalle(o) }}</span
          >
        </span>
        <span class="flex shrink-0 flex-col items-end gap-1">
          <span class="font-medium tabular-nums">{{
            dinero(o.total_minor, o.moneda)
          }}</span>
          <span
            class="tu-pildora text-xs"
            :style="{ '--tono': tono(o.estado) }"
            >{{ $t(`miCuenta.compras.estados.${o.estado}`) }}</span
          >
        </span>
      </li>
    </ul>
    <PaginacionListado
      v-if="meta && meta.ultima_pagina > 1"
      :page="meta.page"
      :ultima-pagina="meta.ultima_pagina"
      :total="meta.total"
      :per-page="meta.per_page"
      @ir="cargar"
    />
  </div>
</template>

<style scoped>
.hc-fila {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 1.25rem;
  border-top: 1px solid var(--borde);
}
</style>
