<script setup lang="ts">
import { onBeforeUnmount, onMounted } from "vue";

import IconoNav from "@/components/IconoNav.vue";

/**
 * Columna de detalle al estilo de la demo de la landing: fondo gris claro, una
 * etiqueta pequeña, el título y una línea de datos; debajo, el contenido.
 *
 * - `incrustado`: se pinta dentro de la página, junto a la lista (escritorio).
 * - si no: panel superpuesto a la derecha con velo (móvil); Esc lo cierra.
 *
 * El slot `destacado` va bajo el título (p. ej. la píldora con contorno
 * `.md-pastilla`: "2 lugares disponibles").
 */
const props = defineProps<{
  incrustado?: boolean;
  etiqueta?: string;
  titulo: string;
  subtitulo?: string;
  etiquetaCerrar: string;
}>();
const emit = defineEmits<{ cerrar: [] }>();

function alTeclear(e: KeyboardEvent): void {
  if (e.key === "Escape") {
    emit("cerrar");
  }
}
onMounted(() => {
  if (!props.incrustado) {
    window.addEventListener("keydown", alTeclear);
  }
});
onBeforeUnmount(() => window.removeEventListener("keydown", alTeclear));
</script>

<template>
  <div :class="incrustado ? 'md-incrustado' : 'md-capa'">
    <div v-if="!incrustado" class="md-velo" @click="emit('cerrar')" />
    <aside
      class="md-panel"
      :class="{ 'md-flotante': !incrustado }"
      :role="incrustado ? undefined : 'dialog'"
      :aria-modal="incrustado ? undefined : 'true'"
      :aria-label="titulo"
    >
      <header class="md-cabecera">
        <div class="min-w-0">
          <p v-if="etiqueta" class="md-etiqueta">{{ etiqueta }}</p>
          <h2 class="md-titulo">{{ titulo }}</h2>
          <p v-if="subtitulo" class="md-subtitulo">{{ subtitulo }}</p>
          <slot name="destacado" />
        </div>
        <button
          type="button"
          class="tu-icono-btn shrink-0 -mr-2 -mt-1"
          :aria-label="etiquetaCerrar"
          @click="emit('cerrar')"
        >
          <IconoNav nombre="cerrar" :tam="18" />
        </button>
      </header>
      <div class="md-cuerpo">
        <slot />
      </div>
    </aside>
  </div>
</template>

<style scoped>
.md-capa {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  justify-content: flex-end;
}
.md-velo {
  position: absolute;
  inset: 0;
  background: rgb(15 23 42 / 35%);
}
.md-incrustado {
  height: 100%;
}
.md-panel {
  display: flex;
  flex-direction: column;
  min-height: 100%;
  background: var(--fondo);
}
.md-flotante {
  position: relative;
  width: 100%;
  max-width: 26rem;
  height: 100%;
  overflow-y: auto;
  border-left: 1px solid var(--borde);
}
.md-cabecera {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1.5rem 1.25rem 1rem;
}
.md-etiqueta {
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--texto-suave);
}
.md-titulo {
  margin-top: 0.35rem;
  font-size: 1.2rem;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: -0.02em;
}
.md-subtitulo {
  margin-top: 0.35rem;
  font-size: 0.8rem;
  color: var(--texto-suave);
}
.md-cabecera :slotted(.md-pastilla) {
  display: inline-block;
  margin-top: 0.9rem;
  padding: 0.35rem 0.7rem;
  border: 1px solid var(--borde);
  border-radius: 999px;
  background: var(--superficie);
  color: var(--primario-fuerte);
  font-size: 0.72rem;
  font-weight: 500;
}
.md-cabecera :slotted(.md-pastilla-aviso) {
  color: var(--aviso);
}
.md-cuerpo {
  flex: 1;
  padding: 0 1.25rem 1.5rem;
}
</style>
