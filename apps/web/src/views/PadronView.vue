<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Fila {
  id: string;
  nombre_completo: string;
  email: string | null;
  sucursal: string | null;
  alta: string | null;
  razon: string;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const filas = ref<Fila[]>([]);
const total = ref(0);
// Por páginas (antes, todo de una vez), buscando por nombre o correo.
const buscar = ref("");
const pagina = ref(1);
const meta = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
} | null>(null);
function irPagina(n: number): void {
  pagina.value = n;
  void cargar();
}
let espera: ReturnType<typeof setTimeout> | undefined;
watch(buscar, () => {
  clearTimeout(espera);
  espera = setTimeout(() => {
    pagina.value = 1;
    void cargar();
  }, 300);
});
const regla = ref("");
const cargando = ref(true);
const error = ref<string | null>(null);
const exportando = ref(false);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: Fila[];
      meta: {
        total: number;
        total_padron?: number;
        page?: number;
        per_page?: number;
        ultima_pagina?: number;
        regla: string;
      };
    }>(`${base.value}/miembros/padron`, {
      params: {
        page: pagina.value,
        ...(buscar.value.trim() !== "" ? { q: buscar.value.trim() } : {}),
      },
    });
    filas.value = data.data;
    // El indicador es el padrón completo, no lo que coincide con la búsqueda.
    total.value = data.meta.total_padron ?? data.meta.total;
    meta.value =
      data.meta.ultima_pagina !== undefined
        ? {
            total: data.meta.total,
            page: data.meta.page ?? 1,
            per_page: data.meta.per_page ?? data.data.length,
            ultima_pagina: data.meta.ultima_pagina,
          }
        : null;
    regla.value = data.meta.regla;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function exportar(): Promise<void> {
  exportando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<Blob>(`${base.value}/miembros/padron`, {
      params: { formato: "csv" },
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = `padron-facturable-${sesion.slug}.csv`;
    document.body.appendChild(enlace);
    enlace.click();
    document.body.removeChild(enlace);
    URL.revokeObjectURL(url);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    exportando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion :titulo="$t('padron.titulo')" />

    <div class="mt-6 flex flex-wrap items-center gap-3">
      <div class="tu-card px-5 py-3">
        <div class="text-2xl font-semibold">{{ total }}</div>
        <div class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("padron.alumnosFacturables") }}
        </div>
      </div>
      <button
        class="tu-btn tu-btn-primario ml-auto"
        type="button"
        :disabled="exportando || total === 0"
        @click="exportar"
      >
        {{ exportando ? $t("padron.exportando") : $t("padron.exportar") }}
      </button>
    </div>

    <p
      v-if="regla"
      class="mt-3 text-xs"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("padron.regla", { regla }) }}
    </p>
    <input
      v-if="total > 0"
      v-model="buscar"
      type="search"
      class="tu-input mt-4 max-w-sm"
      :placeholder="$t('operacion.listadosTres.buscarPadron')"
      :aria-label="$t('operacion.listadosTres.buscarPadron')"
      data-prueba="buscar-padron"
    />
    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
    </AvisoTono>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <EstadoVacio
        v-if="filas.length === 0 && !error"
        class="tu-card mt-6"
        icono="facturas"
        :titulo="
          buscar.trim() !== ''
            ? $t('operacion.listadosDos.sinResultados')
            : $t('padron.vacio')
        "
      />
      <div v-else-if="filas.length > 0" class="mt-4 tu-card overflow-x-auto">
        <table class="tu-tabla">
          <thead>
            <tr>
              <th>
                {{ $t("padron.colAlumno") }}
              </th>
              <th class="hidden sm:table-cell">
                {{ $t("padron.colSucursal") }}
              </th>
              <th>{{ $t("padron.colAlta") }}</th>
              <th class="hidden sm:table-cell">
                {{ $t("padron.colRazon") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in filas" :key="f.id">
              <td>
                <span class="font-semibold">{{ f.nombre_completo }}</span>
                <span
                  v-if="f.email"
                  class="block text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                  >{{ f.email }}</span
                >
              </td>
              <td
                class="hidden sm:table-cell"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ f.sucursal ?? "—" }}
              </td>
              <td>{{ f.alta ?? "—" }}</td>
              <td
                class="hidden sm:table-cell"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ f.razon }}
              </td>
            </tr>
          </tbody>
        </table>
        <PaginacionListado
          v-if="meta && meta.ultima_pagina > 1"
          :page="meta.page"
          :ultima-pagina="meta.ultima_pagina"
          :total="meta.total"
          :per-page="meta.per_page"
          @ir="irPagina"
        />
      </div>
    </template>
  </section>
</template>
