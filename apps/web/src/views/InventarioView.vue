<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import CargadorImagen from "@/components/CargadorImagen.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import LeyendaSucursal from "@/components/LeyendaSucursal.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Inventario con el patrón de los listados: indicadores de stock, búsqueda y filtros,
 * y la tabla de productos con su estado (con stock, stock bajo, sin stock). Qué es
 * «stock bajo» lo decide el negocio (parámetro `inventario.stock_bajo`); el API lo
 * calcula. Con una sucursal elegida en la barra, todo se cuenta en esa sucursal.
 * Cada producto puede llevar una categoría del negocio (para filtrar aquí y en el
 * mostrador) y su foto.
 */
type EstadoStock = "con_stock" | "bajo" | "sin_stock";
interface Existencia {
  sucursal_id: string | null;
  sucursal: string;
  stock: number;
  estado?: EstadoStock;
}
interface Extra {
  id?: string;
  nombre: string;
  precio_minor: number;
}
interface Articulo {
  id: string;
  nombre: string;
  sku: string | null;
  precio_minor: number;
  extras?: Extra[];
  moneda: string;
  activo: boolean;
  stock_total: number;
  estado_stock?: EstadoStock;
  existencias: Existencia[];
  categoria?: { id: string; nombre: string; orden: number } | null;
  foto_url?: string | null;
}
interface Categoria {
  id: string;
  nombre: string;
  orden: number;
  productos: number;
}
interface Sucursal {
  id: string;
  nombre: string;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("inventario.gestionar"));

const articulos = ref<Articulo[]>([]);
const categorias = ref<Categoria[]>([]);
const sucursales = ref<Sucursal[]>([]);
const stockBajo = ref(3);
const cargando = ref(true);
const error = ref<string | null>(null);
const guardando = ref(false);

// Con una sucursal fija (la de la barra o la única), el stock es el de ahí.
const { fija: sucursalFija, mostrarSelect: elegirSucursal } =
  useSucursalOperativa();

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [a, s, c] = await Promise.all([
      api.get<{ data: Articulo[]; meta?: { stock_bajo?: number } }>(
        `${base.value}/articulos`,
      ),
      api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`),
      api.get<{ data: Categoria[] }>(`${base.value}/categorias-articulo`),
    ]);
    articulos.value = a.data.data;
    categorias.value = c.data.data;
    stockBajo.value = a.data.meta?.stock_bajo ?? stockBajo.value;
    sucursales.value = s.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// En la moneda del negocio (ADR 0099) y con los números de su país.
function dinero(minor: number, moneda = sesion.moneda): string {
  return dineroDelPais(minor, moneda, sesion.pais);
}

// El stock que cuenta: el de la sucursal fija o el total.
function existenciaFija(a: Articulo): Existencia | undefined {
  return a.existencias.find((e) => e.sucursal_id === sucursalFija.value);
}
function stockDe(a: Articulo): number {
  return sucursalFija.value !== null
    ? (existenciaFija(a)?.stock ?? 0)
    : a.stock_total;
}
function estadoDe(a: Articulo): EstadoStock {
  const delApi =
    sucursalFija.value !== null ? existenciaFija(a)?.estado : a.estado_stock;
  if (delApi) {
    return delApi;
  }
  const n = stockDe(a);
  return n <= 0 ? "sin_stock" : n <= stockBajo.value ? "bajo" : "con_stock";
}
const TONOS: Record<EstadoStock, string> = {
  con_stock: "var(--exito)",
  bajo: "var(--aviso)",
  sin_stock: "var(--error)",
};
function desglose(a: Articulo): string {
  return sucursalFija.value === null && a.existencias.length > 1
    ? a.existencias.map((e) => `${e.sucursal} ${e.stock}`).join(" · ")
    : "";
}
function iniciales(nombre: string): string {
  return nombre
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]!.toUpperCase())
    .join("");
}

// ---- Indicadores ----
const aLaVenta = computed(() => articulos.value.filter((a) => a.activo));
const indicadores = computed<Indicador[]>(() => {
  const contar = (e: EstadoStock): number =>
    aLaVenta.value.filter((a) => estadoDe(a) === e).length;
  return [
    {
      clave: "productos",
      etiqueta: t("inventarioVisual.kpi.productos"),
      valor: String(aLaVenta.value.length),
      icono: "pos",
    },
    {
      clave: "con_stock",
      etiqueta: t("inventarioVisual.estado.con_stock"),
      valor: String(contar("con_stock")),
      icono: "hecho",
    },
    {
      clave: "bajo",
      etiqueta: t("inventarioVisual.estado.bajo"),
      valor: String(contar("bajo")),
      icono: "abajo",
    },
    {
      clave: "sin_stock",
      etiqueta: t("inventarioVisual.estado.sin_stock"),
      valor: String(contar("sin_stock")),
      icono: "cerrar",
    },
  ];
});

// ---- Búsqueda y filtros ----
const busqueda = ref("");
const filtroVenta = ref<"" | "activo" | "inactivo">("");
const filtroStock = ref<"" | EstadoStock>("");
// Una categoría, «sin categoría» (`-`) o todas ("").
const filtroCategoria = ref("");
const visibles = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  return articulos.value.filter(
    (a) =>
      (q === "" ||
        a.nombre.toLowerCase().includes(q) ||
        (a.sku ?? "").toLowerCase().includes(q)) &&
      (filtroVenta.value === "" ||
        (filtroVenta.value === "activo") === a.activo) &&
      (filtroStock.value === "" || estadoDe(a) === filtroStock.value) &&
      (filtroCategoria.value === "" ||
        (a.categoria?.id ?? "-") === filtroCategoria.value),
  );
});
const hayFiltros = computed(
  () =>
    busqueda.value !== "" ||
    filtroVenta.value !== "" ||
    filtroStock.value !== "" ||
    filtroCategoria.value !== "",
);
function limpiar(): void {
  busqueda.value = "";
  filtroVenta.value = "";
  filtroStock.value = "";
  filtroCategoria.value = "";
}

// ---- Categorías (un diálogo para crearlas, renombrarlas y quitarlas) ----
const dialogoCategorias = ref(false);
const nuevaCategoria = ref("");
const nombres = ref<Record<string, string>>({});
function abrirCategorias(): void {
  nuevaCategoria.value = "";
  nombres.value = Object.fromEntries(
    categorias.value.map((c) => [c.id, c.nombre]),
  );
  dialogoCategorias.value = true;
}
async function recargarCategorias(): Promise<void> {
  const { data } = await api.get<{ data: Categoria[] }>(
    `${base.value}/categorias-articulo`,
  );
  categorias.value = data.data;
  nombres.value = Object.fromEntries(data.data.map((c) => [c.id, c.nombre]));
}
async function agregarCategoria(): Promise<void> {
  const nombre = nuevaCategoria.value.trim();
  if (nombre === "" || guardando.value) {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/categorias-articulo`, { nombre });
    nuevaCategoria.value = "";
    await recargarCategorias();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}
async function renombrarCategoria(c: Categoria): Promise<void> {
  const nombre = (nombres.value[c.id] ?? "").trim();
  if (nombre === "" || nombre === c.nombre || guardando.value) {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    await api.put(`${base.value}/categorias-articulo/${c.id}`, { nombre });
    await Promise.all([recargarCategorias(), cargar()]);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}
async function quitarCategoria(c: Categoria): Promise<void> {
  if (
    !(await confirmar(
      t("operacion.productos.confirmarQuitar", { nombre: c.nombre }),
      { aceptar: t("operacion.productos.quitar"), peligro: true },
    ))
  ) {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    await api.delete(`${base.value}/categorias-articulo/${c.id}`);
    if (filtroCategoria.value === c.id) {
      filtroCategoria.value = "";
    }
    toast.exito(t("operacion.productos.quitada"));
    await Promise.all([recargarCategorias(), cargar()]);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

// ---- Alta y edición (un diálogo) ----
const editando = ref<Articulo | null>(null);
const dialogoProducto = ref(false);
const form = ref({
  nombre: "",
  sku: "",
  precio: "",
  activo: true,
  categoria: "",
});
// La foto de un producto nuevo: se elige aquí y se sube al crearlo.
const fotoNueva = ref<File | null>(null);
const vistaFotoNueva = ref<string | null>(null);
function elegirFotoNueva(archivo: File): void {
  if (vistaFotoNueva.value) {
    URL.revokeObjectURL(vistaFotoNueva.value);
  }
  fotoNueva.value = archivo;
  vistaFotoNueva.value = URL.createObjectURL(archivo);
}
function olvidarFotoNueva(): void {
  if (vistaFotoNueva.value) {
    URL.revokeObjectURL(vistaFotoNueva.value);
  }
  fotoNueva.value = null;
  vistaFotoNueva.value = null;
}
// La foto del que se edita: se sube o se quita al momento.
function fotoCambiada(url: string | null): void {
  if (editando.value) {
    editando.value.foto_url = url;
    const a = articulos.value.find((x) => x.id === editando.value?.id);
    if (a) {
      a.foto_url = url;
    }
  }
}
// Extras del producto (ADR 0139): nombre y precio en pesos, como el producto.
const extras = ref<{ id?: string; nombre: string; precio: string }[]>([]);
function nuevoProducto(): void {
  editando.value = null;
  form.value = {
    nombre: "",
    sku: "",
    precio: "",
    activo: true,
    // Con un filtro de categoría puesto, el nuevo nace en ella.
    categoria: filtroCategoria.value !== "-" ? filtroCategoria.value : "",
  };
  extras.value = [];
  olvidarFotoNueva();
  dialogoProducto.value = true;
}
function editar(a: Articulo): void {
  editando.value = a;
  form.value = {
    nombre: a.nombre,
    sku: a.sku ?? "",
    precio: String(a.precio_minor / 100),
    activo: a.activo,
    categoria: a.categoria?.id ?? "",
  };
  extras.value = (a.extras ?? []).map((e) => ({
    id: e.id,
    nombre: e.nombre,
    precio: String(e.precio_minor / 100),
  }));
  dialogoProducto.value = true;
}
function agregarExtra(): void {
  extras.value.push({ nombre: "", precio: "" });
}
function quitarExtra(i: number): void {
  extras.value.splice(i, 1);
}
const formValido = computed(
  () =>
    form.value.nombre.trim() !== "" &&
    form.value.precio !== "" &&
    Number(form.value.precio) >= 0,
);
async function guardarProducto(): Promise<void> {
  guardando.value = true;
  error.value = null;
  const datos = {
    nombre: form.value.nombre.trim(),
    sku: form.value.sku.trim() !== "" ? form.value.sku.trim() : null,
    precio_minor: Math.round(Number(form.value.precio) * 100),
    categoria_id: form.value.categoria !== "" ? form.value.categoria : null,
    extras: extras.value
      .filter((e) => e.nombre.trim() !== "")
      .map((e) => ({
        id: e.id,
        nombre: e.nombre.trim(),
        precio_minor: Math.round(Number(e.precio || 0) * 100),
      })),
  };
  try {
    if (editando.value) {
      await api.put(`${base.value}/articulos/${editando.value.id}`, {
        ...datos,
        moneda: editando.value.moneda,
        activo: form.value.activo,
      });
      toast.exito(t("inventarioExtra.guardado"));
    } else {
      const { data } = await api.post<{ data: Articulo }>(
        `${base.value}/articulos`,
        datos,
      );
      // Con su foto, si se eligió una.
      if (fotoNueva.value) {
        const cuerpo = new FormData();
        cuerpo.append("foto", fotoNueva.value);
        await api.post(`${base.value}/articulos/${data.data.id}/foto`, cuerpo);
        olvidarFotoNueva();
      }
      toast.exito(t("inventarioVisual.creado"));
    }
    dialogoProducto.value = false;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

// ---- Movimiento de stock (entrada o ajuste) ----
const moviendo = ref<Articulo | null>(null);
const movimiento = ref<{
  sucursal_id: string;
  tipo: "entrada" | "ajuste";
  cantidad: string;
}>({ sucursal_id: "", tipo: "entrada", cantidad: "" });
function abrirMovimiento(a: Articulo): void {
  moviendo.value = a;
  movimiento.value = {
    sucursal_id: sucursalFija.value ?? sucursales.value[0]?.id ?? "",
    tipo: "entrada",
    cantidad: "",
  };
}
const movimientoValido = computed(() => {
  const n = Number(movimiento.value.cantidad);
  return (
    movimiento.value.sucursal_id !== "" &&
    Number.isInteger(n) &&
    n !== 0 &&
    (movimiento.value.tipo === "ajuste" || n > 0)
  );
});
async function guardarMovimiento(): Promise<void> {
  if (!moviendo.value) {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/articulos/${moviendo.value.id}/movimientos`, {
      sucursal_id: movimiento.value.sucursal_id,
      tipo: movimiento.value.tipo,
      cantidad: Number(movimiento.value.cantidad),
    });
    toast.exito(t("inventarioVisual.movido"));
    moviendo.value = null;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('inventarioVisual.titulo')"
      :subtitulo="$t('inventarioVisual.subtitulo')"
    >
      <template #acciones>
        <button
          v-if="puedeGestionar"
          type="button"
          class="tu-btn tu-btn-contorno tu-tono-neutral"
          data-prueba="categorias"
          @click="abrirCategorias"
        >
          {{ $t("operacion.productos.categorias") }}
        </button>
        <button
          v-if="puedeGestionar"
          type="button"
          class="tu-btn tu-btn-primario tu-btn-crear"
          data-prueba="nuevo-producto"
          @click="nuevoProducto"
        >
          {{ $t("inventarioVisual.nuevo") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
    </AvisoTono>
    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

    <template v-else>
      <EstadoVacio
        v-if="articulos.length === 0"
        class="tu-card mt-6"
        icono="pos"
        :titulo="$t('pos.inventario.sinArticulos')"
      />

      <template v-else>
        <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

        <div class="tu-card mt-5">
          <!-- Búsqueda y filtros -->
          <div class="iv-filtros">
            <label class="iv-buscar">
              <IconoNav nombre="buscar" :tam="16" />
              <input
                v-model="busqueda"
                type="search"
                class="tu-input"
                :placeholder="$t('inventarioVisual.buscar')"
                :aria-label="$t('inventarioVisual.buscar')"
                data-prueba="buscar"
              />
            </label>
            <label class="iv-filtro">
              <span>{{ $t("inventarioVisual.filtroVenta") }}</span>
              <select v-model="filtroVenta" class="tu-input">
                <option value="">{{ $t("inventarioVisual.todos") }}</option>
                <option value="activo">
                  {{ $t("inventarioExtra.activo") }}
                </option>
                <option value="inactivo">
                  {{ $t("inventarioExtra.inactivo") }}
                </option>
              </select>
            </label>
            <label class="iv-filtro">
              <span>{{ $t("inventarioVisual.filtroStock") }}</span>
              <select
                v-model="filtroStock"
                class="tu-input"
                data-prueba="filtro-stock"
              >
                <option value="">{{ $t("inventarioVisual.todos") }}</option>
                <option value="con_stock">
                  {{ $t("inventarioVisual.estado.con_stock") }}
                </option>
                <option value="bajo">
                  {{ $t("inventarioVisual.estado.bajo") }}
                </option>
                <option value="sin_stock">
                  {{ $t("inventarioVisual.estado.sin_stock") }}
                </option>
              </select>
            </label>
            <label v-if="categorias.length > 0" class="iv-filtro">
              <span>{{ $t("operacion.productos.categoria") }}</span>
              <select
                v-model="filtroCategoria"
                class="tu-input"
                data-prueba="filtro-categoria"
              >
                <option value="">{{ $t("operacion.productos.todas") }}</option>
                <option v-for="c in categorias" :key="c.id" :value="c.id">
                  {{ c.nombre }}
                </option>
                <option value="-">
                  {{ $t("operacion.productos.sinCategoria") }}
                </option>
              </select>
            </label>
          </div>

          <div class="relative overflow-x-auto">
            <table class="iv-tabla">
              <thead>
                <tr>
                  <th>{{ $t("inventarioVisual.col.producto") }}</th>
                  <th class="hidden sm:table-cell">
                    {{ $t("inventarioVisual.col.sku") }}
                  </th>
                  <th class="text-right">
                    {{ $t("inventarioVisual.col.precio") }}
                  </th>
                  <th class="text-right">
                    {{ $t("inventarioVisual.col.stock") }}
                  </th>
                  <th>{{ $t("inventarioVisual.col.estado") }}</th>
                  <th>
                    <span class="sr-only">{{
                      $t("inventarioVisual.col.acciones")
                    }}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="a in visibles"
                  :key="a.id"
                  :class="{ 'iv-inactivo': !a.activo }"
                  data-prueba="producto"
                >
                  <td>
                    <div class="flex items-center gap-3">
                      <img
                        v-if="a.foto_url"
                        :src="a.foto_url"
                        alt=""
                        class="iv-tile iv-foto"
                      />
                      <span v-else class="iv-tile" aria-hidden="true">{{
                        iniciales(a.nombre)
                      }}</span>
                      <div class="min-w-0">
                        <p class="font-medium truncate">{{ a.nombre }}</p>
                        <p
                          v-if="a.categoria"
                          class="iv-sub"
                          data-prueba="categoria-producto"
                        >
                          {{ a.categoria.nombre }}
                        </p>
                        <p v-if="!a.activo" class="iv-sub">
                          {{ $t("inventarioExtra.inactivo") }}
                        </p>
                        <p v-else-if="desglose(a)" class="iv-sub">
                          {{ desglose(a) }}
                        </p>
                      </div>
                    </div>
                  </td>
                  <td class="hidden sm:table-cell iv-suave">
                    {{ a.sku ?? "—" }}
                  </td>
                  <td class="text-right tabular-nums font-medium">
                    {{ dinero(a.precio_minor, a.moneda) }}
                  </td>
                  <td class="text-right tabular-nums" data-prueba="stock">
                    {{ stockDe(a) }}
                  </td>
                  <td>
                    <span
                      class="tu-pildora"
                      :style="{ '--tono': TONOS[estadoDe(a)] }"
                      :data-estado="estadoDe(a)"
                      >{{ $t(`inventarioVisual.estado.${estadoDe(a)}`) }}</span
                    >
                  </td>
                  <td class="text-right whitespace-nowrap">
                    <template v-if="puedeGestionar">
                      <button
                        type="button"
                        class="tu-enlace text-sm"
                        data-prueba="movimiento"
                        @click="abrirMovimiento(a)"
                      >
                        {{ $t("inventarioVisual.movimiento") }}
                      </button>
                      <button
                        type="button"
                        class="tu-enlace text-sm ml-4"
                        data-prueba="editar"
                        @click="editar(a)"
                      >
                        {{ $t("inventarioExtra.editar") }}
                      </button>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
            <div v-if="visibles.length === 0" class="iv-sin-resultados">
              {{ $t("inventarioVisual.sinResultados") }}
              <button
                v-if="hayFiltros"
                type="button"
                class="tu-enlace"
                @click="limpiar"
              >
                {{ $t("inventarioVisual.limpiar") }}
              </button>
            </div>
          </div>
        </div>
        <p class="mt-3 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("inventarioVisual.stockBajoAyuda", { n: stockBajo }) }}
        </p>
      </template>
    </template>

    <!-- Nuevo producto o editar -->
    <ModalDialogo
      :abierto="dialogoProducto"
      :titulo="
        editando
          ? $t('inventarioVisual.editarTitulo')
          : $t('inventarioVisual.nuevo')
      "
      icono="pos"
      @cerrar="dialogoProducto = false"
    >
      <form
        id="iv-producto"
        class="grid gap-4"
        @submit.prevent="guardarProducto"
      >
        <div>
          <label class="tu-label" for="iv-nombre">{{
            $t("pos.inventario.nombre")
          }}</label>
          <input
            id="iv-nombre"
            v-model="form.nombre"
            class="tu-input"
            maxlength="120"
            required
          />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="tu-label" for="iv-sku">{{
              $t("pos.inventario.sku")
            }}</label>
            <input id="iv-sku" v-model="form.sku" class="tu-input" />
          </div>
          <div>
            <label class="tu-label" for="iv-precio">{{
              $t("pos.inventario.precio")
            }}</label>
            <input
              id="iv-precio"
              v-model="form.precio"
              type="number"
              min="0"
              step="0.01"
              class="tu-input"
              required
            />
          </div>
        </div>
        <div v-if="categorias.length > 0">
          <label class="tu-label" for="iv-categoria">{{
            $t("operacion.productos.categoria")
          }}</label>
          <select
            id="iv-categoria"
            v-model="form.categoria"
            class="tu-input"
            data-prueba="categoria"
          >
            <option value="">
              {{ $t("operacion.productos.sinCategoria") }}
            </option>
            <option v-for="c in categorias" :key="c.id" :value="c.id">
              {{ c.nombre }}
            </option>
          </select>
        </div>
        <div data-prueba="foto-producto">
          <p class="tu-label">{{ $t("operacion.productos.foto") }}</p>
          <CargadorImagen
            v-if="editando"
            :url="editando.foto_url ?? null"
            :ruta="`articulos/${editando.id}/foto`"
            campo="foto"
            clave="foto_url"
            proporcion="1 / 1"
            :arrastra="$t('operacion.productos.fotoArrastra')"
            :ayuda="$t('operacion.productos.fotoAyuda')"
            :quitar-texto="$t('operacion.productos.fotoQuitar')"
            :puede-gestionar="puedeGestionar"
            @update:url="fotoCambiada"
          />
          <template v-else>
            <div v-if="vistaFotoNueva" class="iv-foto-nueva">
              <img :src="vistaFotoNueva" alt="" />
              <span class="text-xs" :style="{ color: 'var(--texto-suave)' }">{{
                $t("operacion.productos.fotoAlGuardar")
              }}</span>
              <button
                type="button"
                class="tu-btn tu-btn-contorno tu-tono-peligro tu-btn-chico"
                @click="olvidarFotoNueva"
              >
                {{ $t("operacion.productos.fotoQuitar") }}
              </button>
            </div>
            <ZonaArchivo
              v-else
              fija
              compacta
              icono="imagen"
              accept="image/png,image/jpeg,image/webp"
              :max-bytes="4 * 1024 * 1024"
              :texto="$t('operacion.productos.fotoArrastra')"
              :elige="$t('zonaArchivo.imagen.elige')"
              :ayuda="$t('operacion.productos.fotoAyuda')"
              @archivo="elegirFotoNueva"
            />
          </template>
        </div>
        <fieldset class="grid gap-2" data-prueba="extras-producto">
          <legend class="tu-label">
            {{ $t("inventarioVisual.extras.titulo") }}
          </legend>
          <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("inventarioVisual.extras.ayuda") }}
          </p>
          <div
            v-for="(e, i) in extras"
            :key="i"
            class="grid grid-cols-[minmax(0,1fr)_7rem_auto] items-center gap-2"
          >
            <input
              v-model="e.nombre"
              class="tu-input"
              maxlength="80"
              :aria-label="$t('inventarioVisual.extras.nombre')"
              :placeholder="$t('inventarioVisual.extras.nombre')"
            />
            <input
              v-model="e.precio"
              class="tu-input"
              type="number"
              min="0"
              step="0.01"
              inputmode="decimal"
              :aria-label="$t('inventarioVisual.extras.precio')"
              :placeholder="$t('inventarioVisual.extras.precio')"
            />
            <button
              type="button"
              class="tu-icono-btn"
              :aria-label="$t('inventarioVisual.extras.quitar')"
              @click="quitarExtra(i)"
            >
              <IconoNav nombre="cerrar" :tam="14" />
            </button>
          </div>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma justify-self-start"
            data-prueba="agregar-extra"
            @click="agregarExtra"
          >
            {{ $t("inventarioVisual.extras.agregar") }}
          </button>
        </fieldset>
        <label v-if="editando" class="flex items-center gap-2 text-sm">
          <input v-model="form.activo" type="checkbox" />
          {{ $t("inventarioExtra.activo") }}
        </label>
        <p
          v-if="!editando"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("inventarioVisual.altaAyuda") }}
        </p>
      </form>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          :disabled="guardando"
          @click="dialogoProducto = false"
        >
          {{ $t("pos.inventario.cancelar") }}
        </button>
        <button
          type="submit"
          form="iv-producto"
          class="tu-btn tu-btn-primario"
          data-prueba="guardar-producto"
          :disabled="guardando || !formValido"
        >
          {{ $t("pos.inventario.guardar") }}
        </button>
      </template>
    </ModalDialogo>

    <!-- Categorías de productos -->
    <ModalDialogo
      :abierto="dialogoCategorias"
      :titulo="$t('operacion.productos.categoriasTitulo')"
      icono="pos"
      @cerrar="dialogoCategorias = false"
    >
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("operacion.productos.categoriasAyuda") }}
      </p>
      <form
        class="mt-4 flex gap-2"
        data-prueba="nueva-categoria"
        @submit.prevent="agregarCategoria"
      >
        <input
          v-model="nuevaCategoria"
          class="tu-input"
          maxlength="60"
          :placeholder="$t('operacion.productos.nueva')"
          :aria-label="$t('operacion.productos.nueva')"
        />
        <button
          type="submit"
          class="tu-btn tu-btn-primario shrink-0"
          :disabled="guardando || nuevaCategoria.trim() === ''"
        >
          {{ $t("operacion.productos.agregar") }}
        </button>
      </form>
      <p
        v-if="categorias.length === 0"
        class="mt-4 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("operacion.productos.vacio") }}
      </p>
      <ul v-else class="mt-4 grid gap-2" data-prueba="lista-categorias">
        <li v-for="c in categorias" :key="c.id" class="iv-categoria">
          <input
            v-model="nombres[c.id]"
            class="tu-input"
            maxlength="60"
            :aria-label="$t('operacion.productos.categoria')"
            @keydown.enter.prevent="renombrarCategoria(c)"
          />
          <span class="iv-sub whitespace-nowrap">{{
            $t("operacion.productos.cuantos", c.productos)
          }}</span>
          <button
            v-if="(nombres[c.id] ?? '').trim() !== c.nombre"
            type="button"
            class="tu-btn tu-btn-suave tu-tono-info tu-btn-chico"
            :disabled="guardando || (nombres[c.id] ?? '').trim() === ''"
            @click="renombrarCategoria(c)"
          >
            {{ $t("operacion.productos.guardar") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-contorno tu-tono-peligro tu-btn-chico"
            :disabled="guardando"
            :data-prueba="`quitar-categoria-${c.id}`"
            @click="quitarCategoria(c)"
          >
            {{ $t("operacion.productos.quitar") }}
          </button>
        </li>
      </ul>
    </ModalDialogo>

    <!-- Entrada o ajuste de stock -->
    <ModalDialogo
      :abierto="moviendo !== null"
      :titulo="moviendo?.nombre ?? ''"
      icono="pos"
      @cerrar="moviendo = null"
    >
      <form
        id="iv-movimiento"
        class="grid gap-4"
        @submit.prevent="guardarMovimiento"
      >
        <div v-if="elegirSucursal">
          <label class="tu-label" for="iv-suc">{{ $t("pos.sucursal") }}</label>
          <select id="iv-suc" v-model="movimiento.sucursal_id" class="tu-input">
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </div>
        <LeyendaSucursal v-else />
        <div class="tu-segmentado self-start" role="group">
          <button
            v-for="tipo in ['entrada', 'ajuste'] as const"
            :key="tipo"
            type="button"
            :aria-pressed="movimiento.tipo === tipo"
            :data-prueba="`tipo-${tipo}`"
            @click="movimiento.tipo = tipo"
          >
            {{ $t(`inventarioVisual.tipo.${tipo}`) }}
          </button>
        </div>
        <div>
          <label class="tu-label" for="iv-cantidad">{{
            $t("pos.inventario.cantidad")
          }}</label>
          <input
            id="iv-cantidad"
            v-model="movimiento.cantidad"
            type="number"
            step="1"
            :min="movimiento.tipo === 'entrada' ? 1 : undefined"
            class="tu-input"
            data-prueba="cantidad"
          />
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{
              movimiento.tipo === "entrada"
                ? $t("inventarioVisual.entradaAyuda")
                : $t("inventarioExtra.ajusteAyuda")
            }}
          </p>
        </div>
      </form>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          :disabled="guardando"
          @click="moviendo = null"
        >
          {{ $t("pos.inventario.cancelar") }}
        </button>
        <button
          type="submit"
          form="iv-movimiento"
          class="tu-btn tu-btn-primario"
          data-prueba="guardar-movimiento"
          :disabled="guardando || !movimientoValido"
        >
          {{ $t("inventarioVisual.registrar") }}
        </button>
      </template>
    </ModalDialogo>
  </section>
</template>

<style scoped>
.iv-filtros {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.75rem;
  padding: 0.9rem 1rem;
  border-bottom: 1px solid var(--borde);
}
.iv-buscar {
  position: relative;
  flex: 1 1 16rem;
  color: var(--texto-suave);
}
.iv-buscar > :first-child {
  position: absolute;
  top: 50%;
  left: 0.75rem;
  transform: translateY(-50%);
}
.iv-buscar > input {
  padding-left: 2.25rem;
}
.iv-filtro {
  display: grid;
  gap: 0.25rem;
  min-width: 9.5rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
}
.iv-tabla {
  width: 100%;
  font-size: 0.9rem;
  border-collapse: collapse;
}
.iv-tabla th {
  padding: 0.7rem 1rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
  font-weight: 500;
  text-align: left;
}
.iv-tabla th.text-right {
  text-align: right;
}
.iv-tabla td {
  padding: 0.7rem 1rem;
  border-top: 1px solid var(--borde);
  vertical-align: middle;
}
.iv-inactivo td {
  color: var(--texto-suave);
}
.iv-tile {
  display: inline-grid;
  place-items: center;
  width: 2.5rem;
  height: 2.5rem;
  flex-shrink: 0;
  border: 1px solid var(--borde);
  border-radius: 0.5rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
  font-weight: 600;
}
.iv-sub {
  overflow: hidden;
  color: var(--texto-suave);
  font-size: 0.78rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.iv-suave {
  color: var(--texto-suave);
}
.iv-sin-resultados {
  display: flex;
  justify-content: center;
  gap: 0.75rem;
  padding: 2rem 1rem;
  border-top: 1px solid var(--borde);
  color: var(--texto-suave);
  font-size: 0.9rem;
}
.iv-foto {
  object-fit: cover;
}
.iv-foto-nueva {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}
.iv-foto-nueva img {
  width: 4.5rem;
  height: 4.5rem;
  border-radius: 0.6rem;
  object-fit: cover;
}
.iv-categoria {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto auto;
  align-items: center;
  gap: 0.5rem;
}
</style>
