<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoFilaImportacion from "@/components/EstadoFilaImportacion.vue";
import ResumenImportacion, {
  type ResumenCarga,
} from "@/components/ResumenImportacion.vue";
import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface FilaPreview {
  fila: number;
  datos: Record<string, unknown>;
  errores: string[];
  estado?: string;
}
interface Resultado {
  ok?: boolean;
  resumen: ResumenCarga;
  filas: FilaPreview[];
  creados?: number;
  invitados?: number;
}

const sesion = useSesionTenantStore();
const { t } = useI18n();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const archivo = ref<File | null>(null);
const nombreArchivo = ref("");
const preview = ref<Resultado | null>(null);
const analizando = ref(false);
const importando = ref(false);
const creados = ref<number | null>(null);
const invitados = ref(0);
const porCorregir = ref(0);
const error = ref<string | null>(null);

// Hay algo nuevo que crear; con filas por corregir, solo las listas (se confirma).
const puedeImportar = computed(
  () => preview.value !== null && preview.value.resumen.validas > 0,
);

function texto(v: unknown): string {
  return v === null || v === undefined || v === "" ? "—" : String(v);
}

async function onArchivo(f: File): Promise<void> {
  archivo.value = f;
  nombreArchivo.value = f.name;
  preview.value = null;
  creados.value = null;
  error.value = null;
  await previsualizar();
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

/**
 * Las invitaciones por correo salen solo si se confirma (C07); si no, se invita
 * después desde Equipo.
 */
async function importar(invitar: boolean): Promise<void> {
  if (archivo.value === null || preview.value === null) {
    return;
  }
  const { validas, invalidas } = preview.value.resumen;
  const avisos = [
    invalidas > 0
      ? t("importar.confirmarListas", { n: validas, m: invalidas })
      : null,
    invitar ? t("importarInstructores.confirmarInvitar", { n: validas }) : null,
  ].filter((x): x is string => x !== null);
  if (avisos.length > 0 && !(await confirmar(avisos.join(" ")))) {
    return;
  }
  importando.value = true;
  error.value = null;
  try {
    const fd = new FormData();
    fd.append("archivo", archivo.value);
    fd.append("invitar", invitar ? "1" : "0");
    if (invalidas > 0) {
      fd.append("parcial", "1");
    }
    const { data } = await api.post<{ data: Resultado }>(
      `${base.value}/importaciones/instructores`,
      fd,
    );
    creados.value = data.data.creados ?? 0;
    invitados.value = data.data.invitados ?? 0;
    porCorregir.value = data.data.resumen.invalidas;
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
      <p
        class="mt-2 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
        data-prueba="invitaciones"
      >
        {{
          invitados > 0
            ? $t("importarInstructores.invitados", { n: invitados })
            : $t("importarInstructores.sinInvitar")
        }}
      </p>
      <p
        v-if="porCorregir > 0"
        class="mt-1 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("importar.porCorregir", { n: porCorregir }) }}
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
        <ZonaArchivo
          class="mt-4"
          accept=".csv,text/csv"
          :boton="$t('importar.elegir')"
          :texto="$t('zonaArchivo.csv.arrastra')"
          :ayuda="$t('zonaArchivo.formatos.csv')"
          :cargado="nombreArchivo || null"
          :ocupado="analizando"
          :ocupado-texto="$t('importar.analizando')"
          @archivo="onArchivo"
        />
        <div class="mt-3 flex flex-wrap items-center gap-3">
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            @click="descargarPlantilla"
          >
            {{ $t("importar.plantilla") }}
          </button>
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
        <ResumenImportacion class="mt-6" :resumen="preview.resumen" />

        <p
          v-if="preview.resumen.invalidas > 0"
          class="mt-4 text-sm"
          :style="{ color: 'var(--aviso)' }"
        >
          {{ $t("importar.corrige") }}
        </p>

        <div class="mt-4 tu-card overflow-x-auto relative">
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ $t("importar.colNombre") }}</th>
                <th class="hidden sm:table-cell">
                  {{ $t("importar.colCorreo") }}
                </th>
                <th>{{ $t("importar.colEstado") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in preview.filas" :key="f.fila">
                <td :style="{ color: 'var(--texto-suave)' }">
                  {{ f.fila }}
                </td>
                <td class="font-medium">{{ texto(f.datos.nombre) }}</td>
                <td
                  class="hidden sm:table-cell"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ texto(f.datos.email) }}
                </td>
                <td>
                  <EstadoFilaImportacion
                    :estado="f.estado"
                    :errores="f.errores"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p
          v-if="puedeImportar"
          class="mt-4 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("importarInstructores.ayudaInvitar") }}
        </p>
        <div class="mt-3 flex flex-wrap justify-end gap-2">
          <button
            class="tu-btn tu-btn-fantasma"
            type="button"
            data-prueba="importar-sin-invitar"
            :disabled="!puedeImportar || importando"
            @click="importar(false)"
          >
            {{ $t("importarInstructores.importarSinInvitar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            data-prueba="importar-e-invitar"
            :disabled="!puedeImportar || importando"
            @click="importar(true)"
          >
            {{
              importando
                ? $t("importar.importando")
                : $t("importarInstructores.importarInvitar", {
                    n: preview.resumen.validas,
                  })
            }}
          </button>
        </div>
      </template>
    </template>
  </section>
</template>
