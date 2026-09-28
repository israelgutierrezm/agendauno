<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";
import { useToastStore, type TipoToast } from "@/stores/toast";

const toast = useToastStore();

const COLOR: Record<TipoToast, string> = {
  exito: "var(--exito)",
  error: "var(--error)",
  aviso: "var(--aviso)",
  info: "var(--primario)",
};

// Íconos (heroicons v2, outline) por tipo.
const ICONO: Record<TipoToast, string> = {
  exito: "M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z",
  error:
    "M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z",
  aviso:
    "M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z",
  info: "M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z",
};
</script>

<template>
  <Teleport to="body">
    <div
      class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:right-4 sm:items-end"
    >
      <TransitionGroup name="tu-toast">
        <div
          v-for="t in toast.toasts"
          :key="t.id"
          class="tu-card pointer-events-auto flex w-full max-w-[calc(100vw-2rem)] items-start gap-3 p-3 shadow-lg sm:w-96"
          role="status"
          aria-live="polite"
          :style="{
            borderInlineStartWidth: '4px',
            borderInlineStartColor: COLOR[t.tipo],
          }"
        >
          <svg
            class="mt-0.5 h-5 w-5 shrink-0"
            :style="{ color: COLOR[t.tipo] }"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.9"
            stroke="currentColor"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              :d="ICONO[t.tipo]"
            />
          </svg>
          <p class="min-w-0 flex-1 text-sm">{{ t.mensaje }}</p>
          <button
            class="tu-icono-btn shrink-0"
            type="button"
            :aria-label="$t('comun.cerrar')"
            @click="toast.quitar(t.id)"
          >
            <IconoNav nombre="cerrar" :tam="18" />
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.tu-toast-enter-active,
.tu-toast-leave-active {
  transition: all 0.25s ease;
}
.tu-toast-enter-from {
  opacity: 0;
  transform: translateY(-10px);
}
.tu-toast-leave-to {
  opacity: 0;
  transform: translateX(14px);
}
.tu-toast-leave-active {
  position: absolute;
}
</style>
