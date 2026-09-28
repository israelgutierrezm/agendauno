<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";

/**
 * Acceso directo del Inicio de un portal (alumno o instructor): ícono, título y su
 * dato. Lleva a una pantalla (`to`) o hace algo al tocarla (`tocar`). El dato va en
 * ámbar solo cuando pide atención. Con `tono` (un color de la paleta), el ícono va
 * en su cuadro de ese color y la tarjeta lleva una forma suave en la esquina; el
 * tinte se mezcla con la superficie del tema, así que también se lee en oscuro.
 */
const props = defineProps<{
  to?: RouteLocationRaw;
  icono: string;
  titulo: string;
  valor: string;
  atencion?: boolean;
  tono?: string;
}>();
const emit = defineEmits<{ tocar: [] }>();
</script>

<template>
  <component
    :is="props.to ? RouterLink : 'button'"
    :to="props.to"
    :type="props.to ? undefined : 'button'"
    class="ta-acceso tu-card"
    :class="{ 'ta-con-tono': tono }"
    :style="tono ? { '--ta-tono': tono } : undefined"
    @click="props.to ? undefined : emit('tocar')"
  >
    <span v-if="tono" class="ta-forma" aria-hidden="true" />
    <span v-if="tono" class="ta-icono">
      <IconoNav :nombre="icono" :tam="22" />
    </span>
    <IconoNav
      v-else
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
.ta-con-tono {
  position: relative;
  overflow: hidden;
  align-items: center;
}
.ta-con-tono:hover {
  border-color: color-mix(in srgb, var(--ta-tono) 45%, var(--borde));
}
.ta-icono {
  position: relative;
  display: grid;
  flex: none;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.8rem;
  color: var(--ta-tono);
  background: color-mix(in srgb, var(--ta-tono) 14%, var(--superficie));
}
.ta-forma {
  position: absolute;
  top: -2.2rem;
  right: -2.2rem;
  width: 6rem;
  height: 6rem;
  border-radius: 9999px;
  background: color-mix(in srgb, var(--ta-tono) 9%, transparent);
  pointer-events: none;
}
.ta-con-tono > :not(.ta-forma) {
  position: relative;
}
</style>
