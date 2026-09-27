<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";

/**
 * Acceso directo del Inicio de un portal (alumno o instructor): ícono, título y su
 * dato. Lleva a una pantalla (`to`) o hace algo al tocarla (`tocar`). El dato va en
 * ámbar solo cuando pide atención.
 */
const props = defineProps<{
  to?: RouteLocationRaw;
  icono: string;
  titulo: string;
  valor: string;
  atencion?: boolean;
}>();
const emit = defineEmits<{ tocar: [] }>();
</script>

<template>
  <component
    :is="props.to ? RouterLink : 'button'"
    :to="props.to"
    :type="props.to ? undefined : 'button'"
    class="ta-acceso tu-card"
    @click="props.to ? undefined : emit('tocar')"
  >
    <IconoNav
      :nombre="icono"
      :tam="22"
      :style="{ color: 'var(--texto-suave)' }"
    />
    <span class="min-w-0">
      <span class="block font-semibold">{{ titulo }}</span>
      <span
        class="mt-0.5 block text-sm"
        :style="{ color: atencion ? 'var(--aviso)' : 'var(--texto-suave)' }"
        >{{ valor }}</span
      >
    </span>
    <span class="ta-flecha" aria-hidden="true">→</span>
  </component>
</template>

<style scoped>
.ta-acceso {
  display: flex;
  width: 100%;
  height: 100%;
  align-items: flex-start;
  gap: 0.9rem;
  padding: 1.1rem 1.2rem;
  text-align: left;
  text-decoration: none;
  color: inherit;
  transition: border-color 0.15s ease;
}
.ta-acceso:hover {
  border-color: color-mix(in srgb, var(--primario) 45%, var(--borde));
}
.ta-flecha {
  margin-left: auto;
  color: var(--texto-suave);
}
</style>
