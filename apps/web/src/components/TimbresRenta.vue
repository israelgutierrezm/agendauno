<script setup lang="ts">
import { onMounted, ref } from "vue";

import AvisoTono from "@/components/AvisoTono.vue";
import IconoNav from "@/components/IconoNav.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useToastStore } from "@/stores/toast";

/**
 * Timbres para facturar a los clientes (ADR 0107), en resumen: cuántos quedan, los
 * usados este mes y la compra de un paquete (en la página de Stripe). El uso por mes y
 * todos los movimientos viven en su pantalla (/renta/timbres). Solo se muestra a quien
 * puede facturar (México y, en citas, el plan Pro): si no, la API dice por qué.
 */
interface Timbres {
  disponibles: number;
  precio_timbre_minor: number;
  iva_porcentaje: number;
  moneda: string;
  paquetes: { cantidad: number; precio_minor: number }[];
  posible: boolean;
  motivo: string | null;
  resumen?: { usados_mes: number };
}

const props = withDefaults(
  defineProps<{ base: string; pagado?: boolean; puedeGestionar?: boolean }>(),
  { pagado: false, puedeGestionar: true },
);
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
    }>(`${props.base}/timbres/comprar`, {
      cantidad: paquete.value,
      // Vuelve a Timbres, con su aviso (no el de la renta).
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
      <p
        v-if="timbres.resumen"
        class="mt-1 text-sm tm-suave"
        data-prueba="timbres-usados-mes"
      >
        {{
          $t(
            "suscripcion.timbres.usadosMes",
            { n: timbres.resumen.usados_mes },
            timbres.resumen.usados_mes,
          )
        }}
      </p>
      <p class="mt-1 text-sm tm-suave">{{ $t("suscripcion.timbres.ayuda") }}</p>
      <AvisoTono
        v-if="timbres.posible && timbres.disponibles === 0"
        tono="aviso"
        class="mt-2"
      >
        {{ $t("suscripcion.timbres.agotados") }}
      </AvisoTono>
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
          v-if="puedeGestionar"
          type="button"
          class="tu-btn tu-btn-secundario"
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
      <RouterLink
        :to="{ name: 'timbres' }"
        class="tu-enlace mt-4 inline-flex items-center gap-1 text-sm"
        data-prueba="ver-timbres"
      >
        {{ $t("suscripcion.timbres.verDetalle") }}
        <IconoNav nombre="flecha" :tam="14" />
      </RouterLink>
    </template>
    <AvisoTono v-if="error" tono="peligro" class="mt-3" role="alert">
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-peligro tu-btn-chico"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
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
