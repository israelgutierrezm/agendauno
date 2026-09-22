<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";

defineProps<{
  icono?: string;
  titulo: string;
  subtitulo?: string;
  total?: number | string;
}>();
</script>

<template>
  <div class="flex items-start justify-between gap-4 flex-wrap">
    <div class="flex items-center gap-3 min-w-0">
      <span
        v-if="icono"
        class="h-11 w-11 rounded-xl inline-flex items-center justify-center shrink-0"
        :style="{
          background: 'color-mix(in srgb, var(--acento) 12%, transparent)',
          color: 'var(--acento)',
        }"
        aria-hidden="true"
      >
        <IconoNav :nombre="icono" :tam="22" />
      </span>
      <div class="min-w-0">
        <h1
          class="text-xl font-bold leading-tight flex items-center gap-2 flex-wrap"
        >
          {{ titulo }}
          <span
            v-if="total !== undefined"
            class="tu-badge"
            :style="{
              background: 'var(--superficie-2)',
              color: 'var(--texto-suave)',
            }"
            >{{ total }}</span
          >
        </h1>
        <p
          v-if="subtitulo"
          class="mt-0.5 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ subtitulo }}
        </p>
      </div>
    </div>
    <div class="flex items-center gap-2 shrink-0">
      <slot name="acciones" />
    </div>
  </div>
</template>
