<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";

/**
 * «Actualizado hace 3 min · Actualizar»: cuándo se trajeron los datos que se ven y un
 * botón discreto para volver a pedirlos (tableros que cambian mientras el negocio
 * opera). El tiempo se recalcula solo.
 */
const props = defineProps<{ en: Date | null; actualizando?: boolean }>();
const emit = defineEmits<{ actualizar: [] }>();

const { t } = useI18n();
const ahora = ref(Date.now());
let reloj: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
  reloj = setInterval(() => {
    ahora.value = Date.now();
  }, 30_000);
});
onUnmounted(() => clearInterval(reloj));

const texto = computed(() => {
  if (props.en === null) {
    return "";
  }
  const minutos = Math.floor((ahora.value - props.en.getTime()) / 60_000);
  if (minutos < 1) {
    return t("actualizado.recien");
  }
  if (minutos < 60) {
    return t("actualizado.minutos", { n: minutos });
  }
  return t("actualizado.horas", { n: Math.floor(minutos / 60) });
});
</script>

<template>
  <p class="ah" data-prueba="actualizado-hace">
    <span v-if="en" :title="en.toLocaleString('es-MX')">{{ texto }}</span>
    <button
      type="button"
      class="ah-boton"
      :disabled="actualizando"
      data-prueba="actualizar"
      @click="emit('actualizar')"
    >
      <IconoNav
        nombre="actualizar"
        :tam="14"
        :class="{ 'ah-girando': actualizando }"
      />
      {{
        actualizando ? $t("actualizado.actualizando") : $t("actualizado.boton")
      }}
    </button>
  </p>
</template>

<style scoped>
.ah {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.25rem 0.75rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.ah-boton {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.2rem 0.1rem;
  color: var(--texto-suave);
  font-weight: 500;
}
.ah-boton:hover:not(:disabled) {
  color: var(--texto);
}
.ah-boton:disabled {
  cursor: progress;
}
.ah-girando {
  animation: ah-girar 0.9s linear infinite;
}
@keyframes ah-girar {
  to {
    transform: rotate(360deg);
  }
}
@media (prefers-reduced-motion: reduce) {
  .ah-girando {
    animation: none;
  }
}
</style>
