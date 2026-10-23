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
import { opcionEnUrl, useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Planes y paquetes (ADR 0050) con el patrón de los listados: indicadores, búsqueda
 * y filtro por tipo (en la dirección), y la tabla con precio, qué incluye, cuánto
 * dura, para qué sirve y si está a la venta; en el teléfono, una ficha por plan. Se
 * crean y editan en un diálogo (EditorPlan) con Guardar y Cancelar en su pie.
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
// La búsqueda, el tipo y si se ven los archivados, en la dirección: recargar o
// compartir el enlace conserva lo que se veía.
useFiltrosEnUrl({
  q: busqueda,
  tipo: opcionEnUrl(seccion, ["", ...SECCIONES]),
  archivados: computed<string>({
    get: () => (verArchivados.value ? "1" : ""),
    set: (v: string) => {
      verArchivados.value = v === "1";
    },
  }),
});
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
// Avisos de coherencia del catálogo (precios por clase que suben, «ilimitado» con
// tope, reglas apagadas): no impiden vender.
const avisos = ref<{ codigo: string; producto_id: string; mensaje: string }[]>(
  [],
);
async function cargarAvisos(): Promise<void> {
  try {
    const { data } = await api.get<{ data: typeof avisos.value }>(
      `${base.value}/productos/coherencia`,
    );
    avisos.value = data.data;
  } catch {
    // Son una ayuda: si no llegan, la lista sigue igual.
    avisos.value = [];
  }
}
async function cargar(): Promise<void> {
  error.value = null;
  void cargarAvisos();
  try {
    const { data } = await api.get<{
      data: Plan[];
      meta?: { truncado: boolean; limite: number };
    }>(`${base.value}/productos`, { params: { incluir: "todos" } });
    planes.value = data.data;
    // Un tipo de la dirección del que ya no hay planes no deja la lista vacía sin
    // un filtro a la vista para quitarlo.
    if (
      seccion.value !== "" &&
      !(seccionesConPlanes.value as readonly string[]).includes(seccion.value)
    ) {
      seccion.value = "";
    }
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
async function guardado(
  nombre: string,
  avisosDelPlan: string[] = [],
): Promise<void> {
  editorPlan.cerrado();
  toast.exito(t("planes.editor.guardadoNombre", { nombre }));
  for (const aviso of avisosDelPlan) {
    toast.aviso(aviso);
  }
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
          class="tu-btn tu-btn-secundario"
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
      <button type="button" class="tu-btn tu-btn-secundario" @click="cargar">
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
      <AvisoTono
        v-if="avisos.length > 0"
        tono="aviso"
        class="mt-4"
        data-prueba="planes-coherencia"
      >
        <p class="font-medium">{{ $t("planes.coherencia.titulo") }}</p>
        <ul class="mt-1 list-disc space-y-1 pl-5">
          <li v-for="a in avisos" :key="`${a.codigo}-${a.producto_id}`">
            {{ a.mensaje }}
          </li>
        </ul>
        <p class="mt-1 text-xs">{{ $t("planes.coherencia.ayuda") }}</p>
      </AvisoTono>

      <div class="tu-card mt-5">
        <div class="tu-filtros">
          <label class="tu-buscar">
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

        <div class="relative overflow-x-auto tu-tabla-adaptable">
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>{{ $t("planesVisual.col.plan") }}</th>
                <th class="text-right">{{ $t("planesVisual.col.precio") }}</th>
                <th class="hidden sm:table-cell">
                  {{ $t("planesVisual.col.incluye") }}
                </th>
                <th class="hidden md:table-cell">
                  {{ $t("planesVisual.col.dura") }}
                </th>
                <th class="hidden lg:table-cell">
                  {{ $t("planesVisual.col.sirve") }}
                </th>
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
                <td :data-etiqueta="$t('planesVisual.col.plan')">
                  <!-- Tocar el nombre abre el plan, igual que «Editar». -->
                  <button
                    v-if="puedeEditar"
                    type="button"
                    class="pv-nombre"
                    @click="abrir(p)"
                  >
                    {{ p.nombre }}
                  </button>
                  <span v-else class="font-medium">{{ p.nombre }}</span>
                  <span v-if="p.todas_sucursales !== undefined" class="tu-sub">
                    {{
                      p.todas_sucursales
                        ? $t("sucursalOperativa.todas")
                        : p.sucursales?.map((s) => s.nombre).join(" · ")
                    }}
                  </span>
                  <span class="tu-sub">{{
                    nombreSeccion(seccionDe(p.tipo))
                  }}</span>
                  <span
                    v-if="restricciones(p).length > 0"
                    class="tu-sub"
                    data-prueba="restricciones-plan"
                  >
                    {{ restricciones(p).join(" · ") }}
                  </span>
                </td>
                <td
                  class="text-right font-medium tabular-nums"
                  :data-etiqueta="$t('planesVisual.col.precio')"
                >
                  {{ dinero(p.precio_minor, p.moneda, sesion.pais) }}
                </td>
                <td
                  class="hidden sm:table-cell"
                  :data-etiqueta="$t('planesVisual.col.incluye')"
                >
                  {{ clases(p) }}
                </td>
                <td
                  class="hidden md:table-cell pv-suave"
                  :data-etiqueta="$t('planesVisual.col.dura')"
                >
                  <span>
                    {{ vigencia(p) }}
                    <span
                      v-if="renovacion(p)"
                      class="tu-sub"
                      data-prueba="renovacion-plan"
                      >{{ renovacion(p) }}</span
                    >
                  </span>
                </td>
                <td
                  class="hidden lg:table-cell pv-suave pv-corta"
                  :data-etiqueta="$t('planesVisual.col.sirve')"
                >
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
                  <span v-else>{{ aplicaA(p) }}</span>
                </td>
                <td :data-etiqueta="$t('planesVisual.col.estado')">
                  <span
                    class="tu-estado"
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
                <td class="tu-celda-acciones">
                  <button
                    v-if="puedeEditar"
                    type="button"
                    class="tu-btn tu-btn-secundario tu-btn-chico"
                    data-prueba="editar-plan"
                    @click="abrir(p)"
                  >
                    {{ $t("planes.editar") }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="visibles.length === 0" class="tu-sin-resultados">
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
      tam="xl"
      icono="etiqueta"
      :ocupado="editorPlan.ocupado.value"
      @cerrar="editorPlan.cerrar"
    >
      <EditorPlan
        v-if="abierto"
        :key="editando?.id ?? 'nuevo'"
        :plan="editando"
        @guardado="guardado"
        @estado="editorPlan.alEstado"
      />
      <!-- Guardar y Cancelar siempre a la vista, sin bajar todo el formulario. -->
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="editorPlan.ocupado.value"
          @click="editorPlan.cerrar"
        >
          {{ $t("planes.editor.cancelar") }}
        </button>
        <button
          type="submit"
          form="form-plan"
          class="tu-btn tu-btn-primario"
          data-prueba="guardar-plan"
          :disabled="editorPlan.ocupado.value || !editorPlan.valido.value"
          :aria-busy="editorPlan.ocupado.value || undefined"
        >
          {{
            editorPlan.ocupado.value
              ? $t("planes.editor.guardando")
              : $t("planes.editor.guardar")
          }}
        </button>
      </template>
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
        @estado="editorCuotas.alEstado"
      />
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="editorCuotas.ocupado.value"
          @click="editorCuotas.cerrar"
        >
          {{ $t("planes.editor.cancelar") }}
        </button>
        <button
          type="submit"
          form="form-cuotas"
          class="tu-btn tu-btn-primario"
          data-prueba="guardar-cuotas"
          :disabled="editorCuotas.ocupado.value || !editorCuotas.valido.value"
          :aria-busy="editorCuotas.ocupado.value || undefined"
        >
          {{
            editorCuotas.ocupado.value
              ? $t("planes.editor.guardando")
              : $t("planes.editor.guardar")
          }}
        </button>
      </template>
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
.pv-archivado td {
  color: var(--texto-suave);
}
.pv-suave {
  color: var(--texto-suave);
}
/* El nombre abre el plan: se lee como el título de la fila, no como un enlace. */
.pv-nombre {
  font-weight: 500;
  text-align: left;
}
.pv-nombre:hover {
  text-decoration: underline;
}
/* En la tabla, «Válido para» en una línea; en la ficha del teléfono, completo. */
@media (min-width: 640px) {
  .pv-corta {
    max-width: 16rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}
</style>
