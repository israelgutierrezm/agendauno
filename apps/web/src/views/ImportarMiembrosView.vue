<script setup lang="ts">
import axios from "axios";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoFilaImportacion from "@/components/EstadoFilaImportacion.vue";
import IconoNav from "@/components/IconoNav.vue";
import ResumenImportacion, {
  type ResumenCarga,
} from "@/components/ResumenImportacion.vue";
import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import PaginacionListado from "@/components/PaginacionListado.vue";
import FiltroFilasImportacion from "@/components/FiltroFilasImportacion.vue";
import { useFilasImportacion } from "@/lib/filasImportacion";

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
}

const sesion = useSesionTenantStore();
const { t } = useI18n();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
// Los saldos que cada alumno traía de otro sistema (C07), después de cargarlos.
const puedeSaldos = computed(
  () =>
    sesion.puede("membresias.gestionar") && sesion.puede("creditos.gestionar"),
);

const archivo = ref<File | null>(null);
const nombreArchivo = ref("");
const preview = ref<Resultado | null>(null);
// La vista previa de 50 en 50, y solo lo que hay que corregir si se pide.
const {
  soloErrores,
  pagina: paginaFilas,
  enPagina: filasEnPagina,
  ultimaPagina: ultimaPaginaFilas,
  total: totalFilas,
  todas: todasFilas,
  conErrores: filasConErrores,
  porPagina: filasPorPagina,
} = useFilasImportacion(() => preview.value?.filas);
const analizando = ref(false);
const importando = ref(false);
const creados = ref<number | null>(null);
const porCorregir = ref(0);
const error = ref<string | null>(null);

// Todo de una vez: nada por corregir y algo nuevo.
const puedeImportar = computed(
  () =>
    preview.value !== null &&
    preview.value.resumen.invalidas === 0 &&
    preview.value.resumen.validas > 0,
);
// Solo las listas: hay algo por corregir, pero también filas listas.
const puedeImportarListas = computed(
  () =>
    preview.value !== null &&
    preview.value.resumen.invalidas > 0 &&
    preview.value.resumen.validas > 0,
);

// Un 422 de la carga trae el análisis del archivo (`{data: resultado}`), no un mensaje:
// las filas por corregir se ven en la lista.
function rechazoConFilas(e: unknown): Resultado | null {
  if (!axios.isAxiosError(e) || e.response?.status !== 422) {
    return null;
  }
  const datos = (e.response.data as { data?: Resultado } | undefined)?.data;
  return datos && Array.isArray(datos.filas) && datos.resumen ? datos : null;
}

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
      `${base.value}/importaciones/miembros/preview`,
      fd,
    );
    preview.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    analizando.value = false;
  }
}

async function importar(parcial = false): Promise<void> {
  if (archivo.value === null || preview.value === null) {
    return;
  }
  if (
    parcial &&
    !(await confirmar(
      t("importar.confirmarListas", {
        n: preview.value.resumen.validas,
        m: preview.value.resumen.invalidas,
      }),
    ))
  ) {
    return;
  }
  importando.value = true;
  error.value = null;
  try {
    const fd = new FormData();
    fd.append("archivo", archivo.value);
    if (parcial) {
      fd.append("parcial", "1");
    }
    const { data } = await api.post<{ data: Resultado }>(
      `${base.value}/importaciones/miembros`,
      fd,
    );
    creados.value = data.data.creados ?? 0;
    porCorregir.value = data.data.resumen.invalidas;
    preview.value = null;
    archivo.value = null;
    nombreArchivo.value = "";
  } catch (e) {
    const rechazo = rechazoConFilas(e);
    if (rechazo !== null) {
      preview.value = rechazo;
      error.value =
        rechazo.resumen.total === 0
          ? t("importar.sinFilas")
          : t("importar.rechazado");
    } else {
      error.value = mensajeDeError(e);
    }
  } finally {
    importando.value = false;
  }
}

// Plantilla CSV de ejemplo (descarga local, sin llamar al servidor).
function descargarPlantilla(): void {
  const csv =
    "nombre,primer_apellido,email,celular,fecha_nacimiento,genero,tipo,es_facturable\nAna,Ríos,ana@correo.mx,5512345678,1990-05-04,mujer,miembro,si\nBeto,Luna,,,,,miembro,no\n";
  const url = URL.createObjectURL(
    new Blob([csv], { type: "text/csv;charset=utf-8" }),
  );
  const a = document.createElement("a");
  a.href = url;
  a.download = "plantilla-miembros.csv";
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
</script>

<template>
  <section class="tu-pagina">
    <!-- «Volver» a la lista de origen, encima del encabezado (como en las fichas). -->
    <RouterLink :to="{ name: 'miembros' }" class="tu-volver"
      ><IconoNav nombre="atras" :tam="16" />
      {{
        $t("ficha.volver", {
          grupo: plural(sesion.terminologia.miembro).toLowerCase(),
        })
      }}</RouterLink
    >
    <EncabezadoSeccion
      class="mt-4"
      :titulo="$t('importar.titulo')"
      :subtitulo="$t('importar.subtitulo')"
    />

    <!-- Éxito -->
    <div
      v-if="creados !== null"
      class="mt-6 tu-card p-6 text-center"
      role="status"
    >
      <p class="text-lg font-semibold" :style="{ color: 'var(--exito)' }">
        {{ $t("importar.creados", { n: creados }) }}
      </p>
      <p
        v-if="porCorregir > 0"
        class="mt-2 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
        data-prueba="por-corregir"
      >
        {{ $t("importar.porCorregir", { n: porCorregir }) }}
      </p>
      <div class="mt-4 flex flex-wrap justify-center gap-2">
        <RouterLink :to="{ name: 'miembros' }" class="tu-btn tu-btn-primario">{{
          $t("importar.verMiembros")
        }}</RouterLink>
        <RouterLink
          v-if="puedeSaldos"
          :to="{ name: 'importar-saldos' }"
          class="tu-btn tu-btn-secundario"
          >{{ $t("importarSaldos.ir") }}</RouterLink
        >
      </div>
    </div>

    <template v-else>
      <!-- Instrucciones + carga -->
      <div class="mt-6 tu-card p-6">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("importar.instrucciones") }}
        </p>
        <p class="mt-2 text-sm">
          <span :style="{ color: 'var(--texto-suave)' }"
            >{{ $t("importar.columnas") }}:</span
          >
          <code class="ml-1"
            >nombre, primer_apellido, email, celular, fecha_nacimiento, genero,
            tipo, es_facturable</code
          >
        </p>
        <ZonaArchivo
          fija
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
            class="tu-btn tu-btn-secundario"
            @click="descargarPlantilla"
          >
            {{ $t("importar.plantilla") }}
          </button>
          <RouterLink
            v-if="puedeSaldos"
            :to="{ name: 'importar-saldos' }"
            class="tu-enlace text-sm"
            >{{ $t("importarSaldos.ir") }}</RouterLink
          >
        </div>
      </div>

      <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
        {{ error }}
      </AvisoTono>
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

        <AvisoTono
          v-if="preview.resumen.invalidas > 0"
          tono="aviso"
          class="mt-4"
        >
          {{ $t("importar.corrige") }}
        </AvisoTono>

        <FiltroFilasImportacion
          v-model="soloErrores"
          :todas="todasFilas"
          :con-errores="filasConErrores"
        />
        <!-- En el teléfono, una ficha por fila: el nombre (con su n.º de fila) como
             título y el estado a la vista, sin desplazar. -->
        <div class="mt-4 tu-card relative overflow-x-auto tu-tabla-adaptable">
          <table class="tu-tabla">
            <thead>
              <tr>
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
              <tr v-for="f in filasEnPagina" :key="f.fila">
                <td :data-etiqueta="$t('importar.colNombre')">
                  <span class="font-medium"
                    >{{ texto(f.datos.nombre) }}
                    {{
                      texto(f.datos.primer_apellido) !== "—"
                        ? f.datos.primer_apellido
                        : ""
                    }}</span
                  >
                  <span class="tu-sub">{{
                    $t("importar.filaN", { n: f.fila })
                  }}</span>
                </td>
                <td
                  class="hidden sm:table-cell"
                  :data-etiqueta="$t('importar.colCorreo')"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ texto(f.datos.email) }}
                </td>
                <td :data-etiqueta="$t('importar.colEstado')">
                  <div>
                    <EstadoFilaImportacion
                      :estado="f.estado"
                      :errores="f.errores"
                    />
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <PaginacionListado
          v-if="ultimaPaginaFilas > 1"
          :page="paginaFilas"
          :ultima-pagina="ultimaPaginaFilas"
          :total="totalFilas"
          :per-page="filasPorPagina"
          @ir="paginaFilas = $event"
        />

        <div class="mt-4 flex flex-wrap justify-end gap-2">
          <button
            v-if="puedeImportarListas"
            class="tu-btn tu-btn-secundario"
            type="button"
            data-prueba="importar-listas"
            :disabled="importando"
            @click="importar(true)"
          >
            {{ $t("importar.importarListas", { n: preview.resumen.validas }) }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="!puedeImportar || importando"
            @click="importar()"
          >
            {{
              importando
                ? $t("importar.importando")
                : $t("importar.importar", { n: preview.resumen.validas })
            }}
          </button>
        </div>
      </template>
    </template>
  </section>
</template>
