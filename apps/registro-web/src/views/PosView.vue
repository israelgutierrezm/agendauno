<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
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
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("inventario.gestionar"));
const puedeVender = computed(() => sesion.puede("pos.vender"));

const tab = ref<"vender" | "inventario">("vender");
const articulos = ref<Articulo[]>([]);
const sucursales = ref<Sucursal[]>([]);
const ventas = ref<Venta[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const accionando = ref(false);
const exito = ref<string | null>(null);

// POS
const sucursalSel = ref("");
const metodo = ref("efectivo");
const carrito = ref<
  Array<{ id: string; nombre: string; precio: number; cantidad: number }>
>([]);

// Inventario
const mostrarNuevo = ref(false);
const nuevo = ref({ nombre: "", sku: "", precio: "" });
const restockDe = ref<string | null>(null);
const restock = ref({ sucursal_id: "", cantidad: "" });

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
  restock.value = { sucursal_id: sucursales.value[0]?.id ?? "", cantidad: "" };
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
      tipo: "entrada",
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

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      icono="pos"
      :titulo="$t('pos.titulo')"
      :subtitulo="$t('pos.subtitulo')"
    />

    <div
      class="mt-6 flex gap-1 border-b"
      :style="{ borderColor: 'var(--borde)' }"
    >
      <button
        v-if="puedeVender"
        class="px-4 py-2 text-sm font-medium -mb-px border-b-2 transition"
        :style="{
          borderColor: tab === 'vender' ? 'var(--primario)' : 'transparent',
          color: tab === 'vender' ? 'var(--primario)' : 'var(--texto-suave)',
        }"
        @click="tab = 'vender'"
      >
        {{ $t("pos.tabVender") }}
      </button>
      <button
        v-if="puedeGestionar"
        class="px-4 py-2 text-sm font-medium -mb-px border-b-2 transition"
        :style="{
          borderColor: tab === 'inventario' ? 'var(--primario)' : 'transparent',
          color:
            tab === 'inventario' ? 'var(--primario)' : 'var(--texto-suave)',
        }"
        @click="tab = 'inventario'"
      >
        {{ $t("pos.tabInventario") }}
      </button>
    </div>

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
          <label class="tu-label" for="pos-suc">{{ $t("pos.sucursal") }}</label>
          <select id="pos-suc" v-model="sucursalSel" class="tu-input w-auto">
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>

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
          <h2 class="font-bold">{{ $t("pos.carrito") }}</h2>
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
            class="tu-btn tu-btn-primario"
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

        <p
          v-if="articulos.length === 0"
          class="mt-6 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("pos.inventario.sinArticulos") }}
        </p>
        <ul v-else class="mt-4 space-y-2">
          <li v-for="a in articulos" :key="a.id" class="tu-card p-3">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-semibold">{{ a.nombre }}</span>
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
              <button
                class="tu-enlace shrink-0"
                type="button"
                @click="abrirRestock(a.id)"
              >
                {{ $t("pos.inventario.reabastecer") }}
              </button>
            </div>
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
              <div class="w-24">
                <label class="tu-label" :for="`rc-${a.id}`">{{
                  $t("pos.inventario.cantidad")
                }}</label>
                <input
                  :id="`rc-${a.id}`"
                  v-model="restock.cantidad"
                  type="number"
                  min="1"
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
        <h2 class="mt-8 font-bold text-lg">{{ $t("pos.ventas.titulo") }}</h2>
        <p
          v-if="ventas.length === 0"
          class="mt-3 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("pos.ventas.vacio") }}
        </p>
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
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="v in ventas"
                :key="v.id"
                class="border-t"
                :style="{ borderColor: 'var(--borde)' }"
              >
                <td class="px-4 py-2">{{ v.sucursal ?? "—" }}</td>
                <td class="px-4 py-2 text-right font-semibold">
                  {{ dinero(v.total_minor, v.moneda) }}
                </td>
                <td
                  class="px-4 py-2 hidden sm:table-cell"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ v.metodo_pago }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </section>
</template>
