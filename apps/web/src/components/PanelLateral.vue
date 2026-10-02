<script setup lang="ts">
import { ref } from "vue";

import IconoNav from "@/components/IconoNav.vue";
import { useFocoPanel } from "@/lib/focoPanel";

/**
 * Ventana para altas, ediciones y detalles sin salir de la lista. Por omisión es un
 * modal centrado y amplio (~768 px); solo la apariencia la usa anclada a la derecha
 * (`lateral`), para ver el cambio de tema sobre la pantalla. Se cierra con Escape o
 * tocando el fondo, atrapa el foco y bloquea el scroll de atrás. El contenido va en
 * el slot por defecto y las acciones en el slot `pie`.
 */
const props = defineProps<{
  abierto: boolean;
  titulo?: string;
  lateral?: boolean;
}>();
const emit = defineEmits<{ cerrar: [] }>();

const panel = ref<HTMLElement | null>(null);
const capa = useFocoPanel(
  () => props.abierto,
  panel,
  () => emit("cerrar"),
);
</script>

<template>
  <Teleport to="body">
    <Transition :name="lateral ? 'tu-drawer' : 'tu-ventana'">
      <div
        v-if="abierto"
        class="fixed inset-0 flex"
        :style="{ zIndex: capa }"
        :class="lateral ? 'justify-end' : 'items-center justify-center p-4'"
      >
        <div class="absolute inset-0 bg-black/50" @click="emit('cerrar')" />
        <aside
          ref="panel"
          class="tu-drawer-panel relative flex w-full flex-col overflow-y-auto shadow-xl"
          :class="
            lateral ? 'h-full max-w-md' : 'max-h-[92vh] max-w-3xl rounded-xl'
          "
          :style="{ background: 'var(--superficie)' }"
          role="dialog"
          aria-modal="true"
          :aria-label="titulo || $t('comun.detalle')"
          tabindex="-1"
        >
          <header
            class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b"
            :class="lateral ? 'p-5' : 'px-6 py-4'"
            :style="{
              borderColor: 'var(--borde)',
              background: 'var(--superficie)',
            }"
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

          <div class="flex-1" :class="lateral ? 'p-5' : 'px-6 py-5'">
            <slot />
          </div>

          <footer
            v-if="$slots.pie"
            class="sticky bottom-0 border-t"
            :class="lateral ? 'p-5' : 'px-6 py-4'"
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
.tu-drawer-leave-active,
.tu-ventana-enter-active,
.tu-ventana-leave-active {
  transition: opacity 0.2s ease;
}
.tu-drawer-enter-active .tu-drawer-panel,
.tu-drawer-leave-active .tu-drawer-panel {
  transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.tu-ventana-enter-active .tu-drawer-panel,
.tu-ventana-leave-active .tu-drawer-panel {
  transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.tu-drawer-enter-from,
.tu-drawer-leave-to,
.tu-ventana-enter-from,
.tu-ventana-leave-to {
  opacity: 0;
}
.tu-drawer-enter-from .tu-drawer-panel,
.tu-drawer-leave-to .tu-drawer-panel {
  transform: translateX(100%);
}
.tu-ventana-enter-from .tu-drawer-panel,
.tu-ventana-leave-to .tu-drawer-panel {
  transform: translateY(0.75rem) scale(0.98);
}
@media (prefers-reduced-motion: reduce) {
  .tu-drawer-enter-active,
  .tu-drawer-leave-active,
  .tu-drawer-enter-active .tu-drawer-panel,
  .tu-drawer-leave-active .tu-drawer-panel,
  .tu-ventana-enter-active,
  .tu-ventana-leave-active,
  .tu-ventana-enter-active .tu-drawer-panel,
  .tu-ventana-leave-active .tu-drawer-panel {
    transition: none;
  }
}
</style>
