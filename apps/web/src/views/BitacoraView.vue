<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import RangoFechas from "@/components/RangoFechas.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Bitácora del estudio: los cambios sensibles (quién, qué y el antes/después) y
 * los accesos registrados en recepción (entradas permitidas o denegadas).
 */
const { t, te } = useI18n();

type Valor = string | number | boolean | null;
interface Cambio {
  id: string;
  actor: string | null;
  accion: string;
  // Qué pasó, en palabras (del servidor).
  descripcion?: string;
  entidad_tipo: string;
  motivo: string | null;
  antes: Record<string, Valor> | null;
  despues: Record<string, Valor> | null;
  fecha: string | null;
}
interface Acceso {
  id: string;
  persona: string | null;
  metodo: string;
  permitido: boolean;
  codigo: string;
  registrado_en: string;
}
type Pestana = "cambios" | "accesos";
// Las entradas van por páginas: el API busca, filtra y cuenta por resultado.
interface MetaEntradas {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
  conteos: Record<string, number>;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeCambios = computed(() => sesion.puede("auditoria.ver"));
// La bitácora de entradas, solo en negocios que controlan la entrada (ADR 0105).
const puedeAccesos = computed(
  () => sesion.accesoAbierto && sesion.puede("checkins.registrar"),
);

const pestana = ref<Pestana>(puedeCambios.value ? "cambios" : "accesos");
const cambios = ref<Cambio[]>([]);
const accesos = ref<Acceso[]>([]);
const abierto = ref<string | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

// Filtros de los cambios (fechas en la zona del negocio) y paginación.
const desde = ref("");
const hasta = ref("");
const usuario = ref("");
const categoria = ref("");
const texto = ref("");
const pagina = ref(1);
const ultimaPagina = ref(1);
const actores = ref<{ id: string; nombre: string }[]>([]);
const categorias = ref<string[]>([]);
const descargando = ref(false);

// Filtros y página de las entradas.
const buscarEntradas = ref("");
const resultadoEntradas = ref<"" | "permitido" | "denegado">("");
const desdeEntradas = ref("");
const hastaEntradas = ref("");
const paginaEntradas = ref(1);
const metaEntradas = ref<MetaEntradas | null>(null);
const conFiltrosEntradas = computed(
  () =>
    buscarEntradas.value.trim() !== "" ||
    resultadoEntradas.value !== "" ||
    desdeEntradas.value !== "" ||
    hastaEntradas.value !== "",
);
function limpiarFiltrosEntradas(): void {
  buscarEntradas.value = "";
  resultadoEntradas.value = "";
  desdeEntradas.value = "";
  hastaEntradas.value = "";
}

function filtros(): Record<string, string | number | undefined> {
  return {
    desde: desde.value || undefined,
    hasta: hasta.value || undefined,
    usuario: usuario.value || undefined,
    categoria: categoria.value || undefined,
    q: texto.value.trim() || undefined,
  };
}

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(iso));
}

function accion(c: Cambio): string {
  if (c.descripcion && c.descripcion !== c.accion) {
    return c.descripcion;
  }
  const clave = `bitacora.acciones.${c.accion}`;
  return te(clave) ? t(clave) : c.accion;
}

/** Campos que cambiaron, con su valor antes y después. */
function diferencias(
  c: Cambio,
): { campo: string; antes: string; despues: string }[] {
  const campos = new Set([
    ...Object.keys(c.antes ?? {}),
    ...Object.keys(c.despues ?? {}),
  ]);
  const texto = (v: Valor | undefined): string =>
    v === undefined || v === null || v === "" ? "—" : String(v);
  return [...campos]
    .filter((k) => (c.antes ?? {})[k] !== (c.despues ?? {})[k])
    .map((k) => ({
      campo: k,
      antes: texto((c.antes ?? {})[k]),
      despues: texto((c.despues ?? {})[k]),
    }));
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    if (pestana.value === "cambios") {
      const { data } = await api.get<{
        data: Cambio[];
        meta?: {
          ultima_pagina: number;
          actores: { id: string; nombre: string }[];
          categorias: string[];
        };
      }>(`${base.value}/auditorias`, {
        params: { ...filtros(), page: pagina.value, per_page: 50 },
      });
      cambios.value = data.data;
      ultimaPagina.value = data.meta?.ultima_pagina ?? 1;
      actores.value = data.meta?.actores ?? [];
      categorias.value = data.meta?.categorias ?? [];
    } else {
      const q = buscarEntradas.value.trim();
      const { data } = await api.get<{ data: Acceso[]; meta?: MetaEntradas }>(
        `${base.value}/accesos`,
        {
          params: {
            page: paginaEntradas.value,
            ...(q !== "" ? { q } : {}),
            ...(resultadoEntradas.value !== ""
              ? { resultado: resultadoEntradas.value }
              : {}),
            ...(desdeEntradas.value !== ""
              ? { desde: desdeEntradas.value }
              : {}),
            ...(hastaEntradas.value !== ""
              ? { hasta: hastaEntradas.value }
              : {}),
          },
        },
      );
      accesos.value = data.data;
      metaEntradas.value = data.meta ?? null;
    }
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function irPestana(p: Pestana): void {
  pestana.value = p;
  void cargar();
}

function irPagina(n: number): void {
  if (n < 1 || n > ultimaPagina.value) {
    return;
  }
  pagina.value = n;
  void cargar();
}

let espera: ReturnType<typeof setTimeout> | undefined;
watch([desde, hasta, usuario, categoria, texto], () => {
  clearTimeout(espera);
  espera = setTimeout(() => {
    pagina.value = 1;
    void cargar();
  }, 300);
});

function irPaginaEntradas(n: number): void {
  paginaEntradas.value = n;
  void cargar();
}
// La búsqueda espera a que se deje de escribir; el resultado y las fechas recargan ya.
let esperaEntradas: ReturnType<typeof setTimeout> | undefined;
watch(buscarEntradas, () => {
  clearTimeout(esperaEntradas);
  esperaEntradas = setTimeout(() => irPaginaEntradas(1), 300);
});
watch([resultadoEntradas, desdeEntradas, hastaEntradas], () => {
  if (
    desdeEntradas.value === "" ||
    hastaEntradas.value === "" ||
    hastaEntradas.value >= desdeEntradas.value
  ) {
    irPaginaEntradas(1);
  }
});
onBeforeUnmount(() => {
  clearTimeout(espera);
  clearTimeout(esperaEntradas);
});

async function descargar(): Promise<void> {
  descargando.value = true;
  try {
    const { data } = await api.get<Blob>(`${base.value}/auditorias`, {
      params: { ...filtros(), formato: "csv" },
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = "bitacora.csv";
    enlace.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    descargando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion :titulo="$t('bitacora.titulo')" />

    <div
      v-if="puedeCambios && puedeAccesos"
      class="tu-pestanas mt-6"
      role="group"
    >
      <button
        type="button"
        :aria-pressed="pestana === 'cambios'"
        @click="irPestana('cambios')"
      >
        {{ $t("bitacora.cambios") }}
      </button>
      <button
        type="button"
        :aria-pressed="pestana === 'accesos'"
        @click="irPestana('accesos')"
      >
        {{ $t("bitacora.accesos") }}
      </button>
    </div>

    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
    </AvisoTono>

    <div class="mt-5 tu-card">
      <!-- Filtros de los cambios -->
      <div v-if="pestana === 'cambios'" class="tu-filtros bi-filtros text-sm">
        <label class="grid gap-1">
          <span class="tu-label">{{ $t("bitacora.desde") }}</span>
          <input v-model="desde" type="date" class="tu-input" />
        </label>
        <label class="grid gap-1">
          <span class="tu-label">{{ $t("bitacora.hasta") }}</span>
          <input v-model="hasta" type="date" class="tu-input" />
        </label>
        <label class="grid gap-1">
          <span class="tu-label">{{ $t("bitacora.quien") }}</span>
          <select v-model="usuario" class="tu-input">
            <option value="">{{ $t("bitacora.todos") }}</option>
            <option v-for="a in actores" :key="a.id" :value="a.id">
              {{ a.nombre }}
            </option>
          </select>
        </label>
        <label class="grid gap-1">
          <span class="tu-label">{{ $t("bitacora.categoria") }}</span>
          <select v-model="categoria" class="tu-input">
            <option value="">{{ $t("bitacora.todos") }}</option>
            <option v-for="c in categorias" :key="c" :value="c">
              {{ $t(`bitacora.categorias.${c}`) }}
            </option>
          </select>
        </label>
        <label class="tu-buscar self-end">
          <IconoNav nombre="buscar" :tam="16" />
          <input
            v-model="texto"
            type="search"
            class="tu-input"
            :placeholder="$t('bitacora.buscar')"
            :aria-label="$t('bitacora.buscar')"
          />
        </label>
        <button
          type="button"
          class="tu-btn tu-btn-fantasma text-sm"
          :disabled="descargando"
          @click="descargar"
        >
          {{ $t("bitacora.descargar") }}
        </button>
      </div>
      <!-- Filtros de las entradas -->
      <div v-else class="tu-filtros">
        <label class="tu-buscar">
          <IconoNav nombre="buscar" :tam="16" />
          <input
            v-model="buscarEntradas"
            type="search"
            class="tu-input"
            :placeholder="$t('operacion.entradas.buscar')"
            :aria-label="$t('operacion.entradas.buscar')"
            data-prueba="buscar-entradas"
          />
        </label>
        <div
          class="tu-segmentado"
          role="group"
          :aria-label="$t('operacion.entradas.resultado')"
        >
          <button
            v-for="r in ['', 'permitido', 'denegado'] as const"
            :key="r"
            type="button"
            :aria-pressed="resultadoEntradas === r"
            :data-prueba="`entradas-${r || 'todas'}`"
            @click="resultadoEntradas = r"
          >
            {{
              r === ""
                ? $t("operacion.entradas.todas")
                : $t("operacion.salida.conCuenta", {
                    estado: $t(`operacion.entradas.${r}`),
                    n: metaEntradas?.conteos[r] ?? 0,
                  })
            }}
          </button>
        </div>
        <RangoFechas
          v-model:desde="desdeEntradas"
          v-model:hasta="hastaEntradas"
          prueba="entradas"
          atajos
          :zona="sesion.zonaHoraria"
        />
        <button
          v-if="conFiltrosEntradas"
          type="button"
          class="tu-enlace text-sm"
          @click="limpiarFiltrosEntradas"
        >
          {{ $t("operacion.listados.quitarFiltros") }}
        </button>
      </div>

      <div class="px-5 pb-3 pt-2">
        <p
          v-if="cargando"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>

        <!-- Cambios -->
        <template v-else-if="pestana === 'cambios'">
          <EstadoVacio
            v-if="cambios.length === 0"
            class="py-6"
            icono="lista"
            compacto
            :titulo="$t('bitacora.sinCambios')"
          />
          <ul v-else>
            <li v-for="c in cambios" :key="c.id" class="bi-fila text-sm">
              <div class="flex w-full items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="font-medium">{{ accion(c) }}</p>
                  <p
                    class="mt-0.5 text-xs"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ fecha(c.fecha) }} ·
                    {{
                      $t("bitacora.por", {
                        actor: c.actor ?? $t("bitacora.sistema"),
                      })
                    }}
                    <template v-if="c.motivo"> · {{ c.motivo }}</template>
                  </p>
                </div>
                <button
                  v-if="diferencias(c).length > 0"
                  type="button"
                  class="tu-enlace shrink-0"
                  :aria-expanded="abierto === c.id"
                  @click="abierto = abierto === c.id ? null : c.id"
                >
                  {{ $t("bitacora.detalle") }}
                </button>
              </div>
              <table v-if="abierto === c.id" class="tu-tabla bi-detalle">
                <thead>
                  <tr>
                    <th></th>
                    <th>{{ $t("bitacora.antes") }}</th>
                    <th>{{ $t("bitacora.despues") }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="d in diferencias(c)" :key="d.campo">
                    <td class="font-medium">{{ d.campo }}</td>
                    <td>{{ d.antes }}</td>
                    <td>{{ d.despues }}</td>
                  </tr>
                </tbody>
              </table>
            </li>
          </ul>
          <div
            v-if="ultimaPagina > 1"
            class="mt-3 flex items-center justify-between gap-3 text-sm"
          >
            <button
              type="button"
              class="tu-enlace"
              :disabled="pagina <= 1"
              @click="irPagina(pagina - 1)"
            >
              <IconoNav
                nombre="atras"
                :tam="14"
                class="inline align-[-0.15em]"
              />
              {{ $t("bitacora.anterior") }}
            </button>
            <span :style="{ color: 'var(--texto-suave)' }">{{
              $t("bitacora.pagina", { page: pagina, total: ultimaPagina })
            }}</span>
            <button
              type="button"
              class="tu-enlace"
              :disabled="pagina >= ultimaPagina"
              @click="irPagina(pagina + 1)"
            >
              {{ $t("bitacora.siguiente") }}
              <IconoNav
                nombre="flecha"
                :tam="14"
                class="inline align-[-0.15em]"
              />
            </button>
          </div>
        </template>

        <!-- Accesos -->
        <template v-else>
          <EstadoVacio
            v-if="accesos.length === 0"
            class="py-6"
            :icono="conFiltrosEntradas ? 'buscar' : 'lista'"
            compacto
            :titulo="
              conFiltrosEntradas
                ? $t('operacion.listados.sinResultados')
                : $t('bitacora.sinAccesos')
            "
          />
          <ul v-else>
            <li v-for="a in accesos" :key="a.id" class="bi-fila text-sm">
              <div class="min-w-0">
                <p class="font-medium truncate">{{ a.persona ?? "—" }}</p>
                <p
                  class="mt-0.5 text-xs"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ fecha(a.registrado_en) }} ·
                  {{ $t(`bitacora.metodos.${a.metodo}`) }} ·
                  {{ $t(`accesoRecepcion.codigos.${a.codigo}`) }}
                </p>
              </div>
              <span
                class="tu-pildora shrink-0"
                :style="{
                  '--tono': a.permitido ? 'var(--exito)' : 'var(--error)',
                }"
                >{{
                  a.permitido
                    ? $t("accesoRecepcion.permitido")
                    : $t("accesoRecepcion.denegado")
                }}</span
              >
            </li>
          </ul>
        </template>
      </div>
      <PaginacionListado
        v-if="
          pestana === 'accesos' &&
          !cargando &&
          metaEntradas &&
          metaEntradas.ultima_pagina > 1
        "
        :page="metaEntradas.page"
        :ultima-pagina="metaEntradas.ultima_pagina"
        :total="metaEntradas.total"
        :per-page="metaEntradas.per_page"
        @ir="irPaginaEntradas"
      />
    </div>
  </section>
</template>

<style scoped>
.bi-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.bi-fila:first-child {
  border-top: 0;
}
.bi-filtros {
  align-items: flex-end;
}
.bi-detalle {
  margin-top: 0.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton);
  font-size: 0.8rem;
}
</style>
