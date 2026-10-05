<script setup lang="ts">
import { ref } from "vue";

import IconoNav from "@/components/IconoNav.vue";
import MenuFlotante from "@/components/MenuFlotante.vue";

/**
 * «Columnas» de una tabla: qué columnas ver (la primera, la del nombre, siempre se
 * ve y no aparece aquí). El padre guarda la elección (`useColumnasVisibles`).
 */
defineProps<{ columnas: { clave: string; texto: string }[] }>();
const visibles = defineModel<string[]>({ required: true });

const abierto = ref(false);
const boton = ref<HTMLElement | null>(null);

function alternar(clave: string): void {
  visibles.value = visibles.value.includes(clave)
    ? visibles.value.filter((c) => c !== clave)
    : [...visibles.value, clave];
}
</script>

<template>
  <button
    ref="boton"
    type="button"
    class="tu-btn tu-btn-fantasma shrink-0 text-sm"
    aria-haspopup="menu"
    :aria-expanded="abierto"
    data-prueba="columnas"
    @click="abierto = !abierto"
  >
    <IconoNav nombre="columnas" :tam="16" />
    {{ $t("tabla.columnas") }}
  </button>
  <MenuFlotante :abierto="abierto" :ancla="boton" @cerrar="abierto = false">
    <p class="sc-titulo">{{ $t("tabla.columnasAyuda") }}</p>
    <label
      v-for="c in columnas"
      :key="c.clave"
      class="sc-opcion"
      role="menuitemcheckbox"
      :aria-checked="visibles.includes(c.clave)"
    >
      <input
        type="checkbox"
        :checked="visibles.includes(c.clave)"
        :data-columna="c.clave"
        @change="alternar(c.clave)"
      />
      {{ c.texto }}
    </label>
  </MenuFlotante>
</template>

<style scoped>
.sc-titulo {
  padding: 0.6rem 0.75rem 0.35rem;
  font-size: 0.75rem;
  color: var(--texto-suave);
}
.sc-opcion {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.45rem 0.75rem;
  font-size: 0.875rem;
  cursor: pointer;
}
.sc-opcion:last-child {
  padding-bottom: 0.65rem;
}
.sc-opcion:hover {
  background: var(--superficie-2);
}
</style>
