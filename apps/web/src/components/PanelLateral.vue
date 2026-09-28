<script setup lang="ts">
import { ref } from "vue";

import IconoNav from "@/components/IconoNav.vue";
import { useFocoPanel } from "@/lib/focoPanel";

/**
 * Panel lateral deslizante (drawer) anclado a la DERECHA, para altas/ediciones
 * sin salir de la lista. Se cierra con la tecla Escape o tocando el fondo, y
 * bloquea el scroll de la página mientras está abierto. Reutilizable en cualquier
 * vista: el contenido va en el slot por defecto y las acciones en el slot `pie`.
 */
const props = defineProps<{ abierto: boolean; titulo?: string }>();
const emit = defineEmits<{ cerrar: [] }>();

const panel = ref<HTMLElement | null>(null);
useFocoPanel(
  () => props.abierto,
  panel,
  () => emit("cerrar"),
);
</script>

<template>
  <Teleport to="body">
    <Transition name="tu-drawer">
      <div v-if="abierto" class="fixed inset-0 z-50 flex justify-end">
        <div class="absolute inset-0 bg-black/50" @click="emit('cerrar')" />
        <aside
          ref="panel"
          class="tu-drawer-panel relative flex h-full w-full max-w-md flex-col overflow-y-auto shadow-xl"
          :style="{ background: 'var(--superficie)' }"
          role="dialog"
          aria-modal="true"
          :aria-label="titulo || $t('comun.detalle')"
          tabindex="-1"
        >
          <header
            class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b p-5"
            :style="{
              borderColor: 'var(--borde)',
              background: 'var(--superficie)',
            }"
          >
            <h2 class="text-lg font-light">{{ titulo }}</h2>
            <button
              class="tu-icono-btn"
              type="button"
              :aria-label="$t('comun.cerrar')"
              @click="emit('cerrar')"
            >
              <IconoNav nombre="cerrar" :tam="18" />
            </button>
          </header>

          <div class="flex-1 p-5">
            <slot />
          </div>

          <footer
            v-if="$slots.pie"
            class="sticky bottom-0 border-t p-5"
            :style="{
              borderColor: 'var(--borde)',
              background: 'var(--superficie)',
            }"
          >
            <slot name="pie" />
          </footer>
        </aside>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.tu-drawer-enter-active,
.tu-drawer-leave-active {
  transition: opacity 0.2s ease;
}
.tu-drawer-enter-active .tu-drawer-panel,
.tu-drawer-leave-active .tu-drawer-panel {
  transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.tu-drawer-enter-from,
.tu-drawer-leave-to {
  opacity: 0;
}
.tu-drawer-enter-from .tu-drawer-panel,
.tu-drawer-leave-to .tu-drawer-panel {
  transform: translateX(100%);
}
@media (prefers-reduced-motion: reduce) {
  .tu-drawer-enter-active,
  .tu-drawer-leave-active,
  .tu-drawer-enter-active .tu-drawer-panel,
  .tu-drawer-leave-active .tu-drawer-panel {
    transition: none;
  }
}
</style>
