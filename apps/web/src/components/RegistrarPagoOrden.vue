<script setup lang="ts">
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import ModalDialogo from "@/components/ModalDialogo.vue";
import { api, mensajeDeError } from "@/lib/api";

/**
 * Registrar en caja el pago de lo que se debe (una cita, una compra): quién, qué,
 * cuánto, con qué se pagó y una referencia opcional. El mismo recorrido desde «Por
 * cobrar» y desde la ficha: cita → cliente → importe → registrar pago.
 */
export interface OrdenPorCobrar {
  id: string;
  persona?: string | null;
  concepto: string | null;
  total_minor: number;
  moneda: string;
}

const props = defineProps<{ base: string; orden: OrdenPorCobrar | null }>();
const emit = defineEmits<{ cerrar: []; registrado: [aviso: string] }>();

const { t } = useI18n();
const METODOS = ["efectivo", "transferencia", "ventanilla"] as const;
const metodo = ref<(typeof METODOS)[number]>("efectivo");
const referencia = ref("");
const procesando = ref(false);
const error = ref<string | null>(null);

// Cada orden empieza en efectivo y sin referencia.
watch(
  () => props.orden?.id,
  () => {
    metodo.value = "efectivo";
    referencia.value = "";
    error.value = null;
  },
);

function dinero(minor: number, moneda: string): string {
  return new Intl.NumberFormat("es-MX", {
    style: "currency",
    currency: moneda,
  }).format(minor / 100);
}

async function registrar(): Promise<void> {
  const o = props.orden;
  if (!o) {
    return;
  }
  procesando.value = true;
  error.value = null;
  try {
    await api.post(`${props.base}/ordenes/${o.id}/liquidar`, {
      metodo: metodo.value,
      referencia: referencia.value.trim() || null,
    });
    emit(
      "registrado",
      t("cobranza.pendientes.registrado", {
        nombre: o.persona ?? "—",
        monto: dinero(o.total_minor, o.moneda),
      }),
    );
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    procesando.value = false;
  }
}
</script>

<template>
  <ModalDialogo
    :abierto="orden !== null"
    :titulo="$t('cobranza.pendientes.registrarTitulo')"
    tam="md"
    @cerrar="emit('cerrar')"
  >
    <form v-if="orden" class="space-y-4" @submit.prevent="registrar">
      <p class="text-sm">
        <span v-if="orden.persona" class="font-medium"
          >{{ orden.persona }} ·
        </span>
        {{ orden.concepto ?? "—" }}
      </p>
      <p class="text-2xl font-semibold tabular-nums">
        {{ dinero(orden.total_minor, orden.moneda) }}
      </p>
      <div>
        <p class="tu-label">{{ $t("ventas.vender.metodo") }}</p>
        <div class="tu-segmentado w-full" role="group">
          <button
            v-for="m in METODOS"
            :key="m"
            type="button"
            class="flex-1"
            :aria-pressed="metodo === m"
            @click="metodo = m"
          >
            {{ $t(`ventas.metodos.${m}`) }}
          </button>
        </div>
      </div>
      <div>
        <label class="tu-label" for="cobro-ref">{{
          $t("cobranza.pendientes.referencia")
        }}</label>
        <input
          id="cobro-ref"
          v-model="referencia"
          class="tu-input"
          maxlength="255"
        />
      </div>
      <p v-if="error" class="text-sm" style="color: var(--error)">
        {{ error }}
      </p>
      <div class="flex justify-end gap-2">
        <button
          type="button"
          class="tu-btn tu-btn-fantasma"
          @click="emit('cerrar')"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="procesando"
          data-prueba="confirmar-pago"
        >
          {{ $t("cobranza.pendientes.registrar") }}
        </button>
      </div>
    </form>
  </ModalDialogo>
</template>
