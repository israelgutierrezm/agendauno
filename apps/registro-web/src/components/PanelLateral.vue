<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from "vue";

/**
 * Panel lateral deslizante (drawer) anclado a la DERECHA, para altas/ediciones
 * sin salir de la lista. Se cierra con la tecla Escape o tocando el fondo, y
 * bloquea el scroll de la página mientras está abierto. Reutilizable en cualquier
 * vista: el contenido va en el slot por defecto y las acciones en el slot `pie`.
 */
const props = defineProps<{ abierto: boolean; titulo?: string }>();
const emit = defineEmits<{ cerrar: [] }>();

function alTecla(evento: KeyboardEvent): void {
  if (evento.key === "Escape" && props.abierto) {
    emit("cerrar");
  }
}

watch(
  () => props.abierto,
  (abierto) => {
    document.body.style.overflow = abierto ? "hidden" : "";
  },
);

onMounted(() => window.addEventListener("keydown", alTecla));
onBeforeUnmount(() => {
  window.removeEventListener("keydown", alTecla);
  document.body.style.overflow = "";
});
</script>

<template>
  <Teleport to="body">
    <Transition name="tu-drawer">
      <div v-if="abierto" class="fixed inset-0 z-50 flex justify-end">
        <div class="absolute inset-0 bg-black/50" @click="emit('cerrar')" />
        <aside
          class="tu-drawer-panel relative flex h-full w-full max-w-md flex-col overflow-y-auto shadow-xl"
          :style="{ background: 'var(--superficie)' }"
          role="dialog"
          aria-modal="true"
        >
          <header
            class="sticky top-0 z-10 flex items-center justify-between gap-3 border-b p-5"
            :style="{
              borderColor: 'var(--borde)',
              background: 'var(--superficie)',
            }"
          >
            <h2 class="text-lg font-bold">{{ titulo }}</h2>
            <button
              class="tu-icono-btn"
              type="button"
              :aria-label="$t('comun.cerrar')"
              @click="emit('cerrar')"
            >
              ✕
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
</style>
