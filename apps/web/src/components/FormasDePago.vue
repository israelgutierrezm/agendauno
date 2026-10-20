<script setup lang="ts">
import { computed, ref, watch } from "vue";

import { aMinor, dinero as dineroDelPais } from "@/lib/formato";
import type { ParteCobro } from "@/lib/formasDePago";

/**
 * Dividir un cobro en caja entre varias formas de pago (cobro mixto, ADR 0177). Cada
 * renglón lleva su forma y su monto; el último se completa solo con lo que falta, así
 * las formas siempre buscan sumar el total. Con una sola forma, `partes` es null y la
 * pantalla usa su selector de siempre.
 */
const props = defineProps<{
  /** Lo que se cobra, en centavos. */
  total: number;
  /** Las formas que se ofrecen (las de la caja). */
  metodos: readonly string[];
  /** Llave de i18n de los nombres de cada forma (p. ej. `pos.metodos`). */
  prefijo: string;
  /** La forma elegida antes de dividir: va primero. */
  inicial?: string;
  moneda: string;
  pais?: string | null;
}>();

const dividido = defineModel<boolean>("dividido", { default: false });
const partes = defineModel<ParteCobro[] | null>("partes", { default: null });

interface Renglon {
  metodo: string;
  monto: string;
}
const renglones = ref<Renglon[]>([]);
const maximo = computed(() => Math.min(4, props.metodos.length));

function dinero(minor: number): string {
  return dineroDelPais(minor, props.moneda, props.pais, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function dividir(): void {
  const primero = props.inicial ?? props.metodos[0] ?? "efectivo";
  const segundo = props.metodos.find((m) => m !== primero) ?? primero;
  renglones.value = [
    { metodo: primero, monto: "" },
    { metodo: segundo, monto: "" },
  ];
  dividido.value = true;
}
function unaSola(): void {
  renglones.value = [];
  dividido.value = false;
}
// Otra cobranza (el total vuelve a cero al abrir) empieza con una sola forma.
watch(dividido, (si) => {
  if (!si && renglones.value.length > 0) {
    renglones.value = [];
  }
});

const ultimo = computed(() => renglones.value.length - 1);
const montos = computed(() =>
  renglones.value.map((r, i) =>
    i === ultimo.value ? null : aMinor(r.monto, props.pais),
  ),
);
// El último renglón: lo que falta para el total.
const resto = computed(
  () =>
    props.total -
    montos.value.reduce<number>(
      (s, m, i) => (i === ultimo.value ? s : s + (m ?? 0)),
      0,
    ),
);
const estado = computed<"cuadra" | "falta" | "sobra">(() => {
  if (
    montos.value.some((m, i) => i !== ultimo.value && (m === null || m <= 0))
  ) {
    return "falta";
  }
  return resto.value > 0 ? "cuadra" : "sobra";
});

watch(
  [renglones, resto, dividido],
  () => {
    partes.value = dividido.value
      ? renglones.value.map((r, i) => ({
          metodo: r.metodo,
          monto_minor:
            i === ultimo.value ? resto.value : (montos.value[i] ?? 0),
        }))
      : null;
  },
  { deep: true, immediate: true },
);

// Cada forma, una vez: en cada renglón se ofrecen las que no usa otro.
function opciones(i: number): string[] {
  const usadas = renglones.value.filter((_, n) => n !== i).map((r) => r.metodo);
  return props.metodos.filter((m) => !usadas.includes(m));
}
function agregar(): void {
  const libre = props.metodos.find(
    (m) => !renglones.value.some((r) => r.metodo === m),
  );
  if (libre === undefined || renglones.value.length >= maximo.value) return;
  // El nuevo va antes del último, que sigue completando lo que falta.
  renglones.value.splice(ultimo.value, 0, { metodo: libre, monto: "" });
}
function quitar(i: number): void {
  if (renglones.value.length <= 2) return;
  renglones.value.splice(i, 1);
}
</script>

<template>
  <div class="space-y-2" data-prueba="formas-de-pago">
    <button
      v-if="!dividido"
      type="button"
      class="tu-enlace text-sm"
      data-prueba="dividir-pago"
      @click="dividir"
    >
      {{ $t("formasDePago.dividir") }}
    </button>
    <template v-else>
      <div
        v-for="(r, i) in renglones"
        :key="i"
        class="flex flex-wrap items-end gap-2"
        data-prueba="parte-pago"
      >
        <div class="min-w-0 flex-1">
          <label class="tu-label" :for="`fp-forma-${i}`">{{
            $t("formasDePago.forma")
          }}</label>
          <select
            :id="`fp-forma-${i}`"
            v-model="r.metodo"
            class="tu-input"
            :data-prueba="`forma-${i}`"
          >
            <option v-for="m in opciones(i)" :key="m" :value="m">
              {{ $t(`${prefijo}.${m}`) }}
            </option>
          </select>
        </div>
        <div class="w-32">
          <label class="tu-label" :for="`fp-monto-${i}`">{{
            i === ultimo ? $t("formasDePago.resto") : $t("formasDePago.monto")
          }}</label>
          <input
            v-if="i !== ultimo"
            :id="`fp-monto-${i}`"
            v-model="r.monto"
            class="tu-input tabular-nums"
            inputmode="decimal"
            :data-prueba="`monto-${i}`"
          />
          <output
            v-else
            :id="`fp-monto-${i}`"
            class="tu-input block tabular-nums"
            :style="{ color: resto > 0 ? 'var(--texto)' : 'var(--error)' }"
            data-prueba="monto-resto"
            >{{ dinero(resto) }}</output
          >
        </div>
        <button
          v-if="renglones.length > 2 && i !== ultimo"
          type="button"
          class="tu-btn tu-btn-fantasma text-sm"
          :aria-label="$t('formasDePago.quitar')"
          @click="quitar(i)"
        >
          ×
        </button>
      </div>
      <p
        class="text-sm"
        :style="{
          color: estado === 'cuadra' ? 'var(--texto-suave)' : 'var(--error)',
        }"
        role="status"
        data-prueba="estado-formas"
      >
        {{ $t(`formasDePago.${estado}`) }}
      </p>
      <div class="flex flex-wrap gap-3">
        <button
          v-if="renglones.length < maximo"
          type="button"
          class="tu-enlace text-sm"
          data-prueba="agregar-forma"
          @click="agregar"
        >
          {{ $t("formasDePago.agregar") }}
        </button>
        <button
          type="button"
          class="tu-enlace text-sm"
          data-prueba="una-forma"
          @click="unaSola"
        >
          {{ $t("formasDePago.unaForma") }}
        </button>
      </div>
    </template>
  </div>
</template>
