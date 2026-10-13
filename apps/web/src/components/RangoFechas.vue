<script setup lang="ts">
import { computed } from "vue";

import {
  PERIODOS,
  periodoDe,
  rangoDePeriodo,
  type Periodo,
} from "@/lib/periodos";

/**
 * El rango de fechas de un listado (días del negocio): desde y hasta, sin dejar que
 * el final quede antes que el inicio. Mismo aspecto en todos los listados. Con
 * `atajos`, también se elige un periodo de una vez (hoy, los últimos 7 días, este mes
 * o el mes pasado) en la zona del negocio.
 */
const desde = defineModel<string>("desde", { required: true });
const hasta = defineModel<string>("hasta", { required: true });

const props = defineProps<{
  /** Qué fechas son (p. ej. «Vencen»); por omisión, «Fechas». */
  etiqueta?: string;
  /** Prefijo de `data-prueba` de los campos. */
  prueba?: string;
  /** Muestra los atajos de periodo. */
  atajos?: boolean;
  /** Zona del negocio para los atajos (por omisión, la del navegador). */
  zona?: string;
}>();

const zonaAtajos = computed(
  () => props.zona || Intl.DateTimeFormat().resolvedOptions().timeZone,
);
// «todo» sin fechas; un atajo si el rango es uno; si no, «a mano».
const periodo = computed<Periodo | "todo" | "libre">(() => {
  if (desde.value === "" && hasta.value === "") {
    return "todo";
  }
  return periodoDe(desde.value, hasta.value, zonaAtajos.value) ?? "libre";
});

function elegir(valor: string): void {
  if (valor === "todo") {
    desde.value = "";
    hasta.value = "";
    return;
  }
  if ((PERIODOS as readonly string[]).includes(valor)) {
    const rango = rangoDePeriodo(valor as Periodo, zonaAtajos.value);
    desde.value = rango.desde;
    hasta.value = rango.hasta;
  }
}
</script>

<template>
  <fieldset class="rango-fechas">
    <legend class="sr-only">
      {{ etiqueta ?? $t("operacion.listados.fechas") }}
    </legend>
    <select
      v-if="atajos"
      class="tu-input rango-atajo"
      :value="periodo"
      :aria-label="$t('operacion.listados.periodo')"
      :data-prueba="`${prueba ?? 'fechas'}-periodo`"
      @change="elegir(($event.target as HTMLSelectElement).value)"
    >
      <option value="todo">{{ $t("operacion.listados.periodos.todo") }}</option>
      <option v-for="p in PERIODOS" :key="p" :value="p">
        {{ $t(`operacion.listados.periodos.${p}`) }}
      </option>
      <option value="libre" disabled>
        {{ $t("operacion.listados.periodos.libre") }}
      </option>
    </select>
    <input
      v-model="desde"
      type="date"
      class="tu-input"
      :max="hasta || undefined"
      :aria-label="$t('operacion.listados.desde')"
      :data-prueba="`${prueba ?? 'fechas'}-desde`"
    />
    <span aria-hidden="true">–</span>
    <input
      v-model="hasta"
      type="date"
      class="tu-input"
      :min="desde || undefined"
      :aria-label="$t('operacion.listados.hasta')"
      :data-prueba="`${prueba ?? 'fechas'}-hasta`"
    />
  </fieldset>
</template>

<style scoped>
.rango-fechas {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  color: var(--texto-suave);
}
.rango-fechas .tu-input {
  width: auto;
  min-height: 2.5rem;
  padding-block: 0.35rem;
  font-size: 0.85rem;
}
</style>
