<script setup lang="ts">
import { ref, watch } from "vue";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import { api, mensajeDeError } from "@/lib/api";

/**
 * Cancelar un CFDI (ADR 0180): con errores y sin sustituta (02) o porque la venta no
 * se llevó a cabo (03). Si hay datos mal y se va a volver a facturar, se sustituye
 * (la cancelación 01 se pide sola al timbrar la sustituta).
 */
export interface FacturaACancelar {
  id: string;
  uuid: string | null;
  receptor: { nombre: string };
  total: string;
}

const props = defineProps<{ base: string; factura: FacturaACancelar | null }>();
const emit = defineEmits<{
  cerrar: [];
  cancelada: [factura: { estado: string }];
}>();

const motivo = ref<"02" | "03">("02");
const pidiendo = ref(false);
const error = ref<string | null>(null);
watch(
  () => props.factura?.id,
  () => {
    motivo.value = "02";
    error.value = null;
  },
);

function folio(uuid: string | null): string {
  return (uuid ?? "").slice(0, 8).toUpperCase();
}

async function pedir(): Promise<void> {
  if (!props.factura) {
    return;
  }
  pidiendo.value = true;
  error.value = null;
  try {
    const { data } = await api.post<{ data: { estado: string } }>(
      `${props.base}/facturas/${props.factura.id}/cancelar`,
      { motivo: motivo.value },
    );
    emit("cancelada", data.data);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    pidiendo.value = false;
  }
}
</script>

<template>
  <ModalDialogo
    :abierto="factura !== null"
    :titulo="$t('cancelarFactura.titulo')"
    :ocupado="pidiendo"
    @cerrar="emit('cerrar')"
  >
    <template v-if="factura">
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{
          $t("cancelarFactura.deQuien", {
            receptor: factura.receptor.nombre,
            total: factura.total,
            folio: folio(factura.uuid),
          })
        }}
      </p>
      <form
        id="form-cancelar-factura"
        class="mt-4 space-y-4"
        @submit.prevent="pedir"
      >
        <fieldset>
          <legend class="tu-label">{{ $t("cancelarFactura.motivo") }}</legend>
          <label
            v-for="m in ['02', '03'] as const"
            :key="m"
            class="mt-2 flex items-start gap-2 text-sm"
          >
            <input
              v-model="motivo"
              type="radio"
              name="motivo-cancelacion"
              :value="m"
              class="mt-1"
              :data-prueba="`motivo-${m}`"
            />
            <span>
              <span class="font-medium">{{
                $t(`cancelarFactura.motivo${m}`)
              }}</span>
              <span class="tu-hint block">{{
                $t(`cancelarFactura.motivo${m}Ayuda`)
              }}</span>
            </span>
          </label>
        </fieldset>
        <p class="text-sm">
          {{ $t("cancelarFactura.sustituirPregunta") }}
          <RouterLink
            class="tu-enlace"
            :to="{ name: 'facturas', query: { sustituye: factura.id } }"
            data-prueba="sustituir-factura"
            @click="emit('cerrar')"
            >{{ $t("cancelarFactura.sustituirla") }}</RouterLink
          >
        </p>
        <p class="tu-hint">{{ $t("cancelarFactura.esperaAyuda") }}</p>
        <AvisoTono
          v-if="error"
          tono="peligro"
          role="alert"
          data-prueba="error-cancelar"
        >
          {{ error }}
        </AvisoTono>
      </form>
    </template>
    <template #pie>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="pidiendo"
        @click="emit('cerrar')"
      >
        {{ $t("cancelarFactura.volver") }}
      </button>
      <button
        class="tu-btn tu-btn-solido tu-tono-peligro"
        type="submit"
        form="form-cancelar-factura"
        data-prueba="pedir-cancelacion"
        :disabled="pidiendo"
      >
        {{
          pidiendo
            ? $t("cancelarFactura.pidiendo")
            : $t("cancelarFactura.pedir")
        }}
      </button>
    </template>
  </ModalDialogo>
</template>
