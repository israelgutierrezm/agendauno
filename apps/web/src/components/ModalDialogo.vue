<script setup lang="ts">
import { computed, ref } from "vue";

import IconoNav from "@/components/IconoNav.vue";
import { useFocoPanel } from "@/lib/focoPanel";

/**
 * Diálogo centrado del panel. Grande por defecto (`lg`, ~768 px) para que los
 * formularios quepan en dos columnas sin apretarse; `md` para confirmaciones cortas
 * y `xl` para contenido ancho. Se cierra con Escape o tocando el fondo, atrapa el
 * foco y bloquea el scroll de atrás (como `PanelLateral`). Las acciones van en el
 * slot `pie`, alineadas a la derecha.
 */
const props = withDefaults(
  defineProps<{
    abierto: boolean;
    titulo: string;
    tam?: "md" | "lg" | "xl";
  }>(),
  { tam: "lg" },
);
const emit = defineEmits<{ cerrar: [] }>();

const panel = ref<HTMLElement | null>(null);
const capa = useFocoPanel(
  () => props.abierto,
  panel,
  () => emit("cerrar"),
);

const ancho = computed(
  () => ({ md: "max-w-lg", lg: "max-w-3xl", xl: "max-w-5xl" })[props.tam],
);
</script>

<template>
  <Teleport to="body">
    <Transition name="tu-modal">
      <div
        v-if="abierto"
        class="fixed inset-0 flex items-center justify-center p-4"
        :style="{ zIndex: capa }"
      >
        <div class="absolute inset-0 bg-black/50" @click="emit('cerrar')" />
        <section
          ref="panel"
          class="tu-modal-panel relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-2xl shadow-xl"
          :class="ancho"
          :style="{ background: 'var(--superficie)' }"
          role="dialog"
          aria-modal="true"
          :aria-label="titulo"
          tabindex="-1"
        >
          <header
            class="flex items-center justify-between gap-3 border-b px-6 py-4"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <h2 class="text-lg font-semibold">{{ titulo }}</h2>
            <button
              class="tu-icono-btn"
              type="button"
              :aria-label="$t('comun.cerrar')"
              @click="emit('cerrar')"
            >
              <IconoNav nombre="cerrar" :tam="18" />
            </button>
          </header>

          <div class="flex-1 overflow-y-auto px-6 py-5">
            <slot />
          </div>

          <footer
            v-if="$slots.pie"
            class="flex flex-wrap items-center justify-end gap-2 border-t px-6 py-4"
            :style="{ borderColor: 'var(--borde)' }"
          >
            <slot name="pie" />
          </footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.tu-modal-enter-active,
.tu-modal-leave-active {
  transition: opacity 0.18s ease;
}
.tu-modal-enter-active .tu-modal-panel,
.tu-modal-leave-active .tu-modal-panel {
  transition: transform 0.18s ease;
}
.tu-modal-enter-from,
.tu-modal-leave-to {
  opacity: 0;
}
.tu-modal-enter-from .tu-modal-panel,
.tu-modal-leave-to .tu-modal-panel {
  transform: translateY(8px) scale(0.99);
}
@media (prefers-reduced-motion: reduce) {
  .tu-modal-enter-active,
  .tu-modal-leave-active,
  .tu-modal-enter-active .tu-modal-panel,
  .tu-modal-leave-active .tu-modal-panel {
    transition: none;
  }
}
</style>
