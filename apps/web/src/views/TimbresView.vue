<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoRetornoPago from "@/components/AvisoRetornoPago.vue";
import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import RangoFechas from "@/components/RangoFechas.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { fechaEnUrl, opcionEnUrl, useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
import { useConfirmacionPago } from "@/lib/retornoPago";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Timbres para facturar (ADR 0107), en su propia pantalla: cuántos quedan, cómo se
 * usan por mes y para cuánto alcanzan, la compra de un paquete y todos los
 * movimientos por páginas, cada uno con su factura (cliente, RFC, total) o su compra.
 * Un negocio que factura mucho acumula cientos de movimientos al mes: se filtran por
 * tipo, fechas y cliente en el servidor y se exportan en CSV.
 */
type TipoMovimiento = "consumo" | "compra" | "ajuste";
interface Movimiento {
  id: string;
  tipo: TipoMovimiento;
  cantidad: number;
  saldo_despues: number;
  detalle: string | null;
  fecha: string | null;
  factura: {
    id: string;
    cliente: string | null;
    rfc: string | null;
    total_minor: number;
    moneda: string;
    estado: string;
    uuid: string | null;
  } | null;
  compra: { id: string; monto_minor: number; moneda: string } | null;
}
interface Resumen {
  usados_mes: number;
  usados_mes_anterior: number;
  promedio_mensual: number;
  alcanza_meses: number | null;
  por_mes: { mes: string; usados: number; comprados: number }[];
}
interface Timbres {
  disponibles: number;
  iva_porcentaje: number;
  moneda: string;
  paquetes: { cantidad: number; precio_minor: number }[];
  posible: boolean;
  motivo: string | null;
  resumen: Resumen;
  compra_pendiente: {
    id: string;
    cantidad: number;
    monto_minor: number;
    moneda: string;
  } | null;
}
interface Meta {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const veTimbres = computed(() => sesion.puede("facturacion.ver"));
const puedeComprar = computed(() => sesion.puede("estudio.gestionar"));

const timbres = ref<Timbres | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Timbres }>(`${base.value}/timbres`);
    timbres.value = data.data;
    paquete.value ??= data.data.paquetes[0]?.cantidad ?? null;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// --- Indicadores ---------------------------------------------------------------
const indicadores = computed<Indicador[]>(() => {
  const x = timbres.value;
  if (!x) {
    return [];
  }
  const r = x.resumen;
  const alcanza =
    r.alcanza_meses === null
      ? t("suscripcion.timbres.pantalla.sinEstimacion")
      : r.alcanza_meses < 1
        ? t("suscripcion.timbres.pantalla.menosDeUnMes")
        : t(
            "suscripcion.timbres.pantalla.alcanzaMeses",
            { n: r.alcanza_meses },
            r.alcanza_meses,
          );
  return [
    {
      clave: "disponibles",
      etiqueta: t("suscripcion.timbres.pantalla.disponibles"),
      valor: String(x.disponibles),
      icono: "facturas",
      tono: "verde",
      aviso: x.posible && x.disponibles === 0,
    },
    {
      clave: "usados",
      etiqueta: t("suscripcion.timbres.pantalla.usadosMes"),
      valor: String(r.usados_mes),
      icono: "reportes",
      tono: "azul",
      tendencia: {
        direccion:
          r.usados_mes > r.usados_mes_anterior
            ? "sube"
            : r.usados_mes < r.usados_mes_anterior
              ? "baja"
              : "igual",
        texto: t("suscripcion.timbres.pantalla.mesAnterior", {
          n: r.usados_mes_anterior,
        }),
        buena: null,
      },
    },
    {
      clave: "promedio",
      etiqueta: t("suscripcion.timbres.pantalla.promedio"),
      // Sin meses completos con uso, no hay promedio que mostrar.
      valor: r.promedio_mensual > 0 ? String(r.promedio_mensual) : "—",
      icono: "pulso",
      tono: "morado",
    },
    {
      clave: "alcanza",
      etiqueta: t("suscripcion.timbres.pantalla.alcanza"),
      valor: alcanza,
      icono: "reloj",
      tono: "naranja",
      aviso: x.posible && r.alcanza_meses !== null && r.alcanza_meses < 1,
    },
  ];
});

// --- Uso por mes (gráfica) -----------------------------------------------------
const porMes = computed(() => timbres.value?.resumen.por_mes ?? []);
const maximo = computed(() =>
  Math.max(1, ...porMes.value.map((m) => m.usados)),
);
const conUso = computed(() =>
  porMes.value.some((m) => m.usados > 0 || m.comprados > 0),
);
function nombreMes(mes: string, largo = false): string {
  const [a, m] = mes.split("-").map(Number);
  return new Intl.DateTimeFormat("es-MX", {
    month: largo ? "long" : "short",
    ...(largo ? { year: "numeric" } : {}),
    timeZone: "UTC",
  }).format(new Date(Date.UTC(a, (m ?? 1) - 1, 15)));
}
function descripcionMes(m: {
  mes: string;
  usados: number;
  comprados: number;
}): string {
  return t("suscripcion.timbres.pantalla.usoBarra", {
    mes: nombreMes(m.mes, true),
    usados: m.usados,
    comprados: m.comprados,
  });
}

// --- Comprar -------------------------------------------------------------------
const paquete = ref<number | null>(null);
const comprando = ref(false);
const pagandoPendiente = ref(false);
const elegido = computed(
  () =>
    timbres.value?.paquetes.find((p) => p.cantidad === paquete.value) ?? null,
);
function dinero(minor: number, moneda?: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda ?? timbres.value?.moneda ?? "MXN",
  }).format(minor / 100);
}
const totalConIva = computed(() => {
  const p = elegido.value;
  const iva = timbres.value?.iva_porcentaje ?? 16;
  return p ? Math.round((p.precio_minor * (100 + iva)) / 100) : 0;
});
async function comprar(): Promise<void> {
  if (paquete.value === null) {
    return;
  }
  comprando.value = true;
  try {
    const { data } = await api.post<{
      data: { checkout?: { url?: string } };
    }>(`${base.value}/timbres/comprar`, {
      cantidad: paquete.value,
      retorno: "/renta/timbres",
    });
    const url = data.data.checkout?.url;
    if (typeof url === "string" && url !== "") {
      window.location.href = url;
      return;
    }
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    comprando.value = false;
  }
}
async function pagarPendiente(): Promise<void> {
  const pendiente = timbres.value?.compra_pendiente;
  if (!pendiente) {
    return;
  }
  pagandoPendiente.value = true;
  try {
    const { data } = await api.post<{
      data: { estado?: string; checkout?: { tipo?: string; url?: string } };
    }>(`${base.value}/renta/cargos/${pendiente.id}/pagar`, {
      // Vuelve aquí, con el aviso de los timbres (no el de la renta).
      retorno: "/renta/timbres",
    });
    const checkout = data.data.checkout ?? {};
    if (typeof checkout.url === "string" && checkout.url !== "") {
      window.location.href = checkout.url;
      return;
    }
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    pagandoPendiente.value = false;
  }
}

// --- Movimientos (por páginas, filtros en el servidor y en la dirección) -------
const movimientos = ref<Movimiento[]>([]);
const meta = ref<Meta | null>(null);
const cargandoMovimientos = ref(true);
const errorMovimientos = ref<string | null>(null);
const buscar = ref("");
const tipo = ref<"" | TipoMovimiento>("");
const desde = ref("");
const hasta = ref("");
const pagina = ref(1);
const porPagina = ref(20);
useFiltrosEnUrl({
  q: buscar,
  tipo: opcionEnUrl(tipo, ["", "consumo", "compra", "ajuste"] as const),
  desde: fechaEnUrl(desde),
  hasta: fechaEnUrl(hasta),
});
const conFiltros = computed(
  () =>
    buscar.value !== "" ||
    tipo.value !== "" ||
    desde.value !== "" ||
    hasta.value !== "",
);
function filtros(): Record<string, string> {
  const q = buscar.value.trim();
  return {
    ...(q !== "" ? { q } : {}),
    ...(tipo.value !== "" ? { tipo: tipo.value } : {}),
    ...(desde.value !== "" ? { desde: desde.value } : {}),
    ...(hasta.value !== "" ? { hasta: hasta.value } : {}),
  };
}
async function cargarMovimientos(): Promise<void> {
  cargandoMovimientos.value = true;
  errorMovimientos.value = null;
  try {
    const { data } = await api.get<{ data: Movimiento[]; meta: Meta }>(
      `${base.value}/timbres/movimientos`,
      {
        params: { ...filtros(), page: pagina.value, per_page: porPagina.value },
      },
    );
    movimientos.value = data.data;
    meta.value = data.meta;
  } catch (e) {
    errorMovimientos.value = mensajeDeError(e);
  } finally {
    cargandoMovimientos.value = false;
  }
}
function irPagina(n: number): void {
  pagina.value = n;
  void cargarMovimientos();
}
function cambiarPorPagina(n: number): void {
  porPagina.value = n;
  pagina.value = 1;
  void cargarMovimientos();
}
function limpiarFiltros(): void {
  buscar.value = "";
  tipo.value = "";
  desde.value = "";
  hasta.value = "";
}
let espera: ReturnType<typeof setTimeout> | undefined;
watch(buscar, () => {
  clearTimeout(espera);
  espera = setTimeout(() => {
    pagina.value = 1;
    void cargarMovimientos();
  }, 300);
});
watch([tipo, desde, hasta], () => {
  pagina.value = 1;
  void cargarMovimientos();
});
const exportando = ref(false);
async function exportar(): Promise<void> {
  exportando.value = true;
  try {
    const { data } = await api.get<Blob>(`${base.value}/timbres/movimientos`, {
      params: { ...filtros(), formato: "csv" },
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = "timbres.csv";
    enlace.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    exportando.value = false;
  }
}

const TIPOS = ["", "consumo", "compra", "ajuste"] as const;
const ICONO: Record<TipoMovimiento, string> = {
  consumo: "facturas",
  compra: "pos",
  ajuste: "ajustes",
};
function etiquetaTipo(x: "" | TipoMovimiento): string {
  return {
    "": t("suscripcion.timbres.pantalla.todos"),
    consumo: t("suscripcion.timbres.pantalla.facturas"),
    compra: t("suscripcion.timbres.pantalla.compras"),
    ajuste: t("suscripcion.timbres.pantalla.ajustes"),
  }[x];
}
function fechaHora(iso: string | null): string {
  if (!iso) {
    return "";
  }
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: sesion.zonaHoraria,
  }).format(new Date(iso));
}
function cantidad(n: number): string {
  return n > 0 ? `+${n}` : `−${Math.abs(n)}`;
}

// Al volver de pagar, el aviso de la pasarela llega en unos segundos.
// Se dicen comprados solo cuando el servidor confirma el pago.
const retornoPago = useConfirmacionPago(
  (ref) => `${base.value}/renta/cargos/${encodeURIComponent(ref)}/confirmacion`,
  () => {
    toast.exito(t("suscripcion.timbres.comprados"));
    void cargar();
    void cargarMovimientos();
  },
);
onMounted(() => {
  if (!veTimbres.value) {
    return;
  }
  void cargar();
  void cargarMovimientos();
});
</script>

<template>
  <!-- Sin acceso: un aviso neutro como título de la página (el único h1). -->
  <section v-if="!veTimbres" class="tu-pagina" data-prueba="no-disponible">
    <EncabezadoSeccion
      :titulo="$t('renta.noDisponibleTitulo')"
      :subtitulo="$t('renta.noDisponibleAyuda')"
    />
  </section>

  <section v-else class="tu-pagina" data-prueba="pantalla-timbres">
    <RouterLink
      :to="{ name: 'renta' }"
      class="tu-volver"
      data-prueba="volver-renta"
      ><IconoNav nombre="atras" :tam="16" />
      {{ $t("suscripcion.timbres.pantalla.volver") }}</RouterLink
    >
    <EncabezadoSeccion
      class="mt-4"
      :titulo="$t('suscripcion.timbres.titulo')"
      :subtitulo="$t('suscripcion.timbres.ayuda')"
    />
    <AvisoRetornoPago v-if="retornoPago" class="mt-3" :estado="retornoPago" />

    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <p v-if="cargando && !timbres" class="mt-6 ti-suave">
      {{ $t("comun.cargando") }}
    </p>

    <template v-else-if="timbres">
      <AvisoTono
        v-if="timbres.compra_pendiente"
        tono="aviso"
        class="mt-4"
        data-prueba="compra-pendiente"
      >
        {{
          $t("suscripcion.timbres.pantalla.pendiente", {
            n: timbres.compra_pendiente.cantidad,
            monto: dinero(
              timbres.compra_pendiente.monto_minor,
              timbres.compra_pendiente.moneda,
            ),
          })
        }}
        <template v-if="puedeComprar" #acciones>
          <button
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            :disabled="pagandoPendiente"
            data-prueba="pagar-pendiente"
            @click="pagarPendiente"
          >
            {{
              pagandoPendiente
                ? $t("suscripcion.timbres.pantalla.pagando")
                : $t("suscripcion.timbres.pantalla.pagar")
            }}
          </button>
        </template>
      </AvisoTono>
      <AvisoTono
        v-if="timbres.posible && timbres.disponibles === 0"
        tono="aviso"
        class="mt-4"
      >
        {{ $t("suscripcion.timbres.agotados") }}
      </AvisoTono>

      <TarjetasIndicadores
        class="mt-5"
        :tarjetas="indicadores"
        data-prueba="indicadores-timbres"
      />

      <div class="ti-fila mt-5">
        <div class="tu-card p-5" data-prueba="comprar-timbres-tarjeta">
          <h2 class="font-medium">
            {{ $t("suscripcion.timbres.pantalla.comprarTitulo") }}
          </h2>
          <p v-if="!timbres.posible" class="mt-2 text-sm ti-suave">
            {{ timbres.motivo }}
          </p>
          <template v-else>
            <p class="mt-1 text-sm ti-suave">
              {{ $t("suscripcion.timbres.pantalla.comprarAyuda") }}
            </p>
            <label class="mt-4 block">
              <span class="tu-label">{{
                $t("suscripcion.timbres.paqueteEtiqueta")
              }}</span>
              <select
                v-model.number="paquete"
                class="tu-input mt-1 w-full"
                :disabled="!puedeComprar"
                data-prueba="paquete"
              >
                <option
                  v-for="p in timbres.paquetes"
                  :key="p.cantidad"
                  :value="p.cantidad"
                >
                  {{ $t("suscripcion.timbres.paquete", { n: p.cantidad }) }} ·
                  {{
                    $t("suscripcion.timbres.precio", {
                      monto: dinero(p.precio_minor),
                    })
                  }}
                </option>
              </select>
            </label>
            <p
              v-if="elegido"
              class="mt-2 text-sm ti-suave"
              data-prueba="total-con-iva"
            >
              {{
                $t("suscripcion.timbres.pantalla.totalConIva", {
                  monto: dinero(totalConIva),
                })
              }}
            </p>
            <button
              v-if="puedeComprar"
              type="button"
              class="tu-btn tu-btn-primario mt-4 w-full"
              :disabled="comprando || paquete === null"
              data-prueba="comprar-timbres"
              @click="comprar"
            >
              {{
                comprando
                  ? $t("suscripcion.timbres.comprando")
                  : $t("suscripcion.timbres.pantalla.comprarPaquete")
              }}
            </button>
            <p v-else class="mt-3 text-xs ti-suave">
              {{ $t("suscripcion.timbres.pantalla.soloDueno") }}
            </p>
          </template>
        </div>

        <div class="tu-card p-5" data-prueba="uso-por-mes">
          <h2 class="font-medium">
            {{ $t("suscripcion.timbres.pantalla.usoTitulo") }}
          </h2>
          <p class="mt-1 text-sm ti-suave">
            {{ $t("suscripcion.timbres.pantalla.usoAyuda") }}
          </p>
          <p v-if="!conUso" class="mt-6 text-sm ti-suave">
            {{ $t("suscripcion.timbres.pantalla.sinUso") }}
          </p>
          <template v-else>
            <ul class="ti-grafica mt-4" role="list">
              <li
                v-for="m in porMes"
                :key="m.mes"
                class="ti-columna"
                :title="descripcionMes(m)"
                :aria-label="descripcionMes(m)"
              >
                <span class="ti-valor tabular-nums">{{
                  m.usados > 0 ? m.usados : ""
                }}</span>
                <span class="ti-pista">
                  <span
                    class="ti-barra"
                    :class="{ 'ti-barra-vacia': m.usados === 0 }"
                    :style="{ height: `${(m.usados / maximo) * 100}%` }"
                  />
                </span>
                <span
                  class="ti-compra"
                  :class="{ 'ti-compra-si': m.comprados > 0 }"
                  aria-hidden="true"
                />
                <span class="ti-mes">{{ nombreMes(m.mes) }}</span>
              </li>
            </ul>
            <p class="mt-3 text-xs ti-suave flex items-center gap-3">
              <span class="inline-flex items-center gap-1"
                ><span class="ti-leyenda-barra" aria-hidden="true" />{{
                  $t("suscripcion.timbres.pantalla.leyendaUsados")
                }}</span
              >
              <span class="inline-flex items-center gap-1"
                ><span class="ti-compra ti-compra-si" aria-hidden="true" />{{
                  $t("suscripcion.timbres.pantalla.leyendaCompras")
                }}</span
              >
            </p>
          </template>
        </div>
      </div>
    </template>

    <!-- Movimientos -->
    <div class="mt-8 flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="tu-titulo-seccion">
        {{ $t("suscripcion.timbres.pantalla.movimientos") }}
        <span
          v-if="meta"
          class="ml-1 text-sm font-normal tabular-nums ti-suave"
          >{{ meta.total }}</span
        >
      </h2>
      <button
        type="button"
        class="tu-btn tu-btn-secundario tu-btn-chico"
        :disabled="exportando || (meta?.total ?? 0) === 0"
        data-prueba="exportar-timbres"
        @click="exportar"
      >
        <IconoNav nombre="descargar" :tam="15" />
        {{ $t("suscripcion.timbres.pantalla.exportar") }}
      </button>
    </div>
    <!-- Los filtros, dentro de la tarjeta de lo que filtran (y en la dirección). El libro
         de movimientos es comparativo (cantidad y saldo): se queda como tabla. -->
    <div class="tu-card mt-3">
      <div class="tu-filtros" data-prueba="filtros-timbres">
        <label class="tu-buscar">
          <IconoNav nombre="buscar" :tam="16" />
          <input
            v-model="buscar"
            type="search"
            class="tu-input"
            :placeholder="$t('suscripcion.timbres.pantalla.buscar')"
            :aria-label="$t('suscripcion.timbres.pantalla.buscar')"
            data-prueba="buscar-timbres"
          />
        </label>
        <div
          class="tu-segmentado"
          role="group"
          :aria-label="$t('suscripcion.timbres.pantalla.tipo')"
        >
          <button
            v-for="x in TIPOS"
            :key="x"
            type="button"
            :aria-pressed="tipo === x"
            :data-prueba="`timbres-${x || 'todos'}`"
            @click="tipo = x"
          >
            {{ etiquetaTipo(x) }}
          </button>
        </div>
        <RangoFechas
          v-model:desde="desde"
          v-model:hasta="hasta"
          :etiqueta="$t('suscripcion.timbres.pantalla.fechas')"
          prueba="timbres"
          atajos
          :zona="sesion.zonaHoraria"
        />
        <button
          v-if="conFiltros"
          type="button"
          class="tu-btn tu-btn-discreto"
          data-prueba="limpiar-timbres"
          @click="limpiarFiltros"
        >
          {{ $t("suscripcion.timbres.pantalla.limpiar") }}
        </button>
      </div>

      <AvisoTono
        v-if="errorMovimientos"
        tono="peligro"
        class="m-4"
        role="alert"
      >
        {{ errorMovimientos }}
        <template #acciones>
          <button
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            @click="cargarMovimientos"
          >
            {{ $t("comun.reintentar") }}
          </button>
        </template>
      </AvisoTono>
      <p
        v-else-if="cargandoMovimientos && movimientos.length === 0"
        class="p-5 text-sm ti-suave"
      >
        {{ $t("comun.cargando") }}
      </p>
      <p
        v-else-if="movimientos.length === 0"
        class="tu-sin-resultados"
        data-prueba="timbres-vacio"
      >
        {{
          conFiltros
            ? $t("suscripcion.timbres.pantalla.sinResultados")
            : $t("suscripcion.timbres.pantalla.vacio")
        }}
      </p>
      <div
        v-else
        class="overflow-x-auto"
        :class="{ 'opacity-60': cargandoMovimientos }"
      >
        <table class="tu-tabla w-full text-sm" data-prueba="tabla-timbres">
          <thead>
            <tr>
              <th scope="col">
                {{ $t("suscripcion.timbres.pantalla.colMovimiento") }}
              </th>
              <th scope="col">
                {{ $t("suscripcion.timbres.pantalla.colFecha") }}
              </th>
              <th scope="col">
                {{ $t("suscripcion.timbres.pantalla.colDetalle") }}
              </th>
              <th scope="col" class="text-right">
                {{ $t("suscripcion.timbres.pantalla.colCantidad") }}
              </th>
              <th scope="col" class="text-right">
                {{ $t("suscripcion.timbres.pantalla.colSaldo") }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in movimientos" :key="m.id" data-prueba="fila-timbre">
              <td>
                <span class="inline-flex items-center gap-2 font-medium">
                  <IconoNav
                    :nombre="ICONO[m.tipo]"
                    :tam="16"
                    class="ti-icono"
                  />
                  {{ $t(`suscripcion.timbres.${m.tipo}`) }}
                </span>
              </td>
              <td class="whitespace-nowrap ti-suave">
                {{ fechaHora(m.fecha) }}
              </td>
              <td class="min-w-0">
                <template v-if="m.factura">
                  <div class="font-medium truncate">
                    {{
                      m.factura.cliente ??
                      $t("suscripcion.timbres.pantalla.facturaSinCliente")
                    }}
                  </div>
                  <div class="text-xs ti-suave">
                    <span v-if="m.factura.rfc">{{ m.factura.rfc }} · </span>
                    {{ dinero(m.factura.total_minor, m.factura.moneda) }}
                    <RouterLink
                      v-if="m.factura.uuid && sesion.puede('ordenes.ver')"
                      :to="{ name: 'facturas', query: { q: m.factura.uuid } }"
                      class="tu-enlace ml-1 whitespace-nowrap"
                      >{{
                        $t("suscripcion.timbres.pantalla.verFactura")
                      }}</RouterLink
                    >
                  </div>
                </template>
                <template v-else-if="m.compra">
                  <div class="font-medium">{{ m.detalle }}</div>
                  <div class="text-xs ti-suave">
                    {{ dinero(m.compra.monto_minor, m.compra.moneda) }}
                  </div>
                </template>
                <span v-else class="ti-suave">{{ m.detalle ?? "—" }}</span>
              </td>
              <td
                class="text-right tabular-nums font-medium"
                :class="{ 'ti-suma': m.cantidad > 0 }"
              >
                {{ cantidad(m.cantidad) }}
              </td>
              <td class="text-right tabular-nums ti-suave">
                {{ m.saldo_despues }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <PaginacionListado
      v-if="meta && meta.total > 0"
      :page="meta.page"
      :ultima-pagina="meta.ultima_pagina"
      :total="meta.total"
      :per-page="meta.per_page"
      :opciones-por-pagina="[20, 50, 100]"
      @ir="irPagina"
      @por-pagina="cambiarPorPagina"
    />
  </section>
</template>

<style scoped>
.ti-suave {
  color: var(--texto-suave);
}
.ti-fila {
  display: grid;
  gap: 1rem;
}
@media (min-width: 1024px) {
  .ti-fila {
    grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);
  }
}
.ti-icono {
  color: var(--texto-suave);
  flex-shrink: 0;
}
.ti-suma {
  color: var(--exito-texto, var(--exito));
}
/* Uso por mes: una columna por mes; la barra es lo usado y el punto, si hubo compra. */
.ti-grafica {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}
.ti-columna {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  min-width: 0;
}
.ti-valor {
  min-height: 1rem;
  font-size: 0.7rem;
  color: var(--texto-suave);
}
.ti-pista {
  display: flex;
  align-items: flex-end;
  width: 100%;
  height: 7.5rem;
  border-bottom: 1px solid var(--borde);
}
.ti-barra {
  width: 100%;
  min-height: 3px;
  border-radius: 0.3rem 0.3rem 0 0;
  background: color-mix(in srgb, var(--primario) 70%, var(--superficie));
  transition: height 0.2s ease;
}
.ti-barra-vacia {
  background: var(--borde);
}
.ti-compra {
  display: inline-block;
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 999px;
  background: transparent;
}
.ti-compra-si {
  background: var(--exito);
}
.ti-mes {
  font-size: 0.7rem;
  color: var(--texto-suave);
  text-transform: capitalize;
  white-space: nowrap;
}
.ti-leyenda-barra {
  display: inline-block;
  width: 0.7rem;
  height: 0.5rem;
  border-radius: 0.15rem;
  background: color-mix(in srgb, var(--primario) 70%, var(--superficie));
}
@media (max-width: 480px) {
  .ti-grafica {
    gap: 0.15rem;
  }
  .ti-mes {
    font-size: 0.6rem;
  }
}
</style>
