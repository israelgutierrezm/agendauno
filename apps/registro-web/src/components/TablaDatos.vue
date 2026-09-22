<script setup lang="ts" generic="T extends Record<string, unknown>">
import { computed, ref, watch } from "vue";

interface Columna {
  clave: string;
  etiqueta: string;
  alinear?: "izquierda" | "derecha";
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
  }>(),
  { buscar: true, porPagina: 10, buscarEn: undefined, vacio: undefined },
);

const q = ref("");
const pagina = ref(1);

const clavesBusqueda = computed(
  () => props.buscarEn ?? props.columnas.map((c) => c.clave),
);

const filtradas = computed(() => {
  const termino = q.value.trim().toLowerCase();
  if (termino === "") {
    return props.filas;
  }
  return props.filas.filter((fila) =>
    clavesBusqueda.value.some((clave) => {
      const valor = fila[clave];
      return typeof valor === "string" && valor.toLowerCase().includes(termino);
    }),
  );
});

const totalPaginas = computed(() =>
  Math.max(1, Math.ceil(filtradas.value.length / props.porPagina)),
);

const paginaSegura = computed(() => Math.min(pagina.value, totalPaginas.value));

const desde = computed(() =>
  filtradas.value.length === 0
    ? 0
    : (paginaSegura.value - 1) * props.porPagina + 1,
);
const hasta = computed(() =>
  Math.min(paginaSegura.value * props.porPagina, filtradas.value.length),
);

const paginadas = computed(() =>
  filtradas.value.slice(
    (paginaSegura.value - 1) * props.porPagina,
    paginaSegura.value * props.porPagina,
  ),
);

// Al cambiar el filtro o los datos, vuelve a la primera pagina.
watch([q, () => props.filas], () => {
  pagina.value = 1;
});

function ir(delta: number): void {
  pagina.value = Math.min(
    totalPaginas.value,
    Math.max(1, paginaSegura.value + delta),
  );
}
</script>

<template>
  <div class="tu-card overflow-hidden">
    <div
      v-if="buscar"
      class="p-3 border-b"
      :style="{ borderColor: 'var(--borde)' }"
    >
      <input
        v-model="q"
        class="tu-input"
        type="search"
        :placeholder="$t('tabla.buscar')"
        :aria-label="$t('tabla.buscar')"
      />
    </div>

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

    <!-- Paginacion -->
    <div
      v-if="filtradas.length > porPagina"
      class="flex items-center justify-between gap-3 p-3 border-t text-sm"
      :style="{ borderColor: 'var(--borde)', color: 'var(--texto-suave)' }"
    >
      <span>{{
        $t("tabla.mostrando", { desde, hasta, total: filtradas.length })
      }}</span>
      <div class="flex items-center gap-2">
        <button
          class="tu-btn tu-btn-fantasma px-3 py-1.5"
          :disabled="paginaSegura <= 1"
          @click="ir(-1)"
        >
          {{ $t("tabla.anterior") }}
        </button>
        <span>{{
          $t("tabla.pagina", { n: paginaSegura, total: totalPaginas })
        }}</span>
        <button
          class="tu-btn tu-btn-fantasma px-3 py-1.5"
          :disabled="paginaSegura >= totalPaginas"
          @click="ir(1)"
        >
          {{ $t("tabla.siguiente") }}
        </button>
      </div>
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
