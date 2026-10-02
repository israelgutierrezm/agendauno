<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute } from "vue-router";

import CorregirCobro from "@/components/CorregirCobro.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Existencia {
  sucursal_id: string | null;
  sucursal: string;
  stock: number;
}
interface Articulo {
  id: string;
  nombre: string;
  sku: string | null;
  precio_minor: number;
  moneda: string;
  activo: boolean;
  stock_total: number;
  existencias: Existencia[];
}
interface Sucursal {
  id: string;
  nombre: string;
}
interface Venta {
  id: string;
  sucursal: string | null;
  total_minor: number;
  moneda: string;
  metodo_pago: string;
  creado_en: string | null;
  lineas?: { articulo: string | null; cantidad: number }[];
  // Venta registrada por error: corregir su forma de pago o anularla (ADR 0089).
  anulada_en?: string | null;
  motivo_anulacion?: string | null;
  corregible?: boolean;
  anulable?: boolean;
}

const { t, te } = useI18n();

// Forma de pago a la vista (no el valor interno) y la venta que se corrige.
function nombreMetodo(m: string): string {
  return te(`pos.metodos.${m}`) ? t(`pos.metodos.${m}`) : m;
}
const corrigiendo = ref<string | null>(null);
async function alCorregirVenta(): Promise<void> {
  corrigiendo.value = null;
  await cargar();
}
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

// La vista sigue a la pestaña del área: Mostrador (/pos) o Inventario (/inventario).
const route = useRoute();
const tabDeRuta = () => (route.name === "inventario" ? "inventario" : "vender");
const tab = ref<"vender" | "inventario">(tabDeRuta());
watch(
  () => route.name,
  () => (tab.value = tabDeRuta()),
);
const articulos = ref<Articulo[]>([]);
const sucursales = ref<Sucursal[]>([]);
const ventas = ref<Venta[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const accionando = ref(false);
const exito = ref<string | null>(null);

// POS
const sucursalSel = ref("");
// Con una sucursal fija (la de la barra o la única), se vende ahí sin preguntar.
const { mostrarSelect: elegirSucursal, actual: sucursalActual } =
  useSucursalOperativa({ campo: sucursalSel });
const ventasVisibles = computed(() =>
  sucursalActual.value
    ? ventas.value.filter((v) => v.sucursal === sucursalActual.value!.nombre)
    : ventas.value,
);
const metodo = ref("efectivo");
const carrito = ref<
  Array<{ id: string; nombre: string; precio: number; cantidad: number }>
>([]);

// Inventario
const mostrarNuevo = ref(false);
const nuevo = ref({ nombre: "", sku: "", precio: "" });
const restockDe = ref<string | null>(null);
const restock = ref<{
  sucursal_id: string;
  cantidad: string;
  tipo: "entrada" | "ajuste";
}>({ sucursal_id: "", cantidad: "", tipo: "entrada" });
const editandoDe = ref<string | null>(null);
const edicion = ref({ nombre: "", sku: "", precio: "", activo: true });

function dinero(minor: number, moneda = "MXN"): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

function stockEn(articulo: Articulo, sucursalId: string): number {
  return (
    articulo.existencias.find((e) => e.sucursal_id === sucursalId)?.stock ?? 0
  );
}

const articulosVendibles = computed(() =>
  articulos.value.filter((a) => a.activo),
);
const totalCarrito = computed(() =>
  carrito.value.reduce((s, l) => s + l.precio * l.cantidad, 0),
);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [a, s, v] = await Promise.all([
      api.get<{ data: Articulo[] }>(`${base.value}/articulos`),
      api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`),
      api.get<{ data: Venta[] }>(`${base.value}/pos/ventas`),
    ]);
    articulos.value = a.data.data;
    sucursales.value = s.data.data;
    ventas.value = v.data.data;
    if (sucursalSel.value === "" && sucursales.value.length > 0) {
      sucursalSel.value = sucursales.value[0].id;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function agregar(a: Articulo): void {
  const disp = stockEn(a, sucursalSel.value);
  const linea = carrito.value.find((l) => l.id === a.id);
  const enCarrito = linea?.cantidad ?? 0;
  if (enCarrito >= disp) {
    return;
  }
  if (linea) {
    linea.cantidad++;
  } else {
    carrito.value.push({
      id: a.id,
      nombre: a.nombre,
      precio: a.precio_minor,
      cantidad: 1,
    });
  }
}

function quitar(id: string): void {
  carrito.value = carrito.value.filter((l) => l.id !== id);
}

async function cobrar(): Promise<void> {
  if (carrito.value.length === 0 || sucursalSel.value === "") {
    return;
  }
  if (
    !(await confirmar(
      t("confirmaciones.pos", {
        total: dinero(totalCarrito.value),
        metodo: t(`pos.metodos.${metodo.value}`),
      }),
      { aceptar: t("confirmaciones.cobrar") },
    ))
  ) {
    return;
  }
  accionando.value = true;
  error.value = null;
  exito.value = null;
  try {
    await api.post(`${base.value}/pos/ventas`, {
      sucursal_id: sucursalSel.value,
      metodo_pago: metodo.value,
      items: carrito.value.map((l) => ({
        articulo_id: l.id,
        cantidad: l.cantidad,
      })),
    });
    exito.value = t("pos.vendido");
    carrito.value = [];
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function crearArticulo(): Promise<void> {
  if (nuevo.value.nombre.trim() === "" || nuevo.value.precio === "") {
    return;
  }
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/articulos`, {
      nombre: nuevo.value.nombre,
      sku: nuevo.value.sku !== "" ? nuevo.value.sku : null,
      precio_minor: Math.round(Number(nuevo.value.precio) * 100),
    });
    nuevo.value = { nombre: "", sku: "", precio: "" };
    mostrarNuevo.value = false;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

function abrirRestock(articuloId: string): void {
  restockDe.value = articuloId;
  editandoDe.value = null;
  restock.value = {
    sucursal_id: sucursales.value[0]?.id ?? "",
    cantidad: "",
    tipo: "entrada",
  };
}

async function guardarRestock(articuloId: string): Promise<void> {
  if (restock.value.sucursal_id === "" || restock.value.cantidad === "") {
    return;
  }
  accionando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/articulos/${articuloId}/movimientos`, {
      sucursal_id: restock.value.sucursal_id,
      tipo: restock.value.tipo,
      cantidad: Number(restock.value.cantidad),
    });
    restockDe.value = null;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

function abrirEdicion(a: Articulo): void {
  restockDe.value = null;
  editandoDe.value = a.id;
  edicion.value = {
    nombre: a.nombre,
    sku: a.sku ?? "",
    precio: String(a.precio_minor / 100),
    activo: a.activo,
  };
}

async function guardarEdicion(a: Articulo): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    await api.put(`${base.value}/articulos/${a.id}`, {
      nombre: edicion.value.nombre,
      sku: edicion.value.sku !== "" ? edicion.value.sku : null,
      precio_minor: Math.round(Number(edicion.value.precio) * 100),
      moneda: a.moneda,
      activo: edicion.value.activo,
    });
    editandoDe.value = null;
    exito.value = t("inventarioExtra.guardado");
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t('pos.titulo')" />

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p
      v-if="cargando"
      class="mt-6 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <p
        v-if="sucursales.length === 0"
        class="mt-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("pos.sinSucursales") }}
      </p>

      <!-- ===== Vender ===== -->
      <div v-else-if="tab === 'vender'" class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
          <template v-if="elegirSucursal">
            <label class="tu-label" for="pos-suc">{{
              $t("pos.sucursal")
            }}</label>
            <select id="pos-suc" v-model="sucursalSel" class="tu-input w-auto">
              <option v-for="s in sucursales" :key="s.id" :value="s.id">
                {{ s.nombre }}
              </option>
            </select>
          </template>

          <p
            v-if="articulosVendibles.length === 0"
            class="mt-4 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("pos.sinArticulos") }}
          </p>
          <div v-else class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-3">
            <button
              v-for="a in articulosVendibles"
              :key="a.id"
              type="button"
              class="tu-card p-3 text-left transition hover:shadow-md disabled:opacity-50"
              :disabled="stockEn(a, sucursalSel) === 0"
              @click="agregar(a)"
            >
              <div class="font-semibold truncate">{{ a.nombre }}</div>
              <div class="text-sm mt-1">
                {{ dinero(a.precio_minor, a.moneda) }}
              </div>
              <div
                class="text-xs mt-1"
                :style="{
                  color:
                    stockEn(a, sucursalSel) === 0
                      ? 'var(--error)'
                      : 'var(--texto-suave)',
                }"
              >
                {{
                  stockEn(a, sucursalSel) === 0
                    ? $t("pos.agotado")
                    : `${$t("pos.stock")}: ${stockEn(a, sucursalSel)}`
                }}
              </div>
            </button>
          </div>
        </div>

        <!-- Carrito -->
        <div class="tu-card p-4 h-fit">
          <h2 class="font-medium">{{ $t("pos.carrito") }}</h2>
          <p
            v-if="carrito.length === 0"
            class="mt-2 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("pos.carritoVacio") }}
          </p>
          <ul v-else class="mt-2 space-y-2">
            <li
              v-for="l in carrito"
              :key="l.id"
              class="flex items-center justify-between gap-2 text-sm"
            >
              <span class="min-w-0"
                ><span class="font-semibold">{{ l.cantidad }}×</span>
                {{ l.nombre }}</span
              >
              <span class="flex items-center gap-2 shrink-0">
                {{ dinero(l.precio * l.cantidad) }}
                <button
                  class="tu-enlace"
                  style="color: var(--error)"
                  type="button"
                  @click="quitar(l.id)"
                >
                  ✕
                </button>
              </span>
            </li>
          </ul>
          <div class="mt-3">
            <label class="tu-label" for="pos-met">{{ $t("pos.metodo") }}</label>
            <select id="pos-met" v-model="metodo" class="tu-input">
              <option value="efectivo">{{ $t("pos.metodos.efectivo") }}</option>
              <option value="tarjeta">{{ $t("pos.metodos.tarjeta") }}</option>
              <option value="transferencia">
                {{ $t("pos.metodos.transferencia") }}
              </option>
            </select>
          </div>
          <div class="mt-3 flex items-center justify-between font-bold">
            <span>{{ $t("pos.total") }}</span
            ><span>{{ dinero(totalCarrito) }}</span>
          </div>
          <p
            v-if="exito"
            class="mt-2 text-sm"
            :style="{ color: 'var(--exito)' }"
          >
            {{ exito }}
          </p>
          <button
            class="tu-btn tu-btn-primario w-full mt-3"
            type="button"
            :disabled="accionando || carrito.length === 0"
            @click="cobrar"
          >
            {{ accionando ? $t("pos.cobrando") : $t("pos.cobrar") }}
          </button>
        </div>
      </div>

      <!-- ===== Inventario ===== -->
      <div v-else-if="tab === 'inventario'" class="mt-6">
        <div class="flex justify-end">
          <button
            class="tu-btn tu-btn-primario tu-btn-crear"
            type="button"
            @click="mostrarNuevo = !mostrarNuevo"
          >
            {{ $t("pos.inventario.nuevo") }}
          </button>
        </div>

        <form
          v-if="mostrarNuevo"
          class="mt-4 tu-card p-4 grid gap-3 sm:grid-cols-3"
          @submit.prevent="crearArticulo"
        >
          <div>
            <label class="tu-label" for="a-nombre">{{
              $t("pos.inventario.nombre")
            }}</label>
            <input
              id="a-nombre"
              v-model="nuevo.nombre"
              class="tu-input"
              required
            />
          </div>
          <div>
            <label class="tu-label" for="a-sku">{{
              $t("pos.inventario.sku")
            }}</label>
            <input id="a-sku" v-model="nuevo.sku" class="tu-input" />
          </div>
          <div>
            <label class="tu-label" for="a-precio">{{
              $t("pos.inventario.precio")
            }}</label>
            <input
              id="a-precio"
              v-model="nuevo.precio"
              type="number"
              min="0"
              step="0.01"
              class="tu-input"
              required
            />
          </div>
          <div class="sm:col-span-3 flex gap-2">
            <button
              class="tu-btn tu-btn-primario"
              type="submit"
              :disabled="accionando"
            >
              {{ $t("pos.inventario.guardar") }}
            </button>
            <button
              class="tu-btn tu-btn-fantasma"
              type="button"
              @click="mostrarNuevo = false"
            >
              {{ $t("pos.inventario.cancelar") }}
            </button>
          </div>
        </form>

        <EstadoVacio
          v-if="articulos.length === 0"
          class="tu-card mt-6"
          icono="pos"
          :titulo="$t('pos.inventario.sinArticulos')"
        />
        <ul v-else class="mt-4 space-y-2">
          <li v-for="a in articulos" :key="a.id" class="tu-card p-3">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span
                    class="font-semibold"
                    :style="a.activo ? {} : { color: 'var(--texto-suave)' }"
                    >{{ a.nombre }}</span
                  >
                  <span v-if="!a.activo" class="tu-badge">{{
                    $t("inventarioExtra.inactivo")
                  }}</span>
                  <span class="tu-badge">{{
                    dinero(a.precio_minor, a.moneda)
                  }}</span>
                  <span
                    class="tu-badge"
                    :class="
                      a.stock_total > 0 ? 'tu-badge-exito' : 'tu-badge-aviso'
                    "
                  >
                    {{ $t("pos.inventario.stockTotal") }}: {{ a.stock_total }}
                  </span>
                </div>
                <div
                  v-if="a.existencias.length > 0"
                  class="text-xs mt-1"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{
                    a.existencias
                      .map((e) => `${e.sucursal}: ${e.stock}`)
                      .join(" · ")
                  }}
                </div>
              </div>
              <span class="flex items-center gap-3 shrink-0">
                <button
                  class="tu-enlace"
                  type="button"
                  @click="abrirEdicion(a)"
                >
                  {{ $t("inventarioExtra.editar") }}
                </button>
                <button
                  class="tu-enlace"
                  type="button"
                  @click="abrirRestock(a.id)"
                >
                  {{ $t("pos.inventario.reabastecer") }}
                </button>
              </span>
            </div>
            <form
              v-if="editandoDe === a.id"
              class="mt-2 flex flex-wrap items-end gap-2 rounded-md p-2"
              :style="{ background: 'var(--fondo-suave)' }"
              @submit.prevent="guardarEdicion(a)"
            >
              <div class="min-w-[10rem] flex-1">
                <label class="tu-label" :for="`en-${a.id}`">{{
                  $t("pos.inventario.nombre")
                }}</label>
                <input
                  :id="`en-${a.id}`"
                  v-model="edicion.nombre"
                  class="tu-input"
                  required
                />
              </div>
              <div class="w-32">
                <label class="tu-label" :for="`es-${a.id}`">{{
                  $t("pos.inventario.sku")
                }}</label>
                <input
                  :id="`es-${a.id}`"
                  v-model="edicion.sku"
                  class="tu-input"
                />
              </div>
              <div class="w-28">
                <label class="tu-label" :for="`ep-${a.id}`">{{
                  $t("pos.inventario.precio")
                }}</label>
                <input
                  :id="`ep-${a.id}`"
                  v-model="edicion.precio"
                  type="number"
                  min="0"
                  step="0.01"
                  class="tu-input"
                  required
                />
              </div>
              <label class="flex items-center gap-2 pb-2 text-sm">
                <input v-model="edicion.activo" type="checkbox" />
                {{ $t("inventarioExtra.activo") }}
              </label>
              <button
                class="tu-btn tu-btn-primario"
                type="submit"
                :disabled="accionando"
              >
                {{ $t("pos.inventario.guardar") }}
              </button>
              <button
                class="tu-btn tu-btn-fantasma"
                type="button"
                @click="editandoDe = null"
              >
                {{ $t("pos.inventario.cancelar") }}
              </button>
            </form>
            <form
              v-if="restockDe === a.id"
              class="mt-2 flex flex-wrap items-end gap-2 rounded-md p-2"
              :style="{ background: 'var(--fondo-suave)' }"
              @submit.prevent="guardarRestock(a.id)"
            >
              <div>
                <label class="tu-label" :for="`rs-${a.id}`">{{
                  $t("pos.sucursal")
                }}</label>
                <select
                  :id="`rs-${a.id}`"
                  v-model="restock.sucursal_id"
                  class="tu-input w-auto"
                >
                  <option v-for="s in sucursales" :key="s.id" :value="s.id">
                    {{ s.nombre }}
                  </option>
                </select>
              </div>
              <div>
                <label class="tu-label" :for="`rt-${a.id}`">{{
                  $t("inventarioExtra.movimiento")
                }}</label>
                <select
                  :id="`rt-${a.id}`"
                  v-model="restock.tipo"
                  class="tu-input w-auto"
                >
                  <option value="entrada">
                    {{ $t("inventarioExtra.entrada") }}
                  </option>
                  <option value="ajuste">
                    {{ $t("inventarioExtra.ajuste") }}
                  </option>
                </select>
              </div>
              <div class="w-24">
                <label class="tu-label" :for="`rc-${a.id}`">{{
                  $t("pos.inventario.cantidad")
                }}</label>
                <input
                  :id="`rc-${a.id}`"
                  v-model="restock.cantidad"
                  type="number"
                  :min="restock.tipo === 'entrada' ? 1 : undefined"
                  :title="
                    restock.tipo === 'ajuste'
                      ? $t('inventarioExtra.ajusteAyuda')
                      : undefined
                  "
                  class="tu-input"
                />
              </div>
              <button
                class="tu-btn tu-btn-primario"
                type="submit"
                :disabled="accionando"
              >
                {{ $t("pos.inventario.agregarStock") }}
              </button>
              <button
                class="tu-btn tu-btn-fantasma"
                type="button"
                @click="restockDe = null"
              >
                {{ $t("pos.inventario.cancelar") }}
              </button>
            </form>
          </li>
        </ul>

        <!-- Ventas recientes -->
        <h2 class="mt-8 font-medium text-lg">{{ $t("pos.ventas.titulo") }}</h2>
        <EstadoVacio
          v-if="ventasVisibles.length === 0"
          class="mt-3 py-6"
          icono="ventas"
          compacto
          :titulo="$t('pos.ventas.vacio')"
        />
        <div v-else class="mt-3 tu-card overflow-hidden">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
                <th class="px-4 py-2 font-medium">
                  {{ $t("pos.ventas.colSucursal") }}
                </th>
                <th class="px-4 py-2 font-medium text-right">
                  {{ $t("pos.ventas.colTotal") }}
                </th>
                <th class="px-4 py-2 font-medium hidden sm:table-cell">
                  {{ $t("pos.ventas.colMetodo") }}
                </th>
                <th class="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="v in ventasVisibles" :key="v.id">
                <tr class="border-t" :style="{ borderColor: 'var(--borde)' }">
                  <td class="px-4 py-2">
                    <span class="block">{{ v.sucursal ?? "—" }}</span>
                    <span
                      v-if="v.lineas?.length"
                      class="block text-xs"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{
                        v.lineas
                          .map((l) => `${l.cantidad} × ${l.articulo ?? ""}`)
                          .join(", ")
                      }}</span
                    >
                  </td>
                  <td
                    class="px-4 py-2 text-right font-semibold"
                    :class="{ 'line-through': v.anulada_en }"
                  >
                    {{ dinero(v.total_minor, v.moneda) }}
                  </td>
                  <td
                    class="px-4 py-2 hidden sm:table-cell"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    <span
                      v-if="v.anulada_en"
                      class="inline-flex items-center gap-1.5"
                      :style="{ color: 'var(--error)' }"
                      :title="v.motivo_anulacion ?? undefined"
                      data-prueba="venta-anulada"
                      ><span
                        class="h-2 w-2 rounded-full"
                        :style="{ background: 'currentColor' }"
                        aria-hidden="true"
                      ></span
                      >{{ $t("corregirCobro.anulada") }}</span
                    >
                    <template v-else>{{
                      nombreMetodo(v.metodo_pago)
                    }}</template>
                  </td>
                  <td class="px-4 py-2 text-right">
                    <button
                      v-if="!v.anulada_en && (v.corregible || v.anulable)"
                      class="tu-enlace text-sm"
                      type="button"
                      data-prueba="corregir-venta"
                      :aria-expanded="corrigiendo === v.id"
                      @click="corrigiendo = corrigiendo === v.id ? null : v.id"
                    >
                      {{ $t("corregirCobro.corregir") }}
                    </button>
                  </td>
                </tr>
                <tr v-if="corrigiendo === v.id">
                  <td colspan="4" class="px-4 pb-3">
                    <CorregirCobro
                      :base="base"
                      :pago="{
                        id: v.id,
                        metodo: v.metodo_pago,
                        corregible: v.corregible === true,
                        anulable: v.anulable === true,
                      }"
                      :url-metodo="`${base}/pos/ventas/${v.id}/metodo`"
                      :url-anular="`${base}/pos/ventas/${v.id}/anular`"
                      :metodos="['efectivo', 'tarjeta', 'transferencia']"
                      permiso-metodo="pos.vender"
                      contexto="venta"
                      @cambiado="alCorregirVenta"
                    />
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </section>
</template>
