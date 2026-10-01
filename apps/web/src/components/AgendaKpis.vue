<script setup lang="ts">
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";

/**
 * Indicadores de la agenda (del día en citas, de la semana en clases). Los valores
 * los calcula la vista según la modalidad.
 */
defineProps<{ tarjetas: Indicador[] }>();
</script>

<template>
  <TarjetasIndicadores :tarjetas="tarjetas" />
</template>
