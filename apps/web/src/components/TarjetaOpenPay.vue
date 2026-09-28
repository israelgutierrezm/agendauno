<script setup lang="ts">
import { reactive, ref } from "vue";

import {
  tokenizarTarjeta,
  type FormularioOpenPay,
  type TarjetaOpenPay,
} from "@/lib/openpay";

/**
 * Captura de tarjeta con OpenPay.js para el pago automático. Los campos no tienen
 * `name` ni se envían a AgendaUno: OpenPay.js los convierte en un token de un solo
 * uso y solo ese token (con la sesión del dispositivo) sale hacia el API.
 */
const props = defineProps<{ formulario: FormularioOpenPay }>();
const emit = defineEmits<{
  lista: [datos: { token_id: string; device_session_id: string }];
  cancelar: [];
}>();

const tarjeta = reactive<TarjetaOpenPay>({
  card_number: "",
  holder_name: "",
  expiration_month: "",
  expiration_year: "",
  cvv2: "",
});
const procesando = ref(false);
const error = ref<string | null>(null);

async function enviar(): Promise<void> {
  procesando.value = true;
  error.value = null;
  try {
    const datos = await tokenizarTarjeta(props.formulario, {
      ...tarjeta,
      card_number: tarjeta.card_number.replace(/\s+/g, ""),
    });
    emit("lista", datos);
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e);
  } finally {
    procesando.value = false;
  }
}
</script>

<template>
  <form class="mt-3 grid gap-2 text-sm" @submit.prevent="enviar">
    <label class="grid gap-1">
      <span class="tu-label">{{ $t("pagoAutomatico.numeroTarjeta") }}</span>
      <input
        v-model="tarjeta.card_number"
        class="tu-input"
        inputmode="numeric"
        autocomplete="cc-number"
        required
      />
    </label>
    <label class="grid gap-1">
      <span class="tu-label">{{ $t("pagoAutomatico.titular") }}</span>
      <input
        v-model="tarjeta.holder_name"
        class="tu-input"
        autocomplete="cc-name"
        required
      />
    </label>
    <div class="grid grid-cols-3 gap-2">
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("pagoAutomatico.mes") }}</span>
        <input
          v-model="tarjeta.expiration_month"
          class="tu-input"
          inputmode="numeric"
          maxlength="2"
          placeholder="MM"
          autocomplete="cc-exp-month"
          required
        />
      </label>
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("pagoAutomatico.anio") }}</span>
        <input
          v-model="tarjeta.expiration_year"
          class="tu-input"
          inputmode="numeric"
          maxlength="2"
          placeholder="AA"
          autocomplete="cc-exp-year"
          required
        />
      </label>
      <label class="grid gap-1">
        <span class="tu-label">CVV</span>
        <input
          v-model="tarjeta.cvv2"
          class="tu-input"
          inputmode="numeric"
          maxlength="4"
          autocomplete="cc-csc"
          required
        />
      </label>
    </div>
    <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("pagoAutomatico.seguridadOpenPay") }}
    </p>
    <p v-if="error" class="text-xs" style="color: var(--error)">{{ error }}</p>
    <div class="flex gap-2">
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="procesando"
      >
        {{ $t("pagoAutomatico.autorizar") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="procesando"
        @click="emit('cancelar')"
      >
        {{ $t("comun.cancelar") }}
      </button>
    </div>
  </form>
</template>
