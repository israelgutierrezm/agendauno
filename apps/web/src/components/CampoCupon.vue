<script setup lang="ts">
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";

/**
 * «¿Tienes un cupón?» (ADR 0220): se escribe, se revisa cuánto descuenta y queda
 * aplicado (el servidor lo vuelve a validar al agendar, cobrar o vender). Quien lo usa
 * da la función que lo revisa y el formato del dinero; si cambia sobre cuánto se
 * calcula (`base`, p. ej. el carrito), se vuelve a revisar.
 */
const props = defineProps<{
  revisar: (codigo: string) => Promise<{ descuento_minor: number }>;
  dinero: (minor: number) => string;
  base?: number;
  deshabilitado?: boolean;
}>();
const codigo = defineModel<string>({ default: "" });
const emit = defineEmits<{ aplicado: [descuentoMinor: number] }>();

const { t } = useI18n();
const abierto = ref(codigo.value !== "");
const escrito = ref(codigo.value);
const revisando = ref(false);
const descuento = ref<number | null>(null);
const error = ref<string | null>(null);
// La base cambió mientras se revisaba: al terminar, otra vez con la nueva.
let otraVez = false;

async function aplicar(): Promise<void> {
  await revisarCodigo(escrito.value.trim());
}

async function revisarCodigo(texto: string): Promise<void> {
  if (texto === "" || revisando.value) return;
  revisando.value = true;
  error.value = null;
  try {
    const { descuento_minor } = await props.revisar(texto);
    descuento.value = descuento_minor;
    codigo.value = texto;
    emit("aplicado", descuento_minor);
  } catch (e) {
    descuento.value = null;
    codigo.value = "";
    error.value = mensajeDeError(e);
    emit("aplicado", 0);
  } finally {
    revisando.value = false;
    if (otraVez) {
      otraVez = false;
      if (codigo.value !== "") void revisarCodigo(codigo.value);
    }
  }
}

// Cambió sobre cuánto se calcula: el mismo cupón, revisado otra vez.
watch(
  () => props.base,
  () => {
    if (revisando.value) otraVez = true;
    else if (codigo.value !== "") void revisarCodigo(codigo.value);
  },
);
// Quien lo usa lo limpió (otro servicio, venta hecha): se vuelve a empezar.
watch(codigo, (v) => {
  if (v === "" && descuento.value !== null) {
    descuento.value = null;
    escrito.value = "";
    abierto.value = false;
  }
});

function quitar(): void {
  escrito.value = "";
  codigo.value = "";
  descuento.value = null;
  error.value = null;
  emit("aplicado", 0);
}
</script>

<template>
  <div class="cc" data-prueba="campo-cupon">
    <button
      v-if="!abierto"
      type="button"
      class="tu-enlace text-sm"
      :disabled="deshabilitado"
      data-prueba="abrir-cupon"
      @click="abierto = true"
    >
      {{ t("cupon.tienes") }}
    </button>
    <template v-else>
      <div v-if="descuento !== null && codigo !== ''" class="cc-aplicado">
        <span data-prueba="cupon-aplicado">{{
          t("cupon.aplicado", {
            codigo: codigo.toUpperCase(),
            monto: props.dinero(descuento),
          })
        }}</span>
        <button
          type="button"
          class="tu-enlace text-sm"
          :disabled="deshabilitado"
          @click="quitar"
        >
          {{ t("cupon.quitar") }}
        </button>
      </div>
      <!-- Sin <form>: el campo vive dentro del formulario de agendar o cobrar. -->
      <div v-else class="cc-form">
        <label class="sr-only" for="cc-codigo">{{ t("cupon.codigo") }}</label>
        <input
          id="cc-codigo"
          v-model="escrito"
          class="tu-input text-sm"
          maxlength="64"
          autocomplete="off"
          autocapitalize="characters"
          spellcheck="false"
          :placeholder="t('cupon.codigo')"
          :disabled="deshabilitado"
          @keydown.enter.prevent="aplicar"
        />
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          :disabled="deshabilitado || revisando || escrito.trim() === ''"
          data-prueba="aplicar-cupon"
          @click="aplicar"
        >
          {{ t("cupon.aplicar") }}
        </button>
      </div>
      <p v-if="error" class="cc-error" role="alert" data-prueba="error-cupon">
        {{ error }}
      </p>
    </template>
  </div>
</template>

<style scoped>
.cc-form,
.cc-aplicado {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}
.cc-form .tu-input {
  max-width: 14rem;
}
.cc-aplicado {
  font-size: 0.875rem;
}
.cc-error {
  margin-top: 0.25rem;
  color: var(--error);
  font-size: 0.8125rem;
}
</style>
