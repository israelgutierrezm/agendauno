<script setup lang="ts">
/**
 * Un aviso con la paleta del sistema (éxito, peligro, aviso, info o neutral): icono,
 * título y mensaje. Para lo que hay que leer antes de actuar: qué hace una función,
 * qué falta, qué salió bien o mal. El mensaje va en el contenido; las acciones, en
 * `#acciones`.
 */
type Tono = "exito" | "peligro" | "aviso" | "info" | "neutral";

withDefaults(
  defineProps<{
    tono?: Tono;
    titulo?: string;
  }>(),
  { tono: "info", titulo: undefined },
);

// Trazos de cada icono (24x24, de línea, como IconoNav).
const ICONOS: Record<Tono, string[]> = {
  exito: ["M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z", "M8.5 12.2l2.4 2.4 4.6-4.9"],
  peligro: [
    "M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z",
    "M9.2 9.2l5.6 5.6",
    "M14.8 9.2l-5.6 5.6",
  ],
  aviso: [
    "M10.3 4.3 2.9 17.2A2 2 0 0 0 4.6 20h14.8a2 2 0 0 0 1.7-2.8L13.7 4.3a2 2 0 0 0-3.4 0z",
    "M12 9.5v4",
    "M12 16.8h.01",
  ],
  info: ["M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z", "M12 11v5", "M12 7.8h.01"],
  neutral: ["M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z", "M12 7.5V12l3 1.8"],
};
</script>

<template>
  <div
    class="tu-alerta"
    :class="[`tu-tono-${tono}`, { 'tu-alerta-simple': !titulo }]"
    data-prueba="aviso"
  >
    <svg
      class="tu-alerta-icono"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="1.8"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <path v-for="(d, i) in ICONOS[tono]" :key="i" :d="d" />
    </svg>
    <div class="tu-alerta-contenido">
      <p v-if="titulo" class="tu-alerta-titulo">{{ titulo }}</p>
      <div class="tu-alerta-mensaje"><slot /></div>
    </div>
    <div v-if="$slots.acciones" class="shrink-0 self-center">
      <slot name="acciones" />
    </div>
  </div>
</template>
