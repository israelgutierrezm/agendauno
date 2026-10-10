<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoRegion from "@/components/AvisoRegion.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import TablaDatos from "@/components/TablaDatos.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
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
const cargando = ref(true);
const error = ref<string | null>(null);
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
    const { data } = await api.get<{ data: Factura[] }>(
      `${base.value}/facturas`,
    );
    facturas.value = data.data;
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
  error.value = null;
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
      error.value = e.response.data.data.motivo_error ?? mensajeDeError(e);
      await cargar();
    } else {
      error.value = mensajeDeError(e);
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
      :total="facturas.length"
    />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <TablaDatos
        class="mt-6"
        :columnas="columnas"
        :filas="facturas"
        :buscar="false"
        :vacio="$t('facturas.vacio')"
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
              <input
                v-model="receptor.nombre"
                class="tu-input"
                :placeholder="$t('facturas.nueva.nombre')"
                required
              />
              <input
                v-model="receptor.rfc"
                class="tu-input uppercase"
                :placeholder="$t('facturas.nueva.rfc')"
                required
              />
              <input
                v-model="receptor.email"
                class="tu-input"
                type="email"
                :placeholder="$t('facturas.nueva.email')"
              />
              <input
                v-model="receptor.codigo_postal"
                class="tu-input"
                inputmode="numeric"
                :placeholder="$t('facturas.nueva.codigoPostal')"
                required
              />
              <input
                v-model="usoCfdi"
                class="tu-input uppercase"
                :placeholder="$t('facturas.nueva.usoCfdi')"
                required
              />
              <input
                v-model="formaPago"
                class="tu-input"
                :placeholder="$t('facturas.nueva.formaPago')"
                required
              />
            </div>
          </div>

          <div>
            <h3 class="font-semibold text-sm mb-2">
              {{ $t("facturas.nueva.conceptos") }}
            </h3>
            <div
              v-for="(c, i) in conceptos"
              :key="i"
              class="grid sm:grid-cols-12 gap-2 items-start mb-2"
            >
              <input
                v-model="c.descripcion"
                class="tu-input sm:col-span-5"
                :placeholder="$t('facturas.nueva.descripcion')"
                required
              />
              <input
                v-model.number="c.cantidad"
                class="tu-input sm:col-span-2"
                type="number"
                min="1"
                :placeholder="$t('facturas.nueva.cantidad')"
                required
              />
              <input
                v-model="c.precio"
                class="tu-input sm:col-span-2"
                type="number"
                min="0"
                step="0.01"
                :placeholder="$t('facturas.nueva.precio')"
                required
              />
              <input
                v-model="c.clave_prod_serv"
                class="tu-input sm:col-span-2"
                :placeholder="$t('facturas.nueva.claveProdServ')"
                required
              />
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

          <p v-if="mensaje" class="text-sm" :style="{ color: 'var(--exito)' }">
            {{ $t("facturas.nueva.timbrada") }}
          </p>
          <p v-if="error" class="text-sm" style="color: var(--error)">
            {{ error }}
          </p>

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
