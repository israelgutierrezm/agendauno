<script setup lang="ts">
import { onMounted, ref } from "vue";

import { api, mensajeDeError } from "@/lib/api";
import { useToastStore } from "@/stores/toast";

/**
 * Timbres para facturar a los clientes (ADR 0107): cuántos quedan, sus movimientos y
 * la compra de un paquete (en la página de Stripe). Solo se muestra a quien puede
 * facturar (México y, en citas, el plan Pro): si no, la API dice por qué.
 */
interface Movimiento {
  id: string;
  tipo: "compra" | "consumo" | "ajuste";
  cantidad: number;
  saldo_despues: number;
  detalle: string | null;
  fecha: string | null;
}
interface Timbres {
  disponibles: number;
  precio_timbre_minor: number;
  iva_porcentaje: number;
  moneda: string;
  paquetes: { cantidad: number; precio_minor: number }[];
  posible: boolean;
  motivo: string | null;
  movimientos: Movimiento[];
}

const props = defineProps<{ base: string; pagado?: boolean }>();
const toast = useToastStore();

const timbres = ref<Timbres | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const paquete = ref<number | null>(null);
const comprando = ref(false);

function dinero(minor: number): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: timbres.value?.moneda ?? "MXN",
  }).format(minor / 100);
}
function fecha(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", { dateStyle: "medium" }).format(
    new Date(iso),
  );
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Timbres }>(`${props.base}/timbres`);
    timbres.value = data.data;
    paquete.value ??= data.data.paquetes[0]?.cantidad ?? null;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function comprar(): Promise<void> {
  if (paquete.value === null) {
    return;
  }
  comprando.value = true;
  try {
    const { data } = await api.post<{
      data: { checkout?: { url?: string } };
    }>(`${props.base}/timbres/comprar`, { cantidad: paquete.value });
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

onMounted(() => {
  void cargar();
  // Al volver de pagar, el aviso de Stripe llega en unos segundos.
  if (props.pagado) {
    window.setTimeout(() => void cargar(), 4000);
  }
});

defineExpose({ cargar });
</script>

<template>
  <div class="tu-card p-5" data-prueba="timbres">
    <h2 class="font-medium">{{ $t("suscripcion.timbres.titulo") }}</h2>
    <p v-if="cargando && !timbres" class="mt-2 text-sm tm-suave">
      {{ $t("comun.cargando") }}
    </p>
    <template v-else-if="timbres">
      <p class="mt-2 text-lg font-semibold" data-prueba="timbres-disponibles">
        {{
          $t(
            "suscripcion.timbres.disponibles",
            { n: timbres.disponibles },
            timbres.disponibles,
          )
        }}
      </p>
      <p class="mt-1 text-sm tm-suave">{{ $t("suscripcion.timbres.ayuda") }}</p>
      <p
        v-if="timbres.posible && timbres.disponibles === 0"
        class="mt-2 text-sm"
        style="color: var(--aviso)"
      >
        {{ $t("suscripcion.timbres.agotados") }}
      </p>
      <p
        v-if="!timbres.posible && timbres.motivo"
        class="mt-2 text-sm tm-suave"
      >
        {{ timbres.motivo }}
      </p>
      <div
        v-else-if="timbres.posible"
        class="mt-4 flex items-end gap-2 flex-wrap"
      >
        <label>
          <span class="sr-only">{{
            $t("suscripcion.timbres.paqueteEtiqueta")
          }}</span>
          <select
            v-model.number="paquete"
            class="tu-input"
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
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          :disabled="comprando || paquete === null"
          data-prueba="comprar-timbres"
          @click="comprar"
        >
          {{
            comprando
              ? $t("suscripcion.timbres.comprando")
              : $t("suscripcion.timbres.comprar")
          }}
        </button>
      </div>
      <details v-if="timbres.movimientos.length > 0" class="mt-4 text-sm">
        <summary class="tu-enlace cursor-pointer">
          {{ $t("suscripcion.timbres.movimientos") }}
        </summary>
        <ul class="mt-2 space-y-1">
          <li
            v-for="m in timbres.movimientos"
            :key="m.id"
            class="flex justify-between gap-3"
          >
            <span>
              {{ $t(`suscripcion.timbres.${m.tipo}`) }}
              <span v-if="m.fecha" class="tm-suave"
                >· {{ fecha(m.fecha) }}</span
              >
            </span>
            <span class="tm-numero">{{
              m.cantidad > 0 ? `+${m.cantidad}` : m.cantidad
            }}</span>
          </li>
        </ul>
      </details>
    </template>
    <p v-if="error" class="mt-3 text-sm" style="color: var(--error)">
      {{ error }}
      <button type="button" class="tu-enlace ml-2" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </p>
  </div>
</template>

<style scoped>
.tm-suave {
  color: var(--texto-suave);
}
.tm-numero {
  font-variant-numeric: tabular-nums;
}
</style>
