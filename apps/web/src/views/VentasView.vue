<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import BuscarPersona, {
  type PersonaBuscable,
} from "@/components/BuscarPersona.vue";
import CorregirCobro from "@/components/CorregirCobro.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { puedeEntrar } from "@/lib/acceso";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { claveSegunModalidad } from "@/lib/menu";
import { crearVenta, derechoVendido, type DerechoDeVenta } from "@/lib/venta";
import {
  queIncluyePlan,
  reglasDePlan,
  useCuotasDeVenta,
} from "@/lib/ventaDePlan";
import { dinero as dineroDelPais } from "@/lib/formato";
import { SECCIONES, fechaLarga, seccionDe, type Plan } from "@/lib/planes";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Vender un plan (membresía, paquete, clase suelta…) con el mismo patrón del
 * Mostrador: indicadores del día, los planes a la venta como tarjetas, la venta
 * actual a la derecha (cliente, forma de pago, cupón y total) y debajo las ventas
 * recientes. Qué se vende se define en Planes y paquetes.
 */
type Producto = Plan;
interface Orden {
  id: string;
  comprador: string | null;
  estado: string;
  total_minor: number;
  moneda: string;
  metodo_pago?: string | null;
  pagada_en?: string | null;
  lineas?: { producto: string | null; cantidad: number }[];
  // Su cobro en caja, para corregir la forma de pago o anularlo (ADR 0086/0087); la
  // misma forma que el de una cita en la agenda.
  pago?: {
    id: string;
    metodo: string | null;
    en_caja?: boolean;
    corregible: boolean;
    anulable: boolean;
  } | null;
}
// Lo de hoy en el negocio, calculado en el API sobre todas las órdenes.
interface ResumenOrdenes {
  ventas_hoy: number;
  vendido_hoy: { moneda: string; total_minor: number }[];
  por_cobrar: number;
}

// «tarjeta»: con la terminal del negocio (no por la pasarela en línea).
const METODOS = ["efectivo", "tarjeta", "transferencia", "ventanilla"] as const;

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
// En citas lo que se vende son bonos (ADR 0130): la variante «…Citas» si existe.
function tm(
  clave: string,
  valores: Record<string, unknown> = {},
  cuantos?: number,
): string {
  const llave = claveSegunModalidad(clave, sesion.esCitas, te);
  return cuantos === undefined ? t(llave, valores) : t(llave, valores, cuantos);
}
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeVender = computed(() => sesion.puede("ordenes.gestionar"));
const puedePromos = computed(() => sesion.puede("ordenes.gestionar"));

// A quién se vende (se busca entre todos en el servidor).
const comprador = ref<PersonaBuscable | null>(null);
const productos = ref<Producto[]>([]);
const ordenes = ref<Orden[]>([]);
// Cuántas ventas recientes se muestran (el resto, en Cobros → Movimientos).
const RECIENTES = 20;
const totalOrdenes = ref(0);
const resumen = ref<ResumenOrdenes | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
// Que no se pudo consultar no debe verse como «aún no hay planes ni ventas».
const errorCarga = ref(false);

const venta = ref<{
  compradorId: string;
  productoId: string;
  metodo: (typeof METODOS)[number];
  codigoPromo: string;
}>({ compradorId: "", productoId: "", metodo: "efectivo", codigoPromo: "" });
const vendiendo = ref(false);
const promoPreview = ref<{ descuento: number; total: number } | null>(null);
// Inscripción (B04): quien puede la exenta con un motivo (queda en la bitácora).
const exentarInscripcion = ref(false);
const motivoExencion = ref("");
const puedeExentar = computed(() => sesion.puede("cuotas.exentar"));
const validandoPromo = ref(false);

// Solo los vendibles (no archivados), agrupados por tipo.
const productosVendibles = computed(() =>
  productos.value.filter((p) => !p.archivado),
);
const gruposVendibles = computed(() =>
  SECCIONES.map((s) => ({
    clave: s,
    productos: productosVendibles.value.filter((p) => seccionDe(p.tipo) === s),
  })).filter((g) => g.productos.length > 0),
);
const productoSel = computed(
  () => productos.value.find((p) => p.id === venta.value.productoId) ?? null,
);
// La inscripción que se sumaría (dicha antes de cobrar) y las condiciones que acepta
// el cliente (permanencia), igual que en la venta rápida de la ficha.
const {
  inscripcion,
  consultandoCuotas,
  errorCuotas,
  reintentarCuotas,
  aceptaCondiciones,
  pideCondiciones,
} = useCuotasDeVenta(
  base,
  computed(() => venta.value.compradorId),
  productoSel,
  () => {
    exentarInscripcion.value = false;
    motivoExencion.value = "";
  },
);
// Dónde vale, qué deja reservar y sus reglas, antes de cobrar.
const reglasSel = computed(() =>
  productoSel.value
    ? reglasDePlan(productoSel.value, t, {
        variasSedes: (sesion.usuario?.sucursales?.length ?? 0) > 1,
        esCitas: sesion.esCitas,
      })
    : [],
);

// En la moneda del negocio (ADR 0099) y con los números de su país.
function dinero(minor: number, moneda = sesion.moneda): string {
  return dineroDelPais(minor, moneda, sesion.pais);
}
function queIncluye(p: Plan): string {
  return queIncluyePlan(p, tm);
}
function nombreMetodo(m: string | null | undefined): string {
  if (!m) {
    return "—";
  }
  if (m === "manual") return t("adminUsabilidad.registroManual");
  return te(`ventas.metodos.${m}`) ? t(`ventas.metodos.${m}`) : m;
}
function cuando(iso: string | null | undefined): string {
  if (!iso) {
    return "—";
  }
  const d = new Date(iso);
  // En la zona del negocio (la de los filtros por día), no en la del navegador.
  const zona = sesion.zonaHoraria || undefined;
  const dia = (x: Date) =>
    new Intl.DateTimeFormat("en-CA", { timeZone: zona }).format(x);
  const hoy = dia(new Date()) === dia(d);
  return new Intl.DateTimeFormat(
    "es-MX",
    hoy
      ? { hour: "numeric", minute: "2-digit", timeZone: zona }
      : {
          day: "numeric",
          month: "short",
          hour: "numeric",
          minute: "2-digit",
          timeZone: zona,
        },
  ).format(d);
}

// Si el cobro falla, reintentar la misma venta cobra esa orden (no crea otra).
// Con varias sucursales, el cobro entra en la caja de la elegida en la barra
// (ADR 0175): con «Todas» no se sabe a cuál, así que se pide elegirla.
const cobro = crearVenta();
const sinSucursalParaCobrar = cobro.sinSucursal;

// `enSegundoPlano`: tras una venta se actualiza sin desmontar el formulario.
async function cargar(enSegundoPlano = false): Promise<void> {
  cargando.value = !enSegundoPlano;
  error.value = null;
  errorCarga.value = false;
  try {
    const [p, o] = await Promise.all([
      api.get<{ data: Producto[] }>(`${base.value}/productos`, {
        params: { incluir: "todos" },
      }),
      // Las ventas recientes (una página) y el resumen de hoy.
      api.get<{
        data: Orden[];
        meta?: { total: number; resumen: ResumenOrdenes };
      }>(`${base.value}/ordenes`, { params: { per_page: RECIENTES } }),
    ]);
    productos.value = p.data.data;
    ordenes.value = o.data.data;
    totalOrdenes.value = o.data.meta?.total ?? o.data.data.length;
    resumen.value = o.data.meta?.resumen ?? null;
  } catch {
    errorCarga.value = true;
  } finally {
    cargando.value = false;
  }
}

function elegirPlan(p: Plan): void {
  venta.value.productoId = p.id;
  promoPreview.value = null;
}

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

const cobraInscripcion = computed(
  () => inscripcion.value > 0 && !exentarInscripcion.value,
);
const total = computed(
  () =>
    (promoPreview.value?.total ?? productoSel.value?.precio_minor ?? 0) +
    (cobraInscripcion.value ? inscripcion.value : 0),
);

// Los planes que ya tiene quien compra (al elegirlo): tras vender, el aviso dice lo
// disponible del plan recién vendido (no el saldo total ni el primero de la lista).
const derechosAntes = ref<DerechoDeVenta[] | null>(null);
let pedidoDerechos = 0;
async function derechosDe(id: string): Promise<DerechoDeVenta[] | null> {
  try {
    const { data } = await api.get<{ data: DerechoDeVenta[] }>(
      `${base.value}/miembros/${id}/derechos`,
    );
    return Array.isArray(data.data) ? data.data : null;
  } catch {
    return null;
  }
}
watch(
  () => venta.value.compradorId,
  async (id) => {
    const mio = ++pedidoDerechos;
    derechosAntes.value = null;
    if (id === "") {
      return;
    }
    const lista = await derechosDe(id);
    if (mio === pedidoDerechos) {
      derechosAntes.value = lista;
    }
  },
);
/**
 * «Vendido. Con este plan, Ana tiene 2.5 créditos disponibles.» (1000 unidades = 1
 * crédito, con decimales). Sin saber cuál se vendió, solo que la venta quedó.
 */
async function mensajeDeVenta(
  personaId: string,
  nombre: string,
  antes: DerechoDeVenta[] | null,
): Promise<string> {
  const despues = antes !== null ? await derechosDe(personaId) : null;
  const vendido =
    antes !== null && despues !== null ? derechoVendido(antes, despues) : null;
  if (vendido === null) {
    return t("ventas.vender.exitoVenta", { persona: nombre });
  }
  if (vendido.ilimitado) {
    return tm("ventas.vender.exitoMembresia", { persona: nombre });
  }
  const n = Math.max(0, vendido.disponible ?? 0) / 1000;
  const saldo = n.toLocaleString("es-MX", { maximumFractionDigits: 3 });
  // En citas: el bono y sus sesiones (ADR 0130).
  return t(
    sesion.esCitas ? "ventas.vender.exitoPackCitas" : "ventas.vender.exitoPack",
    { persona: nombre, saldo },
    n === 1 ? 1 : 2,
  );
}

async function vender(): Promise<void> {
  const producto = productoSel.value;
  const persona = comprador.value;
  if (
    producto === null ||
    persona === null ||
    persona.id !== venta.value.compradorId
  ) {
    return;
  }
  // Vender cobra en el momento: se confirma qué, cuánto, cómo y a quién.
  if (
    !(await confirmar(
      t("confirmaciones.venta", {
        producto: producto.nombre,
        monto: dinero(total.value, producto.moneda),
        metodo: t(`ventas.metodos.${venta.value.metodo}`),
        persona: persona.nombre,
      }),
      { aceptar: t("confirmaciones.cobrar") },
    ))
  ) {
    return;
  }
  vendiendo.value = true;
  error.value = null;
  // Lo que tenía antes de esta venta: el aviso habla del plan que se vendió.
  const antes = derechosAntes.value;
  try {
    await cobro.vender(
      base.value,
      {
        comprador_id: venta.value.compradorId,
        items: [{ producto_id: venta.value.productoId, cantidad: 1 }],
        // Solo el código ya aplicado: lo que se confirmó es lo que se cobra.
        codigo_promo:
          promoPreview.value !== null && venta.value.codigoPromo.trim() !== ""
            ? venta.value.codigoPromo
            : undefined,
        ...(inscripcion.value > 0 && exentarInscripcion.value
          ? {
              exentar_inscripcion: true,
              motivo_exencion: motivoExencion.value.trim(),
            }
          : {}),
        ...(pideCondiciones.value ? { acepta_condiciones: true } : {}),
      },
      venta.value.metodo,
    );
  } catch (e) {
    error.value = mensajeDeError(e);
    vendiendo.value = false;
    return;
  }

  // Ya se cobró: el formulario no queda listo para cobrar otra vez lo mismo ni a la
  // misma persona (la siguiente venta se elige de nuevo).
  const nombre = persona.nombre;
  venta.value.compradorId = "";
  comprador.value = null;
  venta.value.productoId = "";
  venta.value.codigoPromo = "";
  promoPreview.value = null;
  toast.exito(await mensajeDeVenta(persona.id, nombre, antes));
  try {
    await cargar(true);
  } finally {
    vendiendo.value = false;
  }
}

// ---- Corregir un cobro en caja hecho con error (la forma de pago, o anularlo) ----
const corrigiendo = ref<string | null>(null);
function corregible(o: Orden): boolean {
  const p = o.pago;
  return (
    !!p &&
    p.en_caja !== false &&
    ((p.corregible && sesion.puede("ordenes.gestionar")) ||
      (p.anulable && sesion.puede("pagos.reembolsar")))
  );
}
const conCorregir = computed(() => ordenes.value.some(corregible));
async function alCorregir(): Promise<void> {
  corrigiendo.value = null;
  await cargar(true);
}

// ---- Indicadores (los de hoy en el negocio, de todas las órdenes) ----
const vendidoHoy = computed(() => {
  const montos = resumen.value?.vendido_hoy ?? [];
  return montos.length === 0
    ? dinero(0)
    : montos.map((m) => dinero(m.total_minor, m.moneda)).join(" · ");
});
const indicadores = computed<Indicador[]>(() => [
  {
    clave: "ventas",
    etiqueta: t("ventasVisual.kpi.ventasHoy"),
    valor: String(resumen.value?.ventas_hoy ?? 0),
    icono: "ventas",
  },
  {
    clave: "vendido",
    etiqueta: t("ventasVisual.kpi.vendidoHoy"),
    valor: vendidoHoy.value,
    icono: "dinero",
  },
  {
    clave: "pendientes",
    etiqueta: t("ventasVisual.kpi.porCobrar"),
    valor: String(resumen.value?.por_cobrar ?? 0),
    icono: "reloj",
    aviso: (resumen.value?.por_cobrar ?? 0) > 0,
  },
  {
    clave: "planes",
    etiqueta: tm("ventasVisual.kpi.planes"),
    valor: String(productosVendibles.value.length),
    icono: "etiqueta",
  },
]);

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="
        sesion.esCitas
          ? $t('ventasVisual.tituloCitas')
          : $t('ventasVisual.titulo')
      "
      :subtitulo="tm('ventas.vender.subtitulo')"
    />
    <!-- Qué se vende se define en otra sección: se llega con un enlace. -->
    <p
      v-if="puedeEntrar('planes', sesion)"
      class="mt-2 text-sm"
      data-prueba="administrar-planes"
    >
      <RouterLink :to="{ name: 'planes' }" class="tu-enlace">
        {{ tm("ventas.vender.administrarPlanes") }}
      </RouterLink>
    </p>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

    <div
      v-else-if="errorCarga"
      class="mt-6 tu-card flex flex-wrap items-center justify-between gap-3 p-6 text-sm"
      role="alert"
      data-prueba="error-carga-ventas"
    >
      <span style="color: var(--error)">{{ $t("ventas.errorCarga") }}</span>
      <button type="button" class="tu-btn tu-btn-secundario" @click="cargar()">
        {{ $t("comun.reintentar") }}
      </button>
    </div>

    <template v-else>
      <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

      <div v-if="puedeVender" class="mt-5 grid gap-5 lg:grid-cols-3">
        <!-- Planes a la venta -->
        <div class="tu-card lg:col-span-2">
          <p
            v-if="productosVendibles.length === 0"
            class="px-4 py-10 text-center text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("ventas.vender.sinProductos") }}
          </p>
          <div v-else class="vv-planes">
            <section v-for="g in gruposVendibles" :key="g.clave">
              <h2 class="vv-grupo">
                {{
                  sesion.esCitas && g.clave === "paquete"
                    ? $t("planes.tiposCitas.paquete")
                    : $t(`planes.secciones.${g.clave}`)
                }}
              </h2>
              <div class="vv-rejilla">
                <button
                  v-for="p in g.productos"
                  :key="p.id"
                  type="button"
                  class="vv-plan"
                  :aria-pressed="venta.productoId === p.id"
                  data-prueba="plan"
                  @click="elegirPlan(p)"
                >
                  <span class="block font-medium">{{ p.nombre }}</span>
                  <span class="block text-lg font-semibold tabular-nums">{{
                    dinero(p.precio_minor, p.moneda)
                  }}</span>
                  <span class="vv-sub">{{ queIncluye(p) }}</span>
                </button>
              </div>
            </section>
          </div>
        </div>

        <!-- Venta actual -->
        <aside class="tu-card vv-venta h-fit" data-prueba="venta-plan">
          <h2 class="font-medium">{{ $t("ventasVisual.ventaActual") }}</h2>
          <form class="mt-3 space-y-4" @submit.prevent="vender">
            <div>
              <label class="tu-label" for="vm">{{
                $t("ventas.vender.miembro")
              }}</label>
              <BuscarPersona
                v-model="venta.compradorId"
                campo-id="vm"
                :buscar-en="`${base}/miembros`"
                :parametros="{ tipo: 'miembro' }"
                @elegir="comprador = $event"
              />
            </div>

            <div class="vv-elegido">
              <template v-if="productoSel">
                <p class="font-medium">{{ productoSel.nombre }}</p>
                <p class="vv-sub">{{ queIncluye(productoSel) }}</p>
                <p
                  v-for="r in reglasSel"
                  :key="r"
                  class="vv-sub"
                  data-prueba="regla-plan"
                >
                  {{ r }}
                </p>
                <p v-if="productoSel.tipo === 'add_on'" class="vv-sub">
                  {{ $t("planes.vender.extraAyuda") }}
                </p>
                <p v-else-if="productoSel.vence_si_compra_hoy" class="vv-sub">
                  {{
                    $t("planes.vender.venceHoy", {
                      hasta: fechaLarga(productoSel.vence_si_compra_hoy),
                    })
                  }}
                </p>
              </template>
              <p v-else class="vv-sub">{{ tm("ventasVisual.eligePlan") }}</p>
            </div>

            <div>
              <p class="tu-label">{{ $t("ventas.vender.metodo") }}</p>
              <div class="tu-segmentado w-full" role="group">
                <button
                  v-for="m in METODOS"
                  :key="m"
                  type="button"
                  class="flex-1"
                  :aria-pressed="venta.metodo === m"
                  @click="venta.metodo = m"
                >
                  {{ $t(`ventas.metodos.${m}`) }}
                </button>
              </div>
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
                  class="tu-btn tu-btn-secundario shrink-0"
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
                      productoSel?.moneda ?? sesion.moneda,
                    ),
                  })
                }}
              </p>
            </div>

            <!-- Inscripción (B04): se suma si es su alta o regresa tras un tiempo. -->
            <p
              v-if="consultandoCuotas"
              class="text-sm"
              role="status"
              :style="{ color: 'var(--texto-suave)' }"
              data-prueba="cuotas-consultando"
            >
              {{ $t("cuotasVenta.consultando") }}
            </p>
            <div
              v-else-if="errorCuotas"
              class="flex flex-wrap items-center gap-2 text-sm"
              role="alert"
              data-prueba="cuotas-error"
            >
              <span :style="{ color: 'var(--error)' }">{{
                $t("cuotasVenta.error")
              }}</span>
              <button
                type="button"
                class="tu-btn tu-btn-secundario tu-btn-chico"
                @click="reintentarCuotas"
              >
                {{ $t("comun.reintentar") }}
              </button>
            </div>
            <div
              v-if="inscripcion > 0"
              class="space-y-2 text-sm"
              data-prueba="inscripcion-venta"
            >
              <div class="flex justify-between">
                <span>{{ $t("ventas.vender.inscripcion") }}</span>
                <span
                  class="tabular-nums"
                  :style="
                    exentarInscripcion
                      ? {
                          textDecoration: 'line-through',
                          color: 'var(--texto-suave)',
                        }
                      : {}
                  "
                  >{{
                    dinero(inscripcion, productoSel?.moneda ?? sesion.moneda)
                  }}</span
                >
              </div>
              <template v-if="puedeExentar">
                <label class="flex items-center gap-2">
                  <input v-model="exentarInscripcion" type="checkbox" />
                  {{ $t("ventas.vender.exentarInscripcion") }}
                </label>
                <input
                  v-if="exentarInscripcion"
                  v-model="motivoExencion"
                  class="tu-input"
                  maxlength="300"
                  :placeholder="$t('ventas.vender.motivoExencion')"
                  :aria-label="$t('ventas.vender.motivoExencion')"
                  data-prueba="motivo-exencion"
                />
              </template>
            </div>

            <!-- Permanencia (B04): las condiciones que acepta el cliente. -->
            <div
              v-if="pideCondiciones && productoSel?.condiciones"
              class="space-y-2 rounded-lg p-3 text-sm"
              :style="{ background: 'var(--superficie-2)' }"
              data-prueba="condiciones-venta"
            >
              <p>{{ productoSel.condiciones }}</p>
              <label class="flex items-start gap-2">
                <input
                  v-model="aceptaCondiciones"
                  type="checkbox"
                  class="mt-1"
                />
                {{ $t("ventas.vender.aceptaCondiciones") }}
              </label>
            </div>

            <div class="vv-total">
              <span>{{ $t("pos.total") }}</span>
              <span class="tabular-nums">{{
                dinero(total, productoSel?.moneda ?? sesion.moneda)
              }}</span>
            </div>
            <p
              v-if="sinSucursalParaCobrar"
              class="text-sm"
              role="status"
              :style="{ color: 'var(--texto-suave)' }"
              data-prueba="elige-sucursal"
            >
              {{ $t("sucursalOperativa.eligeParaCobrar") }}
            </p>
            <button
              class="tu-btn tu-btn-primario w-full"
              type="submit"
              data-prueba="cobrar-plan"
              :aria-busy="vendiendo || undefined"
              :disabled="
                vendiendo ||
                sinSucursalParaCobrar ||
                venta.compradorId === '' ||
                venta.productoId === '' ||
                consultandoCuotas ||
                errorCuotas ||
                (exentarInscripcion && motivoExencion.trim() === '') ||
                (pideCondiciones && !aceptaCondiciones)
              "
            >
              {{
                vendiendo
                  ? $t("ventas.vender.cobrando")
                  : $t("ventas.vender.cobrar")
              }}
            </button>
          </form>
        </aside>
      </div>

      <!-- Ventas recientes -->
      <h2 class="mt-8 tu-titulo-seccion">{{ $t("ventas.ordenes.titulo") }}</h2>
      <EstadoVacio
        v-if="ordenes.length === 0"
        class="mt-3 py-6"
        icono="ventas"
        compacto
        :titulo="$t('ventas.ordenes.vacio')"
      />
      <div v-else class="tu-card mt-3">
        <!-- En el teléfono, una ficha por venta: a quién, cuánto y qué se vendió. -->
        <div class="relative overflow-x-auto tu-tabla-adaptable">
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>{{ $t("ventas.ordenes.colComprador") }}</th>
                <th class="text-right">{{ $t("ventas.ordenes.colTotal") }}</th>
                <th>{{ $t("ventasVisual.col.cuando") }}</th>
                <th class="hidden md:table-cell">
                  {{ tm("ventasVisual.col.plan") }}
                </th>
                <th class="hidden sm:table-cell">
                  {{ $t("ventas.vender.metodo") }}
                </th>
                <th>{{ $t("ventas.ordenes.colEstado") }}</th>
                <th v-if="conCorregir" class="text-right"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="o in ordenes" :key="o.id">
                <tr data-prueba="orden-reciente">
                  <td
                    :data-etiqueta="$t('ventas.ordenes.colComprador')"
                    class="font-medium"
                  >
                    {{ o.comprador ?? "—" }}
                  </td>
                  <td
                    :data-etiqueta="$t('ventas.ordenes.colTotal')"
                    class="text-right font-medium tabular-nums"
                  >
                    {{ dinero(o.total_minor, o.moneda) }}
                  </td>
                  <td
                    :data-etiqueta="$t('ventasVisual.col.cuando')"
                    class="whitespace-nowrap vv-suave"
                  >
                    {{ cuando(o.pagada_en) }}
                  </td>
                  <td
                    :data-etiqueta="tm('ventasVisual.col.plan')"
                    class="hidden md:table-cell"
                  >
                    {{
                      (o.lineas ?? [])
                        .map((l) => l.producto ?? "")
                        .filter(Boolean)
                        .join(", ") || "—"
                    }}
                  </td>
                  <td
                    :data-etiqueta="$t('ventas.vender.metodo')"
                    class="hidden sm:table-cell vv-suave"
                  >
                    {{ nombreMetodo(o.metodo_pago) }}
                  </td>
                  <td :data-etiqueta="$t('ventas.ordenes.colEstado')">
                    <span
                      class="tu-estado"
                      :style="{
                        '--tono':
                          o.estado === 'pagada'
                            ? 'var(--exito)'
                            : 'var(--aviso)',
                      }"
                      >{{
                        o.estado === "pagada"
                          ? $t("ventas.ordenes.pagada")
                          : $t("ventas.ordenes.pendiente")
                      }}</span
                    >
                  </td>
                  <td v-if="conCorregir" class="tu-celda-acciones">
                    <button
                      v-if="corregible(o)"
                      type="button"
                      class="tu-btn tu-btn-secundario tu-btn-chico"
                      :aria-expanded="corrigiendo === o.id"
                      data-prueba="corregir-venta-plan"
                      @click="corrigiendo = corrigiendo === o.id ? null : o.id"
                    >
                      {{ $t("corregirCobro.corregir") }}
                    </button>
                  </td>
                </tr>
                <!-- Cobro en caja con error: corregir la forma o anularlo -->
                <tr v-if="corrigiendo === o.id && o.pago">
                  <td colspan="7" class="tu-celda-detalle">
                    <CorregirCobro
                      :base="base"
                      :pago="o.pago"
                      @cambiado="alCorregir"
                    />
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
        <RouterLink
          v-if="totalOrdenes > RECIENTES && puedeEntrar('cobranza', sesion)"
          :to="{ name: 'cobranza' }"
          class="vv-mas tu-enlace text-sm"
        >
          {{ $t("ventasVisual.verTodas") }}
          <IconoNav nombre="chevron" :tam="14" />
        </RouterLink>
      </div>
    </template>
  </section>
</template>

<style scoped>
.vv-planes {
  display: grid;
  gap: 1.1rem;
  padding: 1rem;
}
.vv-grupo {
  margin-bottom: 0.5rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
  font-weight: 500;
}
.vv-rejilla {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(12.5rem, 1fr));
  gap: 0.6rem;
}
.vv-plan {
  padding: 0.8rem 0.9rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
  font-size: 0.875rem;
  text-align: left;
  transition: border-color 0.15s ease;
}
.vv-plan:hover {
  border-color: color-mix(in srgb, var(--primario) 50%, var(--borde));
}
.vv-plan[aria-pressed="true"] {
  border-color: var(--primario);
  box-shadow: 0 0 0 1px var(--primario);
}
.vv-sub {
  display: block;
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.vv-venta {
  padding: 1rem 1.1rem 1.1rem;
}
.vv-elegido {
  padding: 0.7rem 0.8rem;
  border: 1px dashed var(--borde);
  border-radius: var(--radio-boton);
  font-size: 0.875rem;
}
.vv-total {
  display: flex;
  justify-content: space-between;
  padding-top: 0.85rem;
  border-top: 1px solid var(--borde);
  font-size: 1.05rem;
  font-weight: 600;
}
.vv-suave {
  color: var(--texto-suave);
}
.vv-mas {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  margin: 0.75rem 1rem;
}
</style>
