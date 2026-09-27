<script setup lang="ts">
type Variante =
  | "horizontal"
  | "horizontal-slogan"
  | "vertical"
  | "isotipo"
  | "negativo-slogan";

withDefaults(
  defineProps<{
    variante?: Variante;
    ancho?: number;
    alt?: string;
    adaptable?: boolean;
  }>(),
  {
    variante: "horizontal",
    ancho: 150,
    alt: "Agenda Uno",
    adaptable: true,
  },
);
</script>

<template>
  <span
    class="agendauno-logo"
    :class="{ 'agendauno-logo--isotipo': variante === 'isotipo' }"
    :style="{ width: `${ancho}px` }"
  >
    <!-- Recursos institucionales originales, sin filtros ni redibujado. -->
    <img
      class="agendauno-logo__imagen"
      :src="`/assets/brand/agendauno/final-v2/${variante === 'isotipo' ? 'isotipo' : variante === 'horizontal' ? 'logo' : 'logo-slogan'}.png`"
      :alt="alt"
    />
  </span>
</template>

<style scoped>
.agendauno-logo {
  display: inline-block;
  max-width: 100%;
  flex: 0 0 auto;
  background: #fff;
  border-radius: 6px;
}
.agendauno-logo__imagen {
  display: block;
  width: 100%;
  height: auto;
  object-fit: contain;
}
.agendauno-logo--isotipo {
  background: transparent;
}
</style>
