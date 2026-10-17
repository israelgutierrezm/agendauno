<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoRegion from "@/components/AvisoRegion.vue";
import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import RangoFechas from "@/components/RangoFechas.vue";
import TablaDatos from "@/components/TablaDatos.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { fechaEnUrl, opcionEnUrl, useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Factura {
  id: string;
  estado: string;
  receptor: { nombre: string; rfc: string };
  moneda: string;
  total_minor: number;
  uuid: string | null;
  pdf_url: string | null;
  xml_url: string | null;
  motivo_error: string | null;
}

interface Concepto {
  descripcion: string;
  cantidad: number;
  precio: string; // en pesos (se convierte a minor al enviar)
  clave_prod_serv: string;
  clave_unidad: string;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeEmitir = computed(() => sesion.puede("ordenes.gestionar"));

const facturas = ref<Factura[]>([]);
// Por páginas (antes, solo las últimas 100), con búsqueda, estado y fechas en el API
// (y en la dirección, para volver a ellos).
const buscar = ref("");
const estado = ref<"" | "timbrada" | "error">("");
const desde = ref("");
const hasta = ref("");
const pagina = ref(1);
const meta = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
  conteos?: { timbrada: number; error: number };
} | null>(null);
useFiltrosEnUrl({
  q: buscar,
  estado: opcionEnUrl(estado, ["", "timbrada", "error"] as const),
  desde: fechaEnUrl(desde),
  hasta: fechaEnUrl(hasta),
});
const conFiltros = computed(
  () =>
    buscar.value !== "" ||
    estado.value !== "" ||
    desde.value !== "" ||
    hasta.value !== "",
);
function limpiarFiltros(): void {
  buscar.value = "";
  estado.value = "";
  desde.value = "";
  hasta.value = "";
}
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
watch([estado, desde, hasta], () => {
  pagina.value = 1;
  void cargar();
});
const cargando = ref(true);
// Solo la primera carga oculta la página: al recargar (filtros, después de emitir)
// el formulario sigue montado con lo que se escribió y con su error.
const cargadoUnaVez = ref(false);
// El de la lista (arriba) y el de la emisión (dentro del formulario), separados: recargar
// la lista no borra el rechazo del proveedor.
const error = ref<string | null>(null);
const errorEmision = ref<string | null>(null);
const mensaje = ref<string | null>(null);
const emitiendo = ref(false);

function conceptoNuevo(): Concepto {
  return {
    descripcion: "",
    cantidad: 1,
    precio: "",
    clave_prod_serv: "86121600",
    clave_unidad: "E48",
  };
}

const receptor = ref({ nombre: "", rfc: "", email: "", codigo_postal: "" });
const usoCfdi = ref("G03");
const formaPago = ref("01");
const conceptos = ref<Concepto[]>([conceptoNuevo()]);

const columnas = computed(() => [
  { clave: "receptor", etiqueta: t("facturas.colReceptor") },
  {
    clave: "total_minor",
    etiqueta: t("facturas.colTotal"),
    alinear: "derecha" as const,
  },
  { clave: "estado", etiqueta: t("facturas.colEstado") },
  { clave: "uuid", etiqueta: t("facturas.colFolio") },
]);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: Factura[];
      meta?: typeof meta.value;
    }>(`${base.value}/facturas`, {
      params: {
        page: pagina.value,
        ...(buscar.value.trim() !== "" ? { q: buscar.value.trim() } : {}),
        ...(estado.value !== "" ? { estado: estado.value } : {}),
        ...(desde.value !== "" ? { desde: desde.value } : {}),
        ...(hasta.value !== "" ? { hasta: hasta.value } : {}),
      },
    });
    facturas.value = data.data;
    meta.value = data.meta ?? null;
    cargadoUnaVez.value = true;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function agregarConcepto(): void {
  conceptos.value.push(conceptoNuevo());
}
function quitarConcepto(i: number): void {
  if (conceptos.value.length > 1) {
    conceptos.value.splice(i, 1);
  }
}

async function emitir(): Promise<void> {
  // Un CFDI timbrado no se edita: se confirma antes de timbrar.
  if (
    !(await confirmar(
      t("confirmaciones.factura", { rfc: receptor.value.rfc }),
      { aceptar: t("confirmaciones.timbrar") },
    ))
  ) {
    return;
  }
  emitiendo.value = true;
  errorEmision.value = null;
  mensaje.value = null;
  try {
    await api.post(`${base.value}/facturas`, {
      receptor: {
        nombre: receptor.value.nombre,
        rfc: receptor.value.rfc,
        email: receptor.value.email || null,
        codigo_postal: receptor.value.codigo_postal,
      },
      uso_cfdi: usoCfdi.value,
      forma_pago: formaPago.value,
      items: conceptos.value.map((c) => ({
        descripcion: c.descripcion,
        cantidad: Number(c.cantidad),
        precio_unitario_minor: Math.round(Number(c.precio) * 100),
        clave_prod_serv: c.clave_prod_serv,
        clave_unidad: c.clave_unidad,
      })),
    });
    mensaje.value = "ok";
    receptor.value = { nombre: "", rfc: "", email: "", codigo_postal: "" };
    conceptos.value = [conceptoNuevo()];
    await cargar();
  } catch (e) {
    // El rechazo del proveedor llega como 422 con la factura en error + motivo.
    if (
      axios.isAxiosError(e) &&
      e.response?.status === 422 &&
      e.response.data?.data?.estado === "error"
    ) {
      errorEmision.value =
        e.response.data.data.motivo_error ?? mensajeDeError(e);
      await cargar();
    } else {
      errorEmision.value = mensajeDeError(e);
    }
  } finally {
    emitiendo.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('facturas.titulo')"
      :total="meta?.total ?? facturas.length"
    />

    <AvisoTono
      v-if="error"
      tono="peligro"
      class="mt-4"
      role="alert"
      data-prueba="error-facturas"
    >
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-contorno tu-tono-peligro tu-btn-chico"
          :disabled="cargando"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <p
      v-if="cargando && !cargadoUnaVez"
      class="mt-8"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <!-- Si la primera carga falló, el aviso de arriba lo dice (con Reintentar): la
         tabla vacía diría «aún no has emitido facturas». -->
    <template v-else-if="cargadoUnaVez">
      <div class="fa-filtros mt-6" data-prueba="filtros-facturas">
        <input
          v-model="buscar"
          type="search"
          class="tu-input fa-buscar"
          :placeholder="$t('operacion.listadosDos.buscarFacturas')"
          :aria-label="$t('operacion.listadosDos.buscarFacturas')"
          data-prueba="buscar-facturas"
        />
        <div
          class="tu-segmentado"
          role="group"
          :aria-label="$t('operacion.listadosDos.estadoFactura')"
        >
          <button
            v-for="e in ['', 'timbrada', 'error'] as const"
            :key="e"
            type="button"
            :aria-pressed="estado === e"
            :data-prueba="`facturas-${e || 'todas'}`"
            @click="estado = e"
          >
            {{
              e === ""
                ? $t("operacion.listadosDos.todas")
                : e === "timbrada"
                  ? $t("operacion.listadosDos.timbradas")
                  : $t("operacion.listadosDos.conError")
            }}
            <span
              v-if="e !== '' && meta?.conteos"
              class="ml-1 tabular-nums"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ meta.conteos[e] }}</span
            >
          </button>
        </div>
        <RangoFechas
          v-model:desde="desde"
          v-model:hasta="hasta"
          :etiqueta="$t('operacion.listadosDos.fechasFactura')"
          prueba="facturas"
          atajos
          :zona="sesion.zonaHoraria"
        />
        <button
          v-if="conFiltros"
          type="button"
          class="tu-enlace text-sm"
          data-prueba="limpiar-facturas"
          @click="limpiarFiltros"
        >
          {{ $t("operacion.listadosDos.limpiar") }}
        </button>
      </div>
      <TablaDatos
        class="mt-3"
        :columnas="columnas"
        :filas="facturas"
        :buscar="false"
        :por-pagina="meta?.per_page ?? 20"
        :vacio="
          conFiltros
            ? $t('operacion.listadosDos.sinResultados')
            : $t('facturas.vacio')
        "
        clave-vista="facturas"
      >
        <template #col-receptor="{ fila }">
          <div class="font-semibold">
            {{ (fila as Factura).receptor.nombre }}
          </div>
          <div class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ (fila as Factura).receptor.rfc }}
          </div>
        </template>
        <template #col-total_minor="{ fila }">
          {{ dinero((fila as Factura).total_minor, (fila as Factura).moneda) }}
        </template>
        <template #col-estado="{ valor }">
          <span
            class="tu-badge"
            :class="valor === 'timbrada' ? 'tu-badge-exito' : 'tu-badge-aviso'"
          >
            {{
              valor === "timbrada"
                ? $t("facturas.estado.timbrada")
                : $t("facturas.estado.error")
            }}
          </span>
        </template>
        <template #col-uuid="{ fila }">
          <div v-if="(fila as Factura).uuid" class="flex items-center gap-2">
            <span class="text-xs font-mono truncate max-w-[12rem]">{{
              (fila as Factura).uuid
            }}</span>
            <a
              v-if="(fila as Factura).pdf_url"
              class="tu-badge"
              :href="(fila as Factura).pdf_url ?? '#'"
              target="_blank"
              rel="noopener"
              >{{ $t("facturas.verPdf") }}</a
            >
          </div>
          <span v-else class="text-xs" style="color: var(--error)">{{
            (fila as Factura).motivo_error
          }}</span>
        </template>
      </TablaDatos>
      <PaginacionListado
        v-if="meta && meta.ultima_pagina > 1"
        :page="meta.page"
        :ultima-pagina="meta.ultima_pagina"
        :total="meta.total"
        :per-page="meta.per_page"
        @ir="irPagina"
      />

      <!-- La plataforma aún no factura (sin proveedor en producción). -->
      <AvisoRegion
        v-if="puedeEmitir && sesion.estudio?.facturacion_disponible === false"
        class="mt-6"
        tipo="facturacionPlataforma"
      />
      <!-- Emitir: solo en pesos mexicanos y en México (ADR 0099). -->
      <AvisoRegion
        v-else-if="puedeEmitir && sesion.estudio?.factura_posible === false"
        class="mt-6"
        tipo="facturacion"
      />
      <div v-else-if="puedeEmitir" class="mt-6 tu-card p-6">
        <h2 class="font-medium text-lg">{{ $t("facturas.nueva.titulo") }}</h2>

        <form class="mt-4 space-y-5" @submit.prevent="emitir">
          <div>
            <h3 class="font-semibold text-sm mb-2">
              {{ $t("facturas.nueva.receptor") }}
            </h3>
            <div class="grid sm:grid-cols-2 gap-3">
              <div>
                <label class="tu-label" for="fa-nombre"
                  >{{ $t("facturas.nueva.nombre") }}
                  <span class="tu-obligatorio" aria-hidden="true"
                    >*</span
                  ></label
                >
                <input
                  id="fa-nombre"
                  v-model="receptor.nombre"
                  class="tu-input"
                  maxlength="255"
                  required
                />
              </div>
              <div>
                <label class="tu-label" for="fa-rfc"
                  >{{ $t("facturas.nueva.rfc") }}
                  <span class="tu-obligatorio" aria-hidden="true"
                    >*</span
                  ></label
                >
                <input
                  id="fa-rfc"
                  v-model="receptor.rfc"
                  class="tu-input uppercase"
                  maxlength="13"
                  required
                />
              </div>
              <div>
                <label class="tu-label" for="fa-email">{{
                  $t("facturas.nueva.email")
                }}</label>
                <input
                  id="fa-email"
                  v-model="receptor.email"
                  class="tu-input"
                  type="email"
                />
              </div>
              <div>
                <label class="tu-label" for="fa-cp"
                  >{{ $t("facturas.nueva.codigoPostal") }}
                  <span class="tu-obligatorio" aria-hidden="true"
                    >*</span
                  ></label
                >
                <input
                  id="fa-cp"
                  v-model="receptor.codigo_postal"
                  class="tu-input"
                  inputmode="numeric"
                  maxlength="5"
                  required
                />
              </div>
              <div>
                <label class="tu-label" for="fa-uso"
                  >{{ $t("facturas.nueva.usoCfdi") }}
                  <span class="tu-obligatorio" aria-hidden="true"
                    >*</span
                  ></label
                >
                <input
                  id="fa-uso"
                  v-model="usoCfdi"
                  class="tu-input uppercase"
                  maxlength="4"
                  required
                />
              </div>
              <div>
                <label class="tu-label" for="fa-forma"
                  >{{ $t("facturas.nueva.formaPago") }}
                  <span class="tu-obligatorio" aria-hidden="true"
                    >*</span
                  ></label
                >
                <input
                  id="fa-forma"
                  v-model="formaPago"
                  class="tu-input"
                  maxlength="2"
                  required
                />
              </div>
            </div>
          </div>

          <div>
            <h3 class="font-semibold text-sm mb-2">
              {{ $t("facturas.nueva.conceptos") }}
            </h3>
            <div
              v-for="(c, i) in conceptos"
              :key="i"
              class="grid sm:grid-cols-12 gap-2 items-end mb-3"
              data-prueba="concepto"
            >
              <div class="sm:col-span-5">
                <label class="tu-label" :for="`fa-desc-${i}`"
                  >{{ $t("facturas.nueva.descripcion") }}
                  <span class="tu-obligatorio" aria-hidden="true"
                    >*</span
                  ></label
                >
                <input
                  :id="`fa-desc-${i}`"
                  v-model="c.descripcion"
                  class="tu-input"
                  maxlength="1000"
                  required
                />
              </div>
              <div class="sm:col-span-2">
                <label class="tu-label" :for="`fa-cant-${i}`"
                  >{{ $t("facturas.nueva.cantidad") }}
                  <span class="tu-obligatorio" aria-hidden="true"
                    >*</span
                  ></label
                >
                <input
                  :id="`fa-cant-${i}`"
                  v-model.number="c.cantidad"
                  class="tu-input"
                  type="number"
                  min="1"
                  step="1"
                  required
                />
              </div>
              <div class="sm:col-span-2">
                <label class="tu-label" :for="`fa-precio-${i}`"
                  >{{ $t("facturas.nueva.precio") }}
                  <span class="tu-obligatorio" aria-hidden="true"
                    >*</span
                  ></label
                >
                <!-- El API pide al menos 1 centavo. -->
                <input
                  :id="`fa-precio-${i}`"
                  v-model="c.precio"
                  class="tu-input"
                  type="number"
                  min="0.01"
                  step="0.01"
                  required
                />
              </div>
              <div class="sm:col-span-2">
                <label class="tu-label" :for="`fa-clave-${i}`"
                  >{{ $t("facturas.nueva.claveProdServ") }}
                  <span class="tu-obligatorio" aria-hidden="true"
                    >*</span
                  ></label
                >
                <input
                  :id="`fa-clave-${i}`"
                  v-model="c.clave_prod_serv"
                  class="tu-input"
                  maxlength="8"
                  required
                />
              </div>
              <button
                type="button"
                class="tu-btn tu-btn-fantasma sm:col-span-1"
                :disabled="conceptos.length === 1"
                :aria-label="$t('facturas.nueva.quitar')"
                @click="quitarConcepto(i)"
              >
                <IconoNav nombre="cerrar" :tam="16" />
              </button>
            </div>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma text-sm"
              @click="agregarConcepto"
            >
              {{ $t("facturas.nueva.agregarConcepto") }}
            </button>
          </div>

          <AvisoTono v-if="mensaje" tono="exito">
            {{ $t("facturas.nueva.timbrada") }}
          </AvisoTono>
          <AvisoTono
            v-if="errorEmision"
            tono="peligro"
            role="alert"
            data-prueba="error-emision"
          >
            {{ errorEmision }}
          </AvisoTono>

          <button
            class="tu-btn tu-btn-primario"
            type="submit"
            :disabled="emitiendo"
          >
            {{
              emitiendo
                ? $t("facturas.nueva.emitiendo")
                : $t("facturas.nueva.emitir")
            }}
          </button>
        </form>
      </div>
    </template>
  </section>
</template>

<style scoped>
.fa-filtros {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.75rem;
}
.fa-buscar {
  flex: 1 1 16rem;
  max-width: 24rem;
}
</style>
