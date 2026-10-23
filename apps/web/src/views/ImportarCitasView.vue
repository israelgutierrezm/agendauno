<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import FiltroFilasImportacion from "@/components/FiltroFilasImportacion.vue";
import IconoNav from "@/components/IconoNav.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useFilasImportacion } from "@/lib/filasImportacion";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Importar las citas futuras de otro sistema (ADR 0160). Se sube el CSV, se revisa
 * (las mismas reglas que al agendar, sin guardar), se eligen las correspondencias de
 * los nombres que no coinciden y se importa todo o nada. A los alumnos no se les
 * avisa; los recordatorios antes de cada cita, solo si se marcan.
 */
type Campo = "servicio" | "profesional" | "sucursal";
interface Registro {
  id: string;
  nombre: string;
}
interface Catalogos {
  servicio: Registro[];
  profesional: Registro[];
  sucursal: Registro[];
  max_filas: number;
}
interface Fila {
  fila: number;
  datos: Record<string, string>;
  errores: string[];
  avisos: string[];
  omitida: boolean;
  cliente: { nombre: string; nueva: boolean } | null;
  inicia_en: string | null;
}
interface Resultado {
  ok: boolean;
  confirmacion?: string;
  creados: number;
  resumen: {
    total: number;
    validas: number;
    invalidas: number;
    omitidas: number;
    clientes_nuevos: number;
    // Lo que quedará por cobrar en caja y lo ya pagado en el otro sistema (R14).
    por_cobrar?: number;
    por_cobrar_minor?: number;
    pagadas_antes?: number;
    moneda?: string;
  };
  faltantes: Record<Campo, string[]>;
  filas: Fila[];
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}/importaciones/citas`);
const CAMPOS: Campo[] = ["servicio", "profesional", "sucursal"];
const COLUMNAS = [
  "referencia",
  "cliente",
  "cliente_ref",
  "email",
  "celular",
  "servicio",
  "profesional",
  "sucursal",
  "fecha",
  "hora",
  "pagado",
];
// Lo que queda por cobrar, en la moneda del negocio.
function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

const catalogos = ref<Catalogos | null>(null);
const cargando = ref(true);
const errorCatalogos = ref(false);
const archivo = ref<File | null>(null);
const recordatorios = ref(false);
const correspondencias = ref<Record<Campo, Record<string, string>>>({
  servicio: {},
  profesional: {},
  sucursal: {},
});
const preview = ref<Resultado | null>(null);
const exito = ref<Resultado | null>(null);
const error = ref("");
const ocupado = ref(false);
const {
  soloErrores,
  pagina,
  enPagina,
  ultimaPagina,
  total,
  todas,
  conErrores,
  porPagina,
} = useFilasImportacion(() => preview.value?.filas);

const faltantes = computed(() =>
  CAMPOS.flatMap((campo) =>
    (preview.value?.faltantes[campo] ?? []).map((valor) => ({ campo, valor })),
  ),
);
const puedeImportar = computed(
  () =>
    !ocupado.value &&
    !!preview.value?.confirmacion &&
    preview.value.ok &&
    preview.value.resumen.validas - preview.value.resumen.omitidas > 0,
);

async function cargarCatalogos(): Promise<void> {
  cargando.value = true;
  errorCatalogos.value = false;
  try {
    catalogos.value = (
      await api.get<{ data: Catalogos }>(`${base.value}/catalogos`)
    ).data.data;
  } catch {
    errorCatalogos.value = true;
  } finally {
    cargando.value = false;
  }
}
onMounted(cargarCatalogos);

function formulario(): FormData {
  const form = new FormData();
  if (archivo.value) form.append("archivo", archivo.value);
  form.append("correspondencias", JSON.stringify(correspondencias.value));
  form.append("recordatorios", recordatorios.value ? "1" : "0");
  return form;
}
function limpiar(): void {
  archivo.value = null;
  preview.value = null;
  exito.value = null;
  error.value = "";
  correspondencias.value = { servicio: {}, profesional: {}, sucursal: {} };
}
async function seleccionar(elegido: File): Promise<void> {
  limpiar();
  archivo.value = elegido;
  await revisar();
}
async function revisar(): Promise<void> {
  if (!archivo.value || ocupado.value) return;
  ocupado.value = true;
  error.value = "";
  try {
    preview.value = (
      await api.post<{ data: Resultado }>(`${base.value}/preview`, formulario())
    ).data.data;
  } catch (e) {
    preview.value = null;
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}
async function importar(): Promise<void> {
  if (!puedeImportar.value) return;
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
      error.value = t("importarCitas.cambios");
    } else {
      error.value = mensajeDeError(e);
      if (preview.value) preview.value.confirmacion = undefined;
    }
  } finally {
    ocupado.value = false;
  }
}
// Cambiar una opción invalida la confirmación: se vuelve a revisar.
function cambiarRecordatorios(): void {
  if (preview.value) preview.value.confirmacion = undefined;
}
async function descargar(): Promise<void> {
  ocupado.value = true;
  error.value = "";
  try {
    const respuesta = await api.get(`${base.value}/plantilla`, {
      responseType: "blob",
    });
    const url = URL.createObjectURL(respuesta.data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = "plantilla-citas.csv";
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
function fechaHora(iso: string | null): string {
  if (iso === null) return "—";
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: sesion.zonaHoraria,
  }).format(new Date(iso));
}
</script>

<template>
  <section class="tu-pagina" :aria-busy="ocupado">
    <!-- «Volver» a la lista de origen, encima del encabezado (como en las fichas). -->
    <RouterLink :to="{ name: 'agenda' }" class="tu-volver"
      ><IconoNav nombre="atras" :tam="16" />
      {{ t("importarCitas.volver") }}</RouterLink
    >
    <EncabezadoSeccion
      class="mt-4"
      :titulo="t('importarCitas.titulo')"
      :subtitulo="t('importarCitas.subtituloAlumnos')"
    />

    <AvisoTono v-if="error" tono="peligro" role="alert" class="mt-4">
      {{ error }}
    </AvisoTono>
    <AvisoTono
      v-if="errorCatalogos"
      tono="peligro"
      role="alert"
      class="mt-4"
      data-prueba="error-catalogos-citas"
    >
      {{ t("importarCitas.errorCatalogos") }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          @click="cargarCatalogos"
        >
          {{ t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>

    <div
      v-if="exito"
      class="tu-card mt-6 p-8 text-center"
      role="status"
      data-prueba="exito-citas"
    >
      <p class="text-lg font-semibold" style="color: var(--exito)">
        {{ t("importarCitas.creadas", exito.creados) }}
      </p>
      <p class="mt-2 text-sm" style="color: var(--texto-suave)">
        {{
          recordatorios
            ? t("importarCitas.conRecordatorios")
            : t("importarCitas.sinAvisosAlumnos")
        }}
      </p>
      <div class="mt-5 flex flex-wrap justify-center gap-3">
        <RouterLink :to="{ name: 'agenda' }" class="tu-btn tu-btn-primario">{{
          t("importarCitas.volver")
        }}</RouterLink>
        <button type="button" class="tu-btn tu-btn-secundario" @click="limpiar">
          {{ t("importarCitas.otra") }}
        </button>
      </div>
    </div>

    <template v-else>
      <div class="tu-card mt-6 p-5 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">
            {{ t("importarCitas.preparar") }}
          </h2>
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="ocupado"
            @click="descargar"
          >
            {{ t("importarCitas.plantilla") }}
          </button>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
          <code v-for="col in COLUMNAS" :key="col" class="tu-badge">{{
            col
          }}</code>
        </div>
        <ul class="mt-4 space-y-2 pl-5 text-sm lista-ayuda">
          <li>{{ t("importarCitas.reglaFormato") }}</li>
          <li>{{ t("importarCitas.reglaAlumnos") }}</li>
          <li>{{ t("importarCitas.reglaReferencia") }}</li>
          <li>{{ t("importarCitas.reglaCobro") }}</li>
          <li>{{ t("importarCitas.reglaNombres") }}</li>
          <li>{{ t("importarCitas.reglaTodo") }}</li>
        </ul>
      </div>

      <div class="tu-card mt-5 p-5 sm:p-6">
        <h2 class="text-lg font-semibold">{{ t("importarCitas.subir") }}</h2>
        <p
          v-if="catalogos"
          class="mt-2 text-sm"
          style="color: var(--texto-suave)"
        >
          {{ t("importarCitas.limite", { n: catalogos.max_filas }) }}
        </p>
        <label class="mt-4 flex items-start gap-2 text-sm">
          <input
            v-model="recordatorios"
            type="checkbox"
            class="mt-1"
            data-prueba="recordatorios-citas"
            @change="cambiarRecordatorios"
          />
          <span>
            {{ t("importarCitas.recordatorios") }}
            <span class="block" style="color: var(--texto-suave)">{{
              t("importarCitas.recordatoriosAyuda")
            }}</span>
          </span>
        </label>
        <ZonaArchivo
          id="archivo-citas"
          fija
          class="mt-4"
          accept=".csv,text/csv"
          :boton="t('importarCitas.elegir')"
          :texto="$t('zonaArchivo.csv.arrastra')"
          :ayuda="$t('zonaArchivo.formatos.csv')"
          :cargado="archivo?.name ?? null"
          :ocupado="ocupado"
          :ocupado-texto="t('importarCitas.analizando')"
          :deshabilitado="cargando || errorCatalogos"
          @archivo="seleccionar"
        />
      </div>

      <!-- Los nombres del archivo que no coinciden: se eligen una vez. -->
      <div
        v-if="faltantes.length > 0 && catalogos"
        class="tu-card mt-5 p-5 sm:p-6"
        data-prueba="correspondencias-citas"
      >
        <h2 class="text-lg font-semibold">
          {{ t("importarCitas.correspondencias") }}
        </h2>
        <p class="mt-1 text-sm" style="color: var(--texto-suave)">
          {{ t("importarCitas.correspondenciasAyuda") }}
        </p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <label
            v-for="f in faltantes"
            :key="`${f.campo}-${f.valor}`"
            class="block text-sm"
          >
            <span class="tu-label"
              >{{ t(`importarCitas.campos.${f.campo}`) }} «{{ f.valor }}»</span
            >
            <select
              v-model="correspondencias[f.campo][f.valor]"
              class="tu-input"
              :data-prueba="`corresponde-${f.campo}`"
            >
              <option value="">{{ t("importarCitas.elegir") }}</option>
              <option v-for="r in catalogos[f.campo]" :key="r.id" :value="r.id">
                {{ r.nombre }}
              </option>
            </select>
          </label>
        </div>
        <button
          type="button"
          class="tu-btn tu-btn-primario mt-4"
          :disabled="ocupado"
          data-prueba="revisar-citas"
          @click="revisar"
        >
          {{ t("importarCitas.revisar") }}
        </button>
      </div>

      <div v-if="preview" class="mt-6" data-prueba="vista-previa-citas">
        <h2 class="text-lg font-semibold">
          {{ t("importarCitas.resultado") }}
        </h2>
        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div
            v-for="indicador in [
              { clave: 'filas', valor: preview.resumen.total },
              {
                clave: 'alumnosNuevos',
                valor: preview.resumen.clientes_nuevos,
              },
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
              {{ t(`importarCitas.indicadores.${indicador.clave}`) }}
            </p>
          </div>
        </div>
        <AvisoTono
          v-if="preview.resumen.invalidas"
          tono="peligro"
          class="mt-4"
          role="alert"
        >
          {{ t("importarCitas.corrige") }}
        </AvisoTono>
        <!-- El efecto económico, antes de confirmar (R14). -->
        <AvisoTono
          v-if="
            (preview.resumen.por_cobrar ?? 0) > 0 ||
            (preview.resumen.pagadas_antes ?? 0) > 0
          "
          tono="aviso"
          class="mt-4"
          data-prueba="importar-por-cobrar"
        >
          <p v-if="(preview.resumen.por_cobrar ?? 0) > 0">
            {{
              t(
                "importarCitas.porCobrar",
                {
                  n: preview.resumen.por_cobrar ?? 0,
                  monto: dinero(
                    preview.resumen.por_cobrar_minor ?? 0,
                    preview.resumen.moneda ?? "MXN",
                  ),
                },
                preview.resumen.por_cobrar ?? 0,
              )
            }}
          </p>
          <p v-if="(preview.resumen.pagadas_antes ?? 0) > 0" class="mt-1">
            {{
              t(
                "importarCitas.pagadasAntes",
                { n: preview.resumen.pagadas_antes ?? 0 },
                preview.resumen.pagadas_antes ?? 0,
              )
            }}
          </p>
          <p class="mt-1">{{ t("importarCitas.porCobrarAyuda") }}</p>
        </AvisoTono>
        <FiltroFilasImportacion
          v-model="soloErrores"
          :todas="todas"
          :con-errores="conErrores"
        />
        <!-- En el teléfono, una ficha por fila: quién (con su n.º de fila) como
             título y la revisión a la vista, sin desplazar. -->
        <div class="tu-card relative mt-4 overflow-x-auto tu-tabla-adaptable">
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>{{ t("importarCitas.alumno") }}</th>
                <th>{{ t("importarCitas.cita") }}</th>
                <th>{{ t("importarCitas.revision") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="fila in enPagina" :key="fila.fila">
                <td :data-etiqueta="t('importarCitas.alumno')">
                  <strong>{{
                    fila.cliente?.nombre ?? fila.datos.cliente
                  }}</strong>
                  <span class="tu-sub"
                    >{{ t("importar.filaN", { n: fila.fila })
                    }}<template v-if="fila.cliente">
                      ·
                      {{
                        fila.cliente.nueva
                          ? t("importarCitas.fichaNueva")
                          : t("importarCitas.fichaExistente")
                      }}</template
                    ></span
                  >
                </td>
                <td :data-etiqueta="t('importarCitas.cita')">
                  <div>
                    {{ fila.datos.servicio }} · {{ fila.datos.profesional }}
                    <span class="block" style="color: var(--texto-suave)"
                      >{{ fila.datos.sucursal }} ·
                      {{
                        fila.inicia_en
                          ? fechaHora(fila.inicia_en)
                          : `${fila.datos.fecha} ${fila.datos.hora}`
                      }}</span
                    >
                  </div>
                </td>
                <td :data-etiqueta="t('importarCitas.revision')">
                  <div>
                    <span
                      v-if="!fila.errores.length"
                      class="tu-estado"
                      :style="{
                        '--tono': fila.omitida
                          ? 'var(--texto-suave)'
                          : 'var(--exito)',
                      }"
                      >{{
                        fila.omitida
                          ? t("importarCitas.omitida")
                          : t("importarCitas.lista")
                      }}</span
                    >
                    <p
                      v-for="mensaje in fila.errores"
                      :key="mensaje"
                      style="color: var(--error)"
                    >
                      {{ mensaje }}
                    </p>
                    <p
                      v-for="aviso in fila.avisos"
                      :key="aviso"
                      class="mt-1"
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
          v-if="ultimaPagina > 1"
          :page="pagina"
          :ultima-pagina="ultimaPagina"
          :total="total"
          :per-page="porPagina"
          @ir="pagina = $event"
        />
        <div class="mt-5 flex justify-end">
          <button
            type="button"
            class="tu-btn tu-btn-primario"
            :disabled="!puedeImportar"
            data-prueba="importar-citas"
            @click="importar"
          >
            {{
              t(
                "importarCitas.importar",
                preview.resumen.validas - preview.resumen.omitidas,
              )
            }}
          </button>
        </div>
      </div>
    </template>
  </section>
</template>
