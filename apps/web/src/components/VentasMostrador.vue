<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import CorregirCobro from "@/components/CorregirCobro.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
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
  creado_en: string | null;
  lineas?: { articulo: string | null; cantidad: number }[];
  // Venta registrada por error: corregir su forma de pago o anularla (ADR 0089).
  anulada_en?: string | null;
  motivo_anulacion?: string | null;
  corregible?: boolean;
  anulable?: boolean;
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
const corrigiendo = ref<string | null>(null);

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
      <h2 :class="personaId ? 'text-sm font-semibold' : 'font-medium'">
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
          class="tu-btn tu-btn-fantasma text-sm"
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
      <div v-else class="relative overflow-x-auto">
        <table class="mo-tabla">
          <thead>
            <tr>
              <th>{{ $t("mostradorVisual.col.cuando") }}</th>
              <th v-if="!personaId">
                {{ $t("mostradorVisual.col.ventaA") }}
              </th>
              <th>{{ $t("mostradorVisual.col.productos") }}</th>
              <th v-if="mostrarSucursal" class="hidden md:table-cell">
                {{ $t("pos.ventas.colSucursal") }}
              </th>
              <th class="hidden sm:table-cell">
                {{ $t("pos.ventas.colMetodo") }}
              </th>
              <th class="text-right">{{ $t("pos.ventas.colTotal") }}</th>
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
                <td class="whitespace-nowrap mo-suave">
                  {{ cuando(v.creado_en) }}
                </td>
                <td v-if="!personaId" data-prueba="venta-comprador">
                  <template v-if="v.comprador">
                    <span class="block font-medium">{{
                      v.comprador.nombre
                    }}</span>
                    <span class="block text-xs mo-suave">{{
                      tipoComprador(v.comprador)
                    }}</span>
                  </template>
                  <span v-else class="mo-suave">{{
                    $t("mostradorVisual.comprador.publico")
                  }}</span>
                  <span v-if="v.ticket_enviado_en" class="mt-1 block text-xs">
                    <span
                      class="tu-estado"
                      :style="{ '--tono': 'var(--exito)' }"
                      data-prueba="ticket-enviado"
                      >{{ $t("mostradorVisual.ventas.ticketEnviado") }}</span
                    >
                  </span>
                </td>
                <td>
                  {{
                    (v.lineas ?? [])
                      .map((l) => `${l.cantidad} × ${l.articulo ?? ""}`)
                      .join(", ") || "—"
                  }}
                </td>
                <td v-if="mostrarSucursal" class="hidden md:table-cell">
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
                  <template v-else>{{ nombreMetodo(v.metodo_pago) }}</template>
                </td>
                <td
                  class="text-right font-medium tabular-nums"
                  :class="{ 'line-through mo-suave': v.anulada_en }"
                >
                  {{ dinero(v.total_minor, v.moneda) }}
                  <span
                    v-if="(v.propina_minor ?? 0) > 0"
                    class="block text-xs font-normal mo-suave"
                    >{{
                      $t("mostradorVisual.conPropina", {
                        monto: dinero(v.propina_minor ?? 0, v.moneda),
                      })
                    }}</span
                  >
                </td>
                <td v-if="corregir" class="text-right">
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
              <tr v-if="corregir && corrigiendo === v.id" class="mo-correccion">
                <td :colspan="columnas">
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
