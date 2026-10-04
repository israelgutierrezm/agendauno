<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import BuscarPersona, {
  type PersonaBuscable,
} from "@/components/BuscarPersona.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { puedeEntrar } from "@/lib/acceso";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import {
  SECCIONES,
  clasesDe,
  fechaLarga,
  seccionDe,
  type Plan,
} from "@/lib/planes";
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
}

const METODOS = ["efectivo", "transferencia", "ventanilla"] as const;

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeVender = computed(() => sesion.puede("ordenes.gestionar"));
const puedePromos = computed(() => sesion.puede("ordenes.gestionar"));

// A quién se vende (se busca entre todos en el servidor).
const comprador = ref<PersonaBuscable | null>(null);
const productos = ref<Producto[]>([]);
const ordenes = ref<Orden[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

const venta = ref<{
  compradorId: string;
  productoId: string;
  metodo: (typeof METODOS)[number];
  codigoPromo: string;
}>({ compradorId: "", productoId: "", metodo: "efectivo", codigoPromo: "" });
const vendiendo = ref(false);
const promoPreview = ref<{ descuento: number; total: number } | null>(null);
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

function dinero(minor: number, moneda = "MXN"): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}
function queIncluye(p: Plan): string {
  if (p.tipo === "membresia") {
    return p.ilimitado
      ? t("planes.resumen.ilimitado")
      : t(
          "planes.resumen.porMes",
          { n: clasesDe(p.unidades_por_ciclo) },
          clasesDe(p.unidades_por_ciclo),
        );
  }
  if (p.ilimitado) {
    return t("planes.resumen.ilimitado");
  }
  const n = clasesDe(p.creditos_incluidos);
  return t("planes.resumen.clases", { n }, n);
}
function nombreMetodo(m: string | null | undefined): string {
  if (!m) {
    return "—";
  }
  return te(`ventas.metodos.${m}`) ? t(`ventas.metodos.${m}`) : m;
}
function cuando(iso: string | null | undefined): string {
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

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [p, o] = await Promise.all([
      api.get<{ data: Producto[] }>(`${base.value}/productos`, {
        params: { incluir: "todos" },
      }),
      api.get<{ data: Orden[] }>(`${base.value}/ordenes`),
    ]);
    productos.value = p.data.data;
    ordenes.value = o.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
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

const total = computed(
  () => promoPreview.value?.total ?? productoSel.value?.precio_minor ?? 0,
);

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

  // Ya se cobró: el formulario no queda listo para cobrar otra vez lo mismo.
  const nombre = persona.nombre;
  venta.value.productoId = "";
  venta.value.codigoPromo = "";
  promoPreview.value = null;
  let mensaje = t("ventas.vender.exitoVenta", { persona: nombre });
  try {
    // Lo que le quedó: su membresía o sus créditos.
    const der = await api.get<{
      data: Array<{ ilimitado: boolean; saldo: number | null }>;
    }>(`${base.value}/miembros/${persona.id}/derechos`);
    const ultimo = der.data.data[0];
    if (ultimo) {
      mensaje = ultimo.ilimitado
        ? t("ventas.vender.exitoMembresia", { persona: nombre })
        : t("ventas.vender.exitoPack", {
            persona: nombre,
            saldo: Math.round((ultimo.saldo ?? 0) / 1000),
          });
    }
  } catch {
    // Sin el detalle del derecho basta con confirmar la venta.
  }
  toast.exito(mensaje);
  try {
    await cargar();
  } finally {
    vendiendo.value = false;
  }
}

// ---- Indicadores ----
const pagadasHoy = computed(() => {
  const hoy = new Date().toDateString();
  return ordenes.value.filter(
    (o) =>
      o.estado === "pagada" &&
      o.pagada_en &&
      new Date(o.pagada_en).toDateString() === hoy,
  );
});
const pendientes = computed(() =>
  ordenes.value.filter((o) => o.estado === "pendiente"),
);
const indicadores = computed<Indicador[]>(() => [
  {
    clave: "ventas",
    etiqueta: t("ventasVisual.kpi.ventasHoy"),
    valor: String(pagadasHoy.value.length),
    icono: "ventas",
  },
  {
    clave: "vendido",
    etiqueta: t("ventasVisual.kpi.vendidoHoy"),
    valor: dinero(pagadasHoy.value.reduce((s, o) => s + o.total_minor, 0)),
    icono: "dinero",
  },
  {
    clave: "pendientes",
    etiqueta: t("ventasVisual.kpi.porCobrar"),
    valor: String(pendientes.value.length),
    icono: "reloj",
    aviso: pendientes.value.length > 0,
  },
  {
    clave: "planes",
    etiqueta: t("ventasVisual.kpi.planes"),
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
      :subtitulo="$t('ventas.vender.subtitulo')"
    >
      <template v-if="puedeEntrar('planes', sesion)" #acciones>
        <RouterLink :to="{ name: 'planes' }" class="tu-btn tu-btn-fantasma">
          {{ $t("ventas.vender.administrarPlanes") }}
        </RouterLink>
      </template>
    </EncabezadoSeccion>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>

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
              <h2 class="vv-grupo">{{ $t(`planes.secciones.${g.clave}`) }}</h2>
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
              <p v-else class="vv-sub">{{ $t("ventasVisual.eligePlan") }}</p>
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
              </p>
            </div>

            <div class="vv-total">
              <span>{{ $t("pos.total") }}</span>
              <span class="tabular-nums">{{
                dinero(total, productoSel?.moneda ?? "MXN")
              }}</span>
            </div>
            <button
              class="tu-btn tu-btn-primario w-full"
              type="submit"
              data-prueba="cobrar-plan"
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
        </aside>
      </div>

      <!-- Ventas recientes -->
      <h2 class="mt-8 font-medium">{{ $t("ventas.ordenes.titulo") }}</h2>
      <EstadoVacio
        v-if="ordenes.length === 0"
        class="mt-3 py-6"
        icono="ventas"
        compacto
        :titulo="$t('ventas.ordenes.vacio')"
      />
      <div v-else class="tu-card mt-3 overflow-x-auto">
        <table class="vv-tabla">
          <thead>
            <tr>
              <th>{{ $t("ventasVisual.col.cuando") }}</th>
              <th>{{ $t("ventas.ordenes.colComprador") }}</th>
              <th class="hidden md:table-cell">
                {{ $t("ventasVisual.col.plan") }}
              </th>
              <th class="hidden sm:table-cell">
                {{ $t("ventas.vender.metodo") }}
              </th>
              <th>{{ $t("ventas.ordenes.colEstado") }}</th>
              <th class="text-right">{{ $t("ventas.ordenes.colTotal") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in ordenes.slice(0, 20)" :key="o.id">
              <td class="whitespace-nowrap vv-suave">
                {{ cuando(o.pagada_en) }}
              </td>
              <td class="font-medium">{{ o.comprador ?? "—" }}</td>
              <td class="hidden md:table-cell">
                {{
                  (o.lineas ?? [])
                    .map((l) => l.producto ?? "")
                    .filter(Boolean)
                    .join(", ") || "—"
                }}
              </td>
              <td class="hidden sm:table-cell vv-suave">
                {{ nombreMetodo(o.metodo_pago) }}
              </td>
              <td>
                <span
                  class="tu-pildora"
                  :style="{
                    '--tono':
                      o.estado === 'pagada' ? 'var(--exito)' : 'var(--aviso)',
                  }"
                  >{{
                    o.estado === "pagada"
                      ? $t("ventas.ordenes.pagada")
                      : $t("ventas.ordenes.pendiente")
                  }}</span
                >
              </td>
              <td class="text-right font-medium tabular-nums">
                {{ dinero(o.total_minor, o.moneda) }}
              </td>
            </tr>
          </tbody>
        </table>
        <RouterLink
          v-if="ordenes.length > 20"
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
.vv-tabla {
  width: 100%;
  font-size: 0.9rem;
  border-collapse: collapse;
}
.vv-tabla th {
  padding: 0.7rem 1rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
  font-weight: 500;
  text-align: left;
}
.vv-tabla th.text-right {
  text-align: right;
}
.vv-tabla td {
  padding: 0.7rem 1rem;
  border-top: 1px solid var(--borde);
  vertical-align: middle;
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
