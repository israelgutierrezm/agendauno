<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import { api, mensajeDeError } from "@/lib/api";
import { descargarDeApi } from "@/lib/descargas";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Mis facturas (GET /mi/facturas, ADR 0212): las que el negocio le timbró, con su PDF y
 * su XML. También le llegan por correo. Sin facturas, no aparece.
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

const sesion = useSesionTenantStore();
const toast = useToastStore();
const facturas = ref<Factura[]>([]);
const base = computed(() => `/api/v1/app/${sesion.slug}`);

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Factura[] }>(
      `${base.value}/mi/facturas`,
    );
    facturas.value = data.data;
  } catch {
    // Sin el listado, la sección no aparece (no es «sin facturas»).
    facturas.value = [];
  }
}

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
    : new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
        new Date(iso),
      );
}

onMounted(cargar);
</script>

<template>
  <div
    v-if="facturas.length > 0"
    class="tu-card overflow-hidden"
    data-prueba="mis-facturas"
  >
    <h2 class="px-5 pt-5 font-semibold">{{ $t("cfdi.mias.titulo") }}</h2>
    <ul class="mt-3">
      <li v-for="f in facturas" :key="f.id" class="mf-fila">
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
