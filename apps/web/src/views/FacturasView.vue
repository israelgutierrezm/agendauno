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
import SelectorBuscable from "@/components/SelectorBuscable.vue";
import TablaDatos from "@/components/TablaDatos.vue";
import { api, mensajeDeError } from "@/lib/api";
import {
  opcionesFormaPago,
  opcionesRegimen,
  opcionesUsoCfdi,
  regimenCorresponde,
  tipoPersonaDeRfc,
  usoCfdiCorresponde,
  type TipoPersona,
} from "@/lib/catalogosSat";
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
  subtotal_minor?: number;
  timbrada_en?: string | null;
  // Factura de un anticipo y si ya se aplicó al facturar el resto (ADR 0163).
  anticipo?: boolean;
  anticipo_aplicado?: boolean;
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

const receptor = ref({
  nombre: "",
  rfc: "",
  email: "",
  codigo_postal: "",
  regimen_fiscal: "",
});
// Del catálogo del SAT (CFDI 4.0), con buscador: nadie tiene que saberse las claves.
const tipoReceptor = computed(() => tipoPersonaDeRfc(receptor.value.rfc));
function textoPersonas(personas: TipoPersona[]): string {
  return personas.length > 1
    ? t("datosFiscalesSat.regimen.personas.ambas")
    : t(`datosFiscalesSat.regimen.personas.${personas[0] ?? "fisica"}`);
}
const opcionesRegimenReceptor = computed(() =>
  opcionesRegimen(tipoReceptor.value, {
    persona: textoPersonas,
    paraTuRfc: t("datosFiscalesSat.factura.paraElRfc"),
    otros: t("datosFiscalesSat.regimen.otros"),
  }),
);
const opcionesUso = computed(() =>
  opcionesUsoCfdi(tipoReceptor.value, {
    paraElRfc: t("datosFiscalesSat.factura.paraElRfc"),
    otros: t("datosFiscalesSat.factura.otrosUsos"),
  }),
);
const opcionesForma = opcionesFormaPago();
const avisoRegimenReceptor = computed(() => {
  const tipo = tipoReceptor.value;
  return tipo !== null &&
    receptor.value.regimen_fiscal !== "" &&
    !regimenCorresponde(receptor.value.regimen_fiscal, tipo)
    ? t(`datosFiscalesSat.regimen.noCorresponde.${tipo}`)
    : null;
});
const avisoUso = computed(() =>
  usoCfdiCorresponde(usoCfdi.value, tipoReceptor.value)
    ? null
    : t("datosFiscalesSat.factura.usoNoCorresponde"),
);
const usoCfdi = ref("G03");
const formaPago = ref("01");
const conceptos = ref<Concepto[]>([conceptoNuevo()]);

// Anticipos (ADR 0163, CFDI «por remanente»): la factura de un anticipo lleva un solo
// concepto que fija el SAT; la del resto se relaciona con los anticipos de ese RFC.
const esAnticipo = ref(false);
const importeAnticipo = ref("");
const anticiposDisponibles = ref<Factura[]>([]);
const anticiposElegidos = ref<string[]>([]);
const RFC_VALIDO = /^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/i;
async function buscarAnticipos(): Promise<void> {
  const rfc = receptor.value.rfc.trim();
  anticiposElegidos.value = [];
  if (esAnticipo.value || !RFC_VALIDO.test(rfc)) {
    anticiposDisponibles.value = [];
    return;
  }
  try {
    const { data } = await api.get<{ data: Factura[] }>(
      `${base.value}/facturas`,
      { params: { anticipos_de: rfc, per_page: 20 } },
    );
    anticiposDisponibles.value = data.data;
  } catch {
    // Sin la lista se factura como siempre; el anticipo se puede aplicar después.
    anticiposDisponibles.value = [];
  }
}
watch(() => [receptor.value.rfc, esAnticipo.value], buscarAnticipos);
function fechaCorta(iso: string | null | undefined): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
        new Date(iso),
      )
    : "";
}

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
        regimen_fiscal: receptor.value.regimen_fiscal || null,
      },
      uso_cfdi: usoCfdi.value,
      forma_pago: formaPago.value,
      ...(esAnticipo.value
        ? {
            anticipo: true,
            // Clave, unidad y descripción las fija el SAT (el API las vuelve a poner).
            items: [
              {
                descripcion: "Anticipo del bien o servicio",
                cantidad: 1,
                precio_unitario_minor: Math.round(
                  Number(importeAnticipo.value) * 100,
                ),
                clave_prod_serv: "84111506",
                clave_unidad: "ACT",
              },
            ],
          }
        : {
            items: conceptos.value.map((c) => ({
              descripcion: c.descripcion,
              cantidad: Number(c.cantidad),
              precio_unitario_minor: Math.round(Number(c.precio) * 100),
              clave_prod_serv: c.clave_prod_serv,
              clave_unidad: c.clave_unidad,
            })),
            ...(anticiposElegidos.value.length > 0
              ? { anticipos: anticiposElegidos.value }
              : {}),
          }),
    });
    mensaje.value = "ok";
    receptor.value = {
      nombre: "",
      rfc: "",
      email: "",
      codigo_postal: "",
      regimen_fiscal: "",
    };
    conceptos.value = [conceptoNuevo()];
    esAnticipo.value = false;
    importeAnticipo.value = "";
    anticiposElegidos.value = [];
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
        <template #col-estado="{ valor, fila }">
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
          <span
            v-if="(fila as Factura).anticipo"
            class="block text-xs mt-1"
            :style="{ color: 'var(--texto-suave)' }"
            data-prueba="factura-anticipo"
            >{{
              (fila as Factura).anticipo_aplicado
                ? $t("facturas.anticipo.aplicado")
                : $t("facturas.anticipo.porAplicar")
            }}</span
          >
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
              <div class="sm:col-span-2">
                <label class="tu-label" for="fa-regimen">{{
                  $t("datosFiscalesSat.factura.regimenReceptor")
                }}</label>
                <SelectorBuscable
                  id="fa-regimen"
                  v-model="receptor.regimen_fiscal"
                  :opciones="opcionesRegimenReceptor"
                  :placeholder="
                    $t('datosFiscalesSat.factura.regimenReceptorPh')
                  "
                  data-prueba="regimen-receptor"
                />
                <p
                  v-if="avisoRegimenReceptor"
                  class="tu-error-campo"
                  data-prueba="aviso-regimen-receptor"
                >
                  {{ avisoRegimenReceptor }}
                </p>
                <p v-else class="tu-hint">
                  {{ $t("datosFiscalesSat.factura.regimenReceptorAyuda") }}
                </p>
              </div>
              <div>
                <label class="tu-label" for="fa-uso"
                  >{{ $t("facturas.nueva.usoCfdi") }}
                  <span class="tu-obligatorio" aria-hidden="true"
                    >*</span
                  ></label
                >
                <SelectorBuscable
                  id="fa-uso"
                  v-model="usoCfdi"
                  :opciones="opcionesUso"
                  :placeholder="$t('datosFiscalesSat.factura.usoPh')"
                  data-prueba="uso-cfdi"
                />
                <p
                  v-if="avisoUso"
                  class="tu-error-campo"
                  data-prueba="aviso-uso-cfdi"
                >
                  {{ avisoUso }}
                </p>
                <p v-else class="tu-hint">
                  {{ $t("datosFiscalesSat.factura.usoAyuda") }}
                </p>
              </div>
              <div>
                <label class="tu-label" for="fa-forma"
                  >{{ $t("facturas.nueva.formaPago") }}
                  <span class="tu-obligatorio" aria-hidden="true"
                    >*</span
                  ></label
                >
                <SelectorBuscable
                  id="fa-forma"
                  v-model="formaPago"
                  :opciones="opcionesForma"
                  :placeholder="$t('datosFiscalesSat.factura.formaPh')"
                  data-prueba="forma-pago"
                />
              </div>
            </div>
          </div>

          <div>
            <label class="flex items-start gap-2 text-sm">
              <input
                v-model="esAnticipo"
                type="checkbox"
                class="mt-1"
                data-prueba="es-anticipo"
              />
              <span>
                <span class="font-medium">{{
                  $t("facturas.anticipo.es")
                }}</span>
                <span class="tu-hint block">{{
                  $t("facturas.anticipo.esAyuda")
                }}</span>
              </span>
            </label>
          </div>

          <div v-if="esAnticipo" class="max-w-xs">
            <label class="tu-label" for="fa-anticipo"
              >{{ $t("facturas.anticipo.importe") }}
              <span class="tu-obligatorio" aria-hidden="true">*</span></label
            >
            <input
              id="fa-anticipo"
              v-model="importeAnticipo"
              class="tu-input"
              type="number"
              min="0.01"
              step="0.01"
              required
              data-prueba="importe-anticipo"
            />
            <p class="tu-hint">{{ $t("facturas.anticipo.importeAyuda") }}</p>
          </div>

          <fieldset
            v-if="!esAnticipo && anticiposDisponibles.length > 0"
            data-prueba="anticipos-por-aplicar"
          >
            <legend class="font-semibold text-sm mb-2">
              {{ $t("facturas.anticipo.porAplicarTitulo") }}
            </legend>
            <label
              v-for="a in anticiposDisponibles"
              :key="a.id"
              class="flex items-center gap-2 text-sm mb-1"
            >
              <input
                v-model="anticiposElegidos"
                type="checkbox"
                :value="a.id"
                data-prueba="aplicar-anticipo"
              />
              {{
                $t("facturas.anticipo.opcion", {
                  total: dinero(a.total_minor, a.moneda),
                  fecha: fechaCorta(a.timbrada_en),
                  folio: (a.uuid ?? "").slice(0, 8),
                })
              }}
            </label>
            <p class="tu-hint">{{ $t("facturas.anticipo.restoAyuda") }}</p>
          </fieldset>

          <div v-if="!esAnticipo">
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
