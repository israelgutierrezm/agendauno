<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import type { Parametro } from "@/lib/parametros";

/**
 * Editor de parámetros configurables (ADR 0042), agrupados. En el negocio, un campo
 * vacío usa el valor de la plataforma (se muestra como referencia); en la plataforma,
 * un campo vacío usa el inicial. Solo se envía lo que cambió.
 */
const props = defineProps<{
  parametros: Parametro[];
  modo: "negocio" | "plataforma";
  guardando?: boolean;
}>();

const emit = defineEmits<{
  guardar: [valores: Record<string, number | null>];
}>();

const { t } = useI18n();

// Lo que hay en cada campo ("" = usa el de referencia).
const campos = ref<Record<string, string>>({});
function reiniciar(): void {
  campos.value = Object.fromEntries(
    props.parametros.map((p) => [
      p.clave,
      p.valor === null ? "" : String(p.valor),
    ]),
  );
}
watch(() => props.parametros, reiniciar, { immediate: true });

const grupos = computed(() => {
  const mapa = new Map<string, Parametro[]>();
  for (const p of props.parametros) {
    mapa.set(p.grupo, [...(mapa.get(p.grupo) ?? []), p]);
  }
  return [...mapa.entries()];
});

function referencia(p: Parametro): number {
  return (props.modo === "negocio" ? p.plataforma : p.defecto) ?? 0;
}
function texto(p: Parametro, valor: number): string {
  if (p.tipo === "si_no") {
    return valor === 1 ? t("parametrosConfig.si") : t("parametrosConfig.no");
  }
  return p.unidad ? `${valor} ${p.unidad}` : String(valor);
}

const cambios = computed(() => {
  const out: Record<string, number | null> = {};
  for (const p of props.parametros) {
    const actual = campos.value[p.clave] ?? "";
    const nuevo = actual === "" ? null : Number(actual);
    if (nuevo !== p.valor) {
      out[p.clave] = nuevo;
    }
  }
  return out;
});
const hayCambios = computed(() => Object.keys(cambios.value).length > 0);
</script>

<template>
  <form class="space-y-6" @submit.prevent="emit('guardar', cambios)">
    <fieldset v-for="[grupo, lista] in grupos" :key="grupo">
      <legend class="text-sm font-semibold">{{ grupo }}</legend>
      <div
        class="mt-2 divide-y divide-[var(--borde)] border-y"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <div
          v-for="p in lista"
          :key="p.clave"
          class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
        >
          <label :for="`par-${p.clave}`" class="min-w-0 flex-1">
            <span class="block font-medium">{{ p.etiqueta }}</span>
            <span class="block text-xs" :style="{ color: 'var(--texto-suave)' }"
              >{{ p.ayuda ? `${p.ayuda} ` : ""
              }}{{
                modo === "negocio"
                  ? $t("parametrosConfig.dePlataforma", {
                      valor: texto(p, referencia(p)),
                    })
                  : $t("parametrosConfig.inicial", {
                      valor: texto(p, referencia(p)),
                    })
              }}</span
            >
          </label>
          <select
            v-if="p.tipo === 'si_no'"
            :id="`par-${p.clave}`"
            v-model="campos[p.clave]"
            class="tu-input w-auto"
          >
            <option value="">
              {{ $t("parametrosConfig.usarReferencia") }}
            </option>
            <option value="1">{{ $t("parametrosConfig.si") }}</option>
            <option value="0">{{ $t("parametrosConfig.no") }}</option>
          </select>
          <span v-else class="flex items-center gap-2 shrink-0">
            <input
              :id="`par-${p.clave}`"
              v-model="campos[p.clave]"
              type="number"
              :min="p.minimo"
              :max="p.maximo"
              step="1"
              class="tu-input w-24"
              :placeholder="String(referencia(p))"
            />
            <span
              class="w-10 text-xs"
              :style="{ color: 'var(--texto-suave)' }"
              >{{ p.unidad }}</span
            >
          </span>
        </div>
      </div>
    </fieldset>

    <div class="flex flex-wrap items-center gap-2">
      <button
        type="submit"
        class="tu-btn tu-btn-primario"
        :disabled="guardando || !hayCambios"
      >
        {{ $t("parametrosConfig.guardar") }}
      </button>
      <button
        v-if="hayCambios"
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="guardando"
        @click="reiniciar"
      >
        {{ $t("parametrosConfig.descartar") }}
      </button>
    </div>
  </form>
</template>
