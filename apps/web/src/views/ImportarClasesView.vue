<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import { useI18n } from "vue-i18n";
import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoFilaImportacion from "@/components/EstadoFilaImportacion.vue";
import IconoNav from "@/components/IconoNav.vue";
import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import PaginacionListado from "@/components/PaginacionListado.vue";
import FiltroFilasImportacion from "@/components/FiltroFilasImportacion.vue";
import { useFilasImportacion } from "@/lib/filasImportacion";

type Modo = "fechas" | "semanal";
interface Registro {
  id: string;
  nombre: string;
  zona_horaria?: string;
  sucursal?: string;
}
interface Catalogos {
  clases: Registro[];
  sucursales: Registro[];
  instructores: Registro[];
  salas: Registro[];
  max_sesiones: number;
}
interface Resultado {
  ok: boolean;
  confirmacion?: string;
  creados: number;
  lote: string | null;
  resumen: {
    total: number;
    validas: number;
    invalidas: number;
    omitidas: number;
    sesiones: number;
  };
  filas: {
    fila: number;
    datos: Record<string, string | number | null>;
    errores: string[];
    avisos: string[];
    omitida: boolean;
    sesiones: { fecha: string; inicio: string; fin: string; estado: string }[];
  }[];
}
const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}/importaciones/clases`);
const modo = ref<Modo>("fechas");
const archivo = ref<File | null>(null);
const catalogos = ref<Catalogos | null>(null);
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
const exito = ref<Resultado | null>(null);
const error = ref("");
const ocupado = ref(false);
const cargando = ref(true);
const puedeConfirmar = computed(
  () =>
    !ocupado.value &&
    !!preview.value?.confirmacion &&
    preview.value.ok &&
    preview.value.resumen.sesiones > 0,
);
const columnas = computed(() => [
  "referencia",
  "clase",
  "sucursal",
  "instructor",
  "sala",
  ...(modo.value === "fechas" ? ["fecha"] : ["dia", "desde", "hasta"]),
  "inicio",
  "fin",
  "cupo",
  ...(modo.value === "fechas" ? ["estado"] : []),
]);
const grupos = computed(() =>
  catalogos.value
    ? [
        { clave: "clase", registros: catalogos.value.clases },
        { clave: "sucursal", registros: catalogos.value.sucursales },
        { clave: "instructor", registros: catalogos.value.instructores },
        { clave: "sala", registros: catalogos.value.salas },
      ]
    : [],
);

onMounted(async () => {
  try {
    catalogos.value = (
      await api.get<{ data: Catalogos }>(`${base.value}/catalogos`)
    ).data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
});

function limpiar() {
  archivo.value = null;
  preview.value = null;
  exito.value = null;
  error.value = "";
}
function cambiarModo(valor: Modo) {
  if (!ocupado.value && valor !== modo.value) {
    modo.value = valor;
    limpiar();
  }
}
async function seleccionar(elegido: File) {
  archivo.value = elegido;
  preview.value = null;
  exito.value = null;
  error.value = "";
  if (archivo.value) await revisar();
}
function formulario() {
  const form = new FormData();
  form.append("modo", modo.value);
  if (archivo.value) form.append("archivo", archivo.value);
  return form;
}
async function revisar() {
  if (!archivo.value || ocupado.value) return;
  ocupado.value = true;
  preview.value = null;
  error.value = "";
  try {
    preview.value = (
      await api.post<{ data: Resultado }>(`${base.value}/preview`, formulario())
    ).data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}
async function importar() {
  if (!puedeConfirmar.value) return;
  const form = formulario();
  form.append("confirmacion", preview.value!.confirmacion!);
  ocupado.value = true;
  error.value = "";
  try {
    exito.value = (
      await api.post<{ data: Resultado }>(base.value, form)
    ).data.data;
    preview.value = null;
  } catch (e) {
    const respuesta = (e as { response?: { data?: { data?: Resultado } } })
      .response?.data?.data;
    if (respuesta?.filas) {
      preview.value = respuesta;
      error.value = t("importarClases.cambios");
    } else {
      error.value = mensajeDeError(e);
      if (preview.value) preview.value.confirmacion = undefined;
    }
  } finally {
    ocupado.value = false;
  }
}
async function descargar() {
  ocupado.value = true;
  error.value = "";
  try {
    const respuesta = await api.get(`${base.value}/plantilla`, {
      params: { modo: modo.value },
      responseType: "blob",
    });
    const url = URL.createObjectURL(respuesta.data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = `plantilla-clases-${modo.value}.csv`;
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    URL.revokeObjectURL(url);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}
</script>

<template>
  <section class="tu-pagina" :aria-busy="ocupado">
    <!-- «Volver» a la agenda, encima del encabezado (como en los demás importadores). -->
    <RouterLink :to="{ name: 'agenda' }" class="tu-volver"
      ><IconoNav nombre="atras" :tam="16" />
      {{ t("importarClases.volver") }}</RouterLink
    >
    <EncabezadoSeccion
      class="mt-4"
      :titulo="t('importarClases.titulo')"
      :subtitulo="t('importarClases.subtitulo')"
    />
    <AvisoTono v-if="error" tono="peligro" role="alert" class="mt-4">
      {{ error }}
    </AvisoTono>
    <div v-if="exito" class="tu-card mt-6 p-6 text-center" role="status">
      <p class="text-lg font-semibold" :style="{ color: 'var(--exito)' }">
        {{ t("importarClases.creada", { n: exito.creados }) }}
      </p>
      <p class="mt-2 text-sm" style="color: var(--texto-suave)">
        {{ t("importarClases.lote", { id: exito.lote }) }}
      </p>
      <div class="mt-5 flex flex-wrap justify-center gap-3">
        <RouterLink :to="{ name: 'agenda' }" class="tu-btn tu-btn-primario">{{
          t("importarClases.volver")
        }}</RouterLink>
        <button type="button" class="tu-btn tu-btn-secundario" @click="limpiar">
          {{ t("importarClases.otra") }}
        </button>
      </div>
    </div>
    <template v-else>
      <div
        class="mt-6 grid gap-3 sm:grid-cols-2"
        role="group"
        :aria-label="t('importarClases.titulo')"
      >
        <button
          v-for="opcion in ['fechas', 'semanal'] as const"
          :key="opcion"
          type="button"
          class="tu-card opcion p-5 text-left"
          :class="{ seleccionada: modo === opcion }"
          :aria-pressed="modo === opcion"
          :disabled="ocupado"
          @click="cambiarModo(opcion)"
        >
          <span class="flex items-center gap-3 font-semibold"
            ><span class="indicador" aria-hidden="true"></span
            >{{ t(`importarClases.${opcion}`) }}</span
          >
          <span class="mt-2 block text-sm" style="color: var(--texto-suave)">{{
            t(`importarClases.${opcion}Ayuda`)
          }}</span>
        </button>
      </div>
      <div class="tu-card mt-5 p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="tu-titulo-seccion">
            {{ t("importarClases.preparar") }}
          </h2>
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="ocupado"
            @click="descargar"
          >
            {{ t("importarClases.plantilla") }}
          </button>
        </div>
        <p class="mt-3 text-sm" style="color: var(--texto-suave)">
          {{ t("importarClases.formato") }}
        </p>
        <div class="mt-4 flex flex-wrap gap-2">
          <code v-for="col in columnas" :key="col" class="tu-badge">{{
            col
          }}</code>
        </div>
        <ul class="mt-4 space-y-2 pl-5 text-sm lista-ayuda">
          <li>{{ t("importarClases.reglas") }}</li>
          <li>{{ t("importarClases.referencia") }}</li>
          <li>{{ t("importarClases.opcionales") }}</li>
          <li>
            {{
              t(
                modo === "fechas"
                  ? "importarClases.reglaFechas"
                  : "importarClases.reglaSemanal",
              )
            }}
          </li>
        </ul>
        <details
          v-if="catalogos"
          class="mt-5 border-t pt-4"
          style="border-color: var(--borde)"
        >
          <summary class="cursor-pointer font-medium">
            {{ t("importarClases.catalogos") }}
          </summary>
          <p class="my-3 text-sm" style="color: var(--texto-suave)">
            {{ t("importarClases.catalogoAyuda") }}
          </p>
          <div class="grid gap-4 md:grid-cols-2">
            <div
              v-for="grupo in grupos"
              :key="grupo.clave"
              class="overflow-x-auto"
            >
              <h3 class="font-semibold">
                {{ t(`importarClases.${grupo.clave}`) }}
              </h3>
              <table class="mt-2 w-full text-left text-sm">
                <thead>
                  <tr>
                    <th>{{ t("importarClases.nombre") }}</th>
                    <th>{{ t("importarClases.id") }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in grupo.registros" :key="item.id">
                    <td class="py-2 pr-3">
                      {{ item.nombre
                      }}<small
                        class="block"
                        style="color: var(--texto-suave)"
                        >{{ item.zona_horaria || item.sucursal }}</small
                      >
                    </td>
                    <td>
                      <code class="select-all text-xs">{{ item.id }}</code>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </details>
      </div>
      <div class="tu-card mt-5 p-5 sm:p-6">
        <h2 class="tu-titulo-seccion">{{ t("importarClases.subir") }}</h2>
        <p
          v-if="catalogos"
          class="mt-2 text-sm"
          style="color: var(--texto-suave)"
        >
          {{ t("importarClases.limite", { n: catalogos.max_sesiones }) }}
        </p>
        <AvisoTono
          v-if="
            catalogos &&
            (!catalogos.clases.length || !catalogos.sucursales.length)
          "
          tono="aviso"
          class="mt-3"
        >
          {{ t("importarClases.sinCatalogo") }}
        </AvisoTono>
        <ZonaArchivo
          id="archivo-clases"
          fija
          class="mt-4"
          accept=".csv,text/csv"
          :boton="t('importarClases.elegir')"
          :texto="$t('zonaArchivo.csv.arrastra')"
          :ayuda="$t('zonaArchivo.formatos.csv')"
          :cargado="archivo?.name ?? null"
          :ocupado="ocupado"
          :ocupado-texto="t('importarClases.analizando')"
          :deshabilitado="
            cargando ||
            !catalogos?.clases.length ||
            !catalogos?.sucursales.length
          "
          @archivo="seleccionar"
        />
        <div v-if="archivo" class="mt-3 flex flex-wrap items-center gap-3">
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="ocupado"
            @click="revisar"
          >
            {{ t("importarClases.revisar") }}
          </button>
        </div>
        <p v-if="ocupado" class="mt-3 text-sm" role="status">
          {{ t("importarClases.analizando") }}
        </p>
      </div>
      <div v-if="preview" class="mt-6">
        <h2 class="tu-titulo-seccion">
          {{ t("importarClases.resultado") }}
        </h2>
        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div
            v-for="indicador in [
              { clave: 'filas', valor: preview.resumen.total },
              { clave: 'sesiones', valor: preview.resumen.sesiones },
              { clave: 'errores', valor: preview.resumen.invalidas },
              { clave: 'omitidas', valor: preview.resumen.omitidas },
            ]"
            :key="indicador.clave"
            class="tu-card p-4"
          >
            <p
              class="text-2xl font-semibold"
              :style="
                indicador.clave === 'errores' && indicador.valor
                  ? { color: 'var(--error)' }
                  : {}
              "
            >
              {{ indicador.valor }}
            </p>
            <p class="text-sm" style="color: var(--texto-suave)">
              {{ t(`importarClases.${indicador.clave}`) }}
            </p>
          </div>
        </div>
        <AvisoTono
          v-if="preview.resumen.invalidas"
          tono="peligro"
          class="mt-4"
          role="alert"
        >
          {{ t("importarClases.corrige") }}
        </AvisoTono>
        <p v-else-if="!preview.resumen.sesiones" class="mt-4" role="status">
          {{ t("importarClases.sinNuevas") }}
        </p>
        <FiltroFilasImportacion
          v-model="soloErrores"
          :todas="todasFilas"
          :con-errores="filasConErrores"
        />
        <!-- En el teléfono, una ficha por fila: la clase (con su n.º de fila) como
             título y la revisión a la vista, sin desplazar. -->
        <div class="tu-card mt-4 relative overflow-x-auto tu-tabla-adaptable">
          <table class="tu-tabla tabla-preview">
            <thead>
              <tr>
                <th>{{ t("importarClases.clase") }}</th>
                <th>{{ t("importarClases.horario") }}</th>
                <th>{{ t("importarClases.revision") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="fila in filasEnPagina" :key="fila.fila">
                <td :data-etiqueta="t('importarClases.clase')">
                  <span class="font-medium">{{ fila.datos.clase }}</span>
                  <span class="tu-sub"
                    >{{ $t("importar.filaN", { n: fila.fila })
                    }}<template v-if="fila.datos.referencia">
                      · {{ fila.datos.referencia }}</template
                    ></span
                  >
                  <span class="tu-sub"
                    >{{ fila.datos.sucursal
                    }}<template v-if="fila.datos.sala">
                      · {{ fila.datos.sala }}</template
                    ></span
                  >
                  <span class="tu-sub">{{
                    fila.datos.instructor || t("importarClases.sinInstructor")
                  }}</span>
                </td>
                <td :data-etiqueta="t('importarClases.horario')">
                  <div>
                    <span class="whitespace-nowrap"
                      >{{ fila.datos.inicio }}–{{ fila.datos.fin }}</span
                    ><span class="block">{{
                      fila.datos.fecha ||
                      `${fila.datos.dia}: ${fila.datos.desde} — ${fila.datos.hasta}`
                    }}</span
                    ><span
                      v-if="fila.datos.zona_horaria"
                      class="block text-xs"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{ fila.datos.zona_horaria }}</span
                    >
                    <details v-if="fila.sesiones.length" class="mt-2">
                      <summary class="cursor-pointer">
                        {{
                          t("importarClases.fechasGeneradas", {
                            n: fila.sesiones.length,
                          })
                        }}
                      </summary>
                      <ul class="mt-1 tu-lista-acotada">
                        <li v-for="fecha in fila.sesiones" :key="fecha.fecha">
                          {{ fecha.fecha }}
                          <span v-if="fecha.estado === 'cancelada'"
                            >· {{ t("importarClases.cancelada") }}</span
                          >
                        </li>
                      </ul>
                    </details>
                  </div>
                </td>
                <td :data-etiqueta="t('importarClases.revision')">
                  <!-- Punto y texto, como en los demás importadores: lista, ya
                       importada (se conserva la existente) o con errores. -->
                  <div>
                    <EstadoFilaImportacion
                      :estado="
                        fila.errores.length
                          ? 'invalida'
                          : fila.omitida
                            ? 'existente'
                            : 'valida'
                      "
                      :errores="fila.errores"
                    />
                    <p
                      v-for="aviso in fila.avisos"
                      :key="aviso"
                      class="mt-1 text-xs"
                      style="color: var(--texto-suave)"
                    >
                      {{ aviso }}
                    </p>
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
        <div class="mt-4 flex flex-wrap items-center justify-between gap-4">
          <p class="max-w-xl text-sm" style="color: var(--texto-suave)">
            {{ t("importarClases.seguro") }}
          </p>
          <button
            type="button"
            class="tu-btn tu-btn-primario"
            :disabled="!puedeConfirmar"
            @click="importar"
          >
            {{
              ocupado
                ? t("importarClases.importando")
                : t("importarClases.confirmar", { n: preview.resumen.sesiones })
            }}
          </button>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.opcion {
  border: 1px solid var(--borde);
  transition: border-color 150ms;
}
.opcion.seleccionada {
  border-color: var(--primario);
  box-shadow: inset 0 0 0 1px var(--primario);
}
.indicador {
  width: 1.4rem;
  height: 1.4rem;
  border: 1px solid var(--borde);
  border-radius: 50%;
  display: inline-grid;
  place-items: center;
}
/* Como un radio: el punto relleno marca la opción elegida (sin «✓» de texto). */
.seleccionada .indicador {
  border-color: var(--primario);
}
.seleccionada .indicador::after {
  content: "";
  width: 0.6rem;
  height: 0.6rem;
  border-radius: 50%;
  background: var(--primario);
}
.lista-ayuda {
  list-style-type: disc;
  color: var(--texto-suave);
}
/* Lo de cada fila arriba (la lista de fechas generadas puede ser larga). */
.tabla-preview td {
  vertical-align: top;
}
</style>
