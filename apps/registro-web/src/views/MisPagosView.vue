<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import MovimientosCreditos from "@/components/MovimientosCreditos.vue";
import PagoAutomatico from "@/components/PagoAutomatico.vue";
import { api, mensajeDeError } from "@/lib/api";
import { dinero, useMiCuenta, type Voucher } from "@/lib/miCuenta";
import { useRetornoPago } from "@/lib/retornoPago";

/**
 * Pagos del portal: sus créditos y membresías (con sus movimientos), lo que tiene por
 * pagar (en línea o en tienda), comprar un paquete o membresía, el pago automático y
 * el historial de compras.
 */
const cuenta = useMiCuenta();
const retornoPago = useRetornoPago();
if (retornoPago.value === "exito") {
  // El webhook de la pasarela confirma el pago en segundos.
  window.setTimeout(() => void cuenta.cargar(true), 4000);
}

const verMovimientos = ref<string | null>(null);
const pagando = ref<string | null>(null);
const comprando = ref<string | null>(null);
const voucher = ref<Voucher | null>(null);
const mensaje = ref<string | null>(null);
const error = ref<string | null>(null);
const domiciliar = ref<Record<string, boolean>>({});

const historial = computed(() =>
  cuenta.ordenes.value.filter((o) => o.estado !== "pendiente"),
);

function creditos(u: number | null): string {
  return new Intl.NumberFormat("es-MX", { maximumFractionDigits: 1 }).format(
    (u ?? 0) / 1000,
  );
}
function fecha(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        day: "numeric",
        month: "short",
        year: "numeric",
      }).format(new Date(iso))
    : "";
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
  <section class="mx-auto max-w-5xl px-4 py-8">
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

    <template v-else>
      <div class="mt-5 grid gap-4 lg:grid-cols-2">
        <!-- Créditos y membresías -->
        <div class="tu-card p-5">
          <h2 class="font-semibold">{{ $t("portal.pagos.creditos") }}</h2>
          <ul
            v-if="cuenta.derechos.value.length > 0"
            class="mt-3 space-y-3 text-sm"
          >
            <li v-for="d in cuenta.derechos.value" :key="d.id">
              <div class="flex items-baseline justify-between gap-3">
                <span class="font-medium">{{ d.producto ?? "—" }}</span>
                <span v-if="d.ilimitado">{{ $t("miCuenta.ilimitado") }}</span>
                <span v-else class="text-lg font-semibold">{{
                  creditos(d.disponible)
                }}</span>
              </div>
              <p
                v-if="d.pausa_hasta"
                class="text-xs"
                :style="{ color: 'var(--aviso)' }"
              >
                {{
                  $t("pausaMembresia.enPausa", {
                    fecha: new Intl.DateTimeFormat("es-MX", {
                      day: "numeric",
                      month: "long",
                    }).format(new Date(`${d.pausa_hasta}T12:00:00`)),
                  })
                }}
              </p>
              <template v-if="!d.ilimitado">
                <button
                  type="button"
                  class="tu-enlace mt-1 text-xs"
                  :aria-expanded="verMovimientos === d.id"
                  @click="
                    verMovimientos = verMovimientos === d.id ? null : d.id
                  "
                >
                  {{
                    verMovimientos === d.id
                      ? $t("movimientosCredito.ocultar")
                      : $t("movimientosCredito.ver")
                  }}
                </button>
                <MovimientosCreditos
                  v-if="verMovimientos === d.id"
                  :url="`${cuenta.base.value}/mi/derechos/${d.id}/movimientos`"
                />
              </template>
            </li>
          </ul>
          <p
            v-else
            class="mt-3 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("miCuenta.sinCreditos") }}
          </p>
        </div>

        <!-- Por pagar -->
        <div class="tu-card p-5">
          <h2 class="font-semibold">{{ $t("portal.pagos.porPagar") }}</h2>
          <ul
            v-if="cuenta.porPagar.value.length > 0"
            class="mt-3 divide-y divide-[var(--borde)] text-sm"
          >
            <li v-for="o in cuenta.porPagar.value" :key="o.id" class="py-3">
              <div class="flex items-baseline justify-between gap-3">
                <span class="font-medium">{{
                  o.lineas
                    .map((l) => l.producto)
                    .filter(Boolean)
                    .join(", ") || "—"
                }}</span>
                <span class="font-semibold">{{
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

      <!-- Comprar -->
      <div v-if="cuenta.productos.value.length > 0" class="mt-4 tu-card p-5">
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
            v-for="p in cuenta.productos.value"
            :key="p.id"
            class="flex flex-col rounded-xl border p-4"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <span class="text-xs" :style="{ color: 'var(--texto-suave)' }">{{
              $t(`miCuenta.comprar.tipos.${p.tipo}`)
            }}</span>
            <p class="mt-1 font-semibold">{{ p.nombre }}</p>
            <p class="mt-1 text-xl font-semibold">
              {{ dinero(p.precio_minor, p.moneda) }}
            </p>
            <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
              <template v-if="p.ilimitado">{{
                $t("miCuenta.ilimitado")
              }}</template>
              <template v-else-if="p.creditos_incluidos">{{
                $t("miCuenta.comprar.creditos", {
                  n: p.creditos_incluidos / 1000,
                })
              }}</template>
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
      <PagoAutomatico v-if="cuenta.personaId.value !== null" class="mt-4" />

      <!-- Historial -->
      <div class="mt-4 tu-card p-5">
        <h2 class="font-semibold">{{ $t("portal.pagos.historial") }}</h2>
        <ul
          v-if="historial.length > 0"
          class="mt-3 divide-y divide-[var(--borde)] text-sm"
        >
          <li
            v-for="o in historial"
            :key="o.id"
            class="flex items-center justify-between gap-3 py-2.5"
          >
            <span class="min-w-0">
              <span class="block truncate font-medium">{{
                o.lineas
                  .map((l) => l.producto)
                  .filter(Boolean)
                  .join(", ") || "—"
              }}</span>
              <span class="text-xs" :style="{ color: 'var(--texto-suave)' }"
                >{{ fecha(o.fecha) }} ·
                {{ $t(`miCuenta.compras.estados.${o.estado}`) }}</span
              >
            </span>
            <span class="shrink-0 font-semibold">{{
              dinero(o.total_minor, o.moneda)
            }}</span>
          </li>
        </ul>
        <p v-else class="mt-3 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("portal.pagos.sinHistorial") }}
        </p>
      </div>
    </template>
  </section>
</template>
