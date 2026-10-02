<script setup lang="ts">
import { computed } from "vue";

import IconoNav from "@/components/IconoNav.vue";
import { esMiembro } from "@/lib/roles";
import { useSucursales } from "@/lib/sucursalOperativa";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Con qué sucursal se trabaja (barra superior). Con varias: «Todas las sucursales»
 * o una; al elegir una, el panel la usa en filtros y formularios. Con una sola, solo
 * se muestra su nombre. Los clientes (su cuenta) no lo ven.
 */
const sesion = useSesionTenantStore();
const store = useSucursales();
const visible = computed(
  () => !esMiembro(sesion.usuario) && store.lista.value.length > 0,
);
function elegir(evento: Event): void {
  store.elegir((evento.target as HTMLSelectElement).value || null);
}
</script>

<template>
  <label
    v-if="visible && store.varias.value"
    class="tu-select-icono ss"
    :title="$t('sucursalOperativa.ayuda')"
  >
    <IconoNav nombre="ubicacion" :tam="16" />
    <select
      class="tu-input ss-select"
      :value="store.fija.value ?? ''"
      :aria-label="$t('sucursalOperativa.etiqueta')"
      data-prueba="selector-sucursal"
      @change="elegir"
    >
      <option value="">{{ $t("sucursalOperativa.todas") }}</option>
      <option v-for="s in store.lista.value" :key="s.id" :value="s.id">
        {{ s.nombre }}
      </option>
    </select>
  </label>
  <span
    v-else-if="visible"
    class="ss-unica"
    :title="$t('sucursalOperativa.etiqueta')"
    data-prueba="sucursal-unica"
  >
    <IconoNav nombre="ubicacion" :tam="15" />
    {{ store.actual.value?.nombre }}
  </span>
</template>

<style scoped>
.ss-select {
  min-height: 2.25rem;
  padding-top: 0.3rem;
  padding-bottom: 0.3rem;
  font-size: 0.875rem;
  max-width: 15rem;
}
.ss-unica {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  color: var(--texto-suave);
  font-size: 0.875rem;
  white-space: nowrap;
}
</style>
