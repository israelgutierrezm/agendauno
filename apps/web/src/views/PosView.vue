<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute } from "vue-router";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import BuscarPersona, {
  type PersonaBuscable,
} from "@/components/BuscarPersona.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import FormasDePago from "@/components/FormasDePago.vue";
import IconoNav from "@/components/IconoNav.vue";
import LeyendaSucursal from "@/components/LeyendaSucursal.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import VentasMostrador, {
  type ResumenVentas,
} from "@/components/VentasMostrador.vue";
import { api, camposConError, codigoDeError, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
import { efectivoDe, partesListas, type ParteCobro } from "@/lib/formasDePago";
import { dinero as dineroDelPais } from "@/lib/formato";
import { terminoComprador } from "@/lib/mostrador";
import { useSucursalOperativa, useSucursales } from "@/lib/sucursalOperativa";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Mostrador: vender productos del inventario y cobrar en caja. A la izquierda los
 * productos de la sucursal (con su existencia), a la derecha la venta actual, y
 * debajo las ventas recientes (con su corrección, ADR 0089). Los productos y su stock
 * se administran en Inventario.
 *
 * ADR 0139: un producto con extras (a una proteína, fresas o leche de soya) pregunta
 * cuáles lleva al tocarlo; la propina va aparte de lo vendido; en efectivo, «Recibe»
 * calcula el cambio.
 *
 * ADR 0145: se le vende a una persona del negocio (alumno, instructor o alguien del
 * equipo) o, sin elegir a nadie, a público en general; a la persona se le puede mandar
 * su ticket por correo. Las ventas recientes van por páginas y con fechas.
 */
interface Extra {
  id: string;
  nombre: string;
  precio_minor: number;
}
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
  extras?: Extra[];
  // Su categoría del negocio y su foto (para filtrar y reconocerlo al vender).
  categoria?: { id: string; nombre: string; orden: number } | null;
  foto_url?: string | null;
}
interface Sucursal {
  id: string;
  nombre: string;
}

const METODOS = ["efectivo", "tarjeta", "transferencia"] as const;

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeVender = computed(() => sesion.puede("pos.vender"));

const articulos = ref<Articulo[]>([]);
const sucursales = ref<Sucursal[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
// Que no se pudo consultar no es «primero crea una sucursal».
const errorCarga = ref(false);
const cobrando = ref(false);

const sucursalSel = ref("");
// Con una sucursal fija (la de la barra o la única), se vende ahí sin preguntar. La
// sede de la venta la sigue más abajo, confirmando si hay productos en la venta.
const {
  mostrarSelect: elegirSucursal,
  actual: sucursalActual,
  fija: sucursalFija,
} = useSucursalOperativa();
const barraSucursal = useSucursales();

// Las ventas recientes (con su paginación y fechas) y lo vendido hoy.
const listaVentas = ref<InstanceType<typeof VentasMostrador> | null>(null);
// Null hasta que llega (la lista de ventas lo manda): mientras, «—», no ceros.
const hoy = ref<ResumenVentas | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  errorCarga.value = false;
  try {
    const [a, s] = await Promise.all([
      api.get<{ data: Articulo[] }>(`${base.value}/articulos`),
      api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`),
    ]);
    articulos.value = a.data.data;
    sucursales.value = s.data.data;
    if (sucursalSel.value === "" && sucursales.value.length > 0) {
      sucursalSel.value = sucursales.value[0]!.id;
    }
  } catch {
    errorCarga.value = true;
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

// ---- Productos a la venta ----
const busqueda = ref("");
// Las categorías de los productos a la venta, en su orden; «sin categoría» (`-`) al
// final si hay productos sin ella. Sin categorías, no hay filtro.
const categoria = ref("");
// La búsqueda y la categoría en la dirección: recargar no las pierde.
useFiltrosEnUrl({ q: busqueda, categoria });
const categorias = computed(() => {
  const vistas = new Map<
    string,
    { id: string; nombre: string; orden: number }
  >();
  let sinCategoria = false;
  for (const a of articulos.value) {
    if (!a.activo) {
      continue;
    }
    if (a.categoria) {
      vistas.set(a.categoria.id, a.categoria);
    } else {
      sinCategoria = true;
    }
  }
  const lista = [...vistas.values()].sort(
    (x, y) => x.orden - y.orden || x.nombre.localeCompare(y.nombre),
  );
  return lista.length > 0 && sinCategoria
    ? [
        ...lista,
        { id: "-", nombre: t("operacion.productos.sinCategoria"), orden: 0 },
      ]
    : lista;
});
const vendibles = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  return articulos.value.filter(
    (a) =>
      a.activo &&
      (categoria.value === "" ||
        (a.categoria?.id ?? "-") === categoria.value) &&
      (q === "" ||
        a.nombre.toLowerCase().includes(q) ||
        (a.sku ?? "").toLowerCase().includes(q)),
  );
});

// ---- Venta actual ----
const metodo = ref<(typeof METODOS)[number]>("efectivo");
// Una línea por producto y extras: la misma proteína con y sin fresas son dos líneas.
interface Linea {
  clave: string;
  id: string;
  nombre: string;
  precio: number;
  extras: Extra[];
  cantidad: number;
}
const carrito = ref<Linea[]>([]);
const precioLinea = (l: Linea) =>
  l.precio + l.extras.reduce((s, e) => s + e.precio_minor, 0);
const totalCarrito = computed(() =>
  carrito.value.reduce((s, l) => s + precioLinea(l) * l.cantidad, 0),
);
const piezas = computed(() =>
  carrito.value.reduce((s, l) => s + l.cantidad, 0),
);
function enCarrito(id: string): number {
  return carrito.value
    .filter((l) => l.id === id)
    .reduce((s, l) => s + l.cantidad, 0);
}
function sumarLinea(a: Articulo, extras: Extra[]): void {
  if (enCarrito(a.id) >= stockEn(a)) {
    return;
  }
  const clave = [a.id, ...extras.map((e) => e.id).sort()].join("|");
  const linea = carrito.value.find((l) => l.clave === clave);
  if (linea) {
    linea.cantidad++;
  } else {
    carrito.value.push({
      clave,
      id: a.id,
      nombre: a.nombre,
      precio: a.precio_minor,
      extras,
      cantidad: 1,
    });
  }
}
// Con extras, primero pregunta cuáles lleva (un toque por extra, sin depender del
// mouse); sin extras, se agrega de una vez.
const eligiendoExtras = ref<Articulo | null>(null);
const extrasElegidos = ref<string[]>([]);
function agregar(a: Articulo): void {
  if (enCarrito(a.id) >= stockEn(a)) {
    return;
  }
  if ((a.extras ?? []).length > 0) {
    eligiendoExtras.value = a;
    extrasElegidos.value = [];
    return;
  }
  sumarLinea(a, []);
}
function alternarExtra(id: string): void {
  extrasElegidos.value = extrasElegidos.value.includes(id)
    ? extrasElegidos.value.filter((x) => x !== id)
    : [...extrasElegidos.value, id];
}
function confirmarExtras(): void {
  const a = eligiendoExtras.value;
  if (!a) {
    return;
  }
  sumarLinea(
    a,
    (a.extras ?? []).filter((e) => extrasElegidos.value.includes(e.id)),
  );
  eligiendoExtras.value = null;
}
function restar(clave: string): void {
  const linea = carrito.value.find((l) => l.clave === clave);
  if (!linea) {
    return;
  }
  linea.cantidad--;
  if (linea.cantidad <= 0) {
    quitar(clave);
  }
}
function sumar(clave: string): void {
  const linea = carrito.value.find((l) => l.clave === clave);
  const a = articulos.value.find((x) => x.id === linea?.id);
  if (linea && a && enCarrito(a.id) < stockEn(a)) {
    linea.cantidad++;
  }
}
function quitar(clave: string): void {
  carrito.value = carrito.value.filter((l) => l.clave !== clave);
}

// ---- A quién se le vende (ADR 0145) ----
// Sin elegir a nadie, a público en general (sin ticket por correo).
const comprador = ref("");
const compradorElegido = ref<PersonaBuscable | null>(null);
const enviarTicket = ref(false);
// En su género y con las palabras del negocio: «Alumna», «Maestro», «Equipo».
function tipoComprador(p: PersonaBuscable): string {
  return terminoComprador(p.tipo, p.genero, t, te);
}
// Debajo de su nombre: qué es en el negocio y su correo o celular.
function describirComprador(p: PersonaBuscable): string | null {
  return [tipoComprador(p), p.detalle].filter(Boolean).join(" · ") || null;
}
// Con correo, el ticket se le manda salvo que se desmarque.
function alElegirComprador(p: PersonaBuscable): void {
  compradorElegido.value = p;
  enviarTicket.value = Boolean(p.email);
}
watch(comprador, (id) => {
  if (id === "") {
    compradorElegido.value = null;
    enviarTicket.value = false;
  }
});

// ---- Propina y cambio (ADR 0139) ----
const PORCENTAJES = [10, 15, 20] as const;
const propinaModo = ref<"sin" | "otra" | (typeof PORCENTAJES)[number]>("sin");
const propinaOtra = ref("");
const propinaMinor = computed(() => {
  if (propinaModo.value === "sin") {
    return 0;
  }
  if (propinaModo.value === "otra") {
    return Math.max(0, Math.round(Number(propinaOtra.value || 0) * 100));
  }
  return Math.round((totalCarrito.value * propinaModo.value) / 100);
});
const totalCobrar = computed(() => totalCarrito.value + propinaMinor.value);
// Con varias formas de pago (ADR 0177): cuánto con cada una; suman el total.
const dividido = ref(false);
const partes = ref<ParteCobro[] | null>(null);
const partesOk = computed(
  () => !dividido.value || partesListas(partes.value, totalCobrar.value),
);
// Lo recibido y el cambio, solo contra lo que se paga en efectivo.
const enEfectivo = computed(() =>
  dividido.value
    ? efectivoDe(partes.value)
    : metodo.value === "efectivo"
      ? totalCobrar.value
      : 0,
);
const recibido = ref("");
const recibidoMinor = computed(() =>
  enEfectivo.value > 0 && recibido.value !== ""
    ? Math.round(Number(recibido.value) * 100)
    : null,
);
const cambio = computed(() =>
  recibidoMinor.value === null ? null : recibidoMinor.value - enEfectivo.value,
);
function reiniciarCobro(): void {
  propinaModo.value = "sin";
  propinaOtra.value = "";
  recibido.value = "";
  comprador.value = "";
  dividido.value = false;
}
// Cómo se cobra, en palabras (para confirmar): la forma o cada parte con su monto.
const formaEnPalabras = computed(() =>
  dividido.value && partes.value !== null
    ? partes.value
        .map((p) =>
          t("formasDePago.detalle", {
            forma: t(`pos.metodos.${p.metodo}`),
            monto: dinero(p.monto_minor),
          }),
        )
        .join(" · ")
    : t(`pos.metodos.${metodo.value}`),
);
// La sede de la venta, siempre a la vista: con «Todas» en la barra se vende en la
// elegida aquí (la primera al entrar), no en todas.
const sucursalVenta = computed(
  () => sucursales.value.find((s) => s.id === sucursalSel.value)?.nombre ?? "",
);
// Cambiar de sede descarta la venta (otro stock): si ya hay productos, se pregunta.
async function puedeCambiarSucursal(destino: string): Promise<boolean> {
  if (carrito.value.length === 0) {
    return true;
  }
  return confirmar(
    t(
      "mostradorVisual.sucursal.confirmarCambio",
      {
        sucursal:
          sucursales.value.find((s) => s.id === destino)?.nombre ?? destino,
        n: piezas.value,
      },
      piezas.value,
    ),
    { aceptar: t("mostradorVisual.sucursal.cambiar"), peligro: true },
  );
}
function vaciarVenta(): void {
  carrito.value = [];
  reiniciarCobro();
}
async function alElegirSucursal(evento: Event): Promise<void> {
  const select = evento.target as HTMLSelectElement;
  const destino = select.value;
  if (destino === sucursalSel.value) {
    return;
  }
  if (!(await puedeCambiarSucursal(destino))) {
    select.value = sucursalSel.value;
    return;
  }
  sucursalSel.value = destino;
  vaciarVenta();
}
// Si cambia la sucursal de la barra a media venta: se confirma, y si no, la barra
// vuelve a como estaba (la venta sigue en su sede).
watch(
  sucursalFija,
  async (fija, antes) => {
    if (fija === null || fija === sucursalSel.value) {
      return;
    }
    if (await puedeCambiarSucursal(fija)) {
      sucursalSel.value = fija;
      vaciarVenta();
    } else {
      barraSucursal.elegir(antes ?? null);
    }
  },
  { immediate: true },
);

// En el teléfono la venta queda debajo de los productos: una barra fija con las
// piezas y el total lleva a ella (se oculta cuando la venta ya está a la vista).
const ventaRef = ref<HTMLElement | null>(null);
const ventaVisible = ref(false);
let observador: IntersectionObserver | null = null;
watch(ventaRef, (el) => {
  observador?.disconnect();
  observador = null;
  if (el !== null && typeof IntersectionObserver !== "undefined") {
    observador = new IntersectionObserver((entradas) => {
      ventaVisible.value = entradas[0]?.isIntersecting ?? false;
    });
    observador.observe(el);
  }
});
onBeforeUnmount(() => observador?.disconnect());
function irAVenta(): void {
  ventaRef.value?.scrollIntoView({ behavior: "smooth", block: "start" });
}

// La clave del intento de cobro: si la respuesta se pierde y se vuelve a cobrar, el
// servidor devuelve la misma venta (no la registra dos veces). Se renueva al vender
// o al vaciar el carrito.
let claveIntento: string | null = null;
function nuevaClave(): string {
  return typeof crypto !== "undefined" && "randomUUID" in crypto
    ? crypto.randomUUID()
    : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}
watch(
  () => carrito.value.length,
  (n) => {
    if (n === 0) {
      claveIntento = null;
    }
  },
);

// Cambió un precio o se desactivó un producto con el carrito abierto: se refrescan
// los productos y los precios del carrito, y se pide revisar el total.
function refrescarCarrito(): void {
  carrito.value = carrito.value
    .map((l) => {
      const a = articulos.value.find((x) => x.id === l.id);
      if (!a || !a.activo) {
        return null;
      }
      const extras = l.extras.map(
        (e) => (a.extras ?? []).find((x) => x.id === e.id) ?? e,
      );
      return { ...l, precio: a.precio_minor, extras };
    })
    .filter((l): l is Linea => l !== null);
}

async function cobrar(): Promise<void> {
  if (carrito.value.length === 0 || sucursalSel.value === "") {
    return;
  }
  const a = compradorElegido.value;
  if (
    !(await confirmar(
      t("confirmaciones.pos", {
        total: dinero(totalCobrar.value),
        metodo: formaEnPalabras.value,
      }) +
        " " +
        (a
          ? t("mostradorVisual.comprador.confirmarA", { nombre: a.nombre })
          : t("mostradorVisual.comprador.confirmarPublico")),
      { aceptar: t("confirmaciones.cobrar") },
    ))
  ) {
    return;
  }
  cobrando.value = true;
  error.value = null;
  claveIntento ??= nuevaClave();
  try {
    const { data } = await api.post<{
      data: {
        cambio_minor: number | null;
        ticket_enviado_en?: string | null;
        // Sale por la cola (R06): pendiente hasta que se manda.
        ticket_envio?: "enviado" | "pendiente" | "fallido" | null;
      };
    }>(`${base.value}/pos/ventas`, {
      clave_intento: claveIntento,
      sucursal_id: sucursalSel.value,
      metodo_pago: metodo.value,
      ...(dividido.value && partes.value !== null
        ? { partes: partes.value }
        : {}),
      items: carrito.value.map((l) => ({
        articulo_id: l.id,
        cantidad: l.cantidad,
        extras: l.extras.map((e) => e.id),
        // Lo que se mostró por pieza: si cambió, el servidor pide revisarlo.
        precio_minor: precioLinea(l),
      })),
      propina_minor: propinaMinor.value,
      recibido_minor: recibidoMinor.value,
      comprador_id: comprador.value !== "" ? comprador.value : null,
      enviar_ticket: comprador.value !== "" && enviarTicket.value,
      ...(apartadosACobrar.value.length > 0
        ? { apartados: apartadosACobrar.value }
        : {}),
    });
    const vuelto = data?.data?.cambio_minor ?? null;
    const ticket =
      (data?.data?.ticket_envio === "enviado" ||
        data?.data?.ticket_envio === "pendiente" ||
        data?.data?.ticket_enviado_en) &&
      a?.email
        ? " " +
          t("mostradorVisual.comprador.ticketEnviadoA", { correo: a.email })
        : "";
    toast.exito(
      (vuelto !== null && vuelto > 0
        ? t("mostradorVisual.vendidoCambio", { cambio: dinero(vuelto) })
        : t("pos.vendido")) + ticket,
    );
    carrito.value = [];
    claveIntento = null;
    apartadosACobrar.value = [];
    apartadoDe.value = null;
    reiniciarCobro();
    await Promise.all([cargar(), listaVentas.value?.cargar()]);
  } catch (e) {
    const mensaje = mensajeDeError(e);
    error.value = mensaje;
    // Un precio que cambió, un producto que se desactivó o que otra venta se llevó
    // las últimas piezas: se trae lo vigente (y el aviso se conserva para que se
    // revise el carrito).
    const campos = camposConError(e);
    if (
      codigoDeError(e) === "STOCK_INSUFFICIENT" ||
      campos.some(
        (c) => c.endsWith(".precio_minor") || c.endsWith(".articulo_id"),
      )
    ) {
      await cargar();
      refrescarCarrito();
      error.value = mensaje;
    }
  } finally {
    cobrando.value = false;
  }
}

// ---- Indicadores del día (del servidor: hoy en el negocio, sin anuladas) ----
const indicadores = computed<Indicador[]>(() => {
  const r = hoy.value;
  const sinStock = articulos.value.filter(
    (a) => a.activo && stockEn(a) <= 0,
  ).length;
  return [
    {
      clave: "ventas",
      etiqueta: t("mostradorVisual.kpi.ventasHoy"),
      valor: r === null ? "—" : String(r.ventas),
      icono: "ventas",
    },
    {
      clave: "total",
      etiqueta: t("mostradorVisual.kpi.vendidoHoy"),
      valor: r === null ? "—" : dinero(r.vendido_minor),
      icono: "dinero",
    },
    {
      clave: "ticket",
      etiqueta: t("mostradorVisual.kpi.ticket"),
      valor:
        r !== null && r.ventas > 0
          ? dinero(Math.round(r.vendido_minor / r.ventas))
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

// Lo que un cliente apartó para su clase (ADR 0194): llega desde la lista de la clase
// con sus artículos, su sede y quién es; la venta los entrega.
const ruta = useRoute();
const apartadosACobrar = ref<string[]>([]);
const apartadoDe = ref<string | null>(null);
function precargarApartados(): void {
  // Sin enrutador (p. ej. en pruebas aisladas) no hay nada que precargar.
  const q = (ruta as typeof ruta | undefined)?.query ?? {};
  const lista =
    typeof q.apartados === "string"
      ? q.apartados.split(",").filter(Boolean)
      : [];
  if (lista.length === 0) {
    return;
  }
  if (
    typeof q.sucursal === "string" &&
    sucursales.value.some((s) => s.id === q.sucursal)
  ) {
    sucursalSel.value = q.sucursal;
  }
  const piezas = typeof q.articulos === "string" ? q.articulos.split(",") : [];
  for (const pieza of piezas) {
    const [id, cuantos] = pieza.split(":");
    const articulo = articulos.value.find((a) => a.id === id);
    for (let i = 0; articulo && i < Number(cuantos || 1); i++) {
      sumarLinea(articulo, []);
    }
  }
  if (typeof q.comprador === "string" && q.comprador !== "") {
    comprador.value = q.comprador;
  }
  apartadoDe.value = typeof q.nombre === "string" ? q.nombre : null;
  apartadosACobrar.value = lista;
}

onMounted(async () => {
  await cargar();
  // Una categoría de la dirección que ya no existe no deja la lista vacía.
  if (
    categoria.value !== "" &&
    !categorias.value.some((c) => c.id === categoria.value)
  ) {
    categoria.value = "";
  }
  precargarApartados();
});
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('mostradorVisual.titulo')"
      :subtitulo="$t('mostradorVisual.subtitulo')"
    />

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
      data-prueba="error-carga-mostrador"
    >
      <span style="color: var(--error)">{{
        $t("mostradorVisual.errorCarga")
      }}</span>
      <button type="button" class="tu-btn tu-btn-secundario" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </div>

    <template v-else>
      <p
        v-if="sucursales.length === 0"
        class="mt-6 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("pos.sinSucursales") }}
      </p>

      <template v-else>
        <!-- Lo de hoy, compacto: en el teléfono una línea, para que los productos
             queden arriba. -->
        <TarjetasIndicadores
          class="mt-6 hidden sm:block"
          compacta
          :tarjetas="indicadores"
        />
        <p
          v-if="hoy !== null"
          class="mo-hoy sm:hidden"
          data-prueba="resumen-hoy"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{
            $t(
              "mostradorVisual.hoyResumen",
              { n: hoy.ventas, total: dinero(hoy.vendido_minor) },
              hoy.ventas,
            )
          }}
        </p>

        <div
          class="mt-5 grid gap-5 lg:grid-cols-3"
          :class="{ 'mo-con-barra': carrito.length > 0 }"
        >
          <!-- Productos -->
          <div class="tu-card lg:col-span-2">
            <div class="mo-barra">
              <label class="tu-buscar">
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
                  :value="sucursalSel"
                  class="tu-input"
                  :aria-label="$t('mostradorVisual.sucursal.etiqueta')"
                  data-prueba="sucursal-pos"
                  @change="alElegirSucursal"
                >
                  <option v-for="s in sucursales" :key="s.id" :value="s.id">
                    {{ s.nombre }}
                  </option>
                </select>
              </label>
              <LeyendaSucursal v-else />
            </div>
            <!-- Filtrar por categoría (solo si el negocio tiene categorías): el
                 segmentado de los filtros; las que no caben se desplazan dentro. -->
            <div v-if="categorias.length > 0" class="mo-categorias">
              <div
                class="tu-segmentado"
                role="group"
                :aria-label="$t('operacion.productos.filtrar')"
                data-prueba="categorias-mostrador"
              >
                <button
                  type="button"
                  :aria-pressed="categoria === ''"
                  @click="categoria = ''"
                >
                  {{ $t("operacion.productos.todas") }}
                </button>
                <button
                  v-for="c in categorias"
                  :key="c.id"
                  type="button"
                  :aria-pressed="categoria === c.id"
                  :data-prueba="`categoria-${c.id}`"
                  @click="categoria = c.id"
                >
                  {{ c.nombre }}
                </button>
              </div>
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
                <img
                  v-if="a.foto_url"
                  :src="a.foto_url"
                  alt=""
                  class="mo-tile mo-foto"
                />
                <span v-else class="mo-tile" aria-hidden="true">{{
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
          <aside
            ref="ventaRef"
            class="tu-card mo-venta h-fit"
            data-prueba="venta-actual"
          >
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
              v-if="sucursalVenta"
              class="mo-sede"
              data-prueba="sucursal-venta"
            >
              <IconoNav nombre="ubicacion" :tam="14" />
              {{
                $t("mostradorVisual.sucursal.ventaEn", {
                  sucursal: sucursalVenta,
                })
              }}
            </p>
            <p
              v-if="carrito.length === 0"
              class="mt-3 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ $t("mostradorVisual.vacia") }}
            </p>
            <ul v-else class="mo-lineas">
              <li v-for="l in carrito" :key="l.clave" data-prueba="linea-venta">
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-medium">{{ l.nombre }}</p>
                  <p
                    v-if="l.extras.length > 0"
                    class="text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{
                      $t("mostradorVisual.extras.con", {
                        extras: l.extras.map((e) => e.nombre).join(", "),
                      })
                    }}
                  </p>
                  <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
                    {{ dinero(precioLinea(l)) }}
                  </p>
                </div>
                <div class="mo-cantidad">
                  <button
                    type="button"
                    :aria-label="$t('mostradorVisual.menos')"
                    @click="restar(l.clave)"
                  >
                    −
                  </button>
                  <span class="tabular-nums">{{ l.cantidad }}</span>
                  <button
                    type="button"
                    :aria-label="$t('mostradorVisual.mas')"
                    @click="sumar(l.clave)"
                  >
                    +
                  </button>
                </div>
                <span class="w-20 text-right text-sm tabular-nums">{{
                  dinero(precioLinea(l) * l.cantidad)
                }}</span>
                <button
                  type="button"
                  class="tu-icono-btn"
                  :aria-label="$t('mostradorVisual.quitar')"
                  @click="quitar(l.clave)"
                >
                  <IconoNav nombre="cerrar" :tam="14" />
                </button>
              </li>
            </ul>

            <!-- Lo apartado para una clase (ADR 0194): ya viene en el carrito. -->
            <p
              v-if="apartadoDe"
              class="mt-4 text-sm"
              data-prueba="cobrando-apartados"
            >
              {{ $t("apartados.mostrador.aviso", { nombre: apartadoDe }) }}
            </p>
            <div class="mt-4" data-prueba="comprador">
              <label class="tu-label" for="pos-comprador">{{
                $t("mostradorVisual.comprador.titulo")
              }}</label>
              <BuscarPersona
                v-model="comprador"
                campo-id="pos-comprador"
                :buscar-en="`${base}/pos/compradores`"
                :placeholder="$t('mostradorVisual.comprador.buscar')"
                :describir="describirComprador"
                :deshabilitado="!puedeVender"
                @elegir="alElegirComprador"
              />
              <p
                v-if="comprador === ''"
                class="mt-1 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("mostradorVisual.comprador.publicoAyuda") }}
              </p>
              <label
                v-else
                class="mt-2 flex items-start gap-2 text-sm"
                data-prueba="enviar-ticket"
              >
                <input
                  v-model="enviarTicket"
                  type="checkbox"
                  class="mt-0.5"
                  :disabled="!compradorElegido?.email"
                />
                <span>
                  {{ $t("mostradorVisual.comprador.enviarTicket") }}
                  <span
                    class="block text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{
                      compradorElegido?.email
                        ? $t("mostradorVisual.comprador.aCorreo", {
                            correo: compradorElegido.email,
                          })
                        : $t("mostradorVisual.comprador.sinCorreo")
                    }}</span
                  >
                </span>
              </label>
            </div>
            <div class="mt-4">
              <p class="tu-label">{{ $t("pos.metodo") }}</p>
              <div v-if="!dividido" class="tu-segmentado w-full" role="group">
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
              <FormasDePago
                v-if="carrito.length > 0"
                v-model:dividido="dividido"
                v-model:partes="partes"
                class="mt-2"
                :total="totalCobrar"
                :metodos="METODOS"
                prefijo="pos.metodos"
                :inicial="metodo"
                :moneda="sesion.moneda"
                :pais="sesion.pais"
              />
            </div>
            <div v-if="carrito.length > 0" class="mt-4" data-prueba="propina">
              <p class="tu-label">{{ $t("mostradorVisual.propina.titulo") }}</p>
              <div class="tu-segmentado w-full" role="group">
                <button
                  type="button"
                  class="flex-1"
                  :aria-pressed="propinaModo === 'sin'"
                  @click="propinaModo = 'sin'"
                >
                  {{ $t("mostradorVisual.propina.sin") }}
                </button>
                <button
                  v-for="p in PORCENTAJES"
                  :key="p"
                  type="button"
                  class="flex-1"
                  :aria-pressed="propinaModo === p"
                  @click="propinaModo = p"
                >
                  {{ p }} %
                </button>
                <button
                  type="button"
                  class="flex-1"
                  :aria-pressed="propinaModo === 'otra'"
                  @click="propinaModo = 'otra'"
                >
                  {{ $t("mostradorVisual.propina.otra") }}
                </button>
              </div>
              <input
                v-if="propinaModo === 'otra'"
                v-model="propinaOtra"
                class="tu-input mt-2"
                type="number"
                min="0"
                step="0.01"
                inputmode="decimal"
                data-prueba="propina-otra"
                :aria-label="$t('mostradorVisual.propina.monto')"
                :placeholder="$t('mostradorVisual.propina.monto')"
              />
            </div>
            <dl
              v-if="propinaMinor > 0"
              class="mt-3 grid gap-1 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              <div class="flex justify-between">
                <dt>{{ $t("mostradorVisual.subtotal") }}</dt>
                <dd class="tabular-nums">{{ dinero(totalCarrito) }}</dd>
              </div>
              <div class="flex justify-between">
                <dt>{{ $t("mostradorVisual.propina.titulo") }}</dt>
                <dd class="tabular-nums">{{ dinero(propinaMinor) }}</dd>
              </div>
            </dl>
            <div class="mo-total">
              <span>{{
                propinaMinor > 0
                  ? $t("mostradorVisual.totalCobrar")
                  : $t("pos.total")
              }}</span>
              <span class="tabular-nums" data-prueba="total-cobrar">{{
                dinero(totalCobrar)
              }}</span>
            </div>
            <div
              v-if="enEfectivo > 0 && carrito.length > 0"
              class="mt-2 grid grid-cols-[minmax(0,1fr)_auto] items-end gap-3"
            >
              <label class="block text-sm">
                <span class="tu-label">{{
                  $t("mostradorVisual.recibido")
                }}</span>
                <input
                  v-model="recibido"
                  class="tu-input"
                  type="number"
                  min="0"
                  step="0.01"
                  inputmode="decimal"
                  data-prueba="recibido"
                  :aria-describedby="'mo-recibido-ayuda'"
                />
              </label>
              <p
                v-if="cambio !== null"
                class="pb-2 text-sm font-medium tabular-nums"
                data-prueba="cambio"
                :style="{ color: cambio < 0 ? 'var(--error)' : 'var(--texto)' }"
              >
                {{
                  cambio < 0
                    ? $t("mostradorVisual.falta", { monto: dinero(-cambio) })
                    : `${$t("mostradorVisual.cambio")}: ${dinero(cambio)}`
                }}
              </p>
              <p
                id="mo-recibido-ayuda"
                class="col-span-2 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ $t("mostradorVisual.recibidoAyuda") }}
              </p>
            </div>
            <button
              class="tu-btn tu-btn-primario mt-3 w-full"
              type="button"
              data-prueba="cobrar"
              :aria-busy="cobrando || undefined"
              :disabled="
                !puedeVender ||
                cobrando ||
                carrito.length === 0 ||
                !partesOk ||
                (cambio !== null && cambio < 0)
              "
              @click="cobrar"
            >
              {{ cobrando ? $t("pos.cobrando") : $t("pos.cobrar") }}
            </button>
          </aside>
        </div>

        <!-- En el teléfono: piezas y total siempre a mano, hasta la venta. -->
        <div
          v-if="carrito.length > 0 && !ventaVisible"
          class="mo-barra-cobro lg:hidden"
          data-prueba="barra-cobro"
        >
          <span class="min-w-0">
            <span
              class="block text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ $t("mostradorVisual.piezas", { n: piezas }) }}</span
            >
            <span class="block font-semibold tabular-nums">{{
              dinero(totalCobrar)
            }}</span>
          </span>
          <button
            type="button"
            class="tu-btn tu-btn-primario"
            data-prueba="ir-a-venta"
            @click="irAVenta"
          >
            {{ $t("mostradorVisual.verVenta") }}
          </button>
        </div>

        <!-- Ventas recientes: a quién, por páginas y con fechas (ADR 0145). -->
        <VentasMostrador
          ref="listaVentas"
          class="mt-8"
          :sucursal-id="sucursalFija"
          :mostrar-sucursal="!sucursalActual"
          corregir
          @hoy="hoy = $event"
        />
      </template>
    </template>
    <!-- Extras del producto (ADR 0139): un toque por extra. -->
    <ModalDialogo
      :abierto="eligiendoExtras !== null"
      :titulo="eligiendoExtras?.nombre ?? $t('mostradorVisual.extras.titulo')"
      tam="md"
      @cerrar="eligiendoExtras = null"
    >
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("mostradorVisual.extras.ayuda") }}
      </p>
      <div class="mt-3 flex flex-wrap gap-2" data-prueba="extras-elegir">
        <button
          v-for="e in eligiendoExtras?.extras ?? []"
          :key="e.id"
          type="button"
          class="tu-btn tu-btn-secundario"
          :aria-pressed="extrasElegidos.includes(e.id)"
          :class="{ 'mo-extra-elegido': extrasElegidos.includes(e.id) }"
          @click="alternarExtra(e.id)"
        >
          {{ e.nombre }} · {{ dinero(e.precio_minor) }}
        </button>
      </div>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          data-prueba="extras-cancelar"
          @click="eligiendoExtras = null"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-primario"
          data-prueba="extras-agregar"
          @click="confirmarExtras"
        >
          {{ $t("mostradorVisual.extras.agregar") }}
        </button>
      </template>
    </ModalDialogo>
  </section>
</template>

<style scoped>
/* Extra elegido (ADR 0139): borde de acento, sin depender del mouse. */
.mo-extra-elegido {
  border-color: var(--acento);
  background: var(--superficie-2);
}
.mo-barra {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  padding: 0.9rem 1rem;
  border-bottom: 1px solid var(--borde);
}
.mo-categorias {
  padding: 0.75rem 1rem 0;
}
.mo-foto {
  width: 3rem;
  height: 3rem;
  object-fit: cover;
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
  scroll-margin-top: 1rem;
}
.mo-hoy {
  margin-top: 1rem;
  font-size: 0.875rem;
}
.mo-sede {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.25rem;
  font-size: 0.8rem;
  color: var(--texto-suave);
}
.mo-barra-cobro {
  position: fixed;
  inset-inline: 0;
  bottom: 0;
  z-index: 30;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.7rem 1rem calc(0.7rem + env(safe-area-inset-bottom));
  border-top: 1px solid var(--borde);
  background: var(--superficie);
  box-shadow: 0 -6px 18px rgb(0 0 0 / 0.08);
}
/* Que la barra fija no tape lo último de la venta en el teléfono. */
@media (max-width: 1023.98px) {
  .mo-con-barra {
    padding-bottom: 4.5rem;
  }
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
</style>
