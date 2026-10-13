<script setup lang="ts">
import { onMounted, onUnmounted } from "vue";

import ModalDialogo from "@/components/ModalDialogo.vue";
import { estadoConfirmar, responderConfirmar } from "@/lib/confirmar";

/**
 * El diálogo de `confirmar()` (ver lib/confirmar.ts): una pregunta, "Cancelar" y el
 * botón que confirma: con el color principal del tema o, si la acción lo pide, con su
 * tono (verde para completar, ámbar para regresar, rojo si es destructiva). Se monta
 * una sola vez.
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
    <div class="mt-6 flex justify-end gap-2">
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        @click="responderConfirmar(false)"
      >
        {{ $t("operacion.confirmar.cancelar") }}
      </button>
      <button
        type="button"
        class="tu-btn"
        :class="
          estadoConfirmar.tono
            ? ['tu-btn-solido', `tu-tono-${estadoConfirmar.tono}`]
            : 'tu-btn-primario'
        "
        data-prueba="confirmar-aceptar"
        @click="responderConfirmar(true)"
      >
        {{ estadoConfirmar.aceptar ?? $t("operacion.confirmar.aceptar") }}
      </button>
    </div>
  </ModalDialogo>
</template>
