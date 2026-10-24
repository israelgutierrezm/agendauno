<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import EstadoVacio from "@/components/EstadoVacio.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import { api, mensajeDeError } from "@/lib/api";
import { conTerminosActuales } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Quién cuenta en el cobro del mes (transparencia, ADR 0107), en un diálogo: una tabla
 * por páginas, con búsqueda por nombre y, de cada quien, por qué cuenta (sus reservas y
 * compras pagadas del mes, o las citas que atendió). Un estudio grande junta cientos de
 * alumnos: no se descargan todos ni se amontonan debajo de la tarjeta.
 */
const props = defineProps<{ abierto: boolean; base: string; titulo: string }>();
defineEmits<{ cerrar: [] }>();

interface Fila {
  id: string;
  nombre: string;
  reservas?: number;
  compras?: number;
  sesiones?: number;
}
interface Meta {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
}
interface Respuesta {
  data: { metrica: string; cantidad: number; quienes: Fila[] };
  meta: Meta;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();

const filas = ref<Fila[]>([]);
const meta = ref<Meta | null>(null);
const cantidad = ref<number | null>(null);
const porProfesional = ref(sesion.esCitas);
const cargando = ref(false);
const error = ref<string | null>(null);
const buscar = ref("");
const porPagina = ref(25);

// Solo cuenta la última respuesta: al escribir rápido, una vieja no pisa a la nueva.
let turno = 0;
async function cargar(pagina = 1): Promise<void> {
  const mio = ++turno;
  cargando.value = true;
  error.value = null;
  try {
    const q = buscar.value.trim();
    const { data } = await api.get<Respuesta>(
      `${props.base}/renta/quien-cuenta`,
      {
        params: {
          page: pagina,
          per_page: porPagina.value,
          ...(q ? { q } : {}),
        },
      },
    );
    if (mio !== turno) return;
    filas.value = data.data.quienes;
    meta.value = data.meta;
    cantidad.value = data.data.cantidad;
    porProfesional.value = data.data.metrica === "profesionales_activos";
  } catch (e) {
    if (mio === turno) error.value = mensajeDeError(e);
  } finally {
    if (mio === turno) cargando.value = false;
  }
}

watch(
  () => props.abierto,
  (abierto) => {
    if (!abierto) return;
    buscar.value = "";
    void cargar(1);
  },
  { immediate: true },
);

let espera: ReturnType<typeof setTimeout> | undefined;
watch(buscar, () => {
  clearTimeout(espera);
  espera = setTimeout(() => void cargar(1), 300);
});

function cambiarPorPagina(n: number): void {
  porPagina.value = n;
  void cargar(1);
}

const regla = computed(() =>
  porProfesional.value
    ? t("cobro.quien.reglaProfesionales")
    : conTerminosActuales(t("cobro.quien.reglaAlumnos")),
);
const resumen = computed(() =>
  cantidad.value === null
    ? ""
    : conTerminosActuales(
        t(
          porProfesional.value
            ? "cobro.quien.cuentanProfesionales"
            : "cobro.quien.cuentanAlumnos",
          { n: cantidad.value },
          cantidad.value,
        ),
      ),
);

// A la ficha de cada quien, si quien mira puede abrirla.
function enlace(p: Fila): { name: string; params: { id: string } } | null {
  if (porProfesional.value) {
    return sesion.puede("usuarios.gestionar")
      ? { name: "ficha-instructor", params: { id: p.id } }
      : null;
  }
  return sesion.puede("miembros.ver")
    ? { name: "ficha-miembro", params: { id: p.id } }
    : null;
}
function numero(n: number | undefined): string {
  return n ? String(n) : "—";
}
</script>

<template>
  <ModalDialogo
    :abierto="abierto"
    :titulo="titulo"
    icono="miembros"
    :descripcion="regla"
    @cerrar="$emit('cerrar')"
  >
    <div class="qc-barra">
      <p class="text-sm" data-prueba="resumen-quien">
        {{ resumen }}
      </p>
      <input
        v-model="buscar"
        type="search"
        class="tu-input qc-buscar"
        :placeholder="$t('cobro.quien.buscar')"
        :aria-label="$t('cobro.quien.buscar')"
        data-prueba="buscar-quien"
      />
    </div>

    <p
      v-if="error"
      class="mt-4 text-sm"
      role="alert"
      :style="{ color: 'var(--peligro-texto, var(--peligro))' }"
    >
      {{ error }}
    </p>
    <EstadoVacio
      v-else-if="!cargando && filas.length === 0 && meta"
      class="mt-4"
      icono="miembros"
      compacto
      :titulo="
        buscar.trim()
          ? $t('cobro.quien.sinResultados')
          : $t('cobro.quien.nadie')
      "
    />
    <div
      v-else-if="filas.length > 0"
      class="qc-marco mt-4"
      :class="{ 'opacity-60': cargando }"
    >
      <table class="tu-tabla w-full text-sm" data-prueba="tabla-quien">
        <thead>
          <tr>
            <th scope="col">
              {{
                porProfesional
                  ? $t("cobro.quien.colProfesional")
                  : conTerminosActuales($t("cobro.quien.colAlumno"))
              }}
            </th>
            <th v-if="porProfesional" scope="col" class="text-right">
              {{ $t("cobro.quien.colCitas") }}
            </th>
            <template v-else>
              <th scope="col" class="text-right">
                {{ conTerminosActuales($t("cobro.quien.colReservas")) }}
              </th>
              <th scope="col" class="text-right">
                {{ $t("cobro.quien.colCompras") }}
              </th>
            </template>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in filas" :key="p.id" data-prueba="fila-quien">
            <td class="font-medium">
              <RouterLink
                v-if="enlace(p)"
                :to="enlace(p)!"
                class="tu-enlace"
                @click="$emit('cerrar')"
                >{{ p.nombre }}</RouterLink
              >
              <template v-else>{{ p.nombre }}</template>
            </td>
            <td v-if="porProfesional" class="text-right tabular-nums">
              {{ numero(p.sesiones) }}
            </td>
            <template v-else>
              <td class="text-right tabular-nums">{{ numero(p.reservas) }}</td>
              <td class="text-right tabular-nums">{{ numero(p.compras) }}</td>
            </template>
          </tr>
        </tbody>
      </table>
    </div>
    <PaginacionListado
      v-if="meta && meta.total > 0"
      :page="meta.page"
      :ultima-pagina="meta.ultima_pagina"
      :total="meta.total"
      :per-page="meta.per_page"
      :opciones-por-pagina="[25, 50, 100]"
      @ir="cargar"
      @por-pagina="cambiarPorPagina"
    />
  </ModalDialogo>
</template>

<style scoped>
.qc-barra {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}
.qc-buscar {
  flex: 0 1 18rem;
  min-width: 0;
}
.qc-marco {
  overflow-x: auto;
  border: 1px solid var(--borde);
  border-radius: 0.6rem;
}
</style>
