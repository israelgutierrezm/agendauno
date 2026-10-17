<script setup lang="ts">
/**
 * Arriba de la vista previa de una importación: todas las filas o solo las que hay
 * que corregir (con cuántas son). Sin filas por corregir no se muestra.
 */
defineProps<{ todas: number; conErrores: number }>();
const soloErrores = defineModel<boolean>({ required: true });
</script>

<template>
  <div
    v-if="conErrores > 0"
    class="tu-segmentado mt-4"
    role="group"
    data-prueba="filtro-filas"
  >
    <button
      type="button"
      :aria-pressed="!soloErrores"
      @click="soloErrores = false"
    >
      {{ $t("importar.filtro.todas", { n: todas }) }}
    </button>
    <button
      type="button"
      :aria-pressed="soloErrores"
      data-prueba="solo-errores"
      @click="soloErrores = true"
    >
      {{ $t("importar.filtro.conErrores", { n: conErrores }, conErrores) }}
    </button>
  </div>
</template>
