<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import CuotasNegocio from "@/components/CuotasNegocio.vue";
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
import { useContenedorEditor } from "@/lib/editorFormulario";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

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
// Cuotas del negocio (inscripción y anualidad; B04).
const cuotasAbiertas = ref(false);

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
// Cómo se renueva, aparte de cuánto dura: la membresía cada mes (y si acumula lo no
// usado); lo demás no se renueva solo.
function renovacion(p: Plan): string {
  if (p.tipo === "add_on") {
    return "";
  }
  if (p.tipo !== "membresia") {
    return t("planesVisual.renovacion.no");
  }
  const cada = t("planesVisual.renovacion.cadaMes");
  if (p.ilimitado) {
    return cada;
  }
  if (p.politica_rollover === "completo") {
    return `${cada} · ${t("planesVisual.renovacion.acumula")}`;
  }
  if (p.politica_rollover === "limitado" && p.rollover_max) {
    const n = clasesDe(p.rollover_max);
    return `${cada} · ${t("planesVisual.renovacion.acumulaHasta", { n }, n)}`;
  }
  return `${cada} · ${t("planesVisual.renovacion.noAcumula")}`;
}
// Restricciones de compra, cada una por separado (no mezcladas con lo que incluye).
function restricciones(p: Plan): string[] {
  const lista: string[] = [];
  if (p.primera_vez) {
    lista.push(t("planesVisual.restricciones.primeraVez"));
  }
  if (p.permanencia_meses) {
    lista.push(
      t(
        "planesVisual.restricciones.permanencia",
        { n: p.permanencia_meses },
        p.permanencia_meses,
      ),
    );
  }
  return lista;
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
    // Los negocios de citas no tienen membresías (ADR 0130).
    ...(sesion.esCitas
      ? []
      : [
          {
            clave: "membresias",
            etiqueta: t("planes.secciones.membresia"),
            valor: String(contar("membresia")),
            icono: "reloj",
          },
        ]),
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

const truncado = ref<number | null>(null);
async function cargar(): Promise<void> {
  error.value = null;
  try {
    const { data } = await api.get<{
      data: Plan[];
      meta?: { truncado: boolean; limite: number };
    }>(`${base.value}/productos`, { params: { incluir: "todos" } });
    planes.value = data.data;
    // Nada se corta en silencio: si hay más de los que caben, se dice.
    truncado.value = data.meta?.truncado ? data.meta.limite : null;
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
// Los editores avisan si guardan o tienen cambios: el diálogo no se cierra a medio
// guardar ni descarta sin preguntar.
const editorPlan = useContenedorEditor(abierto);
const editorCuotas = useContenedorEditor(cuotasAbiertas);
const toast = useToastStore();
async function guardado(nombre: string): Promise<void> {
  editorPlan.cerrado();
  toast.exito(t("planes.editor.guardadoNombre", { nombre }));
  await cargar();
}
function cuotasGuardadas(): void {
  editorCuotas.cerrado();
  toast.exito(t("planes.cuotas.guardadas"));
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('planes.titulo')"
      :subtitulo="$t('planes.subtitulo')"
    >
      <template #acciones>
        <button
          v-if="puedeEditar && !sesion.esCitas"
          type="button"
          class="tu-btn tu-btn-fantasma"
          data-prueba="abrir-cuotas"
          @click="cuotasAbiertas = true"
        >
          {{ $t("planes.cuotas.titulo") }}
        </button>
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

    <p v-if="cargando" class="mt-6" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <!-- No se pudo consultar: no es «aún no tienes planes». -->
    <div
      v-else-if="error"
      class="mt-6 tu-card flex flex-wrap items-center justify-between gap-3 p-6 text-sm"
      role="alert"
      data-prueba="error-carga-planes"
    >
      <span style="color: var(--error)">{{ error }}</span>
      <button type="button" class="tu-btn tu-btn-fantasma" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </div>
    <EstadoVacio
      v-else-if="planes.length === 0"
      class="tu-card mt-6"
      icono="etiqueta"
      :titulo="$t('planes.vacio')"
    />

    <template v-else>
      <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />
      <AvisoTono
        v-if="truncado !== null"
        tono="aviso"
        class="mt-4"
        data-prueba="planes-truncado"
      >
        {{ $t("planesVisual.truncado", { n: truncado }) }}
      </AvisoTono>

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
                  <p
                    v-if="restricciones(p).length > 0"
                    class="pv-sub"
                    data-prueba="restricciones-plan"
                  >
                    {{ restricciones(p).join(" · ") }}
                  </p>
                </td>
                <td class="hidden sm:table-cell">{{ clases(p) }}</td>
                <td class="hidden md:table-cell pv-suave">
                  {{ vigencia(p) }}
                  <span
                    v-if="renovacion(p)"
                    class="pv-sub block"
                    data-prueba="renovacion-plan"
                    >{{ renovacion(p) }}</span
                  >
                </td>
                <td class="hidden lg:table-cell pv-suave pv-corta">
                  <details
                    v-if="p.tipo !== 'add_on' && p.ofertas.length > 2"
                    class="pv-incluidas"
                  >
                    <summary>
                      {{
                        $t(
                          "planesVisual.validoPara",
                          { n: p.ofertas.length },
                          p.ofertas.length,
                        )
                      }}
                    </summary>
                    <ul>
                      <li v-for="oferta in p.ofertas" :key="oferta.id">
                        {{ oferta.nombre }}
                      </li>
                    </ul>
                  </details>
                  <template v-else>{{ aplicaA(p) }}</template>
                </td>
                <td class="text-right font-medium tabular-nums">
                  {{ dinero(p.precio_minor, p.moneda, sesion.pais) }}
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
      :ocupado="editorPlan.ocupado.value"
      @cerrar="editorPlan.cerrar"
    >
      <EditorPlan
        v-if="abierto"
        :key="editando?.id ?? 'nuevo'"
        :plan="editando"
        @guardado="guardado"
        @cerrar="editorPlan.cerrar"
        @estado="editorPlan.alEstado"
      />
    </ModalDialogo>

    <ModalDialogo
      :abierto="cuotasAbiertas"
      :titulo="$t('planes.cuotas.titulo')"
      icono="dinero"
      :ocupado="editorCuotas.ocupado.value"
      @cerrar="editorCuotas.cerrar"
    >
      <CuotasNegocio
        v-if="cuotasAbiertas"
        @guardado="cuotasGuardadas"
        @cerrar="editorCuotas.cerrar"
        @estado="editorCuotas.alEstado"
      />
    </ModalDialogo>
  </section>
</template>

<style scoped>
.pv-incluidas summary {
  cursor: pointer;
  color: var(--primario-fuerte);
  padding-block: 0.6rem;
}
.pv-incluidas ul {
  margin-top: 0.25rem;
  padding-left: 1rem;
  list-style: disc;
  max-height: 12rem;
  overflow-y: auto;
  font-size: 0.8rem;
  white-space: normal;
  overflow-wrap: anywhere;
}
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
