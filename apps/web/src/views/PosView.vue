<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import CorregirCobro from "@/components/CorregirCobro.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import LeyendaSucursal from "@/components/LeyendaSucursal.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Mostrador: vender productos del inventario y cobrar en caja. A la izquierda los
 * productos de la sucursal (con su existencia), a la derecha la venta actual, y
 * debajo las ventas recientes (con su corrección, ADR 0089). Los productos y su stock
 * se administran en Inventario.
 */
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

const METODOS = ["efectivo", "tarjeta", "transferencia"] as const;

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeVender = computed(() => sesion.puede("pos.vender"));

// Forma de pago a la vista (no el valor interno) y la venta que se corrige.
function nombreMetodo(m: string): string {
  return te(`pos.metodos.${m}`) ? t(`pos.metodos.${m}`) : m;
}
const corrigiendo = ref<string | null>(null);
async function alCorregirVenta(): Promise<void> {
  corrigiendo.value = null;
  await cargar();
}

const articulos = ref<Articulo[]>([]);
const sucursales = ref<Sucursal[]>([]);
const ventas = ref<Venta[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const cobrando = ref(false);

const sucursalSel = ref("");
// Con una sucursal fija (la de la barra o la única), se vende ahí sin preguntar.
const { mostrarSelect: elegirSucursal, actual: sucursalActual } =
  useSucursalOperativa({ campo: sucursalSel });
const ventasVisibles = computed(() =>
  sucursalActual.value
    ? ventas.value.filter((v) => v.sucursal === sucursalActual.value!.nombre)
    : ventas.value,
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
      sucursalSel.value = sucursales.value[0]!.id;
    }
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
function stockEn(articulo: Articulo): number {
  return (
    articulo.existencias.find((e) => e.sucursal_id === sucursalSel.value)
      ?.stock ?? 0
  );
}
function iniciales(nombre: string): string {
  return nombre
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]!.toUpperCase())
    .join("");
}
function hora(iso: string | null): string {
  if (!iso) {
    return "—";
  }
  const d = new Date(iso);
  const hoy = new Date().toDateString() === d.toDateString();
  return new Intl.DateTimeFormat(
    "es-MX",
    hoy
      ? { hour: "numeric", minute: "2-digit" }
      : { day: "numeric", month: "short", hour: "numeric", minute: "2-digit" },
  ).format(d);
}

// ---- Productos a la venta ----
const busqueda = ref("");
const vendibles = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  return articulos.value.filter(
    (a) =>
      a.activo &&
      (q === "" ||
        a.nombre.toLowerCase().includes(q) ||
        (a.sku ?? "").toLowerCase().includes(q)),
  );
});

// ---- Venta actual ----
const metodo = ref<(typeof METODOS)[number]>("efectivo");
const carrito = ref<
  Array<{ id: string; nombre: string; precio: number; cantidad: number }>
>([]);
const totalCarrito = computed(() =>
  carrito.value.reduce((s, l) => s + l.precio * l.cantidad, 0),
);
const piezas = computed(() =>
  carrito.value.reduce((s, l) => s + l.cantidad, 0),
);
function enCarrito(id: string): number {
  return carrito.value.find((l) => l.id === id)?.cantidad ?? 0;
}
function agregar(a: Articulo): void {
  if (enCarrito(a.id) >= stockEn(a)) {
    return;
  }
  const linea = carrito.value.find((l) => l.id === a.id);
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
function restar(id: string): void {
  const linea = carrito.value.find((l) => l.id === id);
  if (!linea) {
    return;
  }
  linea.cantidad--;
  if (linea.cantidad <= 0) {
    quitar(id);
  }
}
function sumar(id: string): void {
  const a = articulos.value.find((x) => x.id === id);
  if (a) {
    agregar(a);
  }
}
function quitar(id: string): void {
  carrito.value = carrito.value.filter((l) => l.id !== id);
}
// Al cambiar de sucursal, la venta empieza de nuevo (otro stock).
function cambiarSucursal(): void {
  carrito.value = [];
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
  cobrando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/pos/ventas`, {
      sucursal_id: sucursalSel.value,
      metodo_pago: metodo.value,
      items: carrito.value.map((l) => ({
        articulo_id: l.id,
        cantidad: l.cantidad,
      })),
    });
    toast.exito(t("pos.vendido"));
    carrito.value = [];
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cobrando.value = false;
  }
}

// ---- Indicadores del día ----
const deHoy = computed(() => {
  const hoy = new Date().toDateString();
  return ventasVisibles.value.filter(
    (v) =>
      !v.anulada_en &&
      v.creado_en !== null &&
      new Date(v.creado_en).toDateString() === hoy,
  );
});
const indicadores = computed<Indicador[]>(() => {
  const total = deHoy.value.reduce((s, v) => s + v.total_minor, 0);
  const sinStock = articulos.value.filter(
    (a) => a.activo && stockEn(a) <= 0,
  ).length;
  return [
    {
      clave: "ventas",
      etiqueta: t("mostradorVisual.kpi.ventasHoy"),
      valor: String(deHoy.value.length),
      icono: "ventas",
    },
    {
      clave: "total",
      etiqueta: t("mostradorVisual.kpi.vendidoHoy"),
      valor: dinero(total),
      icono: "dinero",
    },
    {
      clave: "ticket",
      etiqueta: t("mostradorVisual.kpi.ticket"),
      valor:
        deHoy.value.length > 0
          ? dinero(Math.round(total / deHoy.value.length))
          : "—",
      icono: "etiqueta",
    },
    {
      clave: "sin_stock",
      etiqueta: t("mostradorVisual.kpi.sinStock"),
      valor: String(sinStock),
      icono: "cerrar",
    },
  ];
});

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('mostradorVisual.titulo')"
      :subtitulo="$t('mostradorVisual.subtitulo')"
    />

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
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

      <template v-else>
        <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

        <div class="mt-5 grid gap-5 lg:grid-cols-3">
          <!-- Productos -->
          <div class="tu-card lg:col-span-2">
            <div class="mo-barra">
              <label class="mo-buscar">
                <IconoNav nombre="buscar" :tam="16" />
                <input
                  v-model="busqueda"
                  type="search"
                  class="tu-input"
                  :placeholder="$t('mostradorVisual.buscar')"
                  :aria-label="$t('mostradorVisual.buscar')"
                />
              </label>
              <label v-if="elegirSucursal" class="tu-select-icono">
                <IconoNav nombre="ubicacion" :tam="16" />
                <select
                  id="pos-suc"
                  v-model="sucursalSel"
                  class="tu-input"
                  :aria-label="$t('pos.sucursal')"
                  @change="cambiarSucursal"
                >
                  <option v-for="s in sucursales" :key="s.id" :value="s.id">
                    {{ s.nombre }}
                  </option>
                </select>
              </label>
              <LeyendaSucursal v-else />
            </div>

            <p
              v-if="vendibles.length === 0"
              class="px-4 py-10 text-center text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{
                busqueda
                  ? $t("mostradorVisual.sinResultados")
                  : $t("pos.sinArticulos")
              }}
            </p>
            <div v-else class="mo-productos">
              <button
                v-for="a in vendibles"
                :key="a.id"
                type="button"
                class="mo-producto"
                :disabled="!puedeVender || stockEn(a) - enCarrito(a.id) <= 0"
                data-prueba="producto"
                @click="agregar(a)"
              >
                <span class="mo-tile" aria-hidden="true">{{
                  iniciales(a.nombre)
                }}</span>
                <span class="min-w-0 flex-1">
                  <span class="block truncate font-medium">{{ a.nombre }}</span>
                  <span class="block tabular-nums">{{
                    dinero(a.precio_minor, a.moneda)
                  }}</span>
                  <span
                    class="mo-stock"
                    :style="{
                      '--tono':
                        stockEn(a) > 0 ? 'var(--exito)' : 'var(--error)',
                    }"
                    >{{
                      stockEn(a) > 0
                        ? $t("mostradorVisual.enExistencia", { n: stockEn(a) })
                        : $t("pos.agotado")
                    }}</span
                  >
                </span>
                <span
                  v-if="enCarrito(a.id) > 0"
                  class="mo-cuenta tabular-nums"
                  >{{ enCarrito(a.id) }}</span
                >
              </button>
            </div>
          </div>

          <!-- Venta actual -->
          <aside class="tu-card mo-venta h-fit" data-prueba="venta-actual">
            <header class="flex items-center justify-between gap-2">
              <h2 class="font-medium">
                {{ $t("mostradorVisual.ventaActual") }}
              </h2>
              <span
                v-if="piezas > 0"
                class="text-sm"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ $t("mostradorVisual.piezas", { n: piezas }) }}</span
              >
            </header>
            <p
              v-if="carrito.length === 0"
              class="mt-3 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("mostradorVisual.vacia") }}
            </p>
            <ul v-else class="mo-lineas">
              <li v-for="l in carrito" :key="l.id">
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium">{{ l.nombre }}</p>
                  <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                    {{ dinero(l.precio) }}
                  </p>
                </div>
                <div class="mo-cantidad">
                  <button
                    type="button"
                    :aria-label="$t('mostradorVisual.menos')"
                    @click="restar(l.id)"
                  >
                    −
                  </button>
                  <span class="tabular-nums">{{ l.cantidad }}</span>
                  <button
                    type="button"
                    :aria-label="$t('mostradorVisual.mas')"
                    @click="sumar(l.id)"
                  >
                    +
                  </button>
                </div>
                <span class="w-20 text-right text-sm tabular-nums">{{
                  dinero(l.precio * l.cantidad)
                }}</span>
                <button
                  type="button"
                  class="tu-icono-btn"
                  :aria-label="$t('mostradorVisual.quitar')"
                  @click="quitar(l.id)"
                >
                  <IconoNav nombre="cerrar" :tam="14" />
                </button>
              </li>
            </ul>

            <div class="mt-4">
              <p class="tu-label">{{ $t("pos.metodo") }}</p>
              <div class="tu-segmentado w-full" role="group">
                <button
                  v-for="m in METODOS"
                  :key="m"
                  type="button"
                  class="flex-1"
                  :aria-pressed="metodo === m"
                  @click="metodo = m"
                >
                  {{ $t(`pos.metodos.${m}`) }}
                </button>
              </div>
            </div>
            <div class="mo-total">
              <span>{{ $t("pos.total") }}</span>
              <span class="tabular-nums">{{ dinero(totalCarrito) }}</span>
            </div>
            <button
              class="tu-btn tu-btn-primario mt-3 w-full"
              type="button"
              data-prueba="cobrar"
              :disabled="!puedeVender || cobrando || carrito.length === 0"
              @click="cobrar"
            >
              {{ cobrando ? $t("pos.cobrando") : $t("pos.cobrar") }}
            </button>
          </aside>
        </div>

        <!-- Ventas recientes -->
        <h2 class="mt-8 font-medium">{{ $t("pos.ventas.titulo") }}</h2>
        <EstadoVacio
          v-if="ventasVisibles.length === 0"
          class="mt-3 py-6"
          icono="ventas"
          compacto
          :titulo="$t('pos.ventas.vacio')"
        />
        <div v-else class="relative tu-card mt-3 overflow-x-auto">
          <table class="mo-tabla">
            <thead>
              <tr>
                <th>{{ $t("mostradorVisual.col.cuando") }}</th>
                <th>{{ $t("mostradorVisual.col.productos") }}</th>
                <th v-if="!sucursalActual" class="hidden md:table-cell">
                  {{ $t("pos.ventas.colSucursal") }}
                </th>
                <th class="hidden sm:table-cell">
                  {{ $t("pos.ventas.colMetodo") }}
                </th>
                <th class="text-right">{{ $t("pos.ventas.colTotal") }}</th>
                <th>
                  <span class="sr-only">{{
                    $t("mostradorVisual.col.acciones")
                  }}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <template v-for="v in ventasVisibles" :key="v.id">
                <tr>
                  <td class="whitespace-nowrap mo-suave">
                    {{ hora(v.creado_en) }}
                  </td>
                  <td>
                    {{
                      (v.lineas ?? [])
                        .map((l) => `${l.cantidad} × ${l.articulo ?? ""}`)
                        .join(", ") || "—"
                    }}
                  </td>
                  <td v-if="!sucursalActual" class="hidden md:table-cell">
                    {{ v.sucursal ?? "—" }}
                  </td>
                  <td class="hidden sm:table-cell">
                    <span
                      v-if="v.anulada_en"
                      class="tu-pildora"
                      :style="{ '--tono': 'var(--error)' }"
                      :title="v.motivo_anulacion ?? undefined"
                      data-prueba="venta-anulada"
                      >{{ $t("corregirCobro.anulada") }}</span
                    >
                    <template v-else>{{
                      nombreMetodo(v.metodo_pago)
                    }}</template>
                  </td>
                  <td
                    class="text-right font-medium tabular-nums"
                    :class="{ 'line-through mo-suave': v.anulada_en }"
                  >
                    {{ dinero(v.total_minor, v.moneda) }}
                  </td>
                  <td class="text-right">
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
                <tr v-if="corrigiendo === v.id" class="mo-correccion">
                  <td colspan="6">
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
                      :metodos="[...METODOS]"
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
      </template>
    </template>
  </section>
</template>

<style scoped>
.mo-barra {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  padding: 0.9rem 1rem;
  border-bottom: 1px solid var(--borde);
}
.mo-buscar {
  position: relative;
  flex: 1 1 14rem;
  color: var(--texto-suave);
}
.mo-buscar > :first-child {
  position: absolute;
  top: 50%;
  left: 0.75rem;
  transform: translateY(-50%);
}
.mo-buscar > input {
  padding-left: 2.25rem;
}
.mo-productos {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
  gap: 0.6rem;
  padding: 1rem;
}
.mo-producto {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 0.7rem;
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
  font-size: 0.875rem;
  text-align: left;
  transition: border-color 0.15s ease;
}
.mo-producto:hover:not(:disabled) {
  border-color: var(--primario);
}
.mo-producto:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
.mo-tile {
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
.mo-stock {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.15rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
}
.mo-stock::before {
  content: "";
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 999px;
  background: var(--tono);
}
.mo-cuenta {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  min-width: 1.4rem;
  padding: 0 0.35rem;
  border-radius: 999px;
  background: var(--primario);
  color: var(--primario-contraste, #fff);
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1.4rem;
  text-align: center;
}
.mo-venta {
  padding: 1rem 1.1rem 1.1rem;
}
.mo-lineas {
  display: grid;
  margin-top: 0.75rem;
}
.mo-lineas > li {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.6rem 0;
  border-top: 1px solid var(--borde);
}
.mo-cantidad {
  display: inline-flex;
  align-items: center;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton);
  font-size: 0.85rem;
}
.mo-cantidad > button {
  width: 1.75rem;
  height: 1.75rem;
  color: var(--texto-suave);
}
.mo-cantidad > button:hover {
  color: var(--texto);
}
.mo-cantidad > span {
  min-width: 1.5rem;
  text-align: center;
}
.mo-total {
  display: flex;
  justify-content: space-between;
  margin-top: 1rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--borde);
  font-size: 1.05rem;
  font-weight: 600;
}
.mo-tabla {
  width: 100%;
  font-size: 0.9rem;
  border-collapse: collapse;
}
.mo-tabla th {
  padding: 0.7rem 1rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
  font-weight: 500;
  text-align: left;
}
.mo-tabla th.text-right {
  text-align: right;
}
.mo-tabla td {
  padding: 0.7rem 1rem;
  border-top: 1px solid var(--borde);
  vertical-align: middle;
}
.mo-correccion td {
  border-top: 0;
  padding-top: 0;
}
.mo-suave {
  color: var(--texto-suave);
}
</style>
