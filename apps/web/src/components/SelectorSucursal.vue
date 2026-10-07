<script setup lang="ts">
import { computed } from "vue";

import IconoNav from "@/components/IconoNav.vue";
import { esMiembro } from "@/lib/roles";
import { useSucursales } from "@/lib/sucursalOperativa";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Con qué sucursal se trabaja, en la barra superior (en lugar del título de la
 * página). Con varias: «Todas las sucursales» o una; con una elegida se ve «Operando
 * en» con un punto que late, para que se note en todo momento. Con una sola sucursal
 * se ve igual, sin lista que abrir. Va en lugar del título de la barra. Los clientes
 * (su cuenta) no lo ven.
 *
 * El `select` nativo va encima, transparente: se abre y se usa con el teclado como
 * cualquier lista; lo que se ve es la etiqueta de abajo.
 */
const props = withDefaults(defineProps<{ variante?: "barra" | "unica" }>(), {
  variante: "barra",
});

const sesion = useSesionTenantStore();
const sucursales = useSucursales();
const visible = computed(
  () => !esMiembro(sesion.usuario) && sucursales.lista.value.length > 0,
);
const mostrarSelector = computed(
  () => visible.value && props.variante === "barra" && sucursales.varias.value,
);
const mostrarUnica = computed(
  () => visible.value && props.variante === "unica" && !sucursales.varias.value,
);
function elegir(evento: Event): void {
  sucursales.elegir((evento.target as HTMLSelectElement).value || null);
}
</script>

<template>
  <div
    v-if="mostrarSelector"
    class="ss"
    :class="{ 'ss-fija': sucursales.fija.value !== null }"
    :title="$t('sucursalOperativa.ayuda')"
  >
    <span
      v-if="sucursales.fija.value !== null"
      class="ss-vivo"
      aria-hidden="true"
    ></span>
    <span v-else class="ss-icono" aria-hidden="true">
      <IconoNav nombre="ubicacion" :tam="16" />
    </span>
    <span class="ss-texto">
      <span class="ss-etiqueta">{{
        sucursales.fija.value !== null
          ? $t("sucursalOperativa.operandoEn")
          : $t("sucursalOperativa.etiquetaCorta")
      }}</span>
      <span class="ss-nombre" data-prueba="sucursal-actual">{{
        sucursales.actual.value?.nombre ?? $t("sucursalOperativa.todas")
      }}</span>
    </span>
    <IconoNav class="ss-flecha" nombre="chevron" :tam="14" />
    <select
      class="ss-select"
      :value="sucursales.fija.value ?? ''"
      :aria-label="$t('sucursalOperativa.etiqueta')"
      data-prueba="selector-sucursal"
      @change="elegir"
    >
      <option value="">{{ $t("sucursalOperativa.todas") }}</option>
      <option v-for="s in sucursales.lista.value" :key="s.id" :value="s.id">
        {{ s.nombre }}
      </option>
    </select>
  </div>
  <!-- Con una sola sucursal: se opera siempre en ella (sin lista que abrir). -->
  <div
    v-else-if="mostrarUnica"
    class="ss ss-fija ss-estatica"
    :title="$t('sucursalOperativa.etiqueta')"
  >
    <span class="ss-vivo" aria-hidden="true"></span>
    <span class="ss-texto">
      <span class="ss-etiqueta">{{ $t("sucursalOperativa.operandoEn") }}</span>
      <span class="ss-nombre" data-prueba="sucursal-unica">{{
        sucursales.actual.value?.nombre
      }}</span>
    </span>
  </div>
</template>

<style scoped>
.ss {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
  max-width: 22rem;
  padding: 0.3rem 0.75rem 0.3rem 0.55rem;
  border: 1px solid var(--borde);
  border-radius: 0.6rem;
  background: var(--superficie);
}
.ss:hover {
  border-color: color-mix(in srgb, var(--texto-suave) 45%, var(--borde));
}
.ss:focus-within {
  outline: 2px solid var(--primario);
  outline-offset: 1px;
}
/* Operando con una sucursal: el borde y el punto lo dicen. */
.ss-fija {
  border-color: color-mix(in srgb, var(--exito) 45%, var(--borde));
}
.ss-icono {
  display: grid;
  place-items: center;
  width: 1.6rem;
  height: 1.6rem;
  color: var(--texto-suave);
}
/* El punto «en vivo»: late como el de una grabación. */
.ss-vivo {
  position: relative;
  width: 0.6rem;
  height: 0.6rem;
  margin: 0 0.5rem 0 0.45rem;
  flex-shrink: 0;
  border-radius: 999px;
  background: var(--exito);
}
.ss-vivo::after {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: inherit;
  background: var(--exito);
  animation: ss-latido 1.8s ease-out infinite;
}
@keyframes ss-latido {
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
  .ss-vivo::after {
    animation: none;
  }
}
.ss-texto {
  display: grid;
  min-width: 0;
  line-height: 1.15;
}
.ss-etiqueta {
  color: var(--texto-suave);
  font-size: 0.72rem;
}
.ss-nombre {
  overflow: hidden;
  color: var(--texto);
  font-size: 0.98rem;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ss-flecha {
  flex-shrink: 0;
  color: var(--texto-suave);
  transform: rotate(90deg);
}
/* El select nativo, invisible, encima de todo: abre la lista al tocar. */
.ss-select {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: 0;
  cursor: pointer;
}
/* Una sola sucursal: se ve igual que el selector fijo, sin lista que abrir. */
.ss-estatica,
.ss-estatica:hover {
  border-color: color-mix(in srgb, var(--exito) 45%, var(--borde));
  cursor: default;
}
</style>
