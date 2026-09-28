<script setup lang="ts">
import { computed } from "vue";

/**
 * Dibujo del clima (trazo 24×24, currentColor), por la clave que manda la API:
 * despejado, parcial, nublado, niebla, llovizna, lluvia, nieve o tormenta. De
 * noche, el sol se vuelve luna.
 */
const props = defineProps<{ icono: string; deDia?: boolean; tam?: number }>();

const SOL = [
  "M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8z",
  "M12 2.5v2",
  "M12 19.5v2",
  "M4.6 4.6l1.4 1.4",
  "M18 18l1.4 1.4",
  "M2.5 12h2",
  "M19.5 12h2",
  "M4.6 19.4 6 18",
  "M18 6l1.4-1.4",
];
const LUNA = ["M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"];
const NUBE = "M7 18.5h10a4 4 0 0 0 .6-7.95A5.5 5.5 0 0 0 7 10.5a4 4 0 0 0 0 8z";
const NUBE_ALTA =
  "M7 14.5h10a3.5 3.5 0 0 0 .5-6.96A5 5 0 0 0 7.2 7a3.75 3.75 0 0 0-.2 7.5z";

const RUTAS: Record<string, string[]> = {
  parcial: [
    "M9 3v1.2",
    "M3.8 5.8l.9.9",
    "M2.5 11h1.2",
    "M5.6 12.4A3.5 3.5 0 1 1 11.3 8",
    "M8.5 20h9a3.5 3.5 0 0 0 .4-6.98A4.75 4.75 0 0 0 8.9 12.5 3.75 3.75 0 0 0 8.5 20z",
  ],
  parcialNoche: [
    "M9.5 3.5a4.25 4.25 0 1 0 3.4 6.8A3.6 3.6 0 0 1 9.5 3.5z",
    "M8.5 20h9a3.5 3.5 0 0 0 .4-6.98A4.75 4.75 0 0 0 8.9 12.5 3.75 3.75 0 0 0 8.5 20z",
  ],
  nublado: [NUBE],
  niebla: [NUBE_ALTA, "M4 18h16", "M6 21h12"],
  llovizna: [NUBE_ALTA, "M8.5 18v1", "M12 18v1", "M15.5 18v1"],
  lluvia: [NUBE_ALTA, "M8.5 17.5l-1 3", "M12.5 17.5l-1 3", "M16.5 17.5l-1 3"],
  nieve: [
    NUBE_ALTA,
    "M8.5 18.5h.01",
    "M12 20.5h.01",
    "M15.5 18.5h.01",
    "M10 21.5h.01",
    "M14 21.5h.01",
  ],
  tormenta: [NUBE_ALTA, "M12.5 15.5 10 19h4l-2.5 3.5"],
};

const rutas = computed(() => {
  const noche = props.deDia === false;
  if (props.icono === "despejado") {
    return noche ? LUNA : SOL;
  }
  if (props.icono === "parcial" && noche) {
    return RUTAS.parcialNoche;
  }
  return RUTAS[props.icono] ?? RUTAS.nublado;
});
</script>

<template>
  <svg
    :width="tam ?? 28"
    :height="tam ?? 28"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <path v-for="(d, i) in rutas" :key="i" :d="d" />
  </svg>
</template>
