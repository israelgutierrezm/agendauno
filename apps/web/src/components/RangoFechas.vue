<script setup lang="ts">
/**
 * El rango de fechas de un listado (días del negocio): desde y hasta, sin dejar que
 * el final quede antes que el inicio. Mismo aspecto en todos los listados.
 */
const desde = defineModel<string>("desde", { required: true });
const hasta = defineModel<string>("hasta", { required: true });

defineProps<{
  /** Qué fechas son (p. ej. «Vencen»); por omisión, «Fechas». */
  etiqueta?: string;
  /** Prefijo de `data-prueba` de los dos campos. */
  prueba?: string;
}>();
</script>

<template>
  <fieldset class="rango-fechas">
    <legend class="sr-only">
      {{ etiqueta ?? $t("operacion.listados.fechas") }}
    </legend>
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
