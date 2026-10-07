<script setup lang="ts">
import { computed, nextTick, ref, useId } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";
import { filtrarOpciones, type OpcionBuscable } from "@/lib/buscable";

/**
 * Elegir de una lista larga escribiendo (países, zonas horarias), con el aspecto de
 * un campo. Al tocarlo (o con la flecha abajo) se ve toda la lista, agrupada (p. ej.
 * las del país primero); al escribir se filtra. Flechas y Enter para elegir, Esc
 * para cerrar. El valor es el `valor` de la opción. Los atributos (`id`,
 * `data-prueba`…) van al campo.
 */
defineOptions({ inheritAttrs: false });

const props = withDefaults(
  defineProps<{
    opciones: OpcionBuscable[];
    placeholder?: string;
    deshabilitado?: boolean;
  }>(),
  { placeholder: undefined, deshabilitado: false },
);
const modelo = defineModel<string>({ required: true });

const { t } = useI18n();
const listaId = useId();
const abierta = ref(false);
const busqueda = ref("");
const activa = ref(0);

const elegida = computed(
  () => props.opciones.find((o) => o.valor === modelo.value) ?? null,
);
const visibles = computed(() =>
  filtrarOpciones(props.opciones, abierta.value ? busqueda.value : ""),
);
const buscando = computed(() => busqueda.value.trim() !== "");

// Título del grupo antes de la primera opción de cada uno (sin búsqueda).
function iniciaGrupo(i: number): string | null {
  const o = visibles.value[i];
  if (buscando.value || !o?.grupo) {
    return null;
  }
  return i === 0 || visibles.value[i - 1]?.grupo !== o.grupo ? o.grupo : null;
}

function idOpcion(i: number): string {
  return `${listaId}-${i}`;
}
function mostrarActiva(): void {
  void nextTick(() => {
    document
      .getElementById(idOpcion(activa.value))
      ?.scrollIntoView?.({ block: "nearest" });
  });
}

function abrir(): void {
  if (abierta.value || props.deshabilitado) {
    return;
  }
  abierta.value = true;
  busqueda.value = "";
  // Se abre en la elegida.
  activa.value = Math.max(
    0,
    visibles.value.findIndex((o) => o.valor === modelo.value),
  );
  mostrarActiva();
}
function cerrar(): void {
  abierta.value = false;
  busqueda.value = "";
}
function elegir(o: OpcionBuscable): void {
  modelo.value = o.valor;
  cerrar();
}
function alEscribir(e: Event): void {
  busqueda.value = (e.target as HTMLInputElement).value;
  abierta.value = true;
  activa.value = 0;
}
function teclado(e: KeyboardEvent): void {
  const n = visibles.value.length;
  if (e.key === "ArrowDown" || e.key === "ArrowUp") {
    e.preventDefault();
    if (!abierta.value) {
      abrir();
      return;
    }
    if (n > 0) {
      activa.value = (activa.value + (e.key === "ArrowDown" ? 1 : -1) + n) % n;
      mostrarActiva();
    }
  } else if (e.key === "Enter" && abierta.value) {
    e.preventDefault();
    const o = visibles.value[activa.value];
    if (o) {
      elegir(o);
    }
  } else if (e.key === "Escape" && abierta.value) {
    // Solo cierra la lista (no el panel donde está el campo).
    e.preventDefault();
    e.stopPropagation();
    cerrar();
  }
}
</script>

<template>
  <div class="sb">
    <div class="tu-campo-icono flex">
      <IconoNav nombre="buscar" :tam="16" />
      <input
        v-bind="$attrs"
        type="text"
        class="tu-input"
        role="combobox"
        autocomplete="off"
        aria-autocomplete="list"
        :aria-expanded="abierta"
        :aria-controls="listaId"
        :aria-activedescendant="
          abierta && visibles[activa] ? idOpcion(activa) : undefined
        "
        :value="abierta ? busqueda : (elegida?.etiqueta ?? modelo)"
        :placeholder="
          abierta ? (elegida?.etiqueta ?? placeholder) : placeholder
        "
        :disabled="deshabilitado"
        @click="abrir"
        @blur="cerrar"
        @input="alEscribir"
        @keydown="teclado"
      />
    </div>
    <!-- Sin quitarle el foco al campo (p. ej. al arrastrar la barra de la lista). -->
    <ul
      v-if="abierta"
      :id="listaId"
      class="sb-lista tu-card"
      role="listbox"
      @mousedown.prevent
    >
      <template v-for="(o, i) in visibles" :key="`${o.grupo ?? ''}|${o.valor}`">
        <li v-if="iniciaGrupo(i)" class="sb-grupo" role="presentation">
          {{ iniciaGrupo(i) }}
        </li>
        <li
          :id="idOpcion(i)"
          role="option"
          class="sb-opcion"
          :class="{ 'sb-opcion-activa': i === activa }"
          :aria-selected="i === activa"
          :data-valor="o.valor"
          @mousedown.prevent="elegir(o)"
        >
          <span class="min-w-0 truncate">{{ o.etiqueta }}</span>
          <span v-if="o.detalle" class="sb-detalle">{{ o.detalle }}</span>
        </li>
      </template>
      <li v-if="visibles.length === 0" class="sb-sin">
        {{ t("selectorBuscable.sinCoincidencias") }}
      </li>
    </ul>
  </div>
</template>

<style scoped>
.sb {
  position: relative;
}
.sb-lista {
  position: absolute;
  z-index: 20;
  left: 0;
  right: 0;
  margin-top: 0.3rem;
  max-height: 18rem;
  overflow-y: auto;
  padding: 0.3rem;
}
.sb-grupo {
  padding: 0.45rem 0.65rem 0.2rem;
  color: var(--texto-suave);
  font-size: 0.75rem;
}
.sb-opcion {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.5rem 0.65rem;
  border-radius: 0.5rem;
  font-size: 0.875rem;
  cursor: pointer;
}
.sb-opcion:hover,
.sb-opcion-activa {
  background: var(--superficie-2);
}
.sb-detalle {
  flex-shrink: 0;
  color: var(--texto-suave);
  font-size: 0.78rem;
  font-variant-numeric: tabular-nums;
}
.sb-sin {
  padding: 0.5rem 0.65rem;
  color: var(--texto-suave);
  font-size: 0.85rem;
}
</style>
