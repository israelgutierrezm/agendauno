<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
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
      meta: { total: number; regla: string };
    }>(`${base.value}/miembros/padron`);
    filas.value = data.data;
    total.value = data.meta.total;
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
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
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
        :disabled="exportando || filas.length === 0"
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
      <EstadoVacio
        v-if="filas.length === 0"
        class="tu-card mt-6"
        icono="facturas"
        :titulo="$t('padron.vacio')"
      />
      <div v-else class="mt-4 tu-card overflow-hidden">
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
      </div>
    </template>
  </section>
</template>
