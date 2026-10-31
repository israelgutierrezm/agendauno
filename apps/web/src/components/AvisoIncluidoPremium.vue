<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Lo que el plan del negocio no incluye (ADR 0107): se dice antes de llenar nada y, a
 * quien ve la suscripción, se le lleva a subir de plan.
 */
const sesion = useSesionTenantStore();
const veRenta = computed(() => sesion.puede("facturacion.ver"));
</script>

<template>
  <AvisoTono
    tono="info"
    :titulo="$t('suscripcion.plan.incluidoPremium')"
    data-prueba="incluido-premium"
  >
    {{ $t("suscripcion.plan.incluidoPremiumAyuda") }}
    <template v-if="veRenta" #acciones>
      <RouterLink
        :to="{ name: 'renta' }"
        class="tu-btn tu-btn-secundario tu-btn-chico"
      >
        {{ $t("suscripcion.plan.subirDePlan") }}
      </RouterLink>
    </template>
  </AvisoTono>
</template>
