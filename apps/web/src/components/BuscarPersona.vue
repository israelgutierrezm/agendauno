<script setup lang="ts">
import { computed, ref, useId } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";
import { normalizar } from "@/lib/menu";

/**
 * Elegir a una persona escribiendo su nombre (o su correo o celular), en lugar de una
 * lista con todos los clientes. Muestra hasta 8 coincidencias; flechas y Enter para
 * elegir, Esc para cerrar. Elegida, se ve su nombre con «Cambiar». El valor es su id.
 */
export interface PersonaBuscable {
  id: string;
  nombre: string;
  // Correo o celular: también se busca por ahí y se muestra debajo del nombre.
  detalle?: string | null;
}

const props = withDefaults(
  defineProps<{
    personas: PersonaBuscable[];
    placeholder?: string;
    // Para un <label for>: el id del campo de búsqueda.
    campoId?: string;
    deshabilitado?: boolean;
  }>(),
  { placeholder: undefined, campoId: undefined, deshabilitado: false },
);
const modelo = defineModel<string>({ required: true });

const { t } = useI18n();
const listaId = useId();
const busqueda = ref("");
const activa = ref(0);
const abierta = ref(false);

const elegida = computed(
  () => props.personas.find((p) => p.id === modelo.value) ?? null,
);

const coincidencias = computed(() => {
  const q = normalizar(busqueda.value.trim());
  if (q === "") {
    return [];
  }
  const palabras = q.split(/\s+/);
  return props.personas
    .filter((p) => {
      const texto = normalizar(`${p.nombre} ${p.detalle ?? ""}`);
      return palabras.every((w) => texto.includes(w));
    })
    .slice(0, 8);
});

function elegir(p: PersonaBuscable): void {
  modelo.value = p.id;
  busqueda.value = "";
  abierta.value = false;
}
function cambiar(): void {
  modelo.value = "";
  busqueda.value = "";
}
function alEscribir(): void {
  abierta.value = true;
  activa.value = 0;
}
function teclado(e: KeyboardEvent): void {
  const n = coincidencias.value.length;
  if (e.key === "ArrowDown" && n > 0) {
    e.preventDefault();
    abierta.value = true;
    activa.value = (activa.value + 1) % n;
  } else if (e.key === "ArrowUp" && n > 0) {
    e.preventDefault();
    activa.value = (activa.value - 1 + n) % n;
  } else if (e.key === "Enter" && abierta.value && n > 0) {
    e.preventDefault();
    const p = coincidencias.value[activa.value];
    if (p) {
      elegir(p);
    }
  } else if (e.key === "Escape") {
    abierta.value = false;
  }
}
</script>

<template>
  <div class="bp">
    <div v-if="elegida" class="bp-elegida" data-prueba="persona-elegida">
      <span class="min-w-0">
        <span class="block truncate font-medium">{{ elegida.nombre }}</span>
        <span v-if="elegida.detalle" class="bp-detalle block truncate">{{
          elegida.detalle
        }}</span>
      </span>
      <button
        v-if="!deshabilitado"
        type="button"
        class="tu-enlace shrink-0 text-sm"
        @click="cambiar"
      >
        {{ t("buscarPersona.cambiar") }}
      </button>
    </div>
    <template v-else>
      <label class="tu-campo-icono flex">
        <IconoNav nombre="buscar" :tam="16" />
        <input
          :id="campoId"
          v-model="busqueda"
          type="search"
          class="tu-input"
          role="combobox"
          autocomplete="off"
          :aria-expanded="abierta && coincidencias.length > 0"
          :aria-controls="listaId"
          :aria-activedescendant="
            abierta && coincidencias[activa]
              ? `${listaId}-${coincidencias[activa].id}`
              : undefined
          "
          :placeholder="placeholder ?? t('buscarPersona.placeholder')"
          :disabled="deshabilitado"
          @input="alEscribir"
          @keydown="teclado"
          @focus="abierta = true"
        />
      </label>
      <ul
        v-if="abierta && busqueda.trim() !== ''"
        :id="listaId"
        class="bp-lista tu-card"
        role="listbox"
      >
        <li
          v-for="(p, i) in coincidencias"
          :id="`${listaId}-${p.id}`"
          :key="p.id"
          role="option"
          :aria-selected="i === activa"
        >
          <button
            type="button"
            class="bp-opcion"
            :class="{ 'bp-opcion-activa': i === activa }"
            tabindex="-1"
            @mousedown.prevent="elegir(p)"
          >
            <span class="block truncate">{{ p.nombre }}</span>
            <span v-if="p.detalle" class="bp-detalle block truncate">{{
              p.detalle
            }}</span>
          </button>
        </li>
        <li v-if="coincidencias.length === 0" class="bp-sin">
          {{ t("buscarPersona.sinCoincidencias") }}
        </li>
      </ul>
    </template>
  </div>
</template>

<style scoped>
.bp {
  position: relative;
}
.bp-elegida {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.55rem 0.8rem;
  border: 1px solid var(--borde);
  border-radius: 0.75rem;
  background: var(--superficie);
}
.bp-detalle {
  color: var(--texto-suave);
  font-size: 0.78rem;
}
.bp-lista {
  position: absolute;
  z-index: 20;
  left: 0;
  right: 0;
  margin-top: 0.3rem;
  max-height: 18rem;
  overflow-y: auto;
  padding: 0.3rem;
}
.bp-opcion {
  display: block;
  width: 100%;
  padding: 0.5rem 0.65rem;
  border-radius: 0.5rem;
  text-align: left;
  font-size: 0.875rem;
}
.bp-opcion:hover,
.bp-opcion-activa {
  background: var(--superficie-2);
}
.bp-sin {
  padding: 0.5rem 0.65rem;
  color: var(--texto-suave);
  font-size: 0.85rem;
}
</style>
