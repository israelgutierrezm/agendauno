<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import PanelLateral from "@/components/PanelLateral.vue";
import { mensajeDeError } from "@/lib/api";
import { useToastStore } from "@/stores/toast";

/**
 * Monitoreo de errores para el superadmin (ADR 0080): los errores de la API, la web y
 * la app agrupados, por estado y origen. El detalle trae la traza y el contexto de la
 * última vez (con lo sensible tachado). Se marcan resueltos o ignorados; uno resuelto
 * que vuelve en otra versión se reabre solo.
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

const ESTADOS: Estado[] = ["abierto", "resuelto", "ignorado"];
const ORIGENES: Origen[] = ["api", "web", "app"];

const { t } = useI18n();
const toast = useToastStore();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});
const auth = (): { headers: Record<string, string> } => ({
  headers: { Authorization: `Bearer ${props.token}` },
});

const estado = ref<Estado>("abierto");
const origen = ref<Origen | "">("");
const errores = ref<ErrorResumen[]>([]);
const conteos = ref<Record<Estado, number>>({
  abierto: 0,
  resuelto: 0,
  ignorado: 0,
});
const pagina = ref(1);
const ultimaPagina = ref(1);
const cargando = ref(false);
const error = ref<string | null>(null);

const detalle = ref<ErrorDetalle | null>(null);
const cambiando = ref(false);

const hayMas = computed(() => pagina.value < ultimaPagina.value);

async function cargar(desde = 1): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await cliente.get<{
      data: ErrorResumen[];
      meta: {
        page: number;
        ultima_pagina: number;
        conteos: Record<Estado, number>;
      };
    }>("/api/v1/plataforma/errores", {
      ...auth(),
      params: {
        estado: estado.value,
        origen: origen.value || undefined,
        page: desde,
      },
    });
    errores.value = desde === 1 ? data.data : [...errores.value, ...data.data];
    pagina.value = data.meta.page;
    ultimaPagina.value = data.meta.ultima_pagina;
    conteos.value = data.meta.conteos;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
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
    await cargar();
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
  <div class="tu-card p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="font-light text-lg">
          {{ $t("plataformaAdmin.errores.titulo") }}
        </h2>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("plataformaAdmin.errores.subtitulo") }}
        </p>
      </div>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma text-sm"
        :disabled="cargando"
        @click="cargar()"
      >
        {{ $t("plataformaAdmin.errores.actualizar") }}
      </button>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
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
    </div>

    <p
      v-if="error"
      class="mt-4 text-sm"
      role="alert"
      style="color: var(--error)"
    >
      {{ error }}
    </p>
    <p
      v-else-if="!cargando && errores.length === 0"
      class="mt-5 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
      data-prueba="vacio"
    >
      {{ $t(`plataformaAdmin.errores.vacio.${estado}`) }}
    </p>

    <ul class="mt-4">
      <li v-for="e in errores" :key="e.id" class="er-fila">
        <button
          type="button"
          class="er-boton"
          data-prueba="error"
          @click="abrir(e)"
        >
          <p class="text-sm">
            <span class="font-medium">{{ tipoCorto(e.tipo) }}</span>
            <span :style="{ color: 'var(--texto-suave)' }">
              · {{ e.mensaje }}</span
            >
          </p>
          <p
            class="mt-0.5 text-xs er-detalle"
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
          </p>
          <p
            v-if="e.regresiones > 0"
            class="er-estado mt-0.5 text-xs"
            data-prueba="volvio"
          >
            <span class="er-punto er-aviso" aria-hidden="true"></span>
            {{ $t("plataformaAdmin.errores.volvio", e.regresiones) }}
          </p>
        </button>
      </li>
    </ul>
    <button
      v-if="hayMas"
      type="button"
      class="tu-btn tu-btn-fantasma mt-3 text-sm"
      :disabled="cargando"
      @click="cargar(pagina + 1)"
    >
      {{ $t("plataformaAdmin.errores.verMas") }}
    </button>

    <PanelLateral
      :abierto="detalle !== null"
      :titulo="detalle ? tipoCorto(detalle.tipo) : ''"
      @cerrar="detalle = null"
    >
      <div v-if="detalle" class="space-y-5 p-5 text-sm" data-prueba="detalle">
        <p class="er-estado">
          <span
            class="er-punto"
            :class="detalle.estado === 'abierto' ? 'er-error' : 'er-gris'"
            aria-hidden="true"
          ></span>
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
              data-prueba="resolver"
              @click="marcar('resuelto')"
            >
              {{ $t("plataformaAdmin.errores.resolver") }}
            </button>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma flex-1"
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
            class="tu-btn tu-btn-fantasma flex-1"
            :disabled="cambiando"
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
  padding: 0.7rem 0;
  text-align: left;
  min-width: 0;
}
.er-boton p {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.er-detalle {
  font-variant-numeric: tabular-nums;
}
.er-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}
.er-punto {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  flex-shrink: 0;
}
.er-error {
  background: var(--error);
}
.er-aviso {
  background: var(--aviso);
}
.er-gris {
  background: var(--texto-suave);
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
