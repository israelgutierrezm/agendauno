<script setup lang="ts" generic="T extends string">
/**
 * Pestañas de segundo nivel de una pantalla (Reportes: Resumen, Ingresos…), justo
 * debajo de su encabezado y antes de avisos y filtros. La elegida es el v-model, que
 * viene de `usePestanaEnUrl` (lib/filtrosEnUrl.ts): así queda en la dirección. Con una
 * sola opción no se muestran; en el teléfono, una lista. La navegación principal de
 * un área es PestanasArea (lib/menu.ts) y Configuración tiene la suya; en un diálogo
 * se usan botones `.tu-pestanas` en memoria.
 */
defineProps<{
  opciones: { clave: T; texto: string; cuenta?: number | string }[];
  // Qué se elige (lectores de pantalla), p. ej. «Secciones de reportes».
  etiqueta: string;
}>();
const pestana = defineModel<T>({ required: true });
</script>

<template>
  <nav
    v-if="opciones.length > 1"
    class="ps mt-6"
    :aria-label="etiqueta"
    data-prueba="pestanas-seccion"
  >
    <div class="tu-pestanas ps-pestanas">
      <button
        v-for="op in opciones"
        :key="op.clave"
        type="button"
        :aria-pressed="pestana === op.clave"
        :data-pestana="op.clave"
        @click="pestana = op.clave"
      >
        {{ op.texto
        }}<span v-if="op.cuenta !== undefined" class="tu-badge ml-2">{{
          op.cuenta
        }}</span>
      </button>
    </div>
    <select
      class="tu-input ps-lista"
      :value="pestana"
      :aria-label="etiqueta"
      @change="pestana = ($event.target as HTMLSelectElement).value as T"
    >
      <option v-for="op in opciones" :key="op.clave" :value="op.clave">
        {{ op.texto }}
      </option>
    </select>
  </nav>
</template>

<style scoped>
.ps-lista {
  display: none;
}
/* En el teléfono: la pestaña activa en una lista, no una fila apretada. */
@media (max-width: 639px) {
  .ps-pestanas {
    display: none;
  }
  .ps-lista {
    display: block;
  }
}
</style>
