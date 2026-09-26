<script setup lang="ts">
import { computed } from "vue";

/**
 * Paginación de un listado (estilo Acadion): «desde–hasta de total» + botones de
 * página. Trabaja sobre el `meta` del backend (page/ultima_pagina/total) y emite
 * `ir(n)` para que el padre recargue esa página. Extraída a componente para no
 * repetir el bloque en cada listado.
 */
const props = defineProps<{
  page: number;
  ultimaPagina: number;
  total: number;
  perPage: number;
}>();

const emit = defineEmits<{ ir: [n: number] }>();

const desde = computed(() =>
  props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1,
);
const hasta = computed(() => Math.min(props.page * props.perPage, props.total));

// Ventana de páginas alrededor de la actual (con primera y última siempre).
const paginas = computed<(number | "...")[]>(() => {
  const u = props.ultimaPagina;
  const p = props.page;
  if (u <= 7) {
    return Array.from({ length: u }, (_, i) => i + 1);
  }
  const set = new Set<number>([1, u, p, p - 1, p + 1]);
  const orden = [...set].filter((n) => n >= 1 && n <= u).sort((a, b) => a - b);
  const salida: (number | "...")[] = [];
  let anterior = 0;
  for (const n of orden) {
    if (n - anterior > 1) {
      salida.push("...");
    }
    salida.push(n);
    anterior = n;
  }
  return salida;
});

function ir(n: number): void {
  if (n >= 1 && n <= props.ultimaPagina && n !== props.page) {
    emit("ir", n);
  }
}
</script>

<template>
  <nav
    v-if="total > 0"
    :aria-label="$t('tabla.paginacion')"
    class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 sm:px-6"
    :style="{ borderColor: 'var(--borde)' }"
  >
    <span class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("tabla.mostrando", { desde, hasta, total }) }}
    </span>

    <div v-if="ultimaPagina > 1" class="flex flex-wrap items-center gap-1">
      <button
        type="button"
        class="min-h-11 min-w-11 rounded-lg px-3 py-1.5 text-center text-sm disabled:opacity-40"
        :style="{ color: 'var(--texto-suave)' }"
        :disabled="page <= 1"
        :aria-label="$t('tabla.anterior')"
        @click="ir(page - 1)"
      >
        ‹
      </button>
      <template v-for="(n, i) in paginas" :key="i">
        <span
          v-if="n === '...'"
          class="px-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          >…</span
        >
        <button
          v-else
          type="button"
          class="min-h-11 min-w-11 rounded-lg px-3 py-1.5 text-center text-sm"
          :aria-current="n === page ? 'page' : undefined"
          :aria-label="$t('tabla.pagina', { n })"
          :style="
            n === page
              ? {
                  background: 'var(--primario)',
                  color: 'var(--primario-contraste)',
                }
              : { color: 'var(--texto-suave)' }
          "
          @click="ir(n)"
        >
          {{ n }}
        </button>
      </template>
      <button
        type="button"
        class="min-h-11 min-w-11 rounded-lg px-3 py-1.5 text-center text-sm disabled:opacity-40"
        :style="{ color: 'var(--texto-suave)' }"
        :disabled="page >= ultimaPagina"
        :aria-label="$t('tabla.siguiente')"
        @click="ir(page + 1)"
      >
        ›
      </button>
    </div>
  </nav>
</template>
