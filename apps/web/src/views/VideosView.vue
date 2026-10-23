<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import { api, erroresPorCampo, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useDescartarCambios } from "@/lib/editorFormulario";
import type { Video } from "@/lib/videos";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Biblioteca de videos (ADR 0184): enlaces de YouTube, Vimeo u otro sitio que el
 * negocio publica para sus alumnos con plan vigente (todos o solo algunos planes).
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeEditar = computed(() => sesion.puede("catalogo.gestionar"));

const videos = ref<Video[]>([]);
const planes = ref<{ id: string; nombre: string }[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const editando = ref<Video | null>(null);
const abierto = ref(false);
const guardando = ref(false);
const errores = ref<Record<string, string>>({});
const form = reactive({
  titulo: "",
  url: "",
  descripcion: "",
  categoria: "",
  duracion: "",
  publicado: true,
  planes: [] as string[],
});
// Con cambios sin guardar, cerrar el diálogo pregunta antes de descartarlos.
const descarte = useDescartarCambios({
  abierto,
  valor: () => ({ ...form, planes: [...form.planes] }),
  guardando,
});

async function cargar(): Promise<void> {
  error.value = null;
  try {
    const [v, p] = await Promise.all([
      api.get<{ data: Video[] }>(`${base.value}/videos`),
      api
        .get<{ data: { id: string; nombre: string; archivado?: boolean }[] }>(
          `${base.value}/productos`,
        )
        .catch(() => ({ data: { data: [] } })),
    ]);
    videos.value = v.data.data;
    planes.value = p.data.data.filter((x) => !x.archivado);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function abrir(v: Video | null): void {
  editando.value = v;
  errores.value = {};
  Object.assign(form, {
    titulo: v?.titulo ?? "",
    url: v?.url ?? "",
    descripcion: v?.descripcion ?? "",
    categoria: v?.categoria ?? "",
    duracion: v?.duracion_minutos ? String(v.duracion_minutos) : "",
    publicado: v?.publicado ?? true,
    planes: v?.planes.map((p) => p.id) ?? [],
  });
  descarte.fijar();
  abierto.value = true;
}

async function guardar(): Promise<void> {
  guardando.value = true;
  errores.value = {};
  const duracion = Number(form.duracion);
  const carga = {
    titulo: form.titulo.trim(),
    url: form.url.trim(),
    descripcion: form.descripcion.trim() || null,
    categoria: form.categoria.trim() || null,
    duracion_minutos:
      form.duracion.trim() !== "" && Number.isFinite(duracion)
        ? Math.round(duracion)
        : null,
    publicado: form.publicado,
    planes: form.planes,
  };
  try {
    if (editando.value) {
      await api.put(`${base.value}/videos/${editando.value.id}`, carga);
    } else {
      await api.post(`${base.value}/videos`, carga);
    }
    toast.exito(t("videos.guardado"));
    descarte.fijar();
    abierto.value = false;
    await cargar();
  } catch (e) {
    errores.value = erroresPorCampo(e);
    if (Object.keys(errores.value).length === 0) {
      toast.error(mensajeDeError(e));
    }
  } finally {
    guardando.value = false;
  }
}

async function borrar(v: Video): Promise<void> {
  if (
    !(await confirmar(t("videos.borrarConfirmar", { titulo: v.titulo }), {
      aceptar: t("videos.borrar"),
      peligro: true,
    }))
  ) {
    return;
  }
  try {
    await api.delete(`${base.value}/videos/${v.id}`);
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('videos.titulo')"
      :subtitulo="$t('videos.subtitulo')"
    >
      <template #acciones>
        <button
          v-if="puedeEditar"
          type="button"
          class="tu-btn tu-btn-primario tu-btn-crear"
          data-prueba="nuevo-video"
          @click="abrir(null)"
        >
          {{ $t("videos.nuevo") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-6" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-else-if="error" class="mt-6" role="alert" style="color: var(--error)">
      {{ error }}
    </p>
    <EstadoVacio
      v-else-if="videos.length === 0"
      class="tu-card mt-6"
      icono="etiqueta"
      :titulo="$t('videos.vacio')"
    />

    <ModalDialogo
      :abierto="abierto"
      :titulo="editando ? $t('videos.editarVideo') : $t('videos.nuevo')"
      :ocupado="guardando"
      @cerrar="descarte.cerrar"
    >
      <form
        id="form-video"
        class="space-y-3"
        data-prueba="form-video"
        @submit.prevent="guardar"
      >
        <div class="grid gap-3 sm:grid-cols-2">
          <label>
            <span class="tu-label">{{ $t("videos.campos.titulo") }}</span>
            <input
              v-model="form.titulo"
              class="tu-input"
              maxlength="150"
              required
              :aria-invalid="errores.titulo ? 'true' : undefined"
            />
            <span v-if="errores.titulo" class="tu-error-campo">{{
              errores.titulo
            }}</span>
          </label>
          <label>
            <span class="tu-label">{{ $t("videos.campos.url") }}</span>
            <input
              v-model="form.url"
              class="tu-input"
              type="url"
              inputmode="url"
              maxlength="500"
              placeholder="https://"
              required
              :aria-invalid="errores.url ? 'true' : undefined"
              data-prueba="video-url"
            />
            <span v-if="errores.url" class="tu-error-campo">{{
              errores.url
            }}</span>
          </label>
          <label>
            <span class="tu-label">{{ $t("videos.campos.categoria") }}</span>
            <input v-model="form.categoria" class="tu-input" maxlength="60" />
          </label>
          <label>
            <span class="tu-label">{{ $t("videos.campos.duracion") }}</span>
            <input
              v-model="form.duracion"
              class="tu-input"
              type="number"
              min="1"
              max="600"
            />
          </label>
          <label class="sm:col-span-2">
            <span class="tu-label">{{ $t("videos.campos.descripcion") }}</span>
            <textarea
              v-model="form.descripcion"
              class="tu-input"
              rows="2"
              maxlength="500"
            />
          </label>
        </div>
        <fieldset v-if="planes.length > 0">
          <legend class="tu-label">{{ $t("videos.campos.planes") }}</legend>
          <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("videos.campos.planesAyuda") }}
          </p>
          <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
            <label
              v-for="p in planes"
              :key="p.id"
              class="flex items-center gap-2 text-sm"
            >
              <input v-model="form.planes" type="checkbox" :value="p.id" />
              {{ p.nombre }}
            </label>
          </div>
        </fieldset>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.publicado" type="checkbox" />
          {{ $t("videos.campos.publicado") }}
        </label>
      </form>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="guardando"
          @click="descarte.cerrar"
        >
          {{ $t("comun.cancelar") }}
        </button>
        <button
          type="submit"
          form="form-video"
          class="tu-btn tu-btn-primario"
          :disabled="guardando"
          :aria-busy="guardando || undefined"
          data-prueba="guardar-video"
        >
          {{ $t("comun.guardar") }}
        </button>
      </template>
    </ModalDialogo>

    <ul v-if="videos.length > 0" class="tu-card mt-6 divide-y">
      <li
        v-for="v in videos"
        :key="v.id"
        class="flex flex-wrap items-center justify-between gap-3 p-4"
      >
        <div class="min-w-0">
          <p class="font-medium">{{ v.titulo }}</p>
          <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{
              [
                v.categoria,
                v.duracion_minutos
                  ? $t("videos.minutos", { n: v.duracion_minutos })
                  : null,
                v.planes.length > 0
                  ? $t("videos.soloPlanes", {
                      planes: v.planes.map((p) => p.nombre).join(", "),
                    })
                  : $t("videos.todosLosPlanes"),
                v.publicado ? null : $t("videos.oculto"),
              ]
                .filter(Boolean)
                .join(" · ")
            }}
          </p>
        </div>
        <div v-if="puedeEditar" class="flex gap-2">
          <button
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            @click="abrir(v)"
          >
            {{ $t("videos.editar") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-peligro tu-btn-chico"
            @click="borrar(v)"
          >
            {{ $t("videos.borrar") }}
          </button>
        </div>
      </li>
    </ul>
  </section>
</template>
