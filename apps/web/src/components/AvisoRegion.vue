<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import { puedeEntrar } from "@/lib/acceso";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Lo que solo funciona en pesos mexicanos (ADR 0099): las pasarelas de pago en línea
 * y la facturación a los clientes (esta, además, solo para negocios en México). Se
 * explica claro y se lleva a «País, moneda y zona horaria».
 */
const props = defineProps<{
  // `facturacionPlataforma`: la plataforma aún no factura; no depende del negocio.
  tipo: "pasarelas" | "facturacion" | "facturacionPlataforma";
}>();
const sesion = useSesionTenantStore();
const puedeCambiar = computed(
  () => props.tipo !== "facturacionPlataforma" && puedeEntrar("region", sesion),
);
</script>

<template>
  <div class="ar-aviso" role="status" :data-prueba="`aviso-${props.tipo}`">
    <span class="ar-punto" aria-hidden="true" />
    <div class="min-w-0">
      <p class="font-medium">
        {{ $t(`region.noDisponible.${props.tipo}Titulo`) }}
      </p>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{
          $t(`region.noDisponible.${props.tipo}Detalle`, {
            moneda: sesion.moneda,
          })
        }}
      </p>
      <RouterLink
        v-if="puedeCambiar"
        :to="{ name: 'region' }"
        class="tu-enlace mt-2 inline-block text-sm"
        >{{ $t("region.noDisponible.cambiar") }}</RouterLink
      >
    </div>
  </div>
</template>

<style scoped>
.ar-aviso {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 1rem 1.1rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
}
.ar-punto {
  flex-shrink: 0;
  width: 0.5rem;
  height: 0.5rem;
  margin-top: 0.45rem;
  border-radius: 999px;
  background: var(--aviso);
}
</style>
