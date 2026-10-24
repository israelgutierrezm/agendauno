<script setup lang="ts">
import { onMounted, onUnmounted } from "vue";

import ModalDialogo from "@/components/ModalDialogo.vue";
import { estadoConfirmar, responderConfirmar } from "@/lib/confirmar";

/**
 * El diálogo de `confirmar()` (ver lib/confirmar.ts): una pregunta y, en el pie,
 * "Cancelar" y el botón que confirma. Con el contrato de botones (style.css) solo hay
 * dos formas de confirmar: el primario del tema o, si la acción es destructiva, el
 * sólido de peligro. Los demás tonos (completar, regresar) confirman con el primario.
 * Se monta una sola vez.
 */
onMounted(() => (estadoConfirmar.montado = true));
onUnmounted(() => {
  estadoConfirmar.montado = false;
  responderConfirmar(false);
});
</script>

<template>
  <ModalDialogo
    :abierto="estadoConfirmar.abierto"
    :titulo="$t('operacion.confirmar.titulo')"
    tam="md"
    @cerrar="responderConfirmar(false)"
  >
    <p class="whitespace-pre-line">{{ estadoConfirmar.mensaje }}</p>
    <template #pie>
      <button
        type="button"
        class="tu-btn tu-btn-secundario"
        @click="responderConfirmar(false)"
      >
        {{ $t("operacion.confirmar.cancelar") }}
      </button>
      <button
        type="button"
        class="tu-btn"
        :class="
          estadoConfirmar.peligro
            ? 'tu-btn-solido tu-tono-peligro'
            : 'tu-btn-primario'
        "
        data-prueba="confirmar-aceptar"
        @click="responderConfirmar(true)"
      >
        {{ estadoConfirmar.aceptar ?? $t("operacion.confirmar.aceptar") }}
      </button>
    </template>
  </ModalDialogo>
</template>
