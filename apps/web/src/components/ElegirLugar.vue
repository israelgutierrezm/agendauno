<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";

/**
 * Elegir un lugar numerado de la clase (plan de mejoras B06): un botón por número, con
 * su estado dicho con texto (libre, ocupado, el tuyo) y no solo con color; se usa con
 * teclado. Los ocupados no dicen de quién son. El servidor vuelve a validar al guardar.
 */
const props = defineProps<{
  total: number;
  ocupados: number[];
  /** El suyo actual (al cambiarlo, el suyo no cuenta como ocupado). */
  mio?: number | null;
}>();
const elegido = defineModel<number | null>({ default: null });

const { t } = useI18n();
const tomados = computed(
  () => new Set(props.ocupados.filter((n) => n !== props.mio)),
);
const numeros = computed(() =>
  Array.from({ length: props.total }, (_, i) => i + 1),
);
function estado(n: number): "tuyo" | "ocupado" | "libre" {
  if (elegido.value === n) {
    return "tuyo";
  }
  return tomados.value.has(n) ? "ocupado" : "libre";
}
function alternar(n: number): void {
  if (tomados.value.has(n)) {
    return;
  }
  elegido.value = elegido.value === n ? null : n;
}
const libres = computed(() => props.total - tomados.value.size);
</script>

<template>
  <div class="space-y-2" data-prueba="elegir-lugar">
    <div
      class="el-lugares"
      role="group"
      :aria-label="t('portal.lugares.titulo')"
    >
      <button
        v-for="n in numeros"
        :key="n"
        type="button"
        class="el-lugar"
        :class="`el-${estado(n)}`"
        :aria-pressed="elegido === n"
        :disabled="tomados.has(n)"
        :aria-label="t(`portal.lugares.aria.${estado(n)}`, { n })"
        :data-lugar="n"
        @click="alternar(n)"
      >
        {{ n }}
      </button>
    </div>
    <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
      {{ t("portal.lugares.leyenda", { libres }) }}
    </p>
    <p v-if="elegido" class="text-sm font-medium" role="status">
      {{
        elegido === mio
          ? t("portal.lugares.actual", { n: elegido })
          : t("portal.lugares.elegido", { n: elegido })
      }}
    </p>
  </div>
</template>

<style scoped>
.el-lugares {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(2.75rem, 1fr));
  gap: 0.4rem;
}
.el-lugar {
  min-height: 2.75rem;
  border: 1px solid var(--borde);
  border-radius: 0.6rem;
  background: var(--superficie);
  font-variant-numeric: tabular-nums;
  font-weight: 500;
}
.el-lugar:focus-visible {
  outline: 2px solid var(--primario);
  outline-offset: 2px;
}
/* El suyo: relleno y borde grueso. Ocupado: tachado y con trama, no solo gris. */
.el-tuyo {
  border: 2px solid var(--primario);
  background: color-mix(in srgb, var(--primario) 14%, var(--superficie));
}
.el-ocupado {
  color: var(--texto-suave);
  text-decoration: line-through;
  border-style: dashed;
  background: repeating-linear-gradient(
    135deg,
    var(--superficie-2),
    var(--superficie-2) 4px,
    var(--superficie) 4px,
    var(--superficie) 8px
  );
  cursor: not-allowed;
}
</style>
