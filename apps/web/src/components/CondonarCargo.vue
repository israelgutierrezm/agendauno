<script setup lang="ts">
import { ref } from "vue";

/**
 * Condonar un cargo de renta pendiente desde el superadmin: pide el motivo en la misma
 * fila (queda en la bitácora) y avisa a quien lo usa con `condonar`. Quien lo usa
 * confirma, llama a la API y lo cierra con `cerrar()`.
 */
defineProps<{ ocupado?: boolean }>();
const emit = defineEmits<{ condonar: [motivo: string] }>();

const abierto = ref(false);
const motivo = ref("");

function enviar(): void {
  const texto = motivo.value.trim();
  if (texto !== "") {
    emit("condonar", texto);
  }
}
function cerrar(): void {
  abierto.value = false;
  motivo.value = "";
}

defineExpose({ cerrar });
</script>

<template>
  <button
    v-if="!abierto"
    type="button"
    class="tu-btn tu-btn-fantasma text-xs"
    data-prueba="condonar"
    @click="abierto = true"
  >
    {{ $t("plataformaAdmin.cobros.condonar") }}
  </button>
  <form
    v-else
    class="cc-form"
    data-prueba="condonar-form"
    @submit.prevent="enviar"
  >
    <label class="cc-campo">
      <span class="sr-only">{{
        $t("plataformaAdmin.cobros.motivoCondonar")
      }}</span>
      <input
        v-model="motivo"
        class="tu-input text-sm"
        maxlength="255"
        required
        :placeholder="$t('plataformaAdmin.cobros.motivoCondonar')"
      />
    </label>
    <button
      type="submit"
      class="tu-btn tu-btn-fantasma text-xs"
      :disabled="ocupado || motivo.trim() === ''"
    >
      {{ $t("plataformaAdmin.cobros.condonar") }}
    </button>
    <button
      type="button"
      class="tu-btn tu-btn-fantasma text-xs"
      @click="cerrar"
    >
      {{ $t("comun.cancelar") }}
    </button>
  </form>
</template>

<style scoped>
.cc-form {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
}
.cc-campo {
  flex: 1 1 12rem;
  min-width: 0;
}
</style>
