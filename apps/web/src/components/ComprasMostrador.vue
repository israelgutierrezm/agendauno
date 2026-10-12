<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Lo que compró en el mostrador del negocio (GET /mi/compras-mostrador, ADR 0145): sus
 * tickets, del más reciente al más antiguo, con lo que llevó, cuándo, dónde y cuánto
 * pagó. Para alumnos y para el equipo. Si nunca compró a su nombre, no se muestra.
 */
interface Ticket {
  id: string;
  folio: string;
  creado_en: string | null;
  sucursal: string | null;
  lineas: {
    articulo: string | null;
    cantidad: number;
    extras: string[];
  }[];
  propina_minor: number;
  cobrado_minor: number;
  moneda: string;
  anulada: boolean;
}
interface Meta {
  page: number;
  ultima_pagina: number;
  total: number;
  per_page: number;
}

const props = withDefaults(defineProps<{ porPagina?: number }>(), {
  porPagina: 5,
});

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const tickets = ref<Ticket[]>([]);
const meta = ref<Meta | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

let pedido = 0;
async function cargar(pagina = 1): Promise<void> {
  const mio = ++pedido;
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Ticket[]; meta?: Meta }>(
      `${base.value}/mi/compras-mostrador`,
      { params: { page: pagina, per_page: props.porPagina } },
    );
    if (mio !== pedido) {
      return;
    }
    tickets.value = data?.data ?? [];
    meta.value = data?.meta ?? null;
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

// Sin compras (ya cargado), la sección no aparece.
const visible = computed(
  () => error.value !== null || cargando.value || tickets.value.length > 0,
);

function dinero(minor: number, moneda: string): string {
  return dineroDelPais(minor, moneda, sesion.pais);
}
function cuando(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        day: "numeric",
        month: "short",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
        timeZone: sesion.zonaHoraria,
      }).format(new Date(iso))
    : "";
}
function productos(t: Ticket): string {
  return t.lineas
    .map(
      (l) =>
        `${l.cantidad} × ${l.articulo ?? ""}` +
        (l.extras.length > 0 ? ` (${l.extras.join(", ")})` : ""),
    )
    .join(", ");
}

defineExpose({ cargar });
onMounted(() => void cargar());
</script>

<template>
  <div
    v-if="visible"
    class="tu-card overflow-hidden"
    data-prueba="compras-mostrador"
  >
    <h2 class="px-5 pt-5 font-semibold">
      {{ $t("portal.comprasMostrador.titulo") }}
    </h2>
    <p v-if="error" class="px-5 py-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-else-if="cargando && tickets.length === 0"
      class="px-5 py-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <ul v-else class="mt-3">
      <li
        v-for="t in tickets"
        :key="t.id"
        class="cm-fila"
        data-prueba="compra-mostrador"
      >
        <span class="min-w-0 flex-1">
          <span class="block font-medium">{{ productos(t) || "—" }}</span>
          <span
            class="block truncate text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            >{{
              [
                cuando(t.creado_en),
                t.sucursal,
                $t("portal.comprasMostrador.folio", { folio: t.folio }),
              ]
                .filter(Boolean)
                .join(" · ")
            }}</span
          >
        </span>
        <span class="flex shrink-0 flex-col items-end gap-1">
          <span
            class="font-medium tabular-nums"
            :class="{ 'line-through': t.anulada }"
            >{{ dinero(t.cobrado_minor, t.moneda) }}</span
          >
          <span
            v-if="t.anulada"
            class="tu-pildora text-xs"
            :style="{ '--tono': 'var(--texto-suave)' }"
            >{{ $t("portal.comprasMostrador.anulada") }}</span
          >
          <span
            v-else-if="t.propina_minor > 0"
            class="text-xs"
            :style="{ color: 'var(--texto-suave)' }"
            >{{
              $t("portal.comprasMostrador.propina", {
                monto: dinero(t.propina_minor, t.moneda),
              })
            }}</span
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
.cm-fila {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 1.25rem;
  border-top: 1px solid var(--borde);
}
</style>
