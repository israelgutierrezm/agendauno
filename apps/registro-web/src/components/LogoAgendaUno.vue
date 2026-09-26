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

const fuente = computed(() => {
  const base = "/assets/brand/agendauno";
  const fuentes: Record<Variante, string> = {
    horizontal: `${base}/agenda_uno_horizontal.png`,
    "horizontal-slogan": `${base}/agenda_uno_horizontal_con_slogan.png`,
    vertical: `${base}/agenda_uno_vertical.png`,
    isotipo: `${base}/isotipo_uno.png`,
    "negativo-slogan": `${base}/agenda_uno_negativo_con_slogan.png`,
  };
  return fuentes[props.variante];
});

const mostrarAlterna = computed(
  () =>
    props.adaptable &&
    ["horizontal", "horizontal-slogan"].includes(props.variante),
);
</script>

<template>
  <span
    class="agendauno-logo"
    :class="{ 'agendauno-logo--adaptable': mostrarAlterna }"
    :style="{ width: `${ancho}px` }"
  >
    <img
      class="agendauno-logo__imagen agendauno-logo__imagen--clara"
      :src="fuente"
      :alt="alt"
      decoding="async"
    />
    <img
      v-if="mostrarAlterna"
      class="agendauno-logo__imagen agendauno-logo__imagen--oscura"
      :src="
        variante === 'horizontal-slogan'
          ? '/assets/brand/agendauno/agenda_uno_negativo_con_slogan.png'
          : '/assets/brand/agendauno/agenda_uno_claro_sobre_fondo_obscuro.png'
      "
      :alt="alt"
      decoding="async"
    />
  </span>
</template>

<style scoped>
.agendauno-logo {
  display: inline-block;
  max-width: 100%;
  flex: 0 0 auto;
}
.agendauno-logo__imagen {
  display: block;
  width: 100%;
  height: auto;
  object-fit: contain;
}
.agendauno-logo__imagen--oscura {
  display: none;
}
:global(.dark) .agendauno-logo--adaptable .agendauno-logo__imagen--clara {
  display: none;
}
:global(.dark) .agendauno-logo--adaptable .agendauno-logo__imagen--oscura {
  display: block;
}
</style>
