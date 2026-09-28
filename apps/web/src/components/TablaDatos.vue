<script setup lang="ts" generic="T extends Record<string, unknown>">
import { computed, ref, watch } from "vue";

import BarraListado from "@/components/BarraListado.vue";
import EstadoVacioListado from "@/components/EstadoVacioListado.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { useVistaListado } from "@/lib/vistaListado";

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
    // Con una clave, el listado ofrece verse como lista o como cuadrícula de
    // tarjetas (y recuerda la elección en este navegador).
    claveVista?: string;
  }>(),
  {
    buscar: true,
    porPagina: 10,
    buscarEn: undefined,
    vacio: undefined,
    placeholder: undefined,
    filtros: () => [],
    filtrarFila: undefined,
    claveVista: undefined,
  },
);

const vista =
  props.claveVista !== undefined ? useVistaListado(props.claveVista) : null;

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

function restablecer(): void {
  q.value = "";
  limpiar();
}

function normalizar(texto: string): string {
  return texto
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase();
}

const filtradas = computed(() => {
  const termino = normalizar(q.value.trim());
  return props.filas.filter((fila) => {
    if (termino !== "") {
      const coincideTexto = clavesBusqueda.value.some((clave) => {
        const valor = fila[clave];
        return typeof valor === "string" && normalizar(valor).includes(termino);
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

const conBarra = computed(
  () =>
    props.buscar || props.filtros.length > 0 || props.claveVista !== undefined,
);

// Para el v-model de la barra: sin clave de vista, el selector no aparece.
const vistaModelo = computed({
  get: () => vista?.value,
  set: (v) => {
    if (vista !== null && v !== undefined) {
      vista.value = v;
    }
  },
});
const enCuadricula = computed(() => vista?.value === "cuadricula");
</script>

<template>
  <div>
    <BarraListado
      v-if="conBarra"
      v-model:busqueda="q"
      v-model:vista="vistaModelo"
      :filtros="filtros"
      :valores="valores"
      :placeholder="placeholder ?? $t('tabla.buscar')"
      :sin-buscador="!buscar"
      @cambio-filtro="cambioFiltro"
      @limpiar="limpiar"
    />

    <!-- Cuadrícula: cada fila como tarjeta (la primera columna es el título). -->
    <div v-if="enCuadricula" :class="conBarra ? 'mt-4' : ''">
      <EstadoVacioListado
        v-if="paginadas.length === 0"
        class="tu-card"
        :filtrado="filas.length > 0"
        :mensaje="vacio"
        @restablecer="restablecer"
      />
      <ul v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <li v-for="(fila, i) in paginadas" :key="i" class="tu-card p-4">
          <slot name="tarjeta" :fila="fila">
            <div class="font-medium">
              <slot
                :name="`col-${columnas[0].clave}`"
                :fila="fila"
                :valor="fila[columnas[0].clave]"
              >
                {{ fila[columnas[0].clave] }}
              </slot>
            </div>
            <dl class="mt-3 space-y-1.5 text-sm">
              <div
                v-for="c in columnas.slice(1)"
                :key="c.clave"
                class="flex items-baseline justify-between gap-3"
              >
                <dt class="shrink-0" :style="{ color: 'var(--texto-suave)' }">
                  {{ c.etiqueta }}
                </dt>
                <dd class="min-w-0 text-right">
                  <slot
                    :name="`col-${c.clave}`"
                    :fila="fila"
                    :valor="fila[c.clave]"
                  >
                    {{ fila[c.clave] }}
                  </slot>
                </dd>
              </div>
            </dl>
          </slot>
        </li>
      </ul>
      <div v-if="filtradas.length" class="mt-3 tu-card overflow-hidden">
        <PaginacionListado
          :page="paginaSegura"
          :ultima-pagina="totalPaginas"
          :total="filtradas.length"
          :per-page="porPagina"
          @ir="(n) => (pagina = n)"
        />
      </div>
    </div>

    <div v-else class="tu-card overflow-hidden" :class="conBarra ? 'mt-4' : ''">
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
                class="text-center"
                :style="{ color: 'var(--texto-suave)' }"
              >
                <EstadoVacioListado
                  :filtrado="filas.length > 0"
                  :mensaje="vacio"
                  @restablecer="restablecer"
                />
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
