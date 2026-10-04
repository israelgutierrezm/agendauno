<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import CalificacionEstrellas from "@/components/CalificacionEstrellas.vue";
import IconoNav from "@/components/IconoNav.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Reseñas de los clientes, con el patrón de los listados (Nómina, Horarios):
 * indicadores arriba, filtros, la lista y, al lado, el promedio de cada profesional.
 * Un comentario se puede ocultar de la página pública del negocio.
 */
interface Resena {
  id: string;
  calificacion: number;
  comentario: string | null;
  visible: boolean;
  actividad: string | null;
  con: string | null;
  persona: string | null;
  fecha: string | null;
}
interface Promedio {
  promedio: number;
  total: number;
  nombre?: string | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeOcultar = computed(() => sesion.puede("miembros.gestionar"));

const resenas = ref<Resena[]>([]);
const general = ref<Promedio | null>(null);
const porProfesional = ref<Promedio[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

// Filtros: calificación, profesional y texto.
type Filtro = "todas" | "5" | "4" | "3";
const filtro = ref<Filtro>("todas");
const profesional = ref("");
const busqueda = ref("");
const pagina = ref(1);
const meta = ref<{
  page: number;
  ultima_pagina: number;
  total: number;
  per_page: number;
} | null>(null);
const conteos = ref<Record<Filtro, number> | null>(null);
const comentarios = ref<number | null>(null);
let solicitud = 0;

function fecha(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat("es-MX", {
        day: "numeric",
        month: "short",
        year: "numeric",
      }).format(new Date(iso))
    : "";
}

async function cargar(): Promise<void> {
  const actual = ++solicitud;
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: Resena[];
      meta?: NonNullable<typeof meta.value>;
      resumen: {
        general: Promedio;
        por_profesional: Promedio[];
        conteos?: Record<Filtro, number>;
        con_comentario?: number;
      };
    }>(`${base.value}/resenas`, {
      params: {
        page: pagina.value,
        per_page: 20,
        q: busqueda.value.trim(),
        calificacion: filtro.value,
        profesional: profesional.value,
      },
    });
    if (actual !== solicitud) return;
    meta.value = data.meta ?? null;
    conteos.value = data.resumen.conteos ?? null;
    comentarios.value = data.resumen.con_comentario ?? null;
    resenas.value = data.data;
    general.value = data.resumen.general;
    porProfesional.value = [...data.resumen.por_profesional].sort(
      (a, b) => b.promedio - a.promedio,
    );
  } catch (e) {
    if (actual === solicitud) error.value = mensajeDeError(e);
  } finally {
    if (actual === solicitud) cargando.value = false;
  }
}

async function alternar(r: Resena): Promise<void> {
  try {
    const { data } = await api.put<{ data: Resena }>(
      `${base.value}/resenas/${r.id}/visible`,
      { visible: !r.visible },
    );
    Object.assign(r, data.data);
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

const cuenta = (f: Filtro): number =>
  conteos.value
    ? conteos.value[f]
    : f === "todas"
      ? resenas.value.length
      : resenas.value.filter((r) =>
          f === "3" ? r.calificacion <= 3 : r.calificacion === Number(f),
        ).length;
const visibles = computed(() => {
  if (meta.value) return resenas.value;
  const q = busqueda.value.trim().toLowerCase();
  return resenas.value.filter(
    (r) =>
      (filtro.value === "todas" ||
        (filtro.value === "3"
          ? r.calificacion <= 3
          : r.calificacion === Number(filtro.value))) &&
      (profesional.value === "" || r.con === profesional.value) &&
      (q === "" ||
        `${r.persona ?? ""} ${r.comentario ?? ""} ${r.actividad ?? ""}`
          .toLowerCase()
          .includes(q)),
  );
});
const nombresProfesionales = computed(() =>
  [
    ...new Set(
      [
        ...porProfesional.value.map((p) => p.nombre),
        ...resenas.value.map((r) => r.con),
      ].filter((x) => x),
    ),
  ].sort(),
);

const indicadores = computed<Indicador[]>(() => {
  const g = general.value;
  const conComentario =
    comentarios.value ?? resenas.value.filter((r) => r.comentario).length;
  const cinco =
    conteos.value?.["5"] ??
    resenas.value.filter((r) => r.calificacion === 5).length;
  const total = conteos.value?.todas ?? resenas.value.length;
  return [
    {
      clave: "promedio",
      etiqueta: t("listadosVisual.resenas.promedio"),
      valor: g && g.total > 0 ? g.promedio.toFixed(1) : "—",
      icono: "estrella",
      tono: "naranja",
    },
    {
      clave: "total",
      etiqueta: t("listadosVisual.resenas.total"),
      valor: String(g?.total ?? 0),
      icono: "mensaje",
      tono: "azul",
    },
    {
      clave: "cinco",
      etiqueta: t("listadosVisual.resenas.cinco"),
      valor: total > 0 ? `${Math.round((cinco / total) * 100)} %` : "—",
      icono: "hecho",
      tono: "verde",
    },
    {
      clave: "comentario",
      etiqueta: t("listadosVisual.resenas.conComentario"),
      valor: String(conComentario),
      icono: "contenido",
      tono: "morado",
    },
  ];
});

watch([filtro, profesional, busqueda], () => {
  if (!meta.value) return;
  pagina.value = 1;
  void cargar();
});
function irPagina(n: number): void {
  pagina.value = n;
  void cargar();
}
onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      :titulo="$t('resenas.titulo')"
      :subtitulo="$t('listadosVisual.resenas.subtitulo')"
    />

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />

    <div class="rs-cuerpo mt-5">
      <div class="tu-card p-5">
        <!-- Filtros -->
        <div class="flex flex-wrap items-center gap-3">
          <div
            class="tu-segmentado"
            role="group"
            :aria-label="$t('listadosVisual.resenas.calificacion')"
          >
            <button
              v-for="f in ['todas', '5', '4', '3'] as const"
              :key="f"
              type="button"
              :aria-pressed="filtro === f"
              @click="filtro = f"
            >
              {{ $t(`listadosVisual.resenas.filtros.${f}`) }}
              <span class="rs-cuenta">{{ cuenta(f) }}</span>
            </button>
          </div>
          <label v-if="nombresProfesionales.length > 1" class="tu-select-icono">
            <IconoNav nombre="instructores" :tam="16" />
            <select
              v-model="profesional"
              class="tu-input"
              :aria-label="$t('listadosVisual.resenas.profesional')"
            >
              <option value="">
                {{ $t("listadosVisual.resenas.todosProfesionales") }}
              </option>
              <option v-for="n in nombresProfesionales" :key="n!" :value="n">
                {{ n }}
              </option>
            </select>
          </label>
          <label class="tu-campo-icono ml-auto min-w-[14rem]">
            <IconoNav nombre="buscar" :tam="16" />
            <input
              v-model="busqueda"
              type="search"
              class="tu-input w-full"
              :placeholder="$t('listadosVisual.resenas.buscar')"
            />
          </label>
        </div>

        <p
          v-if="cargando"
          class="mt-5 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>
        <EstadoVacio
          v-else-if="visibles.length === 0"
          class="mt-4 py-6"
          icono="mensaje"
          compacto
          :titulo="
            resenas.length === 0
              ? $t('resenas.vacio')
              : $t('listadosVisual.resenas.sinCoincidencias')
          "
        />
        <ul v-else class="rs-lista mt-4" data-prueba="resenas">
          <li
            v-for="r in visibles"
            :key="r.id"
            :class="{ 'rs-oculta': !r.visible }"
          >
            <AvatarIniciales :nombre="r.persona" tam="md" />
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="font-medium">{{ r.persona ?? "—" }}</span>
                <CalificacionEstrellas :valor="r.calificacion" />
                <span
                  v-if="!r.visible"
                  class="tu-pildora"
                  :style="{ '--tono': 'var(--texto-suave)' }"
                  >{{ $t("listadosVisual.resenas.oculta") }}</span
                >
              </div>
              <p v-if="r.comentario" class="mt-1">{{ r.comentario }}</p>
              <p class="rs-meta">
                {{ r.actividad ?? "—" }}
                <template v-if="r.con"> · {{ r.con }}</template>
                · {{ fecha(r.fecha) }}
              </p>
            </div>
            <button
              v-if="puedeOcultar && r.comentario"
              type="button"
              class="tu-enlace shrink-0 text-sm"
              @click="alternar(r)"
            >
              {{ r.visible ? $t("resenas.ocultar") : $t("resenas.mostrar") }}
            </button>
          </li>
        </ul>
        <PaginacionListado
          v-if="meta && !cargando"
          :page="meta.page"
          :ultima-pagina="meta.ultima_pagina"
          :total="meta.total"
          :per-page="meta.per_page"
          @ir="irPagina"
        />
      </div>

      <!-- Promedio de cada profesional -->
      <aside class="tu-card p-5">
        <h2 class="font-medium">{{ $t("resenas.porProfesional") }}</h2>
        <p
          v-if="porProfesional.length === 0"
          class="mt-2 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("listadosVisual.resenas.sinProfesionales") }}
        </p>
        <ul v-else class="rs-ranking mt-3">
          <li v-for="p in porProfesional" :key="p.nombre ?? ''">
            <div class="flex items-center justify-between gap-3">
              <span class="flex min-w-0 items-center gap-2">
                <AvatarIniciales :nombre="p.nombre" tam="sm" />
                <span class="truncate">{{ p.nombre ?? "—" }}</span>
              </span>
              <span class="shrink-0 font-medium tabular-nums">{{
                p.promedio.toFixed(1)
              }}</span>
            </div>
            <div class="rs-barra" aria-hidden="true">
              <span :style="{ width: `${(p.promedio / 5) * 100}%` }" />
            </div>
            <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("resenas.total", { n: p.total }) }}
            </p>
          </li>
        </ul>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.rs-cuerpo {
  display: grid;
  gap: 1.25rem;
  align-items: start;
}
@media (min-width: 1024px) {
  .rs-cuerpo {
    grid-template-columns: minmax(0, 1fr) 20rem;
  }
}
.rs-cuenta {
  margin-left: 0.35rem;
  color: var(--texto-suave);
  font-variant-numeric: tabular-nums;
}
.rs-lista > li {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  padding: 0.9rem 0;
  font-size: 0.9rem;
}
.rs-lista > li + li {
  border-top: 1px solid var(--borde);
}
.rs-oculta {
  opacity: 0.6;
}
.rs-meta {
  margin-top: 0.2rem;
  color: var(--texto-suave);
  font-size: 0.8rem;
}
.rs-ranking {
  display: grid;
  gap: 0.9rem;
  font-size: 0.9rem;
}
.rs-barra {
  height: 0.35rem;
  margin: 0.4rem 0 0.25rem;
  border-radius: 999px;
  background: var(--superficie-2);
  overflow: hidden;
}
.rs-barra > span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--aviso);
}
</style>
