<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import CargadorImagen from "@/components/CargadorImagen.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import LeyendaSucursal from "@/components/LeyendaSucursal.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, erroresPorCampo, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { opcionEnUrl, useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
import { dinero as dineroDelPais } from "@/lib/formato";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import { useDescartarCambios } from "@/lib/editorFormulario";
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
  // Se ofrece para apartar al reservar una clase (ADR 0194).
  ofrecer_al_reservar?: boolean;
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
interface MetaInventario {
  stock_bajo?: number;
  total?: number;
  page?: number;
  per_page?: number;
  ultima_pagina?: number;
  total_articulos?: number;
  resumen?: Record<"productos" | EstadoStock, number>;
}
interface MovimientoHistorial {
  id: string;
  tipo: "entrada" | "salida" | "ajuste";
  cantidad: number;
  motivo: string | null;
  sucursal: string;
  quien: string | null;
  de_venta: boolean;
  creado_en: string | null;
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
// Que no se pudo consultar no debe verse como «aún no hay artículos».
const errorCarga = ref(false);
const guardando = ref(false);

// Con una sucursal fija (la de la barra o la única), el stock es el de ahí.
const { fija: sucursalFija, mostrarSelect: elegirSucursal } =
  useSucursalOperativa();

// Por páginas, con la búsqueda y los filtros en el servidor (el catálogo puede ser
// grande); los indicadores vienen contados de todo.
const pagina = ref(1);
const meta = ref<MetaInventario | null>(null);
async function cargarArticulos(): Promise<void> {
  const { data } = await api.get<{ data: Articulo[]; meta?: MetaInventario }>(
    `${base.value}/articulos`,
    {
      params: {
        page: pagina.value,
        q: busqueda.value.trim() || undefined,
        venta: filtroVenta.value || undefined,
        stock: filtroStock.value || undefined,
        categoria: filtroCategoria.value || undefined,
        sucursal_id: sucursalFija.value ?? undefined,
      },
    },
  );
  articulos.value = data.data;
  meta.value = data.meta ?? null;
  stockBajo.value = data.meta?.stock_bajo ?? stockBajo.value;
}
async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  errorCarga.value = false;
  try {
    const [, s, c] = await Promise.all([
      cargarArticulos(),
      api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`),
      api.get<{ data: Categoria[] }>(`${base.value}/categorias-articulo`),
    ]);
    categorias.value = c.data.data;
    sucursales.value = s.data.data;
  } catch {
    errorCarga.value = true;
  } finally {
    cargando.value = false;
  }
}
async function irPagina(n: number): Promise<void> {
  pagina.value = n;
  try {
    await cargarArticulos();
  } catch (e) {
    error.value = mensajeDeError(e);
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
// Del servidor (de todo el inventario, no de la página); sin ellos, de lo cargado.
const aLaVenta = computed(() => articulos.value.filter((a) => a.activo));
const indicadores = computed<Indicador[]>(() => {
  const resumen = meta.value?.resumen;
  const contar = (e: EstadoStock): number =>
    resumen?.[e] ?? aLaVenta.value.filter((a) => estadoDe(a) === e).length;
  return [
    {
      clave: "productos",
      etiqueta: t("inventarioVisual.kpi.productos"),
      valor: String(resumen?.productos ?? aLaVenta.value.length),
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
// La búsqueda y los filtros en la dirección: recargar o volver atrás los conserva.
// Antes de vigilarlos, para que lo leído de la dirección no cargue de más.
useFiltrosEnUrl({
  q: busqueda,
  venta: opcionEnUrl(filtroVenta, ["", "activo", "inactivo"] as const),
  stock: opcionEnUrl(filtroStock, [
    "",
    "con_stock",
    "bajo",
    "sin_stock",
  ] as const),
  categoria: filtroCategoria,
});
// El servidor ya los trae buscados y filtrados.
const visibles = computed(() => articulos.value);
let esperaBusqueda: ReturnType<typeof setTimeout> | undefined;
watch(busqueda, () => {
  clearTimeout(esperaBusqueda);
  esperaBusqueda = setTimeout(() => void irPagina(1), 300);
});
watch([filtroVenta, filtroStock, filtroCategoria, sucursalFija], () => {
  void irPagina(1);
});
// Sin ningún producto aún (no «ninguno coincide con el filtro»).
const sinProductos = computed(() =>
  meta.value?.total_articulos !== undefined
    ? meta.value.total_articulos === 0
    : articulos.value.length === 0 && !hayFiltros.value,
);
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
// Lo que no se pudo (p. ej. un nombre repetido), dentro del diálogo y no atrás.
const errorCategorias = ref<string | null>(null);
function abrirCategorias(): void {
  nuevaCategoria.value = "";
  errorCategorias.value = null;
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
  errorCategorias.value = null;
  try {
    await api.post(`${base.value}/categorias-articulo`, { nombre });
    nuevaCategoria.value = "";
    await recargarCategorias();
  } catch (e) {
    errorCategorias.value = mensajeDeError(e);
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
  errorCategorias.value = null;
  try {
    await api.put(`${base.value}/categorias-articulo/${c.id}`, { nombre });
    await Promise.all([recargarCategorias(), cargar()]);
  } catch (e) {
    errorCategorias.value = mensajeDeError(e);
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
  errorCategorias.value = null;
  try {
    await api.delete(`${base.value}/categorias-articulo/${c.id}`);
    if (filtroCategoria.value === c.id) {
      filtroCategoria.value = "";
    }
    toast.exito(t("operacion.productos.quitada"));
    await Promise.all([recargarCategorias(), cargar()]);
  } catch (e) {
    errorCategorias.value = mensajeDeError(e);
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
  ofrecer: false,
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
// Lo que no se pudo guardar, dentro del diálogo (no en la página de atrás): el error
// general arriba y cada error de campo junto a su campo, con el foco en el primero.
const errorProducto = ref<string | null>(null);
const erroresProducto = ref<Record<string, string>>({});
function errorDe(campo: string): string | undefined {
  return erroresProducto.value[campo];
}
async function enfocarPrimerError(formulario: string): Promise<void> {
  await nextTick();
  document
    .querySelector<HTMLElement>(`#${formulario} [aria-invalid="true"]`)
    ?.focus();
}
function limpiarErroresProducto(): void {
  errorProducto.value = null;
  erroresProducto.value = {};
}
// Con cambios sin guardar, cerrar o salir pregunta antes (patrón de formularios).
const descartarProducto = useDescartarCambios({
  abierto: dialogoProducto,
  valor: () => ({ form: form.value, extras: extras.value }),
  guardando,
});
function cerrarProducto(): void {
  void descartarProducto.cerrar();
}
function nuevoProducto(): void {
  editando.value = null;
  form.value = {
    nombre: "",
    sku: "",
    precio: "",
    activo: true,
    // Con un filtro de categoría puesto, el nuevo nace en ella.
    categoria: filtroCategoria.value !== "-" ? filtroCategoria.value : "",
    ofrecer: false,
  };
  extras.value = [];
  olvidarFotoNueva();
  limpiarErroresProducto();
  descartarProducto.fijar();
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
    ofrecer: a.ofrecer_al_reservar === true,
  };
  extras.value = (a.extras ?? []).map((e) => ({
    id: e.id,
    nombre: e.nombre,
    precio: String(e.precio_minor / 100),
  }));
  limpiarErroresProducto();
  descartarProducto.fijar();
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
  if (guardando.value) {
    return;
  }
  guardando.value = true;
  limpiarErroresProducto();
  // Los extras sin nombre no se mandan: se recuerda cuál es cuál para marcar el error.
  const enviados = extras.value
    .map((e, i) => ({ e, i }))
    .filter(({ e }) => e.nombre.trim() !== "");
  const datos = {
    nombre: form.value.nombre.trim(),
    sku: form.value.sku.trim() !== "" ? form.value.sku.trim() : null,
    precio_minor: Math.round(Number(form.value.precio) * 100),
    categoria_id: form.value.categoria !== "" ? form.value.categoria : null,
    // Solo en un negocio de clases (ADR 0194).
    ...(sesion.esCitas ? {} : { ofrecer_al_reservar: form.value.ofrecer }),
    extras: enviados.map(({ e }) => ({
      id: e.id,
      nombre: e.nombre.trim(),
      precio_minor: Math.round(Number(e.precio || 0) * 100),
    })),
  };
  let creado: Articulo | null = null;
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
      creado = data.data;
      // Con su foto, si se eligió una.
      if (fotoNueva.value) {
        const cuerpo = new FormData();
        cuerpo.append("foto", fotoNueva.value);
        await api.post(`${base.value}/articulos/${data.data.id}/foto`, cuerpo);
        olvidarFotoNueva();
      }
      toast.exito(t("inventarioVisual.creado"));
    }
    descartarProducto.fijar();
    dialogoProducto.value = false;
    await cargar();
  } catch (e) {
    if (creado !== null) {
      // El producto ya quedó; solo faltó la foto: el diálogo sigue con él (editar),
      // y volver a guardar no lo duplica.
      editando.value = creado;
      errorProducto.value = t("inventarioVisual.errores.fotoNoSubio", {
        error: mensajeDeError(e),
      });
      void cargarArticulos().catch(() => undefined);
    } else {
      const campos = erroresPorCampo(e);
      erroresProducto.value = Object.fromEntries(
        Object.entries(campos).map(([campo, mensaje]) => {
          const extra = /^extras\.(\d+)\.(.+)$/.exec(campo);
          return extra
            ? [
                `extras.${enviados[Number(extra[1])]?.i ?? extra[1]}.${extra[2]}`,
                mensaje,
              ]
            : [campo, mensaje];
        }),
      );
      errorProducto.value =
        Object.keys(campos).length > 0
          ? t("inventarioVisual.errores.revisa")
          : mensajeDeError(e);
      void enfocarPrimerError("iv-producto");
    }
  } finally {
    guardando.value = false;
  }
}

// ---- Movimiento de stock (entrada o ajuste) ----
const moviendo = ref<Articulo | null>(null);
// Un ajuste a mano dice por qué (queda en su historial con quién lo hizo); una
// entrada puede llevar una nota (proveedor, factura).
const MOTIVOS_AJUSTE = [
  "conteo",
  "merma",
  "caducidad",
  "uso_interno",
  "otro",
] as const;
const movimiento = ref<{
  sucursal_id: string;
  tipo: "entrada" | "ajuste";
  cantidad: string;
  motivo: "" | (typeof MOTIVOS_AJUSTE)[number];
  detalle: string;
}>({ sucursal_id: "", tipo: "entrada", cantidad: "", motivo: "", detalle: "" });
const errorMovimiento = ref<string | null>(null);
const erroresMovimiento = ref<Record<string, string>>({});
const moviendoAbierto = computed({
  get: () => moviendo.value !== null,
  set: (v: boolean) => {
    if (!v) moviendo.value = null;
  },
});
const descartarMovimiento = useDescartarCambios({
  abierto: moviendoAbierto,
  valor: () => movimiento.value,
  guardando,
});
function cerrarMovimiento(): void {
  void descartarMovimiento.cerrar();
}
function abrirMovimiento(a: Articulo): void {
  moviendo.value = a;
  movimiento.value = {
    sucursal_id: sucursalFija.value ?? sucursales.value[0]?.id ?? "",
    tipo: "entrada",
    cantidad: "",
    motivo: "",
    detalle: "",
  };
  errorMovimiento.value = null;
  erroresMovimiento.value = {};
  descartarMovimiento.fijar();
}
const motivoTexto = computed(() => {
  const m = movimiento.value;
  const detalle = m.detalle.trim();
  if (m.tipo === "entrada") {
    return detalle;
  }
  if (m.motivo === "") {
    return "";
  }
  if (m.motivo === "otro") {
    return detalle;
  }
  const nombre = t(`inventarioVisual.motivos.${m.motivo}`);
  return detalle !== "" ? `${nombre}: ${detalle}` : nombre;
});
const movimientoValido = computed(() => {
  const n = Number(movimiento.value.cantidad);
  return (
    movimiento.value.sucursal_id !== "" &&
    Number.isInteger(n) &&
    n !== 0 &&
    (movimiento.value.tipo === "ajuste" || n > 0) &&
    (movimiento.value.tipo === "entrada" || motivoTexto.value.length >= 3)
  );
});
async function guardarMovimiento(): Promise<void> {
  if (!moviendo.value || guardando.value) {
    return;
  }
  guardando.value = true;
  errorMovimiento.value = null;
  erroresMovimiento.value = {};
  try {
    await api.post(`${base.value}/articulos/${moviendo.value.id}/movimientos`, {
      sucursal_id: movimiento.value.sucursal_id,
      tipo: movimiento.value.tipo,
      cantidad: Number(movimiento.value.cantidad),
      ...(motivoTexto.value !== "" ? { motivo: motivoTexto.value } : {}),
    });
    toast.exito(t("inventarioVisual.movido"));
    descartarMovimiento.fijar();
    moviendo.value = null;
    await irPagina(pagina.value);
  } catch (e) {
    erroresMovimiento.value = erroresPorCampo(e);
    errorMovimiento.value = mensajeDeError(e);
    void enfocarPrimerError("iv-movimiento");
  } finally {
    guardando.value = false;
  }
}

// ---- Historial de un producto (entradas, ajustes y ventas) ----
const historialDe = ref<Articulo | null>(null);
const historial = ref<MovimientoHistorial[]>([]);
const metaHistorial = ref<{
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
} | null>(null);
const tipoHistorial = ref<"" | "entrada" | "salida" | "ajuste">("");
const cargandoHistorial = ref(false);
const errorHistorial = ref<string | null>(null);
async function cargarHistorial(page = 1): Promise<void> {
  if (!historialDe.value) {
    return;
  }
  cargandoHistorial.value = true;
  errorHistorial.value = null;
  try {
    const { data } = await api.get<{
      data: MovimientoHistorial[];
      meta: NonNullable<typeof metaHistorial.value>;
    }>(`${base.value}/articulos/${historialDe.value.id}/movimientos`, {
      params: { page, tipo: tipoHistorial.value || undefined },
    });
    historial.value = data.data;
    metaHistorial.value = data.meta;
  } catch (e) {
    errorHistorial.value = mensajeDeError(e);
  } finally {
    cargandoHistorial.value = false;
  }
}
function abrirHistorial(a: Articulo): void {
  historialDe.value = a;
  tipoHistorial.value = "";
  historial.value = [];
  metaHistorial.value = null;
  void cargarHistorial();
}
watch(tipoHistorial, () => void cargarHistorial());
// En la hora del negocio, como el resto de la caja y el inventario.
function cuandoMovimiento(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        dateStyle: "short",
        timeStyle: "short",
        timeZone: sesion.zonaHoraria || undefined,
      }).format(new Date(iso))
    : "—";
}
function tipoMovimiento(m: MovimientoHistorial): string {
  // De una venta del mostrador: la venta, o su anulación (que regresa el stock).
  if (m.de_venta) {
    return m.cantidad > 0
      ? t("inventarioVisual.historial.ventaAnulada")
      : t("inventarioVisual.historial.venta");
  }
  return t(`inventarioVisual.historial.tipos.${m.tipo}`);
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
          class="tu-btn tu-btn-secundario"
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

    <div
      v-else-if="errorCarga"
      class="mt-6 tu-card flex flex-wrap items-center justify-between gap-3 p-6 text-sm"
      role="alert"
      data-prueba="error-carga-inventario"
    >
      <span style="color: var(--error)">{{
        $t("inventarioVisual.errorCarga")
      }}</span>
      <button type="button" class="tu-btn tu-btn-secundario" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </div>

    <template v-else>
      <EstadoVacio
        v-if="sinProductos"
        class="tu-card mt-6"
        icono="pos"
        :titulo="$t('pos.inventario.sinArticulos')"
      />

      <template v-else>
        <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

        <div class="tu-card mt-5">
          <!-- Búsqueda y filtros -->
          <div class="iv-filtros">
            <label class="tu-buscar">
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

          <!-- En el teléfono, una ficha por producto: su nombre, precio, stock y sus
               acciones a la vista. -->
          <div class="relative overflow-x-auto tu-tabla-adaptable">
            <table class="tu-tabla">
              <thead>
                <tr>
                  <th>{{ $t("inventarioVisual.col.producto") }}</th>
                  <th class="text-right">
                    {{ $t("inventarioVisual.col.precio") }}
                  </th>
                  <th class="text-right">
                    {{ $t("inventarioVisual.col.stock") }}
                  </th>
                  <th>{{ $t("inventarioVisual.col.estado") }}</th>
                  <th class="hidden sm:table-cell">
                    {{ $t("inventarioVisual.col.sku") }}
                  </th>
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
                  <td :data-etiqueta="$t('inventarioVisual.col.producto')">
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
                        <p class="font-medium">{{ a.nombre }}</p>
                        <p
                          v-if="a.categoria"
                          class="tu-sub font-normal"
                          data-prueba="categoria-producto"
                        >
                          {{ a.categoria.nombre }}
                        </p>
                        <p v-if="!a.activo" class="tu-sub font-normal">
                          {{ $t("inventarioExtra.inactivo") }}
                        </p>
                        <p v-else-if="desglose(a)" class="tu-sub font-normal">
                          {{ desglose(a) }}
                        </p>
                      </div>
                    </div>
                  </td>
                  <td
                    :data-etiqueta="$t('inventarioVisual.col.precio')"
                    class="text-right tabular-nums font-medium"
                  >
                    {{ dinero(a.precio_minor, a.moneda) }}
                  </td>
                  <td
                    :data-etiqueta="$t('inventarioVisual.col.stock')"
                    class="text-right tabular-nums"
                    data-prueba="stock"
                  >
                    {{ stockDe(a) }}
                  </td>
                  <td :data-etiqueta="$t('inventarioVisual.col.estado')">
                    <span
                      class="tu-estado"
                      :style="{ '--tono': TONOS[estadoDe(a)] }"
                      :data-estado="estadoDe(a)"
                      >{{ $t(`inventarioVisual.estado.${estadoDe(a)}`) }}</span
                    >
                  </td>
                  <td
                    :data-etiqueta="$t('inventarioVisual.col.sku')"
                    class="hidden sm:table-cell iv-suave"
                  >
                    {{ a.sku ?? "—" }}
                  </td>
                  <td class="tu-celda-acciones">
                    <span
                      class="inline-flex flex-wrap items-center justify-end gap-2"
                    >
                      <template v-if="puedeGestionar">
                        <button
                          type="button"
                          class="tu-btn tu-btn-secundario tu-btn-chico"
                          data-prueba="movimiento"
                          @click="abrirMovimiento(a)"
                        >
                          {{ $t("inventarioVisual.movimiento") }}
                        </button>
                        <button
                          type="button"
                          class="tu-btn tu-btn-secundario tu-btn-chico"
                          data-prueba="editar"
                          @click="editar(a)"
                        >
                          {{ $t("inventarioExtra.editar") }}
                        </button>
                      </template>
                      <!-- Solo consulta: entradas, ajustes y ventas. -->
                      <button
                        type="button"
                        class="tu-enlace text-sm"
                        data-prueba="historial"
                        @click="abrirHistorial(a)"
                      >
                        {{ $t("inventarioVisual.historial.ver") }}
                      </button>
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
            <div v-if="visibles.length === 0" class="iv-sin-resultados">
              {{ $t("inventarioVisual.sinResultados") }}
              <button
                v-if="hayFiltros"
                type="button"
                class="tu-btn tu-btn-discreto"
                @click="limpiar"
              >
                {{ $t("inventarioVisual.limpiar") }}
              </button>
            </div>
          </div>
          <PaginacionListado
            v-if="meta?.ultima_pagina && meta.ultima_pagina > 1"
            :page="meta.page ?? 1"
            :ultima-pagina="meta.ultima_pagina"
            :total="meta.total ?? articulos.length"
            :per-page="meta.per_page ?? 25"
            @ir="irPagina"
          />
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
      :ocupado="guardando"
      @cerrar="cerrarProducto"
    >
      <form
        id="iv-producto"
        class="grid gap-4"
        novalidate
        @submit.prevent="guardarProducto"
      >
        <AvisoTono
          v-if="errorProducto"
          tono="peligro"
          role="alert"
          data-prueba="error-producto"
        >
          {{ errorProducto }}
        </AvisoTono>
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
            :aria-invalid="errorDe('nombre') ? 'true' : undefined"
            :aria-describedby="
              errorDe('nombre') ? 'iv-nombre-error' : undefined
            "
          />
          <span
            v-if="errorDe('nombre')"
            id="iv-nombre-error"
            class="tu-error-campo"
            >{{ errorDe("nombre") }}</span
          >
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="tu-label" for="iv-sku">{{
              $t("pos.inventario.sku")
            }}</label>
            <input
              id="iv-sku"
              v-model="form.sku"
              class="tu-input"
              :aria-invalid="errorDe('sku') ? 'true' : undefined"
              :aria-describedby="errorDe('sku') ? 'iv-sku-error' : undefined"
            />
            <span
              v-if="errorDe('sku')"
              id="iv-sku-error"
              class="tu-error-campo"
              >{{ errorDe("sku") }}</span
            >
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
              :aria-invalid="errorDe('precio_minor') ? 'true' : undefined"
              :aria-describedby="
                errorDe('precio_minor') ? 'iv-precio-error' : undefined
              "
            />
            <span
              v-if="errorDe('precio_minor')"
              id="iv-precio-error"
              class="tu-error-campo"
              >{{ errorDe("precio_minor") }}</span
            >
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
            :aria-invalid="errorDe('categoria_id') ? 'true' : undefined"
          >
            <option value="">
              {{ $t("operacion.productos.sinCategoria") }}
            </option>
            <option v-for="c in categorias" :key="c.id" :value="c.id">
              {{ c.nombre }}
            </option>
          </select>
          <span v-if="errorDe('categoria_id')" class="tu-error-campo">{{
            errorDe("categoria_id")
          }}</span>
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
              <!-- Aún no se sube: solo se deja de elegir. -->
              <button
                type="button"
                class="tu-btn tu-btn-discreto tu-btn-chico"
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
              :aria-invalid="errorDe(`extras.${i}.nombre`) ? 'true' : undefined"
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
              :aria-invalid="
                errorDe(`extras.${i}.precio_minor`) ? 'true' : undefined
              "
            />
            <button
              type="button"
              class="tu-icono-btn"
              :aria-label="$t('inventarioVisual.extras.quitar')"
              @click="quitarExtra(i)"
            >
              <IconoNav nombre="cerrar" :tam="14" />
            </button>
            <span
              v-if="
                errorDe(`extras.${i}.nombre`) ||
                errorDe(`extras.${i}.precio_minor`)
              "
              class="tu-error-campo col-span-3 -mt-1"
              >{{
                errorDe(`extras.${i}.nombre`) ??
                errorDe(`extras.${i}.precio_minor`)
              }}</span
            >
          </div>
          <button
            type="button"
            class="tu-btn tu-btn-secundario justify-self-start"
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
        <label v-if="!sesion.esCitas" class="flex items-start gap-2 text-sm">
          <input
            v-model="form.ofrecer"
            type="checkbox"
            class="mt-1"
            data-prueba="ofrecer-al-reservar"
          />
          <span>
            {{ $t("inventarioExtra.ofrecerAlReservar") }}
            <span class="block" :style="{ color: 'var(--texto-suave)' }">{{
              $t("inventarioExtra.ofrecerAlReservarAyuda")
            }}</span>
          </span>
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
          class="tu-btn tu-btn-secundario"
          :disabled="guardando"
          @click="cerrarProducto"
        >
          {{ $t("pos.inventario.cancelar") }}
        </button>
        <button
          type="submit"
          form="iv-producto"
          class="tu-btn tu-btn-primario"
          data-prueba="guardar-producto"
          :disabled="guardando || !formValido"
          :aria-busy="guardando || undefined"
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
      tam="md"
      :ocupado="guardando"
      @cerrar="dialogoCategorias = false"
    >
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("operacion.productos.categoriasAyuda") }}
      </p>
      <AvisoTono
        v-if="errorCategorias"
        tono="peligro"
        class="mt-4"
        role="alert"
        data-prueba="error-categorias"
      >
        {{ errorCategorias }}
      </AvisoTono>
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
            class="tu-btn tu-btn-secundario tu-btn-chico"
            :disabled="guardando || (nombres[c.id] ?? '').trim() === ''"
            @click="renombrarCategoria(c)"
          >
            {{ $t("operacion.productos.guardar") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-peligro tu-btn-chico"
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
      :ocupado="guardando"
      @cerrar="cerrarMovimiento"
    >
      <form
        id="iv-movimiento"
        class="grid gap-4"
        novalidate
        @submit.prevent="guardarMovimiento"
      >
        <AvisoTono
          v-if="errorMovimiento"
          tono="peligro"
          role="alert"
          data-prueba="error-movimiento"
        >
          {{ errorMovimiento }}
        </AvisoTono>
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
            :aria-invalid="erroresMovimiento.cantidad ? 'true' : undefined"
          />
          <span v-if="erroresMovimiento.cantidad" class="tu-error-campo">{{
            erroresMovimiento.cantidad
          }}</span>
          <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{
              movimiento.tipo === "entrada"
                ? $t("inventarioVisual.entradaAyuda")
                : $t("inventarioExtra.ajusteAyuda")
            }}
          </p>
        </div>
        <div v-if="movimiento.tipo === 'ajuste'">
          <label class="tu-label" for="iv-motivo"
            >{{ $t("inventarioVisual.motivo")
            }}<span class="tu-obligatorio" aria-hidden="true">*</span></label
          >
          <select
            id="iv-motivo"
            v-model="movimiento.motivo"
            class="tu-input"
            data-prueba="motivo"
            required
            :aria-invalid="erroresMovimiento.motivo ? 'true' : undefined"
          >
            <option value="" disabled>
              {{ $t("inventarioVisual.motivoElige") }}
            </option>
            <option v-for="m in MOTIVOS_AJUSTE" :key="m" :value="m">
              {{ $t(`inventarioVisual.motivos.${m}`) }}
            </option>
          </select>
          <span v-if="erroresMovimiento.motivo" class="tu-error-campo">{{
            erroresMovimiento.motivo
          }}</span>
        </div>
        <div>
          <label class="tu-label" for="iv-detalle">{{
            movimiento.tipo === "entrada"
              ? $t("inventarioVisual.nota")
              : movimiento.motivo === "otro"
                ? $t("inventarioVisual.detalleObligatorio")
                : $t("inventarioVisual.detalle")
          }}</label>
          <input
            id="iv-detalle"
            v-model="movimiento.detalle"
            class="tu-input"
            maxlength="200"
            data-prueba="detalle"
            :placeholder="
              movimiento.tipo === 'entrada'
                ? $t('inventarioVisual.notaEjemplo')
                : $t('inventarioVisual.detalleEjemplo')
            "
          />
        </div>
      </form>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="guardando"
          @click="cerrarMovimiento"
        >
          {{ $t("pos.inventario.cancelar") }}
        </button>
        <button
          type="submit"
          form="iv-movimiento"
          class="tu-btn tu-btn-primario"
          data-prueba="guardar-movimiento"
          :disabled="guardando || !movimientoValido"
          :aria-busy="guardando || undefined"
        >
          {{ $t("inventarioVisual.registrar") }}
        </button>
      </template>
    </ModalDialogo>

    <!-- Historial de un producto: entradas, ajustes (con su motivo) y ventas -->
    <ModalDialogo
      :abierto="historialDe !== null"
      :titulo="
        $t('inventarioVisual.historial.titulo', {
          producto: historialDe?.nombre ?? '',
        })
      "
      icono="pos"
      @cerrar="historialDe = null"
    >
      <div class="tu-segmentado self-start" role="group">
        <button
          v-for="tipo in ['', 'entrada', 'ajuste', 'salida'] as const"
          :key="tipo"
          type="button"
          :aria-pressed="tipoHistorial === tipo"
          :data-prueba="`historial-${tipo || 'todos'}`"
          @click="tipoHistorial = tipo"
        >
          {{
            tipo === ""
              ? $t("inventarioVisual.todos")
              : $t(`inventarioVisual.historial.filtros.${tipo}`)
          }}
        </button>
      </div>
      <AvisoTono v-if="errorHistorial" tono="peligro" class="mt-3" role="alert">
        {{ errorHistorial }}
      </AvisoTono>
      <p
        v-else-if="cargandoHistorial && historial.length === 0"
        class="mt-4 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("comun.cargando") }}
      </p>
      <!-- Con un tipo elegido, «ninguno de ese tipo» no es «sin movimientos». -->
      <EstadoVacio
        v-else-if="historial.length === 0 && tipoHistorial !== ''"
        class="mt-3"
        icono="buscar"
        compacto
        :titulo="$t('tabla.sinCoincidencias')"
        :texto="$t('tabla.ajustarBusqueda')"
        data-prueba="historial-sin-coincidencias"
      >
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          @click="tipoHistorial = ''"
        >
          {{ $t("tabla.restablecer") }}
        </button>
      </EstadoVacio>
      <p
        v-else-if="historial.length === 0"
        class="mt-4 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("inventarioVisual.historial.vacio") }}
      </p>
      <ul v-else class="iv-historial mt-3" data-prueba="lista-historial">
        <li
          v-for="m in historial"
          :key="m.id"
          data-prueba="movimiento-historial"
        >
          <div class="min-w-0">
            <p class="text-sm font-medium">
              {{ tipoMovimiento(m) }}
              <span class="iv-sub">· {{ m.sucursal }}</span>
            </p>
            <p v-if="m.motivo" class="iv-sub">{{ m.motivo }}</p>
            <p class="iv-sub">
              {{ cuandoMovimiento(m.creado_en)
              }}<template v-if="m.quien"> · {{ m.quien }}</template>
            </p>
          </div>
          <span
            class="tabular-nums font-medium"
            :style="{
              color: m.cantidad < 0 ? 'var(--error)' : 'var(--exito)',
            }"
            >{{ m.cantidad > 0 ? `+${m.cantidad}` : m.cantidad }}</span
          >
        </li>
      </ul>
      <PaginacionListado
        v-if="metaHistorial && metaHistorial.ultima_pagina > 1"
        :page="metaHistorial.page"
        :ultima-pagina="metaHistorial.ultima_pagina"
        :total="metaHistorial.total"
        :per-page="metaHistorial.per_page"
        @ir="cargarHistorial"
      />
    </ModalDialogo>
  </section>
</template>

<style scoped>
.iv-historial {
  display: grid;
}
.iv-historial > li {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding-block: 0.65rem;
  border-top: 1px solid var(--borde);
}
.iv-historial > li:first-child {
  border-top: 0;
}
.iv-filtros {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.75rem;
  padding: 0.9rem 1rem;
  border-bottom: 1px solid var(--borde);
}
.iv-filtro {
  display: grid;
  gap: 0.25rem;
  min-width: 9.5rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
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
/* Sublíneas de los diálogos: bajan de renglón en vez de ensanchar. */
.iv-sub {
  color: var(--texto-suave);
  font-size: 0.78rem;
}
.iv-suave {
  color: var(--texto-suave);
}
.iv-sin-resultados {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
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
