<script setup lang="ts" generic="T extends Record<string, unknown>">
import { computed, ref, watch } from "vue";

import BarraListado from "@/components/BarraListado.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";

interface Columna {
  clave: string;
  etiqueta: string;
  alinear?: "izquierda" | "derecha";
}
interface DefinicionFiltro {
  clave: string;
  etiqueta: string;
  tipo?: "select" | "booleano";
  opciones?: { valor: string | number; texto: string }[];
}

const props = withDefaults(
  defineProps<{
    columnas: Columna[];
    filas: T[];
    // Claves de texto por las que filtra el buscador; si se omite, usa todas las de tipo string.
    buscarEn?: string[];
    buscar?: boolean;
    porPagina?: number;
    vacio?: string;
    placeholder?: string;
    // Filtros estilo Acadion (opcionales). La lógica de coincidencia la decide el
    // padre con `filtrarFila`, para soportar campos derivados (roles[], booleanos…).
    filtros?: DefinicionFiltro[];
    filtrarFila?: (fila: T, valores: Record<string, string>) => boolean;
  }>(),
  {
    buscar: true,
    porPagina: 10,
    buscarEn: undefined,
    vacio: undefined,
    placeholder: undefined,
    filtros: () => [],
    filtrarFila: undefined,
  },
);

const q = ref("");
const pagina = ref(1);
const valores = ref<Record<string, string>>({});

const clavesBusqueda = computed(
  () => props.buscarEn ?? props.columnas.map((c) => c.clave),
);

function cambioFiltro(clave: string, valor: string): void {
  valores.value = { ...valores.value, [clave]: valor };
}
function limpiar(): void {
  valores.value = {};
}

const filtradas = computed(() => {
  const termino = q.value.trim().toLowerCase();
  return props.filas.filter((fila) => {
    if (termino !== "") {
      const coincideTexto = clavesBusqueda.value.some((clave) => {
        const valor = fila[clave];
        return (
          typeof valor === "string" && valor.toLowerCase().includes(termino)
        );
      });
      if (!coincideTexto) {
        return false;
      }
    }
    if (props.filtrarFila !== undefined) {
      return props.filtrarFila(fila, valores.value);
    }
    return true;
  });
});

const totalPaginas = computed(() =>
  Math.max(1, Math.ceil(filtradas.value.length / props.porPagina)),
);

const paginaSegura = computed(() => Math.min(pagina.value, totalPaginas.value));

const paginadas = computed(() =>
  filtradas.value.slice(
    (paginaSegura.value - 1) * props.porPagina,
    paginaSegura.value * props.porPagina,
  ),
);

// Al cambiar el buscador, los filtros o los datos, vuelve a la primera pagina.
watch([q, valores, () => props.filas], () => {
  pagina.value = 1;
});

const conBarra = computed(() => props.buscar || props.filtros.length > 0);
</script>

<template>
  <div>
    <BarraListado
      v-if="conBarra"
      v-model:busqueda="q"
      :filtros="filtros"
      :valores="valores"
      :placeholder="placeholder ?? $t('tabla.buscar')"
      :sin-buscador="!buscar"
      @cambio-filtro="cambioFiltro"
      @limpiar="limpiar"
    />

    <div class="tu-card overflow-hidden" :class="conBarra ? 'mt-4' : ''">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr
              :style="{
                background:
                  'color-mix(in srgb, var(--texto-suave) 6%, var(--superficie))',
              }"
            >
              <th
                v-for="c in columnas"
                :key="c.clave"
                class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wider whitespace-nowrap"
                :class="c.alinear === 'derecha' ? 'text-right' : 'text-left'"
                :style="{ color: 'var(--texto-suave)' }"
                scope="col"
              >
                {{ c.etiqueta }}
              </th>
            </tr>
          </thead>
          <tbody class="tu-tabla-cuerpo">
            <tr v-if="paginadas.length === 0">
              <td
                :colspan="columnas.length"
                class="px-4 py-8 text-center"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ vacio ?? $t("tabla.vacio") }}
              </td>
            </tr>
            <tr
              v-for="(fila, i) in paginadas"
              :key="i"
              :style="{ borderTop: '1px solid var(--borde)' }"
            >
              <td
                v-for="c in columnas"
                :key="c.clave"
                class="px-4 py-2.5 align-middle"
                :class="c.alinear === 'derecha' ? 'text-right' : 'text-left'"
              >
                <slot
                  :name="`col-${c.clave}`"
                  :fila="fila"
                  :valor="fila[c.clave]"
                >
                  {{ fila[c.clave] }}
                </slot>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginacionListado
        :page="paginaSegura"
        :ultima-pagina="totalPaginas"
        :total="filtradas.length"
        :per-page="porPagina"
        @ir="(n) => (pagina = n)"
      />
    </div>
  </div>
</template>

<style scoped>
.tu-tabla-cuerpo tr {
  transition: background-color 0.12s ease;
}
.tu-tabla-cuerpo tr:hover {
  background: color-mix(in srgb, var(--acento) 5%, transparent);
}
</style>
