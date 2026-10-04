<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface FilaPreview {
  fila: number;
  datos: Record<string, unknown>;
  errores: string[];
}
interface Resultado {
  ok?: boolean;
  resumen: { total: number; validas: number; invalidas: number };
  filas: FilaPreview[];
  creados?: number;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const archivo = ref<File | null>(null);
const nombreArchivo = ref("");
const preview = ref<Resultado | null>(null);
const analizando = ref(false);
const importando = ref(false);
const creados = ref<number | null>(null);
const error = ref<string | null>(null);

const puedeImportar = computed(
  () =>
    preview.value !== null &&
    preview.value.resumen.invalidas === 0 &&
    preview.value.resumen.total > 0,
);

function texto(v: unknown): string {
  return v === null || v === undefined || v === "" ? "—" : String(v);
}

async function onArchivo(e: Event): Promise<void> {
  const input = e.target as HTMLInputElement;
  const f = input.files?.[0] ?? null;
  archivo.value = f;
  nombreArchivo.value = f?.name ?? "";
  preview.value = null;
  creados.value = null;
  error.value = null;
  if (f !== null) {
    await previsualizar();
  }
}

async function previsualizar(): Promise<void> {
  if (archivo.value === null) {
    return;
  }
  analizando.value = true;
  error.value = null;
  try {
    const fd = new FormData();
    fd.append("archivo", archivo.value);
    const { data } = await api.post<{ data: Resultado }>(
      `${base.value}/importaciones/instructores/preview`,
      fd,
    );
    preview.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    analizando.value = false;
  }
}

async function importar(): Promise<void> {
  if (archivo.value === null) {
    return;
  }
  importando.value = true;
  error.value = null;
  try {
    const fd = new FormData();
    fd.append("archivo", archivo.value);
    const { data } = await api.post<{ data: Resultado }>(
      `${base.value}/importaciones/instructores`,
      fd,
    );
    creados.value = data.data.creados ?? 0;
    preview.value = null;
    archivo.value = null;
    nombreArchivo.value = "";
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    importando.value = false;
  }
}

// Plantilla CSV de ejemplo (descarga local, sin llamar al servidor).
function descargarPlantilla(): void {
  const csv =
    "nombre,email\nAna Ríos,ana@correo.mx\nBeto Luna,beto@correo.mx\n";
  const url = URL.createObjectURL(
    new Blob([csv], { type: "text/csv;charset=utf-8" }),
  );
  const a = document.createElement("a");
  a.href = url;
  a.download = "plantilla-instructores.csv";
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion :titulo="$t('importarInstructores.titulo')" />

    <!-- Éxito -->
    <div v-if="creados !== null" class="mt-6 tu-card p-6 text-center">
      <p class="text-2xl font-semibold" :style="{ color: 'var(--exito)' }">
        {{ $t("importarInstructores.creados", { n: creados }) }}
      </p>
      <RouterLink
        :to="{ name: 'instructores' }"
        class="tu-btn tu-btn-primario mt-4 inline-block"
        >{{ $t("importarInstructores.verInstructores") }}</RouterLink
      >
    </div>

    <template v-else>
      <!-- Instrucciones + carga -->
      <div class="mt-6 tu-card p-6">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("importarInstructores.instrucciones") }}
        </p>
        <p class="mt-2 text-sm">
          <span :style="{ color: 'var(--texto-suave)' }"
            >{{ $t("importar.columnas") }}:</span
          >
          <code class="ml-1">nombre, email</code>
        </p>
        <div class="mt-4 flex flex-wrap items-center gap-3">
          <label class="tu-btn tu-btn-primario cursor-pointer">
            {{ $t("importar.elegir") }}
            <input
              type="file"
              accept=".csv,text/csv"
              class="hidden"
              @change="onArchivo"
            />
          </label>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            @click="descargarPlantilla"
          >
            {{ $t("importar.plantilla") }}
          </button>
          <span
            v-if="nombreArchivo"
            class="text-sm"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ nombreArchivo }}</span
          >
        </div>
      </div>

      <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
        {{ error }}
      </p>
      <p
        v-if="analizando"
        class="mt-4 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("importar.analizando") }}
      </p>

      <!-- Preview -->
      <template v-if="preview">
        <div class="mt-6 grid grid-cols-3 gap-3">
          <div class="tu-card p-4 text-center">
            <div class="text-xl font-semibold">
              {{ preview.resumen.total }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("importar.total") }}
            </div>
          </div>
          <div class="tu-card p-4 text-center">
            <div
              class="text-xl font-semibold"
              :style="{ color: 'var(--exito)' }"
            >
              {{ preview.resumen.validas }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("importar.validas") }}
            </div>
          </div>
          <div class="tu-card p-4 text-center">
            <div
              class="text-xl font-semibold"
              :style="{
                color:
                  preview.resumen.invalidas > 0
                    ? 'var(--error)'
                    : 'var(--texto-suave)',
              }"
            >
              {{ preview.resumen.invalidas }}
            </div>
            <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("importar.invalidas") }}
            </div>
          </div>
        </div>

        <p
          v-if="preview.resumen.invalidas > 0"
          class="mt-4 text-sm"
          :style="{ color: 'var(--aviso)' }"
        >
          {{ $t("importar.corrige") }}
        </p>

        <div class="mt-4 tu-card overflow-x-auto">
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>#</th>
                <th>
                  {{ $t("importar.colNombre") }}
                </th>
                <th class="hidden sm:table-cell">
                  {{ $t("importar.colCorreo") }}
                </th>
                <th>
                  {{ $t("importar.colEstado") }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in preview.filas" :key="f.fila">
                <td :style="{ color: 'var(--texto-suave)' }">
                  {{ f.fila }}
                </td>
                <td class="font-medium">
                  {{ texto(f.datos.nombre) }}
                </td>
                <td
                  class="hidden sm:table-cell"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ texto(f.datos.email) }}
                </td>
                <td>
                  <span
                    v-if="f.errores.length === 0"
                    class="tu-badge tu-badge-exito"
                    >{{ $t("importar.ok") }}</span
                  >
                  <span
                    v-for="(er, i) in f.errores"
                    :key="i"
                    class="tu-badge block sm:inline mb-1 sm:mb-0 sm:mr-1"
                    :style="{
                      background: 'var(--error-suave)',
                      color: 'var(--error)',
                    }"
                    >{{ er }}</span
                  >
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-4 flex justify-end">
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="!puedeImportar || importando"
            @click="importar"
          >
            {{
              importando
                ? $t("importar.importando")
                : $t("importarInstructores.importar", {
                    n: preview.resumen.validas,
                  })
            }}
          </button>
        </div>
      </template>
    </template>
  </section>
</template>
