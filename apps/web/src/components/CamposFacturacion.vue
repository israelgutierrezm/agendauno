<script setup lang="ts">
import { computed } from "vue";

import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Cómo se factura lo que se vende (ADR 0212): el IVA (el del negocio, exento o tasa
 * cero) y, si hace falta, su clave del SAT. Solo en los negocios que facturan.
 */
const iva = defineModel<string>("iva", { default: "general" });
const claveSat = defineModel<string>("claveSat", { default: "" });
const props = defineProps<{ id: string }>();

const sesion = useSesionTenantStore();
const visible = computed(() => sesion.estudio?.factura_posible === true);
</script>

<template>
  <fieldset v-if="visible" class="cf" data-prueba="campos-facturacion">
    <div>
      <label class="tu-label" :for="`${props.id}-iva`">{{
        $t("cfdi.iva.etiqueta")
      }}</label>
      <select :id="`${props.id}-iva`" v-model="iva" class="tu-input">
        <option value="general">{{ $t("cfdi.iva.general") }}</option>
        <option value="exento">{{ $t("cfdi.iva.exento") }}</option>
        <option value="cero">{{ $t("cfdi.iva.cero") }}</option>
      </select>
      <p class="cf-ayuda">{{ $t("cfdi.iva.ayuda") }}</p>
    </div>
    <div>
      <label class="tu-label" :for="`${props.id}-clave`">{{
        $t("cfdi.iva.claveSat")
      }}</label>
      <input
        :id="`${props.id}-clave`"
        v-model="claveSat"
        class="tu-input"
        inputmode="numeric"
        maxlength="8"
        pattern="[0-9]{8}"
        autocomplete="off"
      />
      <p class="cf-ayuda">{{ $t("cfdi.iva.claveSatAyuda") }}</p>
    </div>
  </fieldset>
</template>

<style scoped>
.cf {
  display: grid;
  gap: 1rem;
  border: 0;
  padding: 0;
  margin: 0;
}
@media (min-width: 640px) {
  .cf {
    grid-template-columns: 1fr 1fr;
  }
}
.cf-ayuda {
  margin-top: 0.25rem;
  color: var(--texto-suave);
  font-size: 0.75rem;
  line-height: 1.5;
}
</style>
