<script setup lang="ts">
/**
 * Un interruptor (encendido / apagado) con su etiqueta: para activar o desactivar algo
 * en vez de una casilla. Se maneja con el teclado como un botón (`role="switch"`).
 */
const encendido = defineModel<boolean>({ required: true });

defineProps<{
  etiqueta: string;
  deshabilitado?: boolean;
  prueba?: string;
}>();
</script>

<template>
  <button
    type="button"
    role="switch"
    class="tu-interruptor"
    :aria-checked="encendido"
    :disabled="deshabilitado"
    :data-prueba="prueba"
    @click="encendido = !encendido"
  >
    <span class="tu-interruptor-riel" aria-hidden="true" />
    <span>{{ etiqueta }}</span>
  </button>
</template>
