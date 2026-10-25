<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { crearClientePlataforma } from "@/lib/clientePlataforma";
import { mensajeDeError } from "@/lib/api";
import { useFiltrosEnUrl } from "@/lib/filtrosEnUrl";
import { useToastStore } from "@/stores/toast";

/**
 * Monitoreo de errores para el superadmin (ADR 0080): los errores de la API, la web y
 * la app agrupados, por estado y origen. El detalle trae la traza y el contexto de la
 * última vez (con lo sensible tachado). Se marcan resueltos o ignorados; uno resuelto
 * que vuelve en otra versión se reabre solo.
 *
 * Por páginas cortas (no una lista que crece con «Ver más»): cada error en dos
 * renglones y lo de consulta rara (traza, contexto) en el panel al abrirlo.
 */
const props = defineProps<{ apiUrl: string; token: string }>();

type Estado = "abierto" | "resuelto" | "ignorado";
type Origen = "api" | "web" | "app";
interface ErrorResumen {
  id: string;
  origen: Origen;
  tipo: string;
  mensaje: string;
  lugar: string | null;
  veces: number;
  primera_en: string;
  ultima_en: string;
  version_primera: string | null;
  version_ultima: string | null;
  estado: Estado;
  resuelto_en: string | null;
  regresiones: number;
  estudio: string | null;
}
interface ErrorDetalle extends ErrorResumen {
  traza: string | null;
  contexto: Record<string, string>;
}
// El `meta` de un lote de la API (`page` es el lote, no la página que se ve).
interface Meta {
  total: number;
  page: number;
  ultima_pagina: number;
  conteos: Record<Estado, number>;
}
interface Lote {
  data: ErrorResumen[];
  meta: Meta;
}
// Lo que se va a ver, ya armado: la lista traída, el meta del último lote y la página.
interface Vista {
  items: ErrorResumen[];
  meta: Meta;
  pagina: number;
}

const ESTADOS: Estado[] = ["abierto", "resuelto", "ignorado"];
const ORIGENES: Origen[] = ["api", "web", "app"];
// Cuántos se ven a la vez. La API entrega lotes más grandes: se piden los que falten
// para llegar a la página elegida (y no se vuelven a pedir los ya traídos).
const POR_PAGINA = 15;

const { t } = useI18n();
const toast = useToastStore();
const cliente = crearClientePlataforma({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});
const auth = (): { headers: Record<string, string> } => ({
  headers: { Authorization: `Bearer ${props.token}` },
});

const estado = ref<Estado>("abierto");
const origen = ref<Origen | "">("");
// Lo ya traído de la API, en su orden (los lotes 1…`meta.page`), y el meta del último.
const errores = ref<ErrorResumen[]>([]);
const meta = ref<Meta>({
  total: 0,
  page: 1,
  ultima_pagina: 1,
  conteos: { abierto: 0, resuelto: 0, ignorado: 0 },
});
const total = computed(() => meta.value.total);
const conteos = computed(() => meta.value.conteos);
// La página que se ve.
const pagina = ref(1);
const cargando = ref(false);
const error = ref<string | null>(null);

const detalle = ref<ErrorDetalle | null>(null);
const cambiando = ref(false);

const paginasDe = (cuantos: number): number =>
  Math.max(1, Math.ceil(cuantos / POR_PAGINA));
const ultimaPagina = computed(() => paginasDe(total.value));
const visibles = computed(() =>
  errores.value.slice(
    (pagina.value - 1) * POR_PAGINA,
    pagina.value * POR_PAGINA,
  ),
);

// El estado y el origen, en la dirección (un enlace o recargar los conservan). Con
// claves propias: `estado` ya es el filtro de Negocios. «Abiertos» no se escribe.
useFiltrosEnUrl({
  estadoError: computed({
    get: () => (estado.value === "abierto" ? "" : estado.value),
    set: (v: string) => {
      if (v === "") {
        estado.value = "abierto";
      } else if ((ESTADOS as string[]).includes(v)) {
        estado.value = v as Estado;
      }
    },
  }),
  origenError: computed({
    get: () => origen.value,
    set: (v: string) => {
      if (v === "" || (ORIGENES as string[]).includes(v)) {
        origen.value = v as Origen | "";
      }
    },
  }),
});

// Cada búsqueda nueva (otro filtro, «Actualizar», otra página) deja sin efecto las
// respuestas que aún esperaba la anterior.
let vigente = 0;
// El último intento (a qué página y si desde el lote 1): «Reintentar» lo repite.
let intento = { n: 1, desdeCero: true };

/** Pide el lote `n` con los filtros de ahora. Solo lo pide: no cambia lo que se ve. */
async function pedirLote(n: number): Promise<Lote> {
  const { data } = await cliente.get<Lote>("/api/v1/plataforma/errores", {
    ...auth(),
    params: {
      estado: estado.value,
      origen: origen.value || undefined,
      page: n,
    },
  });
  return data;
}

/**
 * Arma la página `n` sin tocar lo que se ve: parte de `desde` (lo ya traído) o, sin
 * él, del lote 1, y suma los lotes que falten para llenarla. Si a medio camino bajó el
 * total (otros resolvieron o ignoraron), lo ya juntado no cuadra con lo que sigue (la
 * lista se recorrió) y se vuelve a empezar desde el lote 1. La página se acota a la
 * última que existe. `null` si mientras tanto empezó otra búsqueda.
 */
async function armar(
  n: number,
  desde: { items: ErrorResumen[]; meta: Meta } | null,
  busqueda: number,
): Promise<Vista | null> {
  let items: ErrorResumen[];
  let ultimo: Meta;
  if (desde === null) {
    const primero = await pedirLote(1);
    if (busqueda !== vigente) {
      return null;
    }
    items = primero.data;
    ultimo = primero.meta;
  } else {
    items = desde.items;
    ultimo = desde.meta;
  }
  const objetivo = Math.min(n, paginasDe(ultimo.total));
  while (
    items.length < Math.min(objetivo * POR_PAGINA, ultimo.total) &&
    ultimo.page < ultimo.ultima_pagina
  ) {
    const siguiente = await pedirLote(ultimo.page + 1);
    if (busqueda !== vigente) {
      return null;
    }
    if (siguiente.meta.total < ultimo.total) {
      return armar(n, null, busqueda);
    }
    // Si entró uno nuevo mientras tanto, el lote siguiente repite el último: sin
    // duplicarlo.
    const ya = new Set(items.map((e) => e.id));
    items = [...items, ...siguiente.data.filter((e) => !ya.has(e.id))];
    ultimo = siguiente.meta;
  }
  return {
    items,
    meta: ultimo,
    pagina: Math.min(objetivo, paginasDe(ultimo.total)),
  };
}

/**
 * Lleva a la página `n`. Con `desdeCero` vuelve a pedir la lista desde el lote 1; si
 * no, suma a lo ya traído solo los lotes que falten. Lo que se ve (la lista, la página
 * y los conteos) cambia una sola vez, con todo ya traído: sin pasar por la página 1 ni
 * por una página a medias. Si falla un lote, nada cambia y el aviso deja reintentar.
 */
async function ir(n: number, desdeCero: boolean): Promise<void> {
  intento = { n, desdeCero };
  const busqueda = ++vigente;
  cargando.value = true;
  error.value = null;
  try {
    const vista = await armar(
      n,
      desdeCero ? null : { items: errores.value, meta: meta.value },
      busqueda,
    );
    if (vista !== null) {
      errores.value = vista.items;
      meta.value = vista.meta;
      pagina.value = vista.pagina;
    }
  } catch (e) {
    if (busqueda === vigente) {
      error.value = mensajeDeError(e);
    }
  } finally {
    if (busqueda === vigente) {
      cargando.value = false;
    }
  }
}

/**
 * Vuelve a pedir la lista desde el principio. Con `quedarse` (actualizar, después de
 * marcar uno) sigue en la misma página, o en la última si ya no alcanza.
 */
function cargar(quedarse = false): Promise<void> {
  return ir(quedarse ? pagina.value : 1, true);
}

function irPagina(n: number): void {
  if (!cargando.value) {
    void ir(n, false);
  }
}

function reintentar(): void {
  void ir(intento.n, intento.desdeCero);
}

async function abrir(e: ErrorResumen): Promise<void> {
  try {
    const { data } = await cliente.get<{ data: ErrorDetalle }>(
      `/api/v1/plataforma/errores/${e.id}`,
      auth(),
    );
    detalle.value = data.data;
  } catch (falla) {
    toast.error(mensajeDeError(falla));
  }
}

async function marcar(nuevo: Estado): Promise<void> {
  if (detalle.value === null) {
    return;
  }
  cambiando.value = true;
  try {
    await cliente.put(
      `/api/v1/plataforma/errores/${detalle.value.id}`,
      { estado: nuevo },
      auth(),
    );
    toast.exito(t(`plataformaAdmin.errores.marcado.${nuevo}`));
    detalle.value = null;
    await cargar(true);
  } catch (falla) {
    toast.error(mensajeDeError(falla));
  } finally {
    cambiando.value = false;
  }
}

/** El nombre corto de la clase (`App\…\RuntimeException` → `RuntimeException`). */
function tipoCorto(tipo: string): string {
  return tipo.split("\\").pop() ?? tipo;
}

function fechaHora(iso: string): string {
  return new Intl.DateTimeFormat("es-MX", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(iso));
}

watch([estado, origen], () => void cargar());
onMounted(() => void cargar());
</script>

<template>
  <div class="tu-card p-5" data-prueba="errores-plataforma">
    <!-- Sin título propio: el encabezado de la sección ya dice «Errores». -->
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("plataformaAdmin.errores.subtitulo") }}
    </p>

    <div class="mt-3 flex flex-wrap items-center gap-3">
      <div class="tu-segmentado" role="group" data-prueba="estados">
        <button
          v-for="e in ESTADOS"
          :key="e"
          type="button"
          :aria-pressed="estado === e"
          :data-prueba="`estado-${e}`"
          @click="estado = e"
        >
          {{ $t(`plataformaAdmin.errores.estados.${e}`) }}
          <span
            class="ml-1 tabular-nums"
            :style="{ color: 'var(--texto-suave)' }"
            >{{ conteos[e] }}</span
          >
        </button>
      </div>
      <select
        v-model="origen"
        class="tu-input er-origen"
        :aria-label="$t('plataformaAdmin.errores.origen')"
      >
        <option value="">{{ $t("plataformaAdmin.errores.todos") }}</option>
        <option v-for="o in ORIGENES" :key="o" :value="o">
          {{ $t(`plataformaAdmin.errores.origenes.${o}`) }}
        </option>
      </select>
      <button
        type="button"
        class="tu-btn tu-btn-secundario ml-auto"
        :disabled="cargando"
        :aria-busy="cargando || undefined"
        data-prueba="actualizar"
        @click="cargar(true)"
      >
        {{ $t("plataformaAdmin.errores.actualizar") }}
      </button>
    </div>

    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
      <template #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          :disabled="cargando"
          data-prueba="reintentar"
          @click="reintentar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <p
      v-else-if="!cargando && errores.length === 0"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
      data-prueba="vacio"
    >
      {{ $t(`plataformaAdmin.errores.vacio.${estado}`) }}
    </p>

    <!-- Dos renglones por error: qué falló (y si volvió) y de dónde, cuántas veces y
         cuándo. El resto, en el panel. -->
    <ul class="mt-3" :aria-busy="cargando || undefined">
      <li v-for="e in visibles" :key="e.id" class="er-fila">
        <button
          type="button"
          class="er-boton"
          data-prueba="error"
          @click="abrir(e)"
        >
          <span class="er-linea">
            <span class="er-texto text-sm">
              <span class="font-medium">{{ tipoCorto(e.tipo) }}</span>
              <span :style="{ color: 'var(--texto-suave)' }">
                · {{ e.mensaje }}</span
              >
            </span>
            <span
              v-if="e.regresiones > 0"
              class="tu-estado er-volvio text-xs"
              :style="{ '--tono': 'var(--aviso)' }"
              data-prueba="volvio"
            >
              {{ $t("plataformaAdmin.errores.volvio", e.regresiones) }}
            </span>
          </span>
          <span
            class="er-texto er-detalle mt-0.5 text-xs"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t(`plataformaAdmin.errores.origenes.${e.origen}`) }}
            <template v-if="e.lugar"> · {{ e.lugar }}</template>
            · {{ $t("plataformaAdmin.errores.veces", e.veces) }} ·
            {{ fechaHora(e.ultima_en) }}
            <template v-if="e.version_ultima">
              · {{ e.version_ultima }}</template
            >
            <template v-if="e.estudio"> · {{ e.estudio }}</template>
          </span>
        </button>
      </li>
    </ul>
    <PaginacionListado
      v-if="ultimaPagina > 1"
      class="mt-2"
      :page="pagina"
      :ultima-pagina="ultimaPagina"
      :total="total"
      :per-page="POR_PAGINA"
      data-prueba="paginas-errores"
      @ir="irPagina"
    />

    <PanelLateral
      :abierto="detalle !== null"
      :titulo="detalle ? tipoCorto(detalle.tipo) : ''"
      @cerrar="detalle = null"
    >
      <div v-if="detalle" class="space-y-5 p-5 text-sm" data-prueba="detalle">
        <p
          class="tu-estado"
          :style="{
            '--tono':
              detalle.estado === 'abierto'
                ? 'var(--error)'
                : 'var(--texto-suave)',
          }"
        >
          {{ $t(`plataformaAdmin.errores.estado.${detalle.estado}`) }}
        </p>
        <p class="er-mensaje">{{ detalle.mensaje }}</p>
        <dl class="er-datos">
          <dt>{{ $t("plataformaAdmin.errores.clase") }}</dt>
          <dd class="font-mono text-xs">{{ detalle.tipo }}</dd>
          <dt>{{ $t("plataformaAdmin.errores.origen") }}</dt>
          <dd>
            {{ $t(`plataformaAdmin.errores.origenes.${detalle.origen}`) }}
          </dd>
          <template v-if="detalle.lugar">
            <dt>{{ $t("plataformaAdmin.errores.lugar") }}</dt>
            <dd class="font-mono text-xs">{{ detalle.lugar }}</dd>
          </template>
          <dt>{{ $t("plataformaAdmin.errores.cuantas") }}</dt>
          <dd>{{ $t("plataformaAdmin.errores.veces", detalle.veces) }}</dd>
          <dt>{{ $t("plataformaAdmin.errores.primera") }}</dt>
          <dd>
            {{ fechaHora(detalle.primera_en) }}
            <template v-if="detalle.version_primera">
              · {{ detalle.version_primera }}</template
            >
          </dd>
          <dt>{{ $t("plataformaAdmin.errores.ultima") }}</dt>
          <dd>
            {{ fechaHora(detalle.ultima_en) }}
            <template v-if="detalle.version_ultima">
              · {{ detalle.version_ultima }}</template
            >
          </dd>
          <template v-if="detalle.regresiones > 0">
            <dt>{{ $t("plataformaAdmin.errores.regresiones") }}</dt>
            <dd>
              {{ $t("plataformaAdmin.errores.volvio", detalle.regresiones) }}
            </dd>
          </template>
        </dl>

        <section v-if="Object.keys(detalle.contexto).length > 0">
          <h3 class="er-subtitulo">
            {{ $t("plataformaAdmin.errores.contexto") }}
          </h3>
          <dl class="er-datos" data-prueba="contexto">
            <template v-for="(valor, clave) in detalle.contexto" :key="clave">
              <dt>{{ clave }}</dt>
              <dd class="font-mono text-xs break-all">{{ valor }}</dd>
            </template>
          </dl>
        </section>

        <section v-if="detalle.traza">
          <h3 class="er-subtitulo">
            {{ $t("plataformaAdmin.errores.traza") }}
          </h3>
          <pre class="er-traza" data-prueba="traza">{{ detalle.traza }}</pre>
        </section>
      </div>
      <template #pie>
        <div v-if="detalle" class="flex gap-2">
          <template v-if="detalle.estado === 'abierto'">
            <button
              type="button"
              class="tu-btn tu-btn-primario flex-1"
              :disabled="cambiando"
              :aria-busy="cambiando || undefined"
              data-prueba="resolver"
              @click="marcar('resuelto')"
            >
              {{ $t("plataformaAdmin.errores.resolver") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-secundario flex-1"
              :disabled="cambiando"
              data-prueba="ignorar"
              @click="marcar('ignorado')"
            >
              {{ $t("plataformaAdmin.errores.ignorar") }}
            </button>
          </template>
          <button
            v-else
            type="button"
            class="tu-btn tu-btn-secundario flex-1"
            :disabled="cambiando"
            :aria-busy="cambiando || undefined"
            data-prueba="reabrir"
            @click="marcar('abierto')"
          >
            {{ $t("plataformaAdmin.errores.reabrir") }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </div>
</template>

<style scoped>
.er-origen {
  width: auto;
  min-width: 9rem;
}
.er-fila {
  border-top: 1px solid var(--borde);
}
.er-fila:first-child {
  border-top: 0;
}
.er-boton {
  display: block;
  width: 100%;
  padding: 0.55rem 0;
  text-align: left;
  min-width: 0;
}
/* Primer renglón: qué falló (se recorta) y, si volvió, el aviso a la derecha; en un
   ancho chico el aviso baja en lugar de dejar el texto sin espacio. */
.er-linea {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.15rem 0.75rem;
  min-width: 0;
}
.er-linea > .er-texto {
  flex: 1 1 14rem;
}
.er-texto {
  display: block;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.er-volvio {
  flex-shrink: 0;
  white-space: nowrap;
}
.er-detalle {
  font-variant-numeric: tabular-nums;
}
.er-mensaje {
  word-break: break-word;
}
.er-subtitulo {
  margin-bottom: 0.4rem;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--texto-suave);
}
.er-datos {
  display: grid;
  grid-template-columns: minmax(6rem, auto) 1fr;
  gap: 0.35rem 1rem;
}
.er-datos dt {
  color: var(--texto-suave);
}
.er-traza {
  max-height: 22rem;
  overflow: auto;
  padding: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: 0.5rem;
  font-size: 0.72rem;
  line-height: 1.5;
  white-space: pre;
}
</style>
