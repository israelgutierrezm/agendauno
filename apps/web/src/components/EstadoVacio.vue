<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";

/**
 * Lo que se ve cuando una lista o un espacio está vacío: el ícono en un círculo suave
 * (con «+» si ahí se puede crear algo), un título y, opcional, cómo empezar. Las
 * acciones van en el slot por defecto.
 */
withDefaults(
  defineProps<{
    icono: string;
    titulo: string;
    texto?: string;
    mas?: boolean;
    compacto?: boolean;
  }>(),
  { texto: undefined, mas: false, compacto: false },
);
</script>

<template>
  <div class="ev" :class="{ 'ev-compacto': compacto }">
    <span class="ev-circulo" aria-hidden="true">
      <IconoNav :nombre="icono" :tam="compacto ? 26 : 30" />
      <span v-if="mas" class="ev-mas">
        <IconoNav nombre="mas" :tam="12" />
      </span>
    </span>
    <p class="ev-titulo">{{ titulo }}</p>
    <p v-if="texto" class="ev-texto">{{ texto }}</p>
    <div v-if="$slots.default" class="ev-acciones">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.ev {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 2rem 1rem;
}
.ev-compacto {
  padding: 0.5rem;
}
.ev-circulo {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 4.25rem;
  height: 4.25rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--primario) 9%, var(--superficie));
  color: color-mix(in srgb, var(--primario) 75%, var(--texto-suave));
}
.ev-compacto .ev-circulo {
  width: 3.6rem;
  height: 3.6rem;
}
.ev-mas {
  position: absolute;
  right: -0.15rem;
  bottom: -0.15rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.35rem;
  height: 1.35rem;
  border-radius: 999px;
  border: 2px solid var(--superficie);
  background: color-mix(in srgb, var(--primario) 65%, var(--superficie));
  color: #fff;
}
.ev-titulo {
  margin-top: 0.85rem;
  font-size: 0.9rem;
  font-weight: 600;
}
.ev-texto {
  margin-top: 0.25rem;
  max-width: 17rem;
  font-size: 0.8rem;
  line-height: 1.4;
  color: var(--texto-suave);
}
.ev-acciones {
  margin-top: 1rem;
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.5rem;
}
</style>
