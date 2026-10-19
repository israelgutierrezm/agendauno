<script setup lang="ts">
import { computed } from "vue";

import { PRODUCTOS, productoActual, productoDeModalidad } from "@/lib/producto";

/**
 * La leyenda de la plataforma en toda página pública de un negocio (ADR 0167): su
 * sitio, sus páginas, sus enlaces, sus formularios y sus widgets. No depende de ningún
 * ajuste del negocio, así que ni el editor del sitio ni un plan la quitan. Es la marca
 * de su producto (AgendaUno o TurnoUno).
 */
const props = defineProps<{ modalidad?: string | null }>();

const producto = computed(() =>
  props.modalidad ? productoDeModalidad(props.modalidad) : productoActual(),
);
const datos = computed(() => PRODUCTOS[producto.value]);
</script>

<template>
  <a
    class="tu-hecho-con"
    :href="`https://${datos.dominio}`"
    target="_blank"
    rel="noopener"
    data-prueba="hecho-con"
  >
    {{ $t("nav.hechoCon", { marca: datos.nombre }) }}
  </a>
</template>

<style scoped>
.tu-hecho-con {
  display: inline-block;
  font-size: 0.8125rem;
  color: var(--texto-suave);
  text-decoration: none;
}
.tu-hecho-con:hover,
.tu-hecho-con:focus-visible {
  color: var(--texto);
  text-decoration: underline;
}
</style>
