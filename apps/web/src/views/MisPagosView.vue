<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import CortePlanes from "@/components/CortePlanes.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import HistorialCompras from "@/components/HistorialCompras.vue";
import PagoAutomatico from "@/components/PagoAutomatico.vue";
import { api, mensajeDeError } from "@/lib/api";
import {
  cuandoCorto,
  dinero,
  useMiCuenta,
  type Orden,
  type Voucher,
} from "@/lib/miCuenta";
import { useRetornoPago } from "@/lib/retornoPago";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Pagos del portal, según cómo trabaja el negocio (ADR 0091):
 * - Clases: su plan vigente (compacto; los anteriores plegados), lo que tiene por
 *   pagar, comprar o renovar, el pago automático y el historial.
 * - Citas: lo que tiene por pagar (servicio, con quién, cuándo) y el historial de
 *   cobros; sus bonos y comprarlos, solo si los tiene o el negocio los vende.
 * Lo que debe llega completo aparte (no de la primera página del historial).
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const cuenta = useMiCuenta();
const retornoPago = useRetornoPago();
const corte = ref<InstanceType<typeof CortePlanes> | null>(null);
const historial = ref<InstanceType<typeof HistorialCompras> | null>(null);
if (retornoPago.value === "exito") {
  // El webhook de la pasarela confirma el pago en segundos.
  window.setTimeout(() => {
    void cuenta.cargar(true);
    void corte.value?.cargar();
    void historial.value?.cargar();
  }, 4000);
}

const citas = computed(() => sesion.esCitas === true);
// En citas, «Mis planes» solo si tiene alguno (bono o membresía).
const mostrarPlanes = computed(
  () => !citas.value || cuenta.derechos.value.length > 0,
);
// Segunda línea de lo que debe: la cita (con quién, cuándo y dónde).
function detalleCita(o: Orden): string | null {
  if (!o.sesion) {
    return null;
  }
  return [
    o.sesion.profesional
      ? t("portal.pagos.con", { nombre: o.sesion.profesional })
      : null,
    cuandoCorto(o.sesion.inicia_en, o.sesion.zona_horaria),
    o.sesion.sucursal,
  ]
    .filter(Boolean)
    .join(" · ");
}

const pagando = ref<string | null>(null);
const comprando = ref<string | null>(null);
const voucher = ref<Voucher | null>(null);
const mensaje = ref<string | null>(null);
const error = ref<string | null>(null);
const domiciliar = ref<Record<string, boolean>>({});

// Las clases extra se suman a un paquete: solo se ofrecen a quien tiene uno.
const tienePaquete = computed(() =>
  cuenta.derechos.value.some((d) => !d.ilimitado),
);
const comprables = computed(() =>
  cuenta.productos.value.filter(
    (p) => p.tipo !== "add_on" || tienePaquete.value,
  ),
);
function vigencia(p: {
  tipo: string;
  vigencia_tipo?: string | null;
  vigencia_cantidad?: number | null;
}): string | null {
  if (p.tipo === "add_on") {
    return t("planes.resumen.conElPaquete");
  }
  const n = p.vigencia_cantidad ?? 0;
  switch (p.vigencia_tipo) {
    case "dias":
      return t("planes.resumen.dias", { n }, n);
    case "meses":
      return t("planes.resumen.meses", { n }, n);
    case "fin_de_mes":
      return t("planes.resumen.finDeMes", { n }, n);
    default:
      return null;
  }
}
// Con pasarela de redirección se va al checkout; con pago en tienda se muestra la
// referencia; si no, el pago queda en proceso.
async function pagar(
  ordenId: string,
  metodo: "tarjeta" | "oxxo" = "tarjeta",
): Promise<void> {
  pagando.value = ordenId;
  error.value = null;
  mensaje.value = null;
  voucher.value = null;
  try {
    const { data } = await api.post<{
      data: { checkout?: ({ tipo?: string; url?: string } & Voucher) | null };
    }>(`${cuenta.base.value}/mi/ordenes/${ordenId}/cobrar`, {
      metodo,
      domiciliar: metodo === "tarjeta" && domiciliar.value[ordenId] === true,
    });
    const checkout = data.data.checkout ?? {};
    if (checkout.tipo === "voucher") {
      voucher.value = checkout;
      return;
    }
    if (checkout.tipo === "redirect" && checkout.url) {
      window.location.href = checkout.url;
      return;
    }
    mensaje.value = "pago";
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    pagando.value = null;
  }
}

async function comprar(id: string): Promise<void> {
  const p = cuenta.productos.value.find((x) => x.id === id);
  if (!p) {
    return;
  }
  comprando.value = id;
  mensaje.value = null;
  if (await cuenta.comprar(p)) {
    mensaje.value = "comprado";
  }
  comprando.value = null;
}

onMounted(() => void cuenta.asegurar());
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion :titulo="$t('portal.pagos.titulo')" />

    <p
      v-if="error || cuenta.error.value"
      class="mt-3 text-sm"
      style="color: var(--error)"
    >
      {{ error ?? cuenta.error.value }}
    </p>
    <p
      v-if="retornoPago"
      class="mt-3 text-sm"
      role="status"
      :style="{
        color: retornoPago === 'exito' ? 'var(--exito)' : 'var(--aviso)',
      }"
    >
      {{ $t(`pagoEnLinea.${retornoPago}`) }}
    </p>
    <p
      v-if="mensaje === 'pago'"
      class="mt-3 text-sm"
      role="status"
      :style="{ color: 'var(--exito)' }"
    >
      {{ $t("miCuentaExtra.pagoEnProceso") }}
    </p>

    <!-- Referencia para pagar en tienda -->
    <div v-if="voucher" class="mt-4 tu-card p-5 text-sm" role="status">
      <p class="font-medium">{{ $t("pagoTienda.titulo") }}</p>
      <p class="mt-1">
        {{ $t("pagoTienda.referencia", { referencia: voucher.referencia }) }}
      </p>
      <img
        v-if="voucher.codigo_barras"
        :src="voucher.codigo_barras"
        alt=""
        class="mt-2 h-12 max-w-full"
      />
      <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("pagoTienda.ayuda") }}
      </p>
      <a
        v-if="voucher.recibo"
        :href="voucher.recibo"
        target="_blank"
        rel="noopener"
        class="tu-enlace mt-2 inline-block"
        >{{ $t("pagoTienda.recibo") }}</a
      >
    </div>

    <p
      v-if="cuenta.cargando.value"
      class="mt-6"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>

    <div v-else class="mp-pagos">
      <!-- Clases: su plan vigente primero. En citas, sus bonos van después. -->
      <CortePlanes
        v-if="mostrarPlanes"
        ref="corte"
        :class="citas ? 'order-3' : 'order-1'"
        :url="`${cuenta.base.value}/mi/planes`"
      />

      <div class="order-2">
        <!-- Por pagar -->
        <div class="tu-card p-5" data-prueba="por-pagar">
          <h2 class="font-semibold">{{ $t("portal.pagos.porPagar") }}</h2>
          <ul
            v-if="cuenta.porPagar.value.length > 0"
            class="mt-3 divide-y divide-[var(--borde)] text-sm"
          >
            <li v-for="o in cuenta.porPagar.value" :key="o.id" class="py-3">
              <div class="flex items-baseline justify-between gap-3">
                <span class="min-w-0">
                  <span class="block font-medium">{{ o.concepto || "—" }}</span>
                  <span
                    v-if="detalleCita(o)"
                    class="block text-sm first-letter:uppercase"
                    :style="{ color: 'var(--texto-suave)' }"
                    >{{ detalleCita(o) }}</span
                  >
                </span>
                <span class="shrink-0 font-semibold tabular-nums">{{
                  dinero(o.total_minor, o.moneda)
                }}</span>
              </div>
              <div
                v-if="cuenta.pagoEnLinea.value"
                class="mt-2 flex flex-wrap items-center gap-3"
              >
                <button
                  class="tu-btn tu-btn-primario text-sm"
                  :disabled="pagando !== null"
                  @click="pagar(o.id)"
                >
                  {{
                    pagando === o.id
                      ? $t("miCuentaExtra.pagando")
                      : $t("miCuentaExtra.pagar")
                  }}
                </button>
                <button
                  type="button"
                  class="tu-enlace text-xs"
                  :disabled="pagando !== null"
                  @click="pagar(o.id, 'oxxo')"
                >
                  {{ $t("pagoTienda.pagarOxxo") }}
                </button>
                <label
                  v-if="cuenta.pagoAutomatico.value && o.recurrente"
                  class="flex items-center gap-1.5 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  <input v-model="domiciliar[o.id]" type="checkbox" />
                  {{ $t("pagoAutomatico.alPagar") }}
                </label>
              </div>
            </li>
          </ul>
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("portal.inicio.tarjetas.alCorriente") }}
          </p>
          <p class="mt-3 text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("miCuenta.compras.nota") }}
          </p>
        </div>
      </div>

      <!-- Comprar o renovar -->
      <div v-if="comprables.length > 0" class="order-4 tu-card p-5">
        <h2 class="font-semibold">{{ $t("miCuenta.comprar.titulo") }}</h2>
        <p
          v-if="mensaje === 'comprado'"
          class="mt-2 text-sm"
          :style="{ color: 'var(--exito)' }"
        >
          {{ $t("miCuenta.comprar.creada") }}
        </p>
        <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <li
            v-for="p in comprables"
            :key="p.id"
            class="flex flex-col rounded-xl border p-4"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <span class="text-xs" :style="{ color: 'var(--texto-suave)' }">{{
              $t(`miCuenta.comprar.tipos.${p.tipo}`)
            }}</span>
            <p class="mt-1 font-semibold">{{ p.nombre }}</p>
            <p
              v-if="p.todas_sucursales !== undefined"
              class="mt-1 text-xs"
              style="color: var(--texto-suave)"
            >
              {{
                p.todas_sucursales
                  ? $t("sucursalOperativa.todas")
                  : p.sucursales?.map((s) => s.nombre).join(" · ")
              }}
            </p>
            <p class="mt-1 text-xl font-semibold">
              {{ dinero(p.precio_minor, p.moneda) }}
            </p>
            <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
              <template v-if="p.ilimitado">{{
                $t("miCuenta.ilimitado")
              }}</template>
              <template v-else-if="p.creditos_incluidos">{{
                $t(
                  "miCuenta.comprar.creditos",
                  { n: p.creditos_incluidos / 1000 },
                  p.creditos_incluidos === 1000 ? 1 : 2,
                )
              }}</template>
            </p>
            <p
              v-if="vigencia(p)"
              class="text-xs"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ vigencia(p) }}
            </p>
            <button
              class="tu-btn tu-btn-primario mt-auto w-full"
              :class="{ 'mt-3': true }"
              :disabled="comprando !== null"
              @click="comprar(p.id)"
            >
              {{
                comprando === p.id
                  ? $t("miCuenta.comprar.comprando")
                  : $t("miCuenta.comprar.comprar")
              }}
            </button>
          </li>
        </ul>
      </div>

      <!-- Pago automático -->
      <PagoAutomatico v-if="cuenta.personaId.value !== null" class="order-5" />

      <!-- Historial: en citas, justo después de lo que debe -->
      <HistorialCompras
        ref="historial"
        :class="citas ? 'order-2' : 'order-6'"
      />
    </div>
  </section>
</template>

<style scoped>
/* Las tarjetas de Pagos en columna; su orden cambia con la modalidad. */
.mp-pagos {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  margin-top: 1.25rem;
}
</style>
