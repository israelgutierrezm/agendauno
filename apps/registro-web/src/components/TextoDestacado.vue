<script setup lang="ts">
import { computed } from "vue";

const props = defineProps<{ texto: string; enfasis: string }>();
// Solo presentación: conservar el texto literal, los espacios y el encabezado padre.
const partes = computed(() => {
  const indice = props.enfasis ? props.texto.indexOf(props.enfasis) : -1;
  if (indice < 0) return [{ texto: props.texto, destacado: false }];
  return [
    { texto: props.texto.slice(0, indice), destacado: false },
    { texto: props.enfasis, destacado: true },
    {
      texto: props.texto.slice(indice + props.enfasis.length),
      destacado: false,
    },
  ];
});
</script>

<template>
  <span
    ><template v-for="(parte, indice) in partes" :key="indice"
      ><span v-if="parte.destacado" class="tu-titulo-enfasis">{{
        parte.texto
      }}</span
      ><template v-else>{{ parte.texto }}</template></template
    ></span
  >
</template>

<style scoped>
.tu-titulo-enfasis {
  font-weight: 400;
  color: var(--marketing-enfasis, var(--enlace));
}
</style>
