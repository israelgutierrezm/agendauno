<script setup lang="ts">
/**
 * Personal sin sucursal asignada en un negocio con varias (ADR 0098): no ve agenda,
 * clientes ni cobros hasta que el administrador le asigne una. Se lo decimos claro.
 */
</script>

<template>
  <div class="tu-aviso-sin-sucursal" role="status" data-prueba="sin-sucursal">
    <span class="tu-aviso-punto" aria-hidden="true" />
    <div class="min-w-0">
      <p class="font-medium">
        {{ $t("sucursalOperativa.sinSucursal.titulo") }}
      </p>
      <p class="mt-0.5 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("sucursalOperativa.sinSucursal.detalle") }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.tu-aviso-sin-sucursal {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  max-width: 72rem;
  margin: 1rem auto 0;
  padding: 0.9rem 1rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
}
.tu-aviso-punto {
  flex-shrink: 0;
  width: 0.5rem;
  height: 0.5rem;
  margin-top: 0.45rem;
  border-radius: 999px;
  background: var(--aviso);
}
</style>
