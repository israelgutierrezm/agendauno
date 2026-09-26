<script setup lang="ts">
defineProps<{ filtrado: boolean; mensaje?: string }>();
defineEmits<{ restablecer: [] }>();
</script>

<template>
  <div class="px-5 py-10 text-center" role="status">
    <svg
      class="mx-auto mb-3 h-8 w-8"
      :style="{ color: 'var(--texto-suave)' }"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="1.5"
      aria-hidden="true"
    >
      <circle cx="10.5" cy="10.5" r="6.5" />
      <path d="m16 16 4 4M8 10.5h5" stroke-linecap="round" />
    </svg>
    <p class="font-medium">
      {{
        filtrado
          ? $t("tabla.sinCoincidencias")
          : (mensaje ?? $t("tabla.sinRegistros"))
      }}
    </p>
    <p
      v-if="filtrado"
      class="mx-auto mt-2 max-w-sm text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("tabla.ajustarBusqueda") }}
    </p>
    <button
      v-if="filtrado"
      type="button"
      class="tu-btn tu-btn-fantasma mt-4"
      @click="$emit('restablecer')"
    >
      {{ $t("tabla.restablecer") }}
    </button>
  </div>
</template>
