<script setup lang="ts">
import axios from "axios";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoFilaImportacion from "@/components/EstadoFilaImportacion.vue";
import FiltroFilasImportacion from "@/components/FiltroFilasImportacion.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import ResumenImportacion from "@/components/ResumenImportacion.vue";
import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useFilasImportacion } from "@/lib/filasImportacion";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Las reservas futuras de clases que los alumnos traen de otro sistema (ADR 0176):
 * confirmadas o en lista de espera, al corte acordado. Antes de revisar se elige si los
 * saldos de apertura ya las descontaron, para no descontar dos veces. Importar no avisa;
 * los recordatorios antes de cada clase van aparte, solo si se marcan.
 */
interface FilaPreview {
  fila: number;
  datos: Record<string, unknown>;
  errores: string[];
  avisos: string[];
  estado: string;
  alumno: string | null;
  clase: string | null;
  inicia_en: string | null;
  queda: string | null;
}
interface Resumen {
  total: number;
  validas: number;
  existentes: number;
  pendientes: number;
  invalidas: number;
  en_espera: number;
  creditos_reintegrados: number;
}
interface Resultado {
  ok: boolean;
  resumen: Resumen;
  filas: FilaPreview[];
  importadas?: number;
  confirmacion?: string;
}

const sesion = useSesionTenantStore();
const { t } = useI18n();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const corte = ref(hoyEnNegocio(sesion.zonaHoraria));
// Sin respuesta por omisión: es la regla contra el doble descuento y se elige.
const yaDescontadas = ref<boolean | null>(null);
// Importar nunca avisa; esto es solo el recordatorio de siempre antes de la clase.
const recordatorios = ref(false);
const archivo = ref<File | null>(null);
const nombreArchivo = ref("");
const preview = ref<Resultado | null>(null);
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
const importadas = ref<number | null>(null);
const porCorregir = ref(0);
const error = ref<string | null>(null);

const listoParaRevisar = computed(
  () =>
    archivo.value !== null &&
    corte.value !== "" &&
    yaDescontadas.value !== null,
);
// Lo que hay que corregir o decidir: inválidas y pendientes.
const porResolver = computed(
  () =>
    (preview.value?.resumen.invalidas ?? 0) +
    (preview.value?.resumen.pendientes ?? 0),
);
const resumenCarga = computed(() =>
  preview.value === null
    ? null
    : {
        total: preview.value.resumen.total,
        validas: preview.value.resumen.validas,
        existentes: preview.value.resumen.existentes,
        invalidas: porResolver.value,
      },
);
const puedeImportar = computed(
  () =>
    preview.value !== null &&
    porResolver.value === 0 &&
    preview.value.resumen.validas > 0,
);
const puedeImportarListas = computed(
  () =>
    preview.value !== null &&
    porResolver.value > 0 &&
    preview.value.resumen.validas > 0,
);

// Otro corte u otra regla cambian qué entra: hay que revisar de nuevo.
watch([corte, yaDescontadas, recordatorios], () => {
  preview.value = null;
});

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

function cuando(f: FilaPreview): string {
  return `${texto(f.datos.fecha)} ${texto(f.datos.hora)}`;
}

function formulario(extra: Record<string, string> = {}): FormData {
  const fd = new FormData();
  if (archivo.value !== null) {
    fd.append("archivo", archivo.value);
  }
  fd.append("corte", corte.value);
  fd.append("ya_descontadas", yaDescontadas.value ? "1" : "0");
  fd.append("recordatorios", recordatorios.value ? "1" : "0");
  for (const [clave, valor] of Object.entries(extra)) {
    fd.append(clave, valor);
  }
  return fd;
}

async function onArchivo(f: File): Promise<void> {
  archivo.value = f;
  nombreArchivo.value = f.name;
  preview.value = null;
  importadas.value = null;
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
      `${base.value}/importaciones/reservas/preview`,
      formulario(),
    );
    preview.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    analizando.value = false;
  }
}

async function importar(parcial = false): Promise<void> {
  if (preview.value?.confirmacion === undefined) {
    return;
  }
  const { validas, creditos_reintegrados } = preview.value.resumen;
  const mensaje = [
    t(
      "importarReservas.confirmar",
      { n: validas, corte: corte.value },
      validas,
    ),
    creditos_reintegrados > 0
      ? t(
          "importarReservas.confirmarReintegro",
          { n: creditos_reintegrados / 1000 },
          creditos_reintegrados / 1000,
        )
      : "",
    recordatorios.value ? t("importarReservas.confirmarRecordatorios") : "",
    parcial
      ? t("importar.confirmarListas", { n: validas, m: porResolver.value })
      : "",
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
      `${base.value}/importaciones/reservas`,
      formulario({
        confirmacion: preview.value.confirmacion,
        parcial: parcial ? "1" : "0",
      }),
    );
    importadas.value = data.data.importadas ?? 0;
    porCorregir.value =
      data.data.resumen.invalidas + data.data.resumen.pendientes;
    preview.value = null;
    archivo.value = null;
    nombreArchivo.value = "";
  } catch (e) {
    const rechazo = rechazoConFilas(e);
    if (rechazo !== null) {
      preview.value = { ...rechazo, confirmacion: preview.value?.confirmacion };
      error.value = t("importar.rechazado");
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
    "referencia,email,clase,sucursal,fecha,hora,estado,posicion\n" +
    "R-1001,ana@correo.mx,Pole Nivel 1,Roma Norte,2026-11-03,19:00,confirmada,\n" +
    "R-1002,beto@correo.mx,Pole Nivel 1,Roma Norte,2026-11-03,19:00,en_espera,1\n";
  const url = URL.createObjectURL(
    new Blob([csv], { type: "text/csv;charset=utf-8" }),
  );
  const a = document.createElement("a");
  a.href = url;
  a.download = "plantilla-reservas.csv";
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion :titulo="$t('importarReservas.titulo')" />

    <!-- Éxito -->
    <div v-if="importadas !== null" class="mt-6 tu-card p-6 text-center">
      <p class="text-2xl font-semibold" :style="{ color: 'var(--exito)' }">
        {{ $t("importarReservas.importadas", { n: importadas }, importadas) }}
      </p>
      <p class="mt-2 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{
          recordatorios
            ? $t("importarReservas.conRecordatorios")
            : $t("importarReservas.sinAvisos")
        }}
      </p>
      <p
        v-if="porCorregir > 0"
        class="mt-2 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("importar.porCorregir", { n: porCorregir }) }}
      </p>
      <RouterLink
        :to="{ name: 'agenda' }"
        class="tu-btn tu-btn-primario mt-4 inline-block"
        >{{ $t("importarReservas.verAgenda") }}</RouterLink
      >
    </div>

    <template v-else>
      <div class="mt-6 tu-card p-6">
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("importarReservas.instrucciones") }}
        </p>
        <ul
          class="mt-2 list-disc pl-5 text-sm space-y-1"
          :style="{ color: 'var(--texto-suave)' }"
        >
          <li>{{ $t("importarReservas.pasoAlumnos") }}</li>
          <li>{{ $t("importarReservas.pasoSaldos") }}</li>
          <li>{{ $t("importarReservas.pasoCorte") }}</li>
        </ul>
        <p class="mt-3 text-sm">
          <span :style="{ color: 'var(--texto-suave)' }"
            >{{ $t("importar.columnas") }}:</span
          >
          <code class="ml-1"
            >referencia, email, clase, sucursal, fecha, hora, estado,
            posicion</code
          >
        </p>

        <div class="mt-4 max-w-[12rem]">
          <label class="tu-label" for="reservas-corte">{{
            $t("importarReservas.corte")
          }}</label>
          <input
            id="reservas-corte"
            v-model="corte"
            type="date"
            class="tu-input"
            required
          />
        </div>

        <fieldset class="mt-4" data-prueba="ya-descontadas">
          <legend class="tu-label">
            {{ $t("importarReservas.descontadas.pregunta") }}
          </legend>
          <label class="mt-2 flex items-start gap-2 text-sm">
            <input
              v-model="yaDescontadas"
              type="radio"
              name="ya-descontadas"
              :value="false"
              data-prueba="descontadas-no"
              class="mt-0.5"
            />
            <span>
              <span class="font-medium">{{
                $t("importarReservas.descontadas.no")
              }}</span>
              <span class="block" :style="{ color: 'var(--texto-suave)' }">{{
                $t("importarReservas.descontadas.noAyuda")
              }}</span>
            </span>
          </label>
          <label class="mt-2 flex items-start gap-2 text-sm">
            <input
              v-model="yaDescontadas"
              type="radio"
              name="ya-descontadas"
              :value="true"
              data-prueba="descontadas-si"
              class="mt-0.5"
            />
            <span>
              <span class="font-medium">{{
                $t("importarReservas.descontadas.si")
              }}</span>
              <span class="block" :style="{ color: 'var(--texto-suave)' }">{{
                $t("importarReservas.descontadas.siAyuda")
              }}</span>
            </span>
          </label>
        </fieldset>

        <label class="mt-4 flex items-start gap-2 text-sm">
          <input
            v-model="recordatorios"
            type="checkbox"
            class="mt-0.5"
            data-prueba="recordatorios-reservas"
          />
          <span>
            <span class="font-medium">{{
              $t("importarReservas.recordatorios")
            }}</span>
            <span class="block" :style="{ color: 'var(--texto-suave)' }">{{
              $t("importarReservas.recordatoriosAyuda")
            }}</span>
          </span>
        </label>

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
          <button
            v-if="archivo !== null"
            type="button"
            class="tu-btn tu-btn-secundario"
            data-prueba="revisar-reservas"
            :disabled="!listoParaRevisar || analizando"
            @click="revisar"
          >
            {{ $t("importarReservas.revisar") }}
          </button>
        </div>
        <AvisoTono
          v-if="archivo !== null && !listoParaRevisar"
          tono="aviso"
          class="mt-2"
        >
          {{ $t("importarReservas.faltan") }}
        </AvisoTono>
      </div>

      <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
        {{ error }}
      </AvisoTono>

      <template v-if="preview && resumenCarga">
        <ResumenImportacion class="mt-6" :resumen="resumenCarga" />
        <p
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="resumen-reservas"
        >
          {{
            $t("importarReservas.extra", {
              pendientes: preview.resumen.pendientes,
              espera: preview.resumen.en_espera,
              reintegrados: preview.resumen.creditos_reintegrados / 1000,
            })
          }}
        </p>
        <AvisoTono v-if="porResolver > 0" tono="aviso" class="mt-4">
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
                <th>{{ $t("importarReservas.colAlumno") }}</th>
                <th>{{ $t("importarReservas.colClase") }}</th>
                <th class="hidden sm:table-cell">
                  {{ $t("importarReservas.colCuando") }}
                </th>
                <th class="hidden sm:table-cell">
                  {{ $t("importarReservas.colQueda") }}
                </th>
                <th>{{ $t("importar.colEstado") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in filasEnPagina" :key="f.fila">
                <td :style="{ color: 'var(--texto-suave)' }">{{ f.fila }}</td>
                <td>
                  <span class="font-medium">{{
                    f.alumno ?? texto(f.datos.email)
                  }}</span>
                  <span
                    v-if="f.alumno"
                    class="block text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ texto(f.datos.email) }}</span
                  >
                </td>
                <td>
                  {{ f.clase ?? texto(f.datos.clase) }}
                  <span
                    v-if="f.datos.sucursal"
                    class="block text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ texto(f.datos.sucursal) }}</span
                  >
                </td>
                <td class="hidden sm:table-cell">{{ cuando(f) }}</td>
                <td class="hidden sm:table-cell">
                  {{
                    f.queda === "confirmada" || f.queda === "en_espera"
                      ? $t(`importarReservas.queda.${f.queda}`)
                      : "—"
                  }}
                </td>
                <td>
                  <EstadoFilaImportacion
                    :estado="f.estado"
                    :errores="f.errores"
                  />
                  <ul
                    v-if="f.avisos.length > 0"
                    class="mt-1 space-y-0.5 text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    <li v-for="(a, i) in f.avisos" :key="i">{{ a }}</li>
                  </ul>
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
            data-prueba="importar-reservas"
            :disabled="!puedeImportar || importando"
            @click="importar()"
          >
            {{
              importando
                ? $t("importar.importando")
                : $t(
                    "importarReservas.importar",
                    { n: preview.resumen.validas },
                    preview.resumen.validas,
                  )
            }}
          </button>
        </div>
      </template>
    </template>
  </section>
</template>
