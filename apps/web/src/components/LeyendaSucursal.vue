<script setup lang="ts">
import { computed } from "vue";

import { useSucursales } from "@/lib/sucursalOperativa";

/**
 * «Se registra en …»: en los formularios, con una sucursal elegida en la barra (y
 * más de una en el negocio), dice dónde queda lo que se da de alta, porque su
 * selector ya no se muestra. Con una sola sucursal no hace falta decirlo.
 */
const sucursales = useSucursales();
const visible = computed(
  () => sucursales.varias.value && sucursales.actual.value !== null,
);
</script>

<template>
  <p v-if="visible" class="ls" data-prueba="leyenda-sucursal">
    <span class="ls-punto" aria-hidden="true"></span>
    {{
      $t("sucursalOperativa.seRegistraEn", {
        sucursal: sucursales.actual.value?.nombre,
      })
    }}
  </p>
</template>

<style scoped>
.ls {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.ls-punto {
  position: relative;
  width: 0.45rem;
  height: 0.45rem;
  flex-shrink: 0;
  border-radius: 999px;
  background: var(--exito);
}
.ls-punto::after {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: inherit;
  background: var(--exito);
  animation: ls-latido 1.8s ease-out infinite;
}
@keyframes ls-latido {
  0% {
    transform: scale(1);
    opacity: 0.55;
  }
  80%,
  100% {
    transform: scale(2.6);
    opacity: 0;
  }
}
@media (prefers-reduced-motion: reduce) {
  .ls-punto::after {
    animation: none;
  }
}
</style>
