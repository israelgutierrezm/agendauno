<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EditorPlan from "@/components/EditorPlan.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import {
  SECCIONES,
  clasesDe,
  dinero,
  seccionDe,
  type Plan,
} from "@/lib/planes";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Planes y paquetes (ADR 0050) con el patrón de los listados: indicadores, búsqueda
 * y filtro por tipo, y la tabla con precio, qué incluye, cuánto dura, para qué sirve
 * y si está a la venta. Se crean y editan en un diálogo (EditorPlan).
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeEditar = computed(() => sesion.puede("productos.gestionar"));

const planes = ref<Plan[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const verArchivados = ref(false);
const editando = ref<Plan | null>(null);
const abierto = ref(false);

const busqueda = ref("");
const seccion = ref<string>("");
const seccionesConPlanes = computed(() =>
  SECCIONES.filter((s) => planes.value.some((p) => seccionDe(p.tipo) === s)),
);
const visibles = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  return planes.value
    .filter(
      (p) =>
        (verArchivados.value || !p.archivado) &&
        (seccion.value === "" || seccionDe(p.tipo) === seccion.value) &&
        (q === "" || p.nombre.toLowerCase().includes(q)),
    )
    .sort(
      (a, b) =>
        SECCIONES.indexOf(seccionDe(a.tipo) as (typeof SECCIONES)[number]) -
          SECCIONES.indexOf(seccionDe(b.tipo) as (typeof SECCIONES)[number]) ||
        a.nombre.localeCompare(b.nombre),
    );
});
const hayArchivados = computed(() => planes.value.some((p) => p.archivado));

function nombreSeccion(s: string): string {
  return sesion.esCitas && s === "paquete"
    ? t("planes.tiposCitas.paquete")
    : t(`planes.secciones.${s}`);
}
function clases(p: Plan): string {
  if (p.tipo === "membresia") {
    return p.ilimitado
      ? t("planes.resumen.ilimitado")
      : t(
          "planes.resumen.porMes",
          { n: clasesDe(p.unidades_por_ciclo) },
          clasesDe(p.unidades_por_ciclo),
        );
  }
  if (p.ilimitado) {
    return t("planes.resumen.ilimitado");
  }
  const n = clasesDe(p.creditos_incluidos);
  return t("planes.resumen.clases", { n }, n);
}
function vigencia(p: Plan): string {
  if (p.tipo === "add_on") {
    return t("planes.resumen.conElPaquete");
  }
  if (p.tipo === "membresia") {
    return t("planesVisual.mientrasPague");
  }
  const n = p.vigencia_cantidad ?? 0;
  switch (p.vigencia_tipo) {
    case "dias":
      return t("planes.resumen.dias", { n }, n);
    case "meses":
      return t("planes.resumen.meses", { n }, n);
    case "fin_de_mes":
      return t("planes.resumen.finDeMes", { n }, n);
    default:
      return t("planes.resumen.sinVencimiento");
  }
}
function aplicaA(p: Plan): string {
  if (p.tipo === "add_on") {
    return "—";
  }
  return p.ofertas.length === 0
    ? t("planes.resumen.todas")
    : p.ofertas.map((o) => o.nombre).join(", ");
}

const indicadores = computed<Indicador[]>(() => {
  const aLaVenta = planes.value.filter((p) => !p.archivado);
  const contar = (s: string) =>
    aLaVenta.filter((p) => seccionDe(p.tipo) === s).length;
  return [
    {
      clave: "venta",
      etiqueta: t("planesVisual.kpi.aLaVenta"),
      valor: String(aLaVenta.length),
      icono: "etiqueta",
    },
    {
      clave: "membresias",
      etiqueta: t("planes.secciones.membresia"),
      valor: String(contar("membresia")),
      icono: "reloj",
    },
    {
      clave: "paquetes",
      etiqueta: nombreSeccion("paquete"),
      valor: String(contar("paquete")),
      icono: "ventas",
    },
    {
      clave: "archivados",
      etiqueta: t("planesVisual.kpi.archivados"),
      valor: String(planes.value.length - aLaVenta.length),
      icono: "cerrar",
    },
  ];
});

async function cargar(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{ data: Plan[] }>(
      `${base.value}/productos`,
      { params: { incluir: "todos" } },
    );
    planes.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}
function abrir(p: Plan | null): void {
  editando.value = p;
  abierto.value = true;
}
async function guardado(): Promise<void> {
  abierto.value = false;
  await cargar();
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      :titulo="$t('planes.titulo')"
      :subtitulo="$t('planes.subtitulo')"
    >
      <template #acciones>
        <button
          v-if="puedeEditar"
          type="button"
          class="tu-btn tu-btn-primario tu-btn-crear"
          data-prueba="nuevo-plan"
          @click="abrir(null)"
        >
          {{ $t("planes.nuevo") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p v-if="cargando" class="mt-6" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <EstadoVacio
      v-else-if="planes.length === 0"
      class="tu-card mt-6"
      icono="etiqueta"
      :titulo="$t('planes.vacio')"
    />

    <template v-else>
      <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

      <div class="tu-card mt-5">
        <div class="pv-filtros">
          <label class="pv-buscar">
            <IconoNav nombre="buscar" :tam="16" />
            <input
              v-model="busqueda"
              type="search"
              class="tu-input"
              :placeholder="$t('planesVisual.buscar')"
              :aria-label="$t('planesVisual.buscar')"
            />
          </label>
          <div
            v-if="seccionesConPlanes.length > 1"
            class="tu-segmentado"
            role="group"
          >
            <button
              type="button"
              :aria-pressed="seccion === ''"
              @click="seccion = ''"
            >
              {{ $t("planesVisual.todos") }}
            </button>
            <button
              v-for="s in seccionesConPlanes"
              :key="s"
              type="button"
              :aria-pressed="seccion === s"
              :data-prueba="`seccion-${s}`"
              @click="seccion = s"
            >
              {{ nombreSeccion(s) }}
            </button>
          </div>
          <label
            v-if="hayArchivados"
            class="ml-auto inline-flex items-center gap-2 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            <input v-model="verArchivados" type="checkbox" />
            {{ $t("planes.verArchivados") }}
          </label>
        </div>

        <div class="relative overflow-x-auto">
          <table class="pv-tabla">
            <thead>
              <tr>
                <th>{{ $t("planesVisual.col.plan") }}</th>
                <th class="hidden sm:table-cell">
                  {{ $t("planesVisual.col.incluye") }}
                </th>
                <th class="hidden md:table-cell">
                  {{ $t("planesVisual.col.dura") }}
                </th>
                <th class="hidden lg:table-cell">
                  {{ $t("planesVisual.col.sirve") }}
                </th>
                <th class="text-right">{{ $t("planesVisual.col.precio") }}</th>
                <th>{{ $t("planesVisual.col.estado") }}</th>
                <th>
                  <span class="sr-only">{{
                    $t("planesVisual.col.acciones")
                  }}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="p in visibles"
                :key="p.id"
                :class="{ 'pv-archivado': p.archivado }"
                data-prueba="plan"
              >
                <td>
                  <p class="font-medium">{{ p.nombre }}</p>
                  <p v-if="p.todas_sucursales !== undefined" class="pv-sub">
                    {{
                      p.todas_sucursales
                        ? $t("sucursalOperativa.todas")
                        : p.sucursales?.map((s) => s.nombre).join(" · ")
                    }}
                  </p>
                  <p class="pv-sub">{{ nombreSeccion(seccionDe(p.tipo)) }}</p>
                </td>
                <td class="hidden sm:table-cell">{{ clases(p) }}</td>
                <td class="hidden md:table-cell pv-suave">{{ vigencia(p) }}</td>
                <td class="hidden lg:table-cell pv-suave pv-corta">
                  {{ aplicaA(p) }}
                </td>
                <td class="text-right font-medium tabular-nums">
                  {{ dinero(p.precio_minor, p.moneda) }}
                </td>
                <td>
                  <span
                    class="tu-pildora"
                    :style="{
                      '--tono': p.archivado
                        ? 'var(--texto-suave)'
                        : 'var(--exito)',
                    }"
                    >{{
                      p.archivado
                        ? $t("planes.archivado")
                        : $t("planesVisual.aLaVenta")
                    }}</span
                  >
                </td>
                <td class="text-right">
                  <button
                    v-if="puedeEditar"
                    type="button"
                    class="tu-enlace text-sm"
                    data-prueba="editar-plan"
                    @click="abrir(p)"
                  >
                    {{ $t("planes.editar") }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="visibles.length === 0" class="pv-sin-resultados">
            {{ $t("planesVisual.sinResultados") }}
          </p>
        </div>
      </div>
    </template>

    <ModalDialogo
      :abierto="abierto"
      :titulo="
        editando
          ? $t('planes.editor.tituloEditar')
          : $t('planes.editor.tituloNuevo')
      "
      icono="etiqueta"
      @cerrar="abierto = false"
    >
      <EditorPlan
        v-if="abierto"
        :key="editando?.id ?? 'nuevo'"
        :plan="editando"
        @guardado="guardado"
        @cerrar="abierto = false"
      />
    </ModalDialogo>
  </section>
</template>

<style scoped>
.pv-filtros {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  padding: 0.9rem 1rem;
  border-bottom: 1px solid var(--borde);
}
.pv-buscar {
  position: relative;
  flex: 1 1 14rem;
  color: var(--texto-suave);
}
.pv-buscar > :first-child {
  position: absolute;
  top: 50%;
  left: 0.75rem;
  transform: translateY(-50%);
}
.pv-buscar > input {
  padding-left: 2.25rem;
}
.pv-tabla {
  width: 100%;
  font-size: 0.9rem;
  border-collapse: collapse;
}
.pv-tabla th {
  padding: 0.7rem 1rem;
  color: var(--texto-suave);
  font-size: 0.78rem;
  font-weight: 500;
  text-align: left;
}
.pv-tabla th.text-right {
  text-align: right;
}
.pv-tabla td {
  padding: 0.75rem 1rem;
  border-top: 1px solid var(--borde);
  vertical-align: middle;
}
.pv-archivado td {
  color: var(--texto-suave);
}
.pv-sub {
  color: var(--texto-suave);
  font-size: 0.78rem;
}
.pv-suave {
  color: var(--texto-suave);
}
.pv-corta {
  max-width: 16rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pv-sin-resultados {
  padding: 2rem 1rem;
  border-top: 1px solid var(--borde);
  color: var(--texto-suave);
  font-size: 0.9rem;
  text-align: center;
}
</style>
