<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";

import AvisoTono from "@/components/AvisoTono.vue";
import EstadoVacioListado from "@/components/EstadoVacioListado.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import RangoFechas from "@/components/RangoFechas.vue";
import { api, mensajeDeError } from "@/lib/api";
import { descargarDeApi } from "@/lib/descargas";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Mis facturas (GET /mi/facturas, ADR 0212 y 0228): las que el negocio le timbró, con
 * su PDF y su XML, por páginas y por periodo (días del negocio en que se timbraron).
 * También le llegan por correo. Mientras carga lo dice; si falla, deja reintentar; sin
 * facturas lo dice claro. Si el negocio no factura y no hay ninguna, no aparece.
 */
interface Factura {
  id: string;
  tipo: string;
  uuid: string | null;
  total_minor: number;
  moneda: string;
  receptor_rfc: string | null;
  timbrada_en: string | null;
  cancelada: boolean;
}
interface Meta {
  page: number;
  ultima_pagina: number;
  total: number;
  per_page: number;
  disponible?: boolean;
}

const props = withDefaults(defineProps<{ porPagina?: number }>(), {
  porPagina: 10,
});

const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const facturas = ref<Factura[]>([]);
const meta = ref<Meta | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const pagina = ref(1);
const desde = ref("");
const hasta = ref("");
const filtrado = computed(() => desde.value !== "" || hasta.value !== "");

let pedido = 0;
async function cargar(n = 1): Promise<void> {
  const mio = ++pedido;
  // La que se pide: «Reintentar» vuelve a esa si no se pudo cargar.
  pagina.value = n;
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Factura[]; meta?: Meta }>(
      `${base.value}/mi/facturas`,
      {
        params: {
          page: n,
          per_page: props.porPagina,
          ...(desde.value !== "" ? { desde: desde.value } : {}),
          ...(hasta.value !== "" ? { hasta: hasta.value } : {}),
        },
      },
    );
    if (mio !== pedido) {
      return;
    }
    facturas.value = data?.data ?? [];
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

// Otro periodo: desde la primera página.
watch([desde, hasta], () => void cargar(1));

function restablecer(): void {
  desde.value = "";
  hasta.value = "";
}

// Si el negocio no factura y no tiene ninguna, la sección no aparece; mientras carga,
// si falló o si se filtró, sí.
const visible = computed(
  () =>
    cargando.value ||
    error.value !== null ||
    filtrado.value ||
    facturas.value.length > 0 ||
    meta.value?.disponible === true,
);
const conPeriodo = computed(
  () => filtrado.value || (meta.value?.total ?? 0) > 0,
);

async function descargar(f: Factura, formato: "pdf" | "xml"): Promise<void> {
  try {
    await descargarDeApi(
      `${base.value}/mi/facturas/${f.id}/${formato}`,
      `factura-${f.uuid ?? f.id}.${formato}`,
    );
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

function cuando(iso: string | null): string {
  return iso === null
    ? ""
    : new Intl.DateTimeFormat("es-MX", {
        dateStyle: "medium",
        timeZone: sesion.zonaHoraria,
      }).format(new Date(iso));
}

defineExpose({ cargar });
onMounted(() => void cargar(1));
</script>

<template>
  <div
    v-if="visible"
    class="tu-card overflow-hidden"
    data-prueba="mis-facturas"
  >
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5">
      <h2 class="font-semibold">{{ $t("cfdi.mias.titulo") }}</h2>
      <RangoFechas
        v-if="conPeriodo"
        v-model:desde="desde"
        v-model:hasta="hasta"
        atajos
        :zona="sesion.zonaHoraria"
        :etiqueta="$t('cfdi.mias.periodo')"
        prueba="mis-facturas"
      />
    </div>

    <p
      v-if="cargando && facturas.length === 0 && error === null"
      class="px-5 py-4 text-sm"
      role="status"
      data-prueba="mis-facturas-cargando"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <AvisoTono
      v-else-if="error"
      tono="peligro"
      class="mx-5 my-4"
      role="alert"
      :titulo="$t('cfdi.mias.error')"
      data-prueba="mis-facturas-error"
    >
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          data-prueba="mis-facturas-reintentar"
          @click="cargar(pagina)"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <EstadoVacioListado
      v-else-if="facturas.length === 0"
      :filtrado="filtrado"
      :mensaje="$t('cfdi.mias.vacio')"
      data-prueba="mis-facturas-vacio"
      @restablecer="restablecer"
    />
    <ul v-else class="mt-3" :aria-busy="cargando || undefined">
      <li
        v-for="f in facturas"
        :key="f.id"
        class="mf-fila"
        data-prueba="mi-factura"
      >
        <span class="min-w-0 flex-1">
          <span class="block font-medium tabular-nums">{{
            dineroDelPais(f.total_minor, f.moneda, sesion.pais)
          }}</span>
          <span
            class="block truncate text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            >{{
              [
                cuando(f.timbrada_en),
                f.receptor_rfc,
                f.cancelada ? $t("cfdi.mias.cancelada") : null,
              ]
                .filter(Boolean)
                .join(" · ")
            }}</span
          >
        </span>
        <span class="flex shrink-0 gap-2">
          <button
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            :aria-label="$t('cfdi.descargarPdf', { uuid: f.uuid ?? f.id })"
            @click="descargar(f, 'pdf')"
          >
            {{ $t("cfdi.pdf") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            :aria-label="$t('cfdi.descargarXml', { uuid: f.uuid ?? f.id })"
            @click="descargar(f, 'xml')"
          >
            {{ $t("cfdi.xml") }}
          </button>
        </span>
      </li>
    </ul>
    <PaginacionListado
      v-if="meta && meta.ultima_pagina > 1 && error === null"
      :page="meta.page"
      :ultima-pagina="meta.ultima_pagina"
      :total="meta.total"
      :per-page="meta.per_page"
      @ir="cargar"
    />
  </div>
</template>

<style scoped>
.mf-fila {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.85rem 1.25rem;
  border-top: 1px solid var(--borde);
}
</style>
