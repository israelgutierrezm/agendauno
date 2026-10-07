<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import LeyendaSucursal from "@/components/LeyendaSucursal.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Inventario con el patrón de los listados: indicadores de stock, búsqueda y filtros,
 * y la tabla de productos con su estado (con stock, stock bajo, sin stock). Qué es
 * «stock bajo» lo decide el negocio (parámetro `inventario.stock_bajo`); el API lo
 * calcula. Con una sucursal elegida en la barra, todo se cuenta en esa sucursal.
 */
type EstadoStock = "con_stock" | "bajo" | "sin_stock";
interface Existencia {
  sucursal_id: string | null;
  sucursal: string;
  stock: number;
  estado?: EstadoStock;
}
interface Articulo {
  id: string;
  nombre: string;
  sku: string | null;
  precio_minor: number;
  moneda: string;
  activo: boolean;
  stock_total: number;
  estado_stock?: EstadoStock;
  existencias: Existencia[];
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
    const [a, s] = await Promise.all([
      api.get<{ data: Articulo[]; meta?: { stock_bajo?: number } }>(
        `${base.value}/articulos`,
      ),
      api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`),
    ]);
    articulos.value = a.data.data;
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
const visibles = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  return articulos.value.filter(
    (a) =>
      (q === "" ||
        a.nombre.toLowerCase().includes(q) ||
        (a.sku ?? "").toLowerCase().includes(q)) &&
      (filtroVenta.value === "" ||
        (filtroVenta.value === "activo") === a.activo) &&
      (filtroStock.value === "" || estadoDe(a) === filtroStock.value),
  );
});
const hayFiltros = computed(
  () =>
    busqueda.value !== "" ||
    filtroVenta.value !== "" ||
    filtroStock.value !== "",
);
function limpiar(): void {
  busqueda.value = "";
  filtroVenta.value = "";
  filtroStock.value = "";
}

// ---- Alta y edición (un diálogo) ----
const editando = ref<Articulo | null>(null);
const dialogoProducto = ref(false);
const form = ref({ nombre: "", sku: "", precio: "", activo: true });
function nuevoProducto(): void {
  editando.value = null;
  form.value = { nombre: "", sku: "", precio: "", activo: true };
  dialogoProducto.value = true;
}
function editar(a: Articulo): void {
  editando.value = a;
  form.value = {
    nombre: a.nombre,
    sku: a.sku ?? "",
    precio: String(a.precio_minor / 100),
    activo: a.activo,
  };
  dialogoProducto.value = true;
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
      await api.post(`${base.value}/articulos`, datos);
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
          class="tu-btn tu-btn-primario tu-btn-crear"
          data-prueba="nuevo-producto"
          @click="nuevoProducto"
        >
          {{ $t("inventarioVisual.nuevo") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
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
                      <span class="iv-tile" aria-hidden="true">{{
                        iniciales(a.nombre)
                      }}</span>
                      <div class="min-w-0">
                        <p class="font-medium truncate">{{ a.nombre }}</p>
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
</style>
