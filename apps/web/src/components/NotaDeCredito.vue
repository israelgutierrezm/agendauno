<script setup lang="ts">
import axios from "axios";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import SelectorBuscable from "@/components/SelectorBuscable.vue";
import { api, erroresPorCampo, mensajeDeError } from "@/lib/api";
import {
  opcionesFormaPago,
  opcionesRegimen,
  tipoPersonaDeRfc,
  type TipoPersona,
} from "@/lib/catalogosSat";
import { confirmar } from "@/lib/confirmar";

/**
 * Una nota de crédito (CFDI de egreso, ADR 0180) de una factura timbrada: lo que se
 * devolvió, descontó o bonificó, con IVA y sin pasar de lo que queda por acreditar.
 * Si la factura no guardó el régimen del cliente, se pide (la nota lo lleva).
 */
export interface FacturaAcreditable {
  id: string;
  receptor: { nombre: string; rfc: string };
  acreditable_minor: number;
  forma_pago?: string | null;
  regimen_receptor?: string | null;
}

const props = defineProps<{
  base: string;
  factura: FacturaAcreditable | null;
  dinero: (minor: number) => string;
}>();
const emit = defineEmits<{
  cerrar: [];
  hecha: [nota: { estado: string }];
}>();

const { t } = useI18n();
const monto = ref("");
const descripcion = ref("");
const formaPago = ref("01");
const regimen = ref("");
const timbrando = ref(false);
const error = ref<string | null>(null);
const errores = ref<Record<string, string>>({});
watch(
  () => props.factura?.id,
  () => {
    monto.value =
      props.factura !== null
        ? (props.factura.acreditable_minor / 100).toFixed(2)
        : "";
    descripcion.value = "";
    formaPago.value = props.factura?.forma_pago ?? "01";
    regimen.value = "";
    error.value = null;
    errores.value = {};
  },
);

const opcionesForma = opcionesFormaPago();
function textoPersonas(personas: TipoPersona[]): string {
  return personas.length > 1
    ? t("datosFiscalesSat.regimen.personas.ambas")
    : t(`datosFiscalesSat.regimen.personas.${personas[0] ?? "fisica"}`);
}
const opcionesRegimenCliente = computed(() =>
  opcionesRegimen(tipoPersonaDeRfc(props.factura?.receptor.rfc ?? ""), {
    persona: textoPersonas,
    paraTuRfc: t("datosFiscalesSat.factura.paraElRfc"),
    otros: t("datosFiscalesSat.regimen.otros"),
  }),
);
const pideRegimen = computed(
  () => props.factura !== null && !props.factura.regimen_receptor,
);
const montoMinor = computed(() => Math.round(Number(monto.value) * 100));
// Los campos que el diálogo muestra junto a su error.
const CAMPOS = ["monto_minor", "regimen_fiscal"];

async function timbrar(): Promise<void> {
  if (!props.factura) {
    return;
  }
  if (
    !(await confirmar(
      t("cancelarFactura.confirmar", {
        monto: props.dinero(montoMinor.value),
        rfc: props.factura.receptor.rfc,
      }),
      { aceptar: t("cancelarFactura.timbrar") },
    ))
  ) {
    return;
  }
  timbrando.value = true;
  error.value = null;
  errores.value = {};
  try {
    const { data } = await api.post<{ data: { estado: string } }>(
      `${props.base}/facturas/${props.factura.id}/notas-credito`,
      {
        monto_minor: montoMinor.value,
        descripcion: descripcion.value.trim() || null,
        forma_pago: formaPago.value,
        ...(pideRegimen.value ? { regimen_fiscal: regimen.value } : {}),
      },
    );
    emit("hecha", data.data);
  } catch (e) {
    // El rechazo del proveedor llega como 422 con la nota en error y su motivo.
    const rechazo =
      axios.isAxiosError(e) && e.response?.data?.data?.estado === "error"
        ? (e.response.data.data.motivo_error as string | null)
        : null;
    const porCampo = rechazo === null ? erroresPorCampo(e) : {};
    // Junto a su campo, lo que es de un campo del diálogo; lo demás (la factura, la
    // facturación, los datos fiscales), arriba.
    errores.value = Object.fromEntries(
      Object.entries(porCampo).filter(([c]) => CAMPOS.includes(c)),
    );
    const otros = Object.entries(porCampo).filter(([c]) => !CAMPOS.includes(c));
    error.value =
      rechazo ??
      (otros.length > 0
        ? otros.map(([, m]) => m).join(" ")
        : Object.keys(errores.value).length > 0
          ? null
          : mensajeDeError(e));
  } finally {
    timbrando.value = false;
  }
}
</script>

<template>
  <ModalDialogo
    :abierto="factura !== null"
    :titulo="$t('cancelarFactura.notaTitulo')"
    :ocupado="timbrando"
    @cerrar="emit('cerrar')"
  >
    <template v-if="factura">
      <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ factura.receptor.nombre }} · {{ factura.receptor.rfc }}
      </p>
      <p class="tu-hint mt-1">{{ $t("cancelarFactura.notaAyuda") }}</p>
      <form
        id="form-nota-credito"
        class="mt-4 grid gap-4 sm:grid-cols-2"
        @submit.prevent="timbrar"
      >
        <div>
          <label class="tu-label" for="nc-monto"
            >{{ $t("cancelarFactura.monto") }}
            <span class="tu-obligatorio" aria-hidden="true">*</span></label
          >
          <input
            id="nc-monto"
            v-model="monto"
            class="tu-input"
            type="number"
            min="0.01"
            :max="factura.acreditable_minor / 100"
            step="0.01"
            required
            data-prueba="monto-nota"
            :aria-invalid="errores.monto_minor ? 'true' : undefined"
          />
          <span v-if="errores.monto_minor" class="tu-error-campo">{{
            errores.monto_minor
          }}</span>
          <span v-else class="tu-hint">{{
            $t("cancelarFactura.queda", {
              monto: dinero(factura.acreditable_minor),
            })
          }}</span>
        </div>
        <div>
          <label class="tu-label" for="nc-desc">{{
            $t("cancelarFactura.descripcion")
          }}</label>
          <input
            id="nc-desc"
            v-model="descripcion"
            class="tu-input"
            maxlength="1000"
            :placeholder="$t('cancelarFactura.descripcionPh')"
          />
        </div>
        <div class="sm:col-span-2">
          <label class="tu-label" for="nc-forma"
            >{{ $t("cancelarFactura.formaPago") }}
            <span class="tu-obligatorio" aria-hidden="true">*</span></label
          >
          <SelectorBuscable
            id="nc-forma"
            v-model="formaPago"
            :opciones="opcionesForma"
            :placeholder="$t('datosFiscalesSat.factura.formaPh')"
            data-prueba="forma-nota"
          />
        </div>
        <div v-if="pideRegimen" class="sm:col-span-2">
          <label class="tu-label" for="nc-regimen"
            >{{ $t("cancelarFactura.regimen") }}
            <span class="tu-obligatorio" aria-hidden="true">*</span></label
          >
          <SelectorBuscable
            id="nc-regimen"
            v-model="regimen"
            :opciones="opcionesRegimenCliente"
            :placeholder="$t('datosFiscalesSat.factura.regimenReceptorPh')"
            data-prueba="regimen-nota"
          />
          <span v-if="errores.regimen_fiscal" class="tu-error-campo">{{
            errores.regimen_fiscal
          }}</span>
          <span v-else class="tu-hint">{{
            $t("cancelarFactura.regimenAyuda")
          }}</span>
        </div>
        <AvisoTono
          v-if="error"
          class="sm:col-span-2"
          tono="peligro"
          role="alert"
          data-prueba="error-nota"
        >
          {{ error }}
        </AvisoTono>
      </form>
    </template>
    <template #pie>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="timbrando"
        @click="emit('cerrar')"
      >
        {{ $t("comun.cancelar") }}
      </button>
      <button
        class="tu-btn tu-btn-primario"
        type="submit"
        form="form-nota-credito"
        data-prueba="timbrar-nota"
        :disabled="timbrando || (pideRegimen && regimen === '')"
      >
        {{
          timbrando
            ? $t("cancelarFactura.timbrando")
            : $t("cancelarFactura.timbrar")
        }}
      </button>
    </template>
  </ModalDialogo>
</template>
