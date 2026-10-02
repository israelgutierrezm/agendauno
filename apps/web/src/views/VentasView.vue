<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { RouterLink } from "vue-router";

import BuscarPersona from "@/components/BuscarPersona.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import TablaDatos from "@/components/TablaDatos.vue";
import { puedeEntrar } from "@/lib/acceso";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { SECCIONES, fechaLarga, seccionDe, type Plan } from "@/lib/planes";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Miembro {
  id: string;
  nombre: string;
  nombre_completo: string;
  email?: string | null;
}
type Producto = Plan;
type Orden = {
  id: string;
  comprador: string | null;
  estado: string;
  total_minor: number;
  moneda: string;
};

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeVender = computed(() => sesion.puede("ordenes.gestionar"));
const puedeVerPlanes = computed(() => sesion.puede("productos.ver"));

const miembros = ref<Miembro[]>([]);

// Para elegir a alguien escribiendo su nombre o correo (BuscarPersona).
const personasBuscables = computed(() =>
  miembros.value.map((m) => ({
    id: m.id,
    nombre: nombreMiembro(m),
    detalle: m.email ?? null,
  })),
);
const productos = ref<Producto[]>([]);
const ordenes = ref<Orden[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

const venta = ref({
  compradorId: "",
  productoId: "",
  metodo: "efectivo",
  codigoPromo: "",
});
const vendiendo = ref(false);
const exito = ref<string | null>(null);
const puedePromos = computed(() => sesion.puede("ordenes.gestionar"));
const promoPreview = ref<{ descuento: number; total: number } | null>(null);
const validandoPromo = ref(false);

// Solo los vendibles (no archivados) entran al selector, agrupados por tipo.
const productosVendibles = computed(() =>
  productos.value.filter((p) => !p.archivado),
);
const gruposVendibles = computed(() =>
  SECCIONES.map((s) => ({
    clave: s,
    productos: productosVendibles.value.filter((p) => seccionDe(p.tipo) === s),
  })).filter((g) => g.productos.length > 0),
);

const columnasOrdenes = computed(() => [
  { clave: "comprador", etiqueta: t("ventas.ordenes.colComprador") },
  {
    clave: "total_minor",
    etiqueta: t("ventas.ordenes.colTotal"),
    alinear: "derecha" as const,
  },
  {
    clave: "estado",
    etiqueta: t("ventas.ordenes.colEstado"),
    alinear: "derecha" as const,
  },
]);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
function nombreMiembro(m: Miembro): string {
  return m.nombre_completo || m.nombre;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [m, p, o] = await Promise.all([
      api.get<{ data: Miembro[] }>(`${base.value}/miembros`, {
        params: { tipo: "miembro" },
      }),
      api.get<{ data: Producto[] }>(`${base.value}/productos`, {
        params: { incluir: "todos" },
      }),
      api.get<{ data: Orden[] }>(`${base.value}/ordenes`),
    ]);
    miembros.value = m.data.data;
    productos.value = p.data.data;
    ordenes.value = o.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

const productoSel = computed(
  () => productos.value.find((p) => p.id === venta.value.productoId) ?? null,
);

async function validarPromo(): Promise<void> {
  promoPreview.value = null;
  const p = productoSel.value;
  if (p === null || venta.value.codigoPromo.trim() === "") {
    return;
  }
  validandoPromo.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{
      data: { descuento_minor: number; total_minor: number };
    }>(`${base.value}/promociones/validar`, {
      codigo: venta.value.codigoPromo,
      subtotal_minor: p.precio_minor,
    });
    promoPreview.value = {
      descuento: data.data.descuento_minor,
      total: data.data.total_minor,
    };
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    validandoPromo.value = false;
  }
}

async function vender(): Promise<void> {
  const producto = productoSel.value;
  const comprador = miembros.value.find(
    (m) => m.id === venta.value.compradorId,
  );
  if (producto === null || comprador === undefined) {
    return;
  }
  // Vender cobra en el momento: se confirma qué, cuánto, cómo y a quién.
  if (
    !(await confirmar(
      t("confirmaciones.venta", {
        producto: producto.nombre,
        monto: dinero(
          promoPreview.value?.total ?? producto.precio_minor,
          producto.moneda,
        ),
        metodo: t(`ventas.metodos.${venta.value.metodo}`),
        persona: nombreMiembro(comprador),
      }),
      { aceptar: t("confirmaciones.cobrar") },
    ))
  ) {
    return;
  }
  vendiendo.value = true;
  error.value = null;
  exito.value = null;
  try {
    const orden = await api.post<{ data: { id: string } }>(
      `${base.value}/ordenes`,
      {
        comprador_id: venta.value.compradorId,
        items: [{ producto_id: venta.value.productoId, cantidad: 1 }],
        codigo_promo:
          venta.value.codigoPromo.trim() !== ""
            ? venta.value.codigoPromo
            : undefined,
      },
    );
    await api.post(`${base.value}/ordenes/${orden.data.data.id}/liquidar`, {
      metodo: venta.value.metodo,
    });
  } catch (e) {
    error.value = mensajeDeError(e);
    vendiendo.value = false;
    return;
  }

  // Ya se cobró: desde aquí nada se muestra como error de la venta ni deja el
  // formulario listo para cobrar otra vez lo mismo.
  const compradorId = venta.value.compradorId;
  venta.value.productoId = "";
  venta.value.codigoPromo = "";
  promoPreview.value = null;
  const persona = miembros.value.find((x) => x.id === compradorId);
  const nombre = persona ? nombreMiembro(persona) : "";
  exito.value = "venta:" + nombre;
  try {
    // Muestra el derecho recien concedido al comprador.
    const der = await api.get<{
      data: Array<{ ilimitado: boolean; saldo: number | null }>;
    }>(`${base.value}/miembros/${compradorId}/derechos`);
    const ultimo = der.data.data[0];
    exito.value =
      ultimo && ultimo.ilimitado
        ? "membresia:" + nombre
        : "pack:" + nombre + "|" + String(ultimo?.saldo ?? 0);
  } catch {
    // Sin el detalle del derecho basta con confirmar la venta.
  }
  try {
    await cargar();
  } finally {
    vendiendo.value = false;
  }
}

const exitoTexto = computed(() => {
  if (exito.value === null) {
    return null;
  }
  if (exito.value.startsWith("venta:")) {
    return {
      clave: "ventas.vender.exitoVenta",
      args: { persona: exito.value.slice("venta:".length) },
    };
  }
  if (exito.value.startsWith("membresia:")) {
    return {
      clave: "ventas.vender.exitoMembresia",
      args: { persona: exito.value.slice("membresia:".length) },
    };
  }
  const resto = exito.value.slice("pack:".length);
  const [persona, saldo] = resto.split("|");
  return {
    clave: "ventas.vender.exitoPack",
    args: { persona, saldo: Math.round(Number(saldo) / 1000) },
  };
});

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 py-10">
    <EncabezadoSeccion
      :titulo="$t('planes.nav.vender')"
      :subtitulo="$t('ventas.vender.subtitulo')"
    >
      <!-- Los planes se definen en Configuración › Servicios y catálogos. -->
      <template v-if="puedeEntrar('planes', sesion)" #acciones>
        <RouterLink :to="{ name: 'planes' }" class="tu-btn tu-btn-fantasma">
          {{ $t("ventas.vender.administrarPlanes") }}
        </RouterLink>
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <div v-if="!cargando" class="mt-6 grid gap-6 md:grid-cols-2">
      <!-- Vender -->
      <div v-if="puedeVender" class="tu-card p-6">
        <h2 class="font-medium text-lg">{{ $t("ventas.vender.titulo") }}</h2>

        <p
          v-if="miembros.length === 0"
          class="mt-3 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("ventas.vender.sinMiembros") }}
        </p>
        <p
          v-else-if="productosVendibles.length === 0"
          class="mt-3 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("ventas.vender.sinProductos") }}
        </p>

        <form v-else class="mt-4 space-y-3" @submit.prevent="vender">
          <div>
            <label class="tu-label" for="vm">{{
              $t("ventas.vender.miembro")
            }}</label>
            <BuscarPersona
              v-model="venta.compradorId"
              campo-id="vm"
              :personas="personasBuscables"
            />
          </div>
          <div>
            <label class="tu-label" for="vp">{{
              $t("ventas.vender.producto")
            }}</label>
            <select
              id="vp"
              v-model="venta.productoId"
              class="tu-input"
              required
            >
              <option value="" disabled>
                {{ $t("ventas.vender.elegir") }}
              </option>
              <optgroup
                v-for="g in gruposVendibles"
                :key="g.clave"
                :label="$t(`planes.secciones.${g.clave}`)"
              >
                <option v-for="p in g.productos" :key="p.id" :value="p.id">
                  {{ p.nombre }} · {{ dinero(p.precio_minor, p.moneda) }}
                </option>
              </optgroup>
            </select>
            <p
              v-if="productoSel?.tipo === 'add_on'"
              class="mt-1 text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("planes.vender.extraAyuda") }}
            </p>
            <p
              v-else-if="productoSel?.vence_si_compra_hoy"
              class="mt-1 text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                $t("planes.vender.venceHoy", {
                  hasta: fechaLarga(productoSel.vence_si_compra_hoy),
                })
              }}
            </p>
          </div>
          <div>
            <label class="tu-label" for="vmet">{{
              $t("ventas.vender.metodo")
            }}</label>
            <select id="vmet" v-model="venta.metodo" class="tu-input">
              <option value="efectivo">
                {{ $t("ventas.metodos.efectivo") }}
              </option>
              <option value="transferencia">
                {{ $t("ventas.metodos.transferencia") }}
              </option>
              <option value="ventanilla">
                {{ $t("ventas.metodos.ventanilla") }}
              </option>
            </select>
          </div>
          <div v-if="puedePromos">
            <label class="tu-label" for="vpromo">{{
              $t("ventas.vender.promo")
            }}</label>
            <div class="flex gap-2">
              <input
                id="vpromo"
                v-model="venta.codigoPromo"
                class="tu-input uppercase"
                :placeholder="$t('ventas.vender.promoPlaceholder')"
                @input="promoPreview = null"
              />
              <button
                class="tu-btn tu-btn-fantasma shrink-0"
                type="button"
                :disabled="
                  validandoPromo ||
                  venta.productoId === '' ||
                  venta.codigoPromo.trim() === ''
                "
                @click="validarPromo"
              >
                {{ $t("ventas.vender.promoAplicar") }}
              </button>
            </div>
            <p
              v-if="promoPreview"
              class="mt-1 text-sm"
              :style="{ color: 'var(--exito)' }"
            >
              {{
                $t("ventas.vender.promoDescuento", {
                  monto: dinero(
                    promoPreview.descuento,
                    productoSel?.moneda ?? "MXN",
                  ),
                })
              }}
              ·
              {{
                $t("ventas.vender.promoTotal", {
                  monto: dinero(
                    promoPreview.total,
                    productoSel?.moneda ?? "MXN",
                  ),
                })
              }}
            </p>
          </div>
          <p
            v-if="exitoTexto"
            class="rounded-lg p-2.5 text-sm"
            :style="{ background: 'var(--exito-suave)', color: 'var(--exito)' }"
          >
            {{ $t(exitoTexto.clave, exitoTexto.args) }}
          </p>
          <button
            class="tu-btn tu-btn-primario w-full"
            type="submit"
            :disabled="
              vendiendo || venta.compradorId === '' || venta.productoId === ''
            "
          >
            {{
              vendiendo
                ? $t("ventas.vender.cobrando")
                : $t("ventas.vender.cobrar")
            }}
          </button>
        </form>
      </div>

      <!-- Qué se vende: se configura en Planes y paquetes -->
      <div v-if="puedeVerPlanes" class="self-start">
        <RouterLink :to="{ name: 'planes' }" class="tu-enlace text-sm">{{
          $t("planes.vender.configurar")
        }}</RouterLink>
      </div>
    </div>

    <!-- Ventas recientes -->
    <div v-if="!cargando" class="mt-6">
      <h2 class="font-medium text-lg mb-3">{{ $t("ventas.ordenes.titulo") }}</h2>
      <TablaDatos
        :columnas="columnasOrdenes"
        :filas="ordenes"
        :buscar-en="['comprador']"
        :por-pagina="8"
        :vacio="$t('ventas.ordenes.vacio')"
        clave-vista="ventas"
      >
        <template #col-comprador="{ valor }">
          <span class="font-medium">{{ valor ?? "—" }}</span>
        </template>
        <template #col-total_minor="{ fila }">
          {{ dinero((fila as Orden).total_minor, (fila as Orden).moneda) }}
        </template>
        <template #col-estado="{ valor }">
          <span
            class="tu-badge"
            :class="{ 'tu-badge-exito': valor === 'pagada' }"
          >
            {{
              valor === "pagada"
                ? $t("ventas.ordenes.pagada")
                : $t("ventas.ordenes.pendiente")
            }}
          </span>
        </template>
      </TablaDatos>
    </div>
  </section>
</template>
