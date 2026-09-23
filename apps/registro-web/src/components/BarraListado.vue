<script setup lang="ts">
import { computed, ref } from "vue";

import IconoNav from "@/components/IconoNav.vue";
import type { VistaListado } from "@/lib/vistaListado";

/**
 * Barra de encabezado de un listado (estilo Acadion): buscador + botón de
 * filtros colapsables + botón «Agregar». Orden homólogo en toda la app. El
 * buscador usa v-model:busqueda; los filtros se declaran con `filtros` y sus
 * valores actuales llegan en `valores`, y cada cambio se emite con `cambioFiltro`
 * para que el padre actualice su estado (y recargue el listado).
 */
interface DefinicionFiltro {
  clave: string;
  etiqueta: string;
  tipo?: "select" | "booleano";
  opciones?: { valor: string | number; texto: string }[];
}

const props = withDefaults(
  defineProps<{
    filtros?: DefinicionFiltro[];
    valores?: Record<string, string>;
    placeholder?: string;
    puedeCrear?: boolean;
    nuevoTexto?: string;
    sinBuscador?: boolean;
  }>(),
  {
    filtros: () => [],
    valores: () => ({}),
    placeholder: "Buscar…",
    puedeCrear: false,
    nuevoTexto: "Agregar",
    sinBuscador: false,
  },
);

const emit = defineEmits<{
  cambioFiltro: [clave: string, valor: string];
  limpiar: [];
  nuevo: [];
}>();

const busqueda = defineModel<string>("busqueda", { default: "" });
// Lista o cuadrícula: solo se muestra el selector si el listado lo ofrece (v-model:vista).
const vista = defineModel<VistaListado | undefined>("vista", {
  default: undefined,
});

// Cuántos filtros están aplicados ahora (para el contador del botón).
const activos = computed(
  () =>
    props.filtros.filter((f) => {
      const v = props.valores[f.clave];
      return v !== undefined && v !== "" && v !== "no";
    }).length,
);

// Arranca abierto si ya hay algún filtro puesto (si no, no se entendería por
// qué la lista viene acotada).
const abierto = ref(activos.value > 0);

function esActivo(clave: string): boolean {
  const v = props.valores[clave];
  return v !== undefined && v !== "" && v !== "no";
}
</script>

<template>
  <section class="tu-card p-3 sm:p-4 space-y-3">
    <!-- Fila 1: filtros + buscador + extra + «Agregar». -->
    <div class="flex flex-wrap items-center gap-2 sm:gap-3">
      <button
        v-if="filtros.length"
        type="button"
        class="tu-btn tu-btn-fantasma shrink-0"
        :style="
          abierto || activos
            ? {
                borderColor: 'var(--primario)',
                color: 'var(--primario-fuerte)',
              }
            : {}
        "
        @click="abierto = !abierto"
      >
        <svg
          class="h-4 w-4 shrink-0"
          fill="none"
          viewBox="0 0 24 24"
          stroke-width="1.7"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"
          />
        </svg>
        {{ $t("tabla.filtros") }}
        <span
          v-if="activos"
          class="rounded-full px-1.5 text-xs"
          :style="{
            background: 'var(--primario)',
            color: 'var(--primario-contraste)',
          }"
          >{{ activos }}</span
        >
      </button>

      <input
        v-if="!sinBuscador"
        v-model="busqueda"
        type="search"
        :placeholder="placeholder"
        class="tu-input min-w-0 flex-1 sm:min-w-52"
      />

      <div class="ms-auto flex items-center gap-2">
        <div
          v-if="vista !== undefined"
          class="tu-segmentado shrink-0"
          role="group"
        >
          <button
            type="button"
            :aria-pressed="vista === 'lista'"
            :aria-label="$t('listados.verLista')"
            :title="$t('listados.verLista')"
            @click="vista = 'lista'"
          >
            <IconoNav nombre="lista" :tam="16" />
          </button>
          <button
            type="button"
            :aria-pressed="vista === 'cuadricula'"
            :aria-label="$t('listados.verCuadricula')"
            :title="$t('listados.verCuadricula')"
            @click="vista = 'cuadricula'"
          >
            <IconoNav nombre="cuadricula" :tam="16" />
          </button>
        </div>
        <slot name="acciones" />
        <button
          v-if="puedeCrear"
          class="tu-btn tu-btn-primario shrink-0"
          type="button"
          @click="emit('nuevo')"
        >
          {{ nuevoTexto }}
        </button>
      </div>
    </div>

    <!-- Fila 2: filtros colapsables. -->
    <div
      v-if="abierto && filtros.length"
      class="flex flex-wrap items-center gap-2 border-t pt-3"
      :style="{ borderColor: 'var(--borde)' }"
    >
      <template v-for="f in filtros" :key="f.clave">
        <button
          v-if="f.tipo === 'booleano'"
          type="button"
          class="shrink-0 rounded-lg border px-2.5 py-1.5 text-xs font-medium"
          :style="{
            borderColor: esActivo(f.clave) ? 'var(--primario)' : 'var(--borde)',
            color: esActivo(f.clave)
              ? 'var(--primario-fuerte)'
              : 'var(--texto-suave)',
            background: esActivo(f.clave)
              ? 'color-mix(in srgb, var(--primario) 10%, transparent)'
              : 'transparent',
          }"
          @click="emit('cambioFiltro', f.clave, valores[f.clave] ? '' : '1')"
        >
          {{ f.etiqueta }}
        </button>

        <select
          v-else
          class="tu-input w-auto min-w-0 grow basis-40 py-1.5 text-xs sm:grow-0 sm:basis-auto"
          :style="
            esActivo(f.clave)
              ? {
                  borderColor: 'var(--primario)',
                  color: 'var(--primario-fuerte)',
                }
              : {}
          "
          :value="valores[f.clave] ?? ''"
          @change="
            emit(
              'cambioFiltro',
              f.clave,
              ($event.target as HTMLSelectElement).value,
            )
          "
        >
          <option value="">{{ f.etiqueta }}</option>
          <option v-for="o in f.opciones" :key="o.valor" :value="o.valor">
            {{ o.texto }}
          </option>
        </select>
      </template>

      <button
        v-if="activos"
        type="button"
        class="shrink-0 text-xs"
        :style="{ color: 'var(--texto-suave)' }"
        @click="emit('limpiar')"
      >
        {{ $t("tabla.limpiar") }}
      </button>
    </div>
  </section>
</template>
