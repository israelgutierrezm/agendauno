<script setup lang="ts">
import { computed } from "vue";

type Variante =
  | "horizontal"
  | "horizontal-slogan"
  | "vertical"
  | "isotipo"
  | "negativo-slogan";

const props = withDefaults(
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

const RUTA = "/assets/brand/agendauno/final-v2";
const archivo = computed(() =>
  props.variante === "isotipo"
    ? "isotipo"
    : props.variante === "horizontal"
      ? "logo"
      : "logo-slogan",
);
// El logo horizontal tiene versión blanca: en modo oscuro se usa esa, sin placa.
const conVersionOscura = computed(
  () => props.adaptable && props.variante === "horizontal",
);
</script>

<template>
  <span
    class="agendauno-logo"
    :class="{
      'agendauno-logo--isotipo': variante === 'isotipo',
      'agendauno-logo--adaptable': conVersionOscura,
    }"
    :style="{ width: `${ancho}px` }"
  >
    <!-- Recursos institucionales originales, sin filtros ni redibujado. -->
    <img
      class="agendauno-logo__imagen"
      :class="{ 'agendauno-logo__imagen--clara': conVersionOscura }"
      :src="`${RUTA}/${archivo}.png`"
      :alt="alt"
    />
    <img
      v-if="conVersionOscura"
      class="agendauno-logo__imagen agendauno-logo__imagen--oscura"
      :src="`${RUTA}/logo-blanco.png`"
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
.agendauno-logo__imagen--oscura {
  display: none;
}
/* `.dark` vive en <html>; el alcance solo marca el último selector. */
.dark .agendauno-logo--adaptable {
  background: transparent;
}
.dark .agendauno-logo__imagen--clara {
  display: none;
}
.dark .agendauno-logo__imagen--oscura {
  display: block;
}
</style>
