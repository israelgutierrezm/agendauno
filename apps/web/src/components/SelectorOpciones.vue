<script setup lang="ts">
import { computed, ref } from "vue";

/**
 * Elegir varias opciones de una lista que crece con el catálogo (los servicios que
 * cubre un plan, los que incluye un paquete): en filas alineadas, en dos columnas, por
 * grupo y con «elegir todas» en cada uno, en vez de chips que se amontonan. Con muchas
 * opciones se busca y la lista se desplaza dentro de su marco. El modelo es la lista
 * de ids en el orden en que se marcaron.
 */
export interface Opcion {
  id: string;
  nombre: string;
  grupo?: string | null;
}

const props = withDefaults(
  defineProps<{
    opciones: Opcion[];
    modelValue: string[];
    // Desde cuántas opciones se ofrece buscar.
    buscarDesde?: number;
  }>(),
  { buscarDesde: 8 },
);
const emit = defineEmits<{ "update:modelValue": [ids: string[]] }>();

const buscar = ref("");
const elegidas = computed(() => new Set(props.modelValue));

// Sin acentos ni mayúsculas: «limpieza» encuentra «Limpieza dental».
function normal(texto: string): string {
  return texto.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
}

const visibles = computed(() => {
  const q = normal(buscar.value.trim());
  return q === ""
    ? props.opciones
    : props.opciones.filter((o) => normal(o.nombre).includes(q));
});
const grupos = computed(() => {
  const mapa = new Map<string, Opcion[]>();
  for (const o of visibles.value) {
    const clave = o.grupo ?? "";
    mapa.set(clave, [...(mapa.get(clave) ?? []), o]);
  }
  return [...mapa.entries()];
});
const conGrupos = computed(
  () => grupos.value.length > 1 || (grupos.value[0]?.[0] ?? "") !== "",
);

function alternar(id: string): void {
  emit(
    "update:modelValue",
    elegidas.value.has(id)
      ? props.modelValue.filter((x) => x !== id)
      : [...props.modelValue, id],
  );
}
function todasDe(lista: Opcion[]): boolean {
  return lista.every((o) => elegidas.value.has(o.id));
}
function alternarGrupo(lista: Opcion[]): void {
  const ids = new Set(lista.map((o) => o.id));
  emit(
    "update:modelValue",
    todasDe(lista)
      ? props.modelValue.filter((x) => !ids.has(x))
      : [
          ...props.modelValue,
          ...lista.map((o) => o.id).filter((id) => !elegidas.value.has(id)),
        ],
  );
}
</script>

<template>
  <div class="so" data-prueba="selector-opciones">
    <div v-if="opciones.length > buscarDesde" class="so-barra">
      <input
        v-model="buscar"
        type="search"
        class="tu-input so-buscar"
        :placeholder="$t('comun.selector.buscar')"
        :aria-label="$t('comun.selector.buscar')"
        data-prueba="selector-buscar"
      />
      <span class="so-cuenta" data-prueba="selector-cuenta">{{
        $t("comun.selector.elegidas", {
          n: modelValue.length,
          total: opciones.length,
        })
      }}</span>
    </div>
    <div class="so-marco">
      <section v-for="[grupo, lista] in grupos" :key="grupo" class="so-grupo">
        <header v-if="conGrupos" class="so-grupo-titulo">
          <span class="truncate">{{
            grupo || $t("comun.selector.sinGrupo")
          }}</span>
          <button
            v-if="lista.length > 1"
            type="button"
            class="tu-enlace so-todas"
            @click="alternarGrupo(lista)"
          >
            {{
              todasDe(lista)
                ? $t("comun.selector.quitarTodas")
                : $t("comun.selector.elegirTodas")
            }}
          </button>
        </header>
        <ul class="so-opciones">
          <li v-for="o in lista" :key="o.id">
            <label class="so-opcion">
              <input
                type="checkbox"
                :value="o.id"
                :checked="elegidas.has(o.id)"
                @change="alternar(o.id)"
              />
              <span class="min-w-0 truncate">{{ o.nombre }}</span>
            </label>
          </li>
        </ul>
      </section>
      <p v-if="visibles.length === 0" class="so-vacio">
        {{ $t("comun.selector.sinCoincidencias") }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.so-barra {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 0.75rem;
  margin-bottom: 0.5rem;
}
.so-buscar {
  flex: 1 1 14rem;
  min-width: 0;
}
.so-cuenta {
  font-size: 0.8125rem;
  color: var(--texto-suave);
  white-space: nowrap;
}
/* Una lista larga se desplaza dentro de su marco: el formulario no se estira. */
.so-marco {
  max-height: 18rem;
  overflow-y: auto;
  border: 1px solid var(--borde);
  border-radius: 0.6rem;
}
.so-grupo + .so-grupo {
  border-top: 1px solid var(--borde);
}
.so-grupo-titulo {
  position: sticky;
  top: 0;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.4rem 0.75rem;
  background: var(--superficie-2);
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--texto-suave);
}
.so-todas {
  font-size: 0.75rem;
  white-space: nowrap;
}
.so-opciones {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
}
@media (min-width: 640px) {
  .so-opciones {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
.so-opcion {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.45rem 0.75rem;
  border-bottom: 1px solid var(--borde);
  font-size: 0.875rem;
  cursor: pointer;
}
.so-opcion:hover {
  background: var(--superficie-2);
}
.so-opcion:has(input:checked) {
  font-weight: 500;
}
.so-opciones > li:last-child .so-opcion {
  border-bottom: 0;
}
@media (min-width: 640px) {
  /* En dos columnas, la última fila completa no lleva línea abajo. */
  .so-opciones > li:nth-last-child(2):nth-child(odd) .so-opcion {
    border-bottom: 0;
  }
}
.so-vacio {
  padding: 0.75rem;
  font-size: 0.875rem;
  color: var(--texto-suave);
}
</style>
