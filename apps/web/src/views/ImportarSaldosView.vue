<script setup lang="ts">
import axios from "axios";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoFilaImportacion from "@/components/EstadoFilaImportacion.vue";
import ResumenImportacion, {
  type ResumenCarga,
} from "@/components/ResumenImportacion.vue";
import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import PaginacionListado from "@/components/PaginacionListado.vue";
import FiltroFilasImportacion from "@/components/FiltroFilasImportacion.vue";
import { useFilasImportacion } from "@/lib/filasImportacion";

/**
 * Saldos de apertura al migrar de otro sistema (C07): el plan, los créditos y la
 * vigencia que cada alumno traía, al corte acordado. No inventa ventas ni pagos: el
 * saldo queda en su estado de cuenta como «Saldo de apertura», con la evidencia.
 */
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
  cargados?: number;
}

const sesion = useSesionTenantStore();
const { t } = useI18n();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

// El corte, por omisión «hoy» del negocio (no del navegador de quien carga).
const corte = ref(hoyEnNegocio(sesion.zonaHoraria));
const evidencia = ref("");
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
const cargados = ref<number | null>(null);
const porCorregir = ref(0);
const error = ref<string | null>(null);

const listoParaRevisar = computed(
  () =>
    archivo.value !== null &&
    corte.value !== "" &&
    evidencia.value.trim().length >= 5,
);
const puedeCargar = computed(
  () =>
    preview.value !== null &&
    preview.value.resumen.invalidas === 0 &&
    preview.value.resumen.validas > 0,
);
const puedeCargarListas = computed(
  () =>
    preview.value !== null &&
    preview.value.resumen.invalidas > 0 &&
    preview.value.resumen.validas > 0,
);

// Otro corte cambia qué vale: hay que revisar de nuevo.
watch(corte, () => {
  preview.value = null;
});

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

function formulario(parcial = false): FormData {
  const fd = new FormData();
  if (archivo.value !== null) {
    fd.append("archivo", archivo.value);
  }
  fd.append("corte", corte.value);
  fd.append("evidencia", evidencia.value.trim());
  if (parcial) {
    fd.append("parcial", "1");
  }
  return fd;
}

async function onArchivo(f: File): Promise<void> {
  archivo.value = f;
  nombreArchivo.value = f.name;
  preview.value = null;
  cargados.value = null;
  error.value = null;
  if (listoParaRevisar.value) {
    await revisar();
  }
}

async function revisar(): Promise<void> {
  if (!listoParaRevisar.value) {
    return;
  }
  analizando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: Resultado }>(
      `${base.value}/importaciones/saldos/preview`,
      formulario(),
    );
    preview.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    analizando.value = false;
  }
}

async function cargar(parcial = false): Promise<void> {
  if (preview.value === null) {
    return;
  }
  const { validas, invalidas } = preview.value.resumen;
  const mensaje = [
    t("importarSaldos.confirmar", { n: validas, corte: corte.value }),
    parcial ? t("importar.confirmarListas", { n: validas, m: invalidas }) : "",
  ]
    .filter((x) => x !== "")
    .join(" ");
  if (!(await confirmar(mensaje))) {
    return;
  }
  importando.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: Resultado }>(
      `${base.value}/importaciones/saldos`,
      formulario(parcial),
    );
    cargados.value = data.data.cargados ?? 0;
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
    "email,plan,creditos,vence,inicio\nana@correo.mx,Paquete 8 clases,4.5,2026-11-15,\nbeto@correo.mx,Mensual ilimitado,,2026-10-31,2026-10-01\n";
  const url = URL.createObjectURL(
    new Blob([csv], { type: "text/csv;charset=utf-8" }),
  );
  const a = document.createElement("a");
  a.href = url;
  a.download = "plantilla-saldos-apertura.csv";
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion :titulo="$t('importarSaldos.titulo')" />

    <!-- Éxito -->
    <div v-if="cargados !== null" class="mt-6 tu-card p-6 text-center">
      <p class="text-2xl font-semibold" :style="{ color: 'var(--exito)' }">
        {{ $t("importarSaldos.cargados", { n: cargados }) }}
      </p>
      <p
        v-if="porCorregir > 0"
        class="mt-2 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("importar.porCorregir", { n: porCorregir }) }}
      </p>
      <RouterLink
        :to="{ name: 'miembros' }"
        class="tu-btn tu-btn-primario mt-4 inline-block"
        >{{ $t("importar.verMiembros") }}</RouterLink
      >
    </div>

    <template v-else>
      <div class="mt-6 tu-card p-6">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("importarSaldos.instrucciones") }}
        </p>
        <ul
          class="mt-2 list-disc pl-5 text-sm space-y-1"
          :style="{ color: 'var(--texto-suave)' }"
        >
          <li>{{ $t("importarSaldos.pasoMiembros") }}</li>
          <li>{{ $t("importarSaldos.pasoPlanes") }}</li>
          <li>{{ $t("importarSaldos.pasoCorte") }}</li>
          <li>
            {{
              $t(
                sesion.esCitas
                  ? "importarSaldos.pasoReservasCitas"
                  : "importarSaldos.pasoReservas",
              )
            }}
            <RouterLink
              v-if="!sesion.esCitas"
              :to="{ name: 'importar-reservas' }"
              class="tu-enlace ml-1"
              data-prueba="ir-importar-reservas"
              >{{ $t("importarReservas.ir") }}</RouterLink
            >
          </li>
        </ul>
        <p class="mt-3 text-sm">
          <span :style="{ color: 'var(--texto-suave)' }"
            >{{ $t("importar.columnas") }}:</span
          >
          <code class="ml-1">email, plan, creditos, vence, inicio</code>
        </p>

        <div
          class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]"
        >
          <div>
            <label class="tu-label" for="saldos-corte">{{
              $t("importarSaldos.corte")
            }}</label>
            <input
              id="saldos-corte"
              v-model="corte"
              type="date"
              class="tu-input"
              required
            />
          </div>
          <div>
            <label class="tu-label" for="saldos-evidencia">{{
              $t("importarSaldos.evidencia")
            }}</label>
            <input
              id="saldos-evidencia"
              v-model="evidencia"
              class="tu-input"
              maxlength="500"
              :placeholder="$t('importarSaldos.evidenciaEjemplo')"
              required
            />
          </div>
        </div>

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
            class="tu-btn tu-btn-fantasma"
            @click="descargarPlantilla"
          >
            {{ $t("importar.plantilla") }}
          </button>
          <button
            v-if="archivo !== null"
            type="button"
            class="tu-btn tu-btn-fantasma"
            data-prueba="revisar-saldos"
            :disabled="!listoParaRevisar || analizando"
            @click="revisar"
          >
            {{ $t("importarSaldos.revisar") }}
          </button>
        </div>
        <AvisoTono
          v-if="archivo !== null && !listoParaRevisar"
          tono="aviso"
          class="mt-2"
        >
          {{ $t("importarSaldos.faltan") }}
        </AvisoTono>
      </div>

      <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
        {{ error }}
      </AvisoTono>

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
        <div class="mt-4 tu-card overflow-x-auto relative">
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ $t("importarSaldos.colAlumno") }}</th>
                <th>{{ $t("importarSaldos.colPlan") }}</th>
                <th class="hidden sm:table-cell text-right">
                  {{ $t("importarSaldos.colCreditos") }}
                </th>
                <th class="hidden sm:table-cell">
                  {{ $t("importarSaldos.colVence") }}
                </th>
                <th>{{ $t("importar.colEstado") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in filasEnPagina" :key="f.fila">
                <td :style="{ color: 'var(--texto-suave)' }">{{ f.fila }}</td>
                <td class="font-medium">{{ texto(f.datos.email) }}</td>
                <td>{{ texto(f.datos.plan) }}</td>
                <td class="hidden sm:table-cell text-right">
                  {{ texto(f.datos.creditos) }}
                </td>
                <td class="hidden sm:table-cell">
                  {{ texto(f.datos.vence) }}
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
            v-if="puedeCargarListas"
            class="tu-btn tu-btn-fantasma"
            type="button"
            data-prueba="cargar-listas"
            :disabled="importando"
            @click="cargar(true)"
          >
            {{ $t("importar.importarListas", { n: preview.resumen.validas }) }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            data-prueba="cargar-saldos"
            :disabled="!puedeCargar || importando"
            @click="cargar()"
          >
            {{
              importando
                ? $t("importar.importando")
                : $t("importarSaldos.cargar", { n: preview.resumen.validas })
            }}
          </button>
        </div>
      </template>
    </template>
  </section>
</template>
