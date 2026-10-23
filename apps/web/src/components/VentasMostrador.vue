<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import CorregirCobro from "@/components/CorregirCobro.vue";
import DevolverVenta, {
  type LineaDevolvible,
} from "@/components/DevolverVenta.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
import { fechaEnUrl, useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
import {
  puedeFacturarVentas,
  rutaFacturaDeVenta,
  rutaFacturarVenta,
} from "@/lib/facturarVenta";
import { dinero as dineroDelPais } from "@/lib/formato";
import { hoyEnNegocio } from "@/lib/hoyNegocio";
import { terminoComprador } from "@/lib/mostrador";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Las ventas del mostrador (ADR 0145), de la más reciente a la más antigua, por
 * páginas y con filtro de fechas (días del negocio). Cada una dice a quién se le vendió
 * («Público en general» si a nadie) y si se le mandó su ticket.
 *
 * En el mostrador se corrige su forma de pago o se anula (ADR 0089, `corregir`); en la
 * ficha de una persona se ven solo las suyas (`personaId`).
 */
export interface ResumenVentas {
  ventas: number;
  vendido_minor: number;
  propinas_minor: number;
}
interface Comprador {
  id: string;
  nombre: string;
  tipo: string;
  genero?: string | null;
}
interface Venta {
  id: string;
  folio?: string;
  sucursal: string | null;
  comprador?: Comprador | null;
  ticket_enviado_en?: string | null;
  total_minor: number;
  propina_minor?: number;
  moneda: string;
  metodo_pago: string;
  // Con varias formas (ADR 0177): cuánto con cada una.
  partes?: { metodo: string; monto_minor: number }[];
  // Por qué formas entró lo vendido (sin servicio ni propina): de ahí sale una devolución.
  partes_devolucion?: { metodo: string; monto_minor: number }[];
  creado_en: string | null;
  lineas?: LineaDevolvible[];
  // Devoluciones parciales (ADR 0178): cuánto salió y si aún se puede devolver.
  devuelto_minor?: number;
  devolvible?: boolean;
  // Venta registrada por error: corregir su forma de pago o anularla (ADR 0089).
  anulada_en?: string | null;
  motivo_anulacion?: string | null;
  corregible?: boolean;
  anulable?: boolean;
  // Su factura, o si ya se puede facturar (ADR 0179).
  factura?: { id: string; uuid: string | null } | null;
  facturable?: boolean;
}
interface Meta {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
  resumen?: ResumenVentas;
  hoy?: ResumenVentas;
}

const METODOS = ["efectivo", "tarjeta", "transferencia"] as const;

const props = withDefaults(
  defineProps<{
    // La sucursal fija (la de la barra o la única); sin ella, todas las que ve.
    sucursalId?: string | null;
    // La columna de sucursal, cuando se ven las de varias.
    mostrarSucursal?: boolean;
    // Solo las de esta persona (su ficha); ahí no hace falta decir a quién.
    personaId?: string | null;
    corregir?: boolean;
    titulo?: string;
    porPagina?: number;
  }>(),
  {
    sucursalId: null,
    mostrarSucursal: false,
    personaId: null,
    corregir: false,
    titulo: undefined,
    porPagina: 20,
  },
);
// Lo vendido hoy (sin anuladas), para los indicadores de quien lo usa.
const emit = defineEmits<{ hoy: [resumen: ResumenVentas] }>();

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const ventas = ref<Venta[]>([]);
const meta = ref<Meta | null>(null);
const pagina = ref(1);
const cargando = ref(true);
const error = ref<string | null>(null);
const desde = ref("");
const hasta = ref("");
const filtrando = computed(() => desde.value !== "" || hasta.value !== "");
// Las fechas en la dirección (recargar o volver atrás las conserva), con claves
// propias para no chocar con los filtros de la pantalla que lo muestra.
useFiltrosEnUrl({
  ventas_desde: fechaEnUrl(desde),
  ventas_hasta: fechaEnUrl(hasta),
});
const corrigiendo = ref<string | null>(null);
// La venta de la que se devuelven productos (ADR 0178).
const devolviendo = ref<string | null>(null);
const puedeDevolver = computed(() => sesion.puede("pagos.reembolsar"));
const puedeFacturar = computed(() => puedeFacturarVentas(sesion));
async function alDevolver(): Promise<void> {
  devolviendo.value = null;
  await cargar();
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Venta[]; meta: Meta }>(
      `${base.value}/pos/ventas`,
      {
        params: {
          page: pagina.value,
          per_page: props.porPagina,
          ...(desde.value !== "" ? { desde: desde.value } : {}),
          ...(hasta.value !== "" ? { hasta: hasta.value } : {}),
          ...(props.sucursalId ? { sucursal_id: props.sucursalId } : {}),
          ...(props.personaId ? { persona_id: props.personaId } : {}),
        },
      },
    );
    ventas.value = data?.data ?? [];
    meta.value = data?.meta ?? null;
    if (data?.meta?.hoy) {
      emit("hoy", data.meta.hoy);
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}
function ir(n: number): void {
  pagina.value = n;
  void cargar();
}
function quitarFiltro(): void {
  desde.value = "";
  hasta.value = "";
}
// Otras fechas u otra sucursal vuelven a la primera página.
watch([desde, hasta, () => props.sucursalId, () => props.personaId], () => {
  pagina.value = 1;
  void cargar();
});
async function alCorregir(): Promise<void> {
  corrigiendo.value = null;
  await cargar();
}

// En la moneda del negocio (ADR 0099) y con los números de su país.
function dinero(minor: number, moneda = sesion.moneda): string {
  return dineroDelPais(minor, moneda, sesion.pais);
}
function nombreMetodo(m: string): string {
  return te(`pos.metodos.${m}`) ? t(`pos.metodos.${m}`) : m;
}
// Hoy, solo la hora; otro día, también la fecha. En la zona del negocio.
function cuando(iso: string | null): string {
  if (!iso) {
    return "—";
  }
  const d = new Date(iso);
  const zona = sesion.zonaHoraria;
  const deHoy = hoyEnNegocio(zona, d) === hoyEnNegocio(zona);
  return new Intl.DateTimeFormat("es-MX", {
    ...(deHoy ? {} : { day: "numeric", month: "short" }),
    hour: "numeric",
    minute: "2-digit",
    timeZone: zona,
  }).format(d);
}
function tipoComprador(c: Comprador): string {
  return terminoComprador(c.tipo, c.genero, t, te);
}
const columnas = computed(
  () =>
    4 +
    (props.personaId ? 0 : 1) +
    (props.mostrarSucursal ? 1 : 0) +
    (props.corregir ? 1 : 0),
);

defineExpose({ cargar });
onMounted(cargar);
</script>

<template>
  <section data-prueba="ventas-mostrador">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
      <!-- En la ficha es un apartado más; en el mostrador, una sección de la página. -->
      <h2 :class="personaId ? 'text-sm font-semibold' : 'tu-titulo-seccion'">
        {{ titulo ?? $t("pos.ventas.titulo") }}
      </h2>
      <p
        v-if="meta?.resumen && meta.total > 0"
        class="text-sm mo-suave"
        data-prueba="ventas-resumen"
      >
        {{
          $t(
            "mostradorVisual.ventas.resumen",
            {
              n: meta.resumen.ventas,
              total: dinero(meta.resumen.vendido_minor),
            },
            meta.resumen.ventas,
          )
        }}
      </p>
    </div>
    <div class="tu-card mt-3 overflow-hidden">
      <div class="tu-filtros">
        <label class="flex items-center gap-2 text-sm">
          <span class="mo-suave">{{ $t("mostradorVisual.ventas.desde") }}</span>
          <input
            v-model="desde"
            class="tu-input"
            type="date"
            :max="hasta || undefined"
            data-prueba="ventas-desde"
          />
        </label>
        <label class="flex items-center gap-2 text-sm">
          <span class="mo-suave">{{ $t("mostradorVisual.ventas.hasta") }}</span>
          <input
            v-model="hasta"
            class="tu-input"
            type="date"
            :min="desde || undefined"
            data-prueba="ventas-hasta"
          />
        </label>
        <button
          v-if="filtrando"
          type="button"
          class="tu-btn tu-btn-discreto"
          data-prueba="ventas-quitar-filtro"
          @click="quitarFiltro"
        >
          {{ $t("mostradorVisual.ventas.quitarFiltro") }}
        </button>
      </div>

      <AvisoTono v-if="error" tono="peligro" class="p-4" role="alert">
        {{ error }}
      </AvisoTono>
      <p
        v-else-if="cargando && ventas.length === 0"
        class="p-4 text-sm mo-suave"
      >
        {{ $t("comun.cargando") }}
      </p>
      <EstadoVacio
        v-else-if="ventas.length === 0"
        class="py-6"
        icono="ventas"
        compacto
        :titulo="
          filtrando
            ? $t('mostradorVisual.ventas.sinEnFechas')
            : $t('pos.ventas.vacio')
        "
      />
      <!-- En el teléfono, una ficha por venta: a quién (o cuándo, en la ficha de la
           persona), cuánto y sus acciones a la vista. -->
      <div v-else class="relative overflow-x-auto tu-tabla-adaptable">
        <table class="tu-tabla">
          <thead>
            <tr>
              <th v-if="!personaId">
                {{ $t("mostradorVisual.col.ventaA") }}
              </th>
              <th v-else>{{ $t("mostradorVisual.col.cuando") }}</th>
              <th class="text-right">{{ $t("pos.ventas.colTotal") }}</th>
              <th v-if="!personaId">{{ $t("mostradorVisual.col.cuando") }}</th>
              <th>{{ $t("mostradorVisual.col.productos") }}</th>
              <th v-if="mostrarSucursal" class="hidden md:table-cell">
                {{ $t("pos.ventas.colSucursal") }}
              </th>
              <th class="hidden sm:table-cell">
                {{ $t("pos.ventas.colMetodo") }}
              </th>
              <th v-if="corregir">
                <span class="sr-only">{{
                  $t("mostradorVisual.col.acciones")
                }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <template v-for="v in ventas" :key="v.id">
              <tr data-prueba="venta">
                <td
                  v-if="!personaId"
                  :data-etiqueta="$t('mostradorVisual.col.ventaA')"
                  data-prueba="venta-comprador"
                >
                  <template v-if="v.comprador">
                    <span class="block font-medium">{{
                      v.comprador.nombre
                    }}</span>
                    <span class="tu-sub font-normal">{{
                      tipoComprador(v.comprador)
                    }}</span>
                  </template>
                  <span v-else class="mo-suave">{{
                    $t("mostradorVisual.comprador.publico")
                  }}</span>
                  <span
                    v-if="v.ticket_enviado_en"
                    class="mt-1 block text-xs font-normal"
                  >
                    <span
                      class="tu-estado"
                      :style="{ '--tono': 'var(--exito)' }"
                      data-prueba="ticket-enviado"
                      >{{ $t("mostradorVisual.ventas.ticketEnviado") }}</span
                    >
                  </span>
                </td>
                <td
                  v-else
                  :data-etiqueta="$t('mostradorVisual.col.cuando')"
                  class="whitespace-nowrap"
                >
                  {{ cuando(v.creado_en) }}
                </td>
                <td
                  :data-etiqueta="$t('pos.ventas.colTotal')"
                  class="text-right tabular-nums"
                >
                  <div>
                    <span
                      class="font-medium"
                      :class="{ 'line-through mo-suave': v.anulada_en }"
                      >{{ dinero(v.total_minor, v.moneda) }}</span
                    >
                    <!-- Anulada: a la vista también en el teléfono (ADR 0089). -->
                    <span v-if="v.anulada_en" class="block text-xs">
                      <span
                        class="tu-estado"
                        :style="{ '--tono': 'var(--error)' }"
                        :title="v.motivo_anulacion ?? undefined"
                        data-prueba="venta-anulada"
                        >{{ $t("corregirCobro.anulada") }}</span
                      >
                    </span>
                    <span
                      v-if="(v.propina_minor ?? 0) > 0"
                      class="block text-xs mo-suave"
                      >{{
                        $t("mostradorVisual.conPropina", {
                          monto: dinero(v.propina_minor ?? 0, v.moneda),
                        })
                      }}</span
                    >
                    <span
                      v-if="(v.devuelto_minor ?? 0) > 0"
                      class="block text-xs mo-suave"
                      data-prueba="devuelto-venta"
                      >{{
                        $t("devolverVenta.devuelto", {
                          monto: dinero(v.devuelto_minor ?? 0, v.moneda),
                        })
                      }}</span
                    >
                  </div>
                </td>
                <td
                  v-if="!personaId"
                  :data-etiqueta="$t('mostradorVisual.col.cuando')"
                  class="whitespace-nowrap mo-suave"
                >
                  {{ cuando(v.creado_en) }}
                </td>
                <td :data-etiqueta="$t('mostradorVisual.col.productos')">
                  {{
                    (v.lineas ?? [])
                      .map((l) => `${l.cantidad} × ${l.articulo ?? ""}`)
                      .join(", ") || "—"
                  }}
                </td>
                <td
                  v-if="mostrarSucursal"
                  :data-etiqueta="$t('pos.ventas.colSucursal')"
                  class="hidden md:table-cell"
                >
                  {{ v.sucursal ?? "—" }}
                </td>
                <td
                  :data-etiqueta="$t('pos.ventas.colMetodo')"
                  class="hidden sm:table-cell"
                >
                  <div>
                    {{ nombreMetodo(v.metodo_pago) }}
                    <span
                      v-if="v.partes && v.partes.length > 1"
                      class="block text-xs mo-suave"
                      data-prueba="partes-venta"
                      >{{
                        v.partes
                          .map((p) =>
                            $t("formasDePago.detalle", {
                              forma: nombreMetodo(p.metodo),
                              monto: dinero(p.monto_minor, v.moneda),
                            }),
                          )
                          .join(" · ")
                      }}</span
                    >
                  </div>
                </td>
                <td v-if="corregir" class="tu-celda-acciones">
                  <span
                    class="inline-flex flex-wrap items-center justify-end gap-2"
                  >
                    <RouterLink
                      v-if="v.factura"
                      class="tu-enlace text-sm"
                      :to="rutaFacturaDeVenta(v.factura.uuid)"
                      data-prueba="venta-facturada"
                      >{{ $t("facturarVenta.facturada") }}</RouterLink
                    >
                    <RouterLink
                      v-else-if="puedeFacturar && v.facturable"
                      class="tu-enlace text-sm"
                      :to="rutaFacturarVenta({ venta: v.id })"
                      data-prueba="venta-facturar"
                      >{{ $t("facturarVenta.facturar") }}</RouterLink
                    >
                    <!-- Corregir la forma de pago o anular la venta (ADR 0089). -->
                    <button
                      v-if="
                        !v.anulada_en &&
                        ((v.corregible && sesion.puede('pos.vender')) ||
                          (v.anulable && puedeDevolver))
                      "
                      class="tu-btn tu-btn-secundario tu-btn-chico"
                      type="button"
                      data-prueba="corregir-venta"
                      :aria-expanded="corrigiendo === v.id"
                      @click="
                        corrigiendo = corrigiendo === v.id ? null : v.id;
                        devolviendo = null;
                      "
                    >
                      {{ $t("corregirCobro.corregir") }}
                    </button>
                    <!-- Devolver productos: sale dinero (ADR 0178). -->
                    <button
                      v-if="v.devolvible && puedeDevolver"
                      class="tu-btn tu-btn-peligro tu-btn-chico"
                      type="button"
                      data-prueba="devolver-venta-abrir"
                      :aria-expanded="devolviendo === v.id"
                      @click="
                        devolviendo = devolviendo === v.id ? null : v.id;
                        corrigiendo = null;
                      "
                    >
                      {{ $t("devolverVenta.accion") }}
                    </button>
                  </span>
                </td>
              </tr>
              <tr v-if="corregir && devolviendo === v.id && v.lineas">
                <td :colspan="columnas" class="tu-celda-detalle">
                  <DevolverVenta
                    :base="base"
                    :venta="{
                      id: v.id,
                      moneda: v.moneda,
                      lineas: v.lineas,
                      // De lo vendido, no del servicio de una cita (ADR 0178).
                      partes: v.partes_devolucion ?? v.partes,
                    }"
                    @devuelto="alDevolver"
                    @cerrar="devolviendo = null"
                  />
                </td>
              </tr>
              <tr v-if="corregir && corrigiendo === v.id">
                <td :colspan="columnas" class="tu-celda-detalle">
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
                    @cambiado="alCorregir"
                  />
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <PaginacionListado
        v-if="meta && meta.ultima_pagina > 1"
        :page="meta.page"
        :ultima-pagina="meta.ultima_pagina"
        :total="meta.total"
        :per-page="meta.per_page"
        @ir="ir"
      />
    </div>
  </section>
</template>

<style scoped>
.mo-suave {
  color: var(--texto-suave);
}
</style>
