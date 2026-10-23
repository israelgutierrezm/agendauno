<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Las plantillas de WhatsApp del negocio para sus campañas (ADR 0190). Las escribe el
 * negocio, la plataforma las manda a aprobar a Meta y, aprobadas, se eligen al mandar
 * una campaña por WhatsApp. Dice cuánto gasta y cuesta cada mensaje según su
 * categoría (marketing cuesta más en Meta).
 */
export interface PlantillaWhatsApp {
  id: string;
  titulo: string;
  categoria: "utilidad" | "marketing";
  cuerpo: string;
  pie: string | null;
  estado:
    | "borrador"
    | "en_revision"
    | "aprobada"
    | "rechazada"
    | "pausada"
    | "archivada";
  motivo: string | null;
  editable: boolean;
}
interface Meta {
  disponible: boolean;
  max_cuerpo: number;
  cobra: boolean;
  categorias: {
    clave: "utilidad" | "marketing";
    mensajes: number;
    precio_minor: number | null;
  }[];
}

const emit = defineEmits<{ cambio: [] }>();
const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(
  () => `/api/v1/app/${sesion.slug}/comunicaciones/whatsapp/plantillas`,
);
const puedeGestionar = computed(() => sesion.puede("comunicaciones.gestionar"));
const puedeArchivar = computed(() => sesion.puede("comunicaciones.eliminar"));

const plantillas = ref<PlantillaWhatsApp[]>([]);
const meta = ref<Meta | null>(null);
const error = ref<string | null>(null);
const errores = ref<Record<string, string[]>>({});
const trabajando = ref<string | null>(null);

// El editor: nueva (id null) o una existente que aún se puede cambiar.
const editando = ref<{
  id: string | null;
  titulo: string;
  categoria: "utilidad" | "marketing";
  cuerpo: string;
  pie: string;
} | null>(null);

const TONOS: Record<PlantillaWhatsApp["estado"], string> = {
  borrador: "var(--texto-suave)",
  en_revision: "var(--aviso)",
  aprobada: "var(--exito)",
  rechazada: "var(--error)",
  pausada: "var(--aviso)",
  archivada: "var(--texto-suave)",
};

// Los marcadores van armados en el script: en la plantilla, Vue leería las llaves.
const MARCADOR_NOMBRE = "{{persona_nombre}}";
const MARCADOR_NEGOCIO = "{{negocio}}";
const ayudaMarcadores = computed(() =>
  t("plantillasWhatsApp.marcadores", {
    nombre: MARCADOR_NOMBRE,
    negocio: MARCADOR_NEGOCIO,
  }),
);
const ejemplo = (texto: string): string =>
  texto
    .replace(/\{\{\s*persona_nombre\s*\}\}/g, "Ana")
    .replace(/\{\{\s*negocio\s*\}\}/g, sesion.estudio?.nombre ?? "");

const dinero = (minor: number): string =>
  new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(
    minor / 100,
  );
function costo(clave: "utilidad" | "marketing"): string {
  const c = meta.value?.categorias.find((x) => x.clave === clave);
  if (!c) {
    return "";
  }
  return c.precio_minor === null
    ? t("plantillasWhatsApp.mensajes", { n: c.mensajes }, c.mensajes)
    : t(
        "plantillasWhatsApp.costo",
        { n: c.mensajes, precio: dinero(c.precio_minor) },
        c.mensajes,
      );
}

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: PlantillaWhatsApp[]; meta: Meta }>(
      base.value,
    );
    plantillas.value = data.data;
    meta.value = data.meta;
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

function nueva(): void {
  errores.value = {};
  editando.value = {
    id: null,
    titulo: "",
    categoria: "marketing",
    cuerpo: "",
    pie: "",
  };
}
function editar(p: PlantillaWhatsApp): void {
  errores.value = {};
  editando.value = {
    id: p.id,
    titulo: p.titulo,
    categoria: p.categoria,
    cuerpo: p.cuerpo,
    pie: p.pie ?? "",
  };
}

async function hacer(clave: string, accion: () => Promise<unknown>) {
  if (trabajando.value !== null) {
    return;
  }
  trabajando.value = clave;
  error.value = null;
  try {
    await accion();
    await cargar();
    emit("cambio");
  } catch (e) {
    const datos = (
      e as { response?: { data?: { meta?: { errors?: unknown } } } }
    ).response?.data?.meta?.errors;
    if (datos && typeof datos === "object") {
      errores.value = datos as Record<string, string[]>;
    }
    error.value = mensajeDeError(e);
  } finally {
    trabajando.value = null;
  }
}

function guardar(): Promise<void> {
  const e = editando.value;
  if (e === null) {
    return Promise.resolve();
  }
  const cuerpo = {
    titulo: e.titulo.trim(),
    categoria: e.categoria,
    cuerpo: e.cuerpo,
    pie: e.pie.trim() || null,
  };
  return hacer("guardar", async () => {
    await (e.id === null
      ? api.post(base.value, cuerpo)
      : api.put(`${base.value}/${e.id}`, cuerpo));
    editando.value = null;
    errores.value = {};
  });
}

function enviar(p: PlantillaWhatsApp): Promise<void> {
  return hacer(p.id, () => api.post(`${base.value}/${p.id}/enviar`));
}
function revisar(p: PlantillaWhatsApp): Promise<void> {
  return hacer(p.id, () => api.post(`${base.value}/${p.id}/revisar`));
}
async function archivar(p: PlantillaWhatsApp): Promise<void> {
  if (
    !(await confirmar(t("plantillasWhatsApp.archivarConfirmar"), {
      aceptar: t("plantillasWhatsApp.archivar"),
      peligro: true,
    }))
  ) {
    return;
  }
  await hacer(p.id, () => api.delete(`${base.value}/${p.id}`));
}

onMounted(cargar);
</script>

<template>
  <div class="mt-6 space-y-4" data-prueba="plantillas-whatsapp">
    <div class="tu-card p-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 class="font-semibold">{{ $t("plantillasWhatsApp.titulo") }}</h3>
          <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("plantillasWhatsApp.ayuda") }}
          </p>
        </div>
        <button
          v-if="puedeGestionar && meta?.disponible && editando === null"
          type="button"
          class="tu-btn tu-btn-primario"
          data-prueba="nueva-plantilla-whatsapp"
          @click="nueva"
        >
          {{ $t("plantillasWhatsApp.nueva") }}
        </button>
      </div>
      <ul
        v-if="meta"
        class="mt-3 text-sm space-y-1"
        data-prueba="costos-whatsapp"
      >
        <li>
          <strong>{{ $t("plantillasWhatsApp.categoria.utilidad") }}:</strong>
          {{ costo("utilidad") }}
        </li>
        <li>
          <strong>{{ $t("plantillasWhatsApp.categoria.marketing") }}:</strong>
          {{ costo("marketing") }}
        </li>
        <li v-if="!meta.cobra" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("plantillasWhatsApp.sinCosto") }}
        </li>
      </ul>
      <p
        v-if="meta && !meta.disponible"
        class="mt-3 text-sm"
        data-prueba="plantillas-no-disponibles"
      >
        {{ $t("plantillasWhatsApp.noDisponible") }}
      </p>
    </div>

    <!-- Editor -->
    <form
      v-if="editando"
      class="tu-card p-5 grid gap-4"
      data-prueba="editor-plantilla-whatsapp"
      @submit.prevent="guardar"
    >
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("plantillasWhatsApp.nombre") }}</span>
        <input
          v-model="editando.titulo"
          class="tu-input"
          maxlength="80"
          required
          data-prueba="plantilla-titulo"
        />
        <span v-if="errores.titulo" class="tu-error-campo">{{
          errores.titulo[0]
        }}</span>
      </label>
      <fieldset>
        <legend class="tu-label">
          {{ $t("plantillasWhatsApp.categoriaLabel") }}
        </legend>
        <div class="mt-2 space-y-2">
          <label
            v-for="c in ['marketing', 'utilidad'] as const"
            :key="c"
            class="flex items-start gap-3 text-sm"
          >
            <input
              v-model="editando.categoria"
              type="radio"
              name="categoria-plantilla"
              :value="c"
              class="mt-1"
            />
            <span>
              <span class="font-medium">{{
                $t(`plantillasWhatsApp.categoria.${c}`)
              }}</span>
              · {{ costo(c) }}
              <span class="block" :style="{ color: 'var(--texto-suave)' }">{{
                $t(`plantillasWhatsApp.categoriaAyuda.${c}`)
              }}</span>
            </span>
          </label>
        </div>
      </fieldset>
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("plantillasWhatsApp.texto") }}</span>
        <textarea
          v-model="editando.cuerpo"
          class="tu-input"
          rows="5"
          :maxlength="meta?.max_cuerpo ?? 1024"
          required
          data-prueba="plantilla-cuerpo"
        ></textarea>
        <span class="text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ ayudaMarcadores }} · {{ editando.cuerpo.length }}/{{
            meta?.max_cuerpo ?? 1024
          }}
        </span>
        <span
          v-for="m in errores.cuerpo ?? []"
          :key="m"
          class="tu-error-campo"
          >{{ m }}</span
        >
      </label>
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("plantillasWhatsApp.pie") }}</span>
        <input
          v-model="editando.pie"
          class="tu-input"
          maxlength="60"
          :placeholder="$t('plantillasWhatsApp.piePh')"
        />
      </label>
      <div
        v-if="editando.cuerpo.trim() !== ''"
        class="rounded-xl border p-4 text-sm whitespace-pre-line"
        :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
        data-prueba="plantilla-vista-previa"
      >
        {{ ejemplo(editando.cuerpo) }}
        <span
          v-if="editando.pie.trim() !== ''"
          class="block mt-2 text-xs"
          :style="{ color: 'var(--texto-suave)' }"
          >{{ editando.pie }}</span
        >
      </div>
      <div class="flex justify-end gap-2">
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          @click="editando = null"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <button
          type="submit"
          class="tu-btn tu-btn-primario"
          :disabled="trabajando !== null"
          data-prueba="guardar-plantilla-whatsapp"
        >
          {{ $t("plantillasWhatsApp.guardar") }}
        </button>
      </div>
    </form>

    <p v-if="error" class="tu-error-campo" role="alert">{{ error }}</p>

    <p
      v-if="meta && plantillas.length === 0 && editando === null"
      class="text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("plantillasWhatsApp.vacio") }}
    </p>

    <article
      v-for="p in plantillas"
      :key="p.id"
      class="tu-card p-5"
      :data-plantilla="p.id"
    >
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h4 class="font-medium">{{ p.titulo }}</h4>
        <div class="flex items-center gap-3 text-sm">
          <span :style="{ color: 'var(--texto-suave)' }">{{
            $t(`plantillasWhatsApp.categoria.${p.categoria}`)
          }}</span>
          <span
            class="tu-estado"
            :style="{ '--tono': TONOS[p.estado] }"
            data-prueba="estado-plantilla"
            >{{ $t(`plantillasWhatsApp.estado.${p.estado}`) }}</span
          >
        </div>
      </div>
      <p class="mt-2 text-sm whitespace-pre-line">{{ ejemplo(p.cuerpo) }}</p>
      <p
        v-if="p.motivo"
        class="mt-2 text-sm"
        :style="{ color: 'var(--texto-suave)' }"
        data-prueba="motivo-plantilla"
      >
        {{ $t("plantillasWhatsApp.motivo", { motivo: p.motivo }) }}
      </p>
      <div
        v-if="puedeGestionar || puedeArchivar"
        class="mt-3 flex flex-wrap justify-end gap-2"
      >
        <button
          v-if="puedeArchivar"
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          :disabled="trabajando !== null"
          @click="archivar(p)"
        >
          {{ $t("plantillasWhatsApp.archivar") }}
        </button>
        <button
          v-if="puedeGestionar && p.editable"
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          :disabled="trabajando !== null"
          @click="editar(p)"
        >
          {{ $t("plantillasWhatsApp.editar") }}
        </button>
        <button
          v-if="puedeGestionar && p.estado === 'en_revision'"
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          :disabled="trabajando !== null"
          @click="revisar(p)"
        >
          {{ $t("plantillasWhatsApp.revisar") }}
        </button>
        <button
          v-if="puedeGestionar && p.estado === 'borrador' && meta?.disponible"
          type="button"
          class="tu-btn tu-btn-primario tu-btn-chico"
          :disabled="trabajando !== null"
          data-prueba="enviar-plantilla-whatsapp"
          @click="enviar(p)"
        >
          {{ $t("plantillasWhatsApp.enviar") }}
        </button>
      </div>
    </article>
  </div>
</template>
