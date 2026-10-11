<script setup lang="ts">
/**
 * Resumen de una carga por CSV (migración guiada, C07): cuántas filas trae el archivo,
 * cuántas están listas, cuántas ya estaban (no se repiten) y cuántas hay que corregir
 * (datos que no sirven o en conflicto).
 */
export interface ResumenCarga {
  total: number;
  validas: number;
  invalidas: number;
  rechazadas?: number;
  conflictos?: number;
  existentes?: number;
}

defineProps<{ resumen: ResumenCarga }>();
</script>

<template>
  <div
    class="grid grid-cols-2 sm:grid-cols-4 gap-3"
    data-prueba="resumen-importacion"
  >
    <div class="tu-card p-4 text-center">
      <div class="text-xl font-semibold">{{ resumen.total }}</div>
      <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("importar.total") }}
      </div>
    </div>
    <div class="tu-card p-4 text-center">
      <div class="text-xl font-semibold" :style="{ color: 'var(--exito)' }">
        {{ resumen.validas }}
      </div>
      <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("importar.validas") }}
      </div>
    </div>
    <div class="tu-card p-4 text-center">
      <div class="text-xl font-semibold">{{ resumen.existentes ?? 0 }}</div>
      <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("importar.existentes") }}
      </div>
    </div>
    <div class="tu-card p-4 text-center">
      <div
        class="text-xl font-semibold"
        :style="{
          color: resumen.invalidas > 0 ? 'var(--error)' : 'var(--texto-suave)',
        }"
      >
        {{ resumen.invalidas }}
      </div>
      <div class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("importar.invalidas") }}
      </div>
    </div>
  </div>
</template>
