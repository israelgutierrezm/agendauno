<script setup lang="ts">
import { ref } from "vue";

defineProps<{
  id?: string;
  modelValue: string;
  autocomplete?: string;
  required?: boolean;
  minlength?: number;
  placeholder?: string;
}>();

defineEmits<{ (e: "update:modelValue", value: string): void }>();

const visible = ref(false);
</script>

<template>
  <div class="relative">
    <input
      :id="id"
      :value="modelValue"
      :type="visible ? 'text' : 'password'"
      class="tu-input pr-11"
      :autocomplete="autocomplete"
      :required="required"
      :minlength="minlength"
      :placeholder="placeholder"
      @input="
        $emit('update:modelValue', ($event.target as HTMLInputElement).value)
      "
    />
    <button
      type="button"
      class="absolute inset-y-0 right-0 flex items-center px-3"
      :style="{ color: 'var(--texto-suave)' }"
      :aria-label="
        visible ? $t('comun.ocultarPassword') : $t('comun.verPassword')
      "
      :aria-pressed="visible"
      :title="visible ? $t('comun.ocultarPassword') : $t('comun.verPassword')"
      tabindex="-1"
      @click="visible = !visible"
    >
      <svg
        v-if="!visible"
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
        <circle cx="12" cy="12" r="3" />
      </svg>
      <svg
        v-else
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
      >
        <path d="M3 3l18 18" />
        <path d="M10.6 10.6a3 3 0 0 0 4.2 4.2" />
        <path
          d="M9.9 5.1A9.8 9.8 0 0 1 12 5c6.5 0 10 7 10 7a15.7 15.7 0 0 1-3.1 3.9M6.1 6.1A15.7 15.7 0 0 0 2 12s3.5 7 10 7a9.8 9.8 0 0 0 3.2-.5"
        />
      </svg>
    </button>
  </div>
</template>
