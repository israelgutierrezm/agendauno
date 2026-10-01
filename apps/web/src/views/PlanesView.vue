<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EditorPlan from "@/components/EditorPlan.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
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
 * Planes y paquetes (ADR 0050): lo que se vende para reservar, agrupado por tipo
 * (paquetes, membresías, clase suelta, clases extra, otros), con su precio, cuántas
 * clases incluye, cuánto dura y para qué clases sirve.
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

const secciones = computed(() =>
  SECCIONES.map((s) => ({
    clave: s,
    planes: planes.value.filter(
      (p) => seccionDe(p.tipo) === s && (verArchivados.value || !p.archivado),
    ),
  })).filter((s) => s.planes.length > 0),
);
const hayArchivados = computed(() => planes.value.some((p) => p.archivado));

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
function vigencia(p: Plan): string | null {
  if (p.tipo === "add_on") {
    return t("planes.resumen.conElPaquete");
  }
  if (p.tipo === "membresia") {
    return null;
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
function aplicaA(p: Plan): string | null {
  if (p.tipo === "add_on") {
    return null;
  }
  return p.ofertas.length === 0
    ? t("planes.resumen.todas")
    : t("planes.resumen.soloAlgunas", {
        clases: p.ofertas.map((o) => o.nombre).join(", "),
      });
}

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
  <section class="mx-auto max-w-6xl px-4 py-8">
    <EncabezadoSeccion :titulo="$t('planes.titulo')">
      <template #acciones>
        <button
          v-if="puedeEditar"
          type="button"
          class="tu-btn tu-btn-primario tu-btn-crear"
          @click="abrir(null)"
        >
          {{ $t("planes.nuevo") }}
        </button>
      </template>
    </EncabezadoSeccion>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("planes.subtitulo") }}
    </p>

    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
    <p v-if="cargando" class="mt-6" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p
      v-else-if="secciones.length === 0"
      class="mt-6"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("planes.vacio") }}
    </p>

    <div v-for="s in secciones" :key="s.clave" class="mt-8">
      <h2 class="font-semibold">{{ $t(`planes.secciones.${s.clave}`) }}</h2>
      <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="p in s.planes" :key="p.id">
          <article
            class="pl-tarjeta tu-card"
            :class="{ 'pl-archivado': p.archivado }"
          >
            <div class="flex items-start justify-between gap-2">
              <h3 class="font-medium">{{ p.nombre }}</h3>
              <span
                v-if="p.archivado"
                class="shrink-0 text-xs"
                :style="{ color: 'var(--texto-suave)' }"
                >{{ $t("planes.archivado") }}</span
              >
            </div>
            <p class="mt-1 text-2xl font-semibold tabular-nums">
              {{ dinero(p.precio_minor, p.moneda) }}
            </p>
            <p class="mt-1 text-sm">{{ clases(p) }}</p>
            <ul
              class="mt-2 space-y-0.5 text-sm"
              :style="{ color: 'var(--texto-suave)' }"
            >
              <li v-if="vigencia(p)">{{ vigencia(p) }}</li>
              <li v-if="aplicaA(p)">{{ aplicaA(p) }}</li>
            </ul>
            <button
              v-if="puedeEditar"
              type="button"
              class="tu-enlace mt-3 text-sm"
              @click="abrir(p)"
            >
              {{ $t("planes.editar") }}
            </button>
          </article>
        </li>
      </ul>
    </div>

    <label
      v-if="hayArchivados"
      class="mt-8 inline-flex items-center gap-2 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      <input v-model="verArchivados" type="checkbox" />
      {{ $t("planes.verArchivados") }}
    </label>

    <ModalDialogo
      :abierto="abierto"
      :titulo="
        editando
          ? $t('planes.editor.tituloEditar')
          : $t('planes.editor.tituloNuevo')
      "
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
.pl-tarjeta {
  display: flex;
  height: 100%;
  flex-direction: column;
  padding: 1.1rem 1.2rem;
}
.pl-tarjeta > button {
  align-self: flex-start;
  margin-top: auto;
  padding-top: 0.75rem;
}
.pl-archivado {
  opacity: 0.6;
}
</style>
