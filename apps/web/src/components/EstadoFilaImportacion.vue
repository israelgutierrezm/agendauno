<script setup lang="ts">
import { computed } from "vue";

/**
 * Estado de una fila de una carga por CSV, con punto y texto: lista, ya estaba (no se
 * repite), en conflicto (el dato es de alguien más o viene repetido) o con errores. Los
 * motivos van debajo, para corregirlos en el archivo.
 */
const props = defineProps<{ estado?: string; errores: string[] }>();

const ESTADOS = ["valida", "existente", "conflicto", "invalida"] as const;
type Estado = (typeof ESTADOS)[number];

const COLOR: Record<Estado, string> = {
  valida: "var(--exito)",
  existente: "var(--texto-suave)",
  conflicto: "var(--aviso)",
  invalida: "var(--error)",
};

const estado = computed<Estado>(() =>
  ESTADOS.includes(props.estado as Estado)
    ? (props.estado as Estado)
    : props.errores.length > 0
      ? "invalida"
      : "valida",
);
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 text-sm"
    data-prueba="estado-fila"
  >
    <span
      class="inline-block size-1.5 rounded-full shrink-0"
      :style="{ background: COLOR[estado] }"
      aria-hidden="true"
    />
    {{ $t(`importar.estados.${estado}`) }}
  </span>
  <ul
    v-if="errores.length > 0"
    class="mt-1 space-y-0.5 text-xs"
    :style="{ color: estado === 'conflicto' ? 'var(--texto)' : 'var(--error)' }"
  >
    <li v-for="(er, i) in errores" :key="i">{{ er }}</li>
  </ul>
</template>
