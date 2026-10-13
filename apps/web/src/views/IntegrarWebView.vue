<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import { api, mensajeDeError } from "@/lib/api";
import { codigoEmbebido } from "@/lib/captacion";
import { confirmar } from "@/lib/confirmar";
import {
  rutaWidget,
  urlWidget,
  type TemaWidget,
  type TipoWidget,
  type Widget,
} from "@/lib/widgets";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Integrar en mi web (ADR 0141): el dueño arma un widget con su calendario de clases o
 * sus planes, elige los filtros iniciales, el tema y su color, registra las webs donde
 * lo pondrá, lo ve en vista previa y copia el código para pegarlo en su web.
 */
interface Opcion {
  id: string;
  nombre: string;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("prospectos.gestionar"));

const widgets = ref<Widget[]>([]);
const sucursales = ref<Opcion[]>([]);
const actividades = ref<Opcion[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Widget[] }>(
      `${base.value}/captacion/widgets`,
    );
    widgets.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
  try {
    const { data } = await api.get<{ data: Opcion[] }>(
      `${base.value}/sucursales`,
    );
    sucursales.value = data.data.map((s) => ({ id: s.id, nombre: s.nombre }));
  } catch {
    sucursales.value = [];
  }
  // Las actividades con clases, las mismas que filtra el calendario público.
  if (!sesion.esCitas) {
    try {
      const { data } = await api.get<{
        data: { filtros: { actividades: Opcion[] } };
      }>(`${base.value}/calendario`, { params: { dias: 7 } });
      actividades.value = data.data.filtros.actividades;
    } catch {
      actividades.value = [];
    }
  }
}

// ---- Editor ----
const editando = ref<Widget | null>(null);
const abierto = ref(false);
const guardando = ref(false);
const form = reactive({
  tipo: "planes" as TipoWidget,
  nombre: "",
  sucursal: "",
  actividad: "",
  tema: "claro" as TemaWidget,
  acento: "",
  dominios: [""] as string[],
  activo: true,
});

function nuevo(): void {
  editando.value = null;
  Object.assign(form, {
    tipo: sesion.esCitas ? "planes" : "calendario",
    nombre: "",
    sucursal: "",
    actividad: "",
    tema: "claro",
    acento: "",
    dominios: [""],
    activo: true,
  });
  abierto.value = true;
}
function editar(w: Widget): void {
  editando.value = w;
  Object.assign(form, {
    tipo: w.tipo,
    nombre: w.nombre,
    sucursal: w.filtros.sucursal ?? "",
    actividad: w.filtros.actividad ?? "",
    tema: w.tema,
    acento: w.acento ?? "",
    dominios: w.dominios.length > 0 ? [...w.dominios] : [""],
    activo: w.activo,
  });
  abierto.value = true;
}
function agregarDominio(): void {
  form.dominios.push("");
}
function quitarDominio(i: number): void {
  form.dominios.splice(i, 1);
  if (form.dominios.length === 0) {
    form.dominios.push("");
  }
}

async function guardar(): Promise<void> {
  guardando.value = true;
  const datos = {
    tipo: form.tipo,
    nombre: form.nombre.trim(),
    filtros: {
      sucursal: form.sucursal || null,
      actividad: form.tipo === "calendario" ? form.actividad || null : null,
    },
    tema: form.tema,
    acento: form.acento || null,
    dominios: form.dominios.map((d) => d.trim()).filter((d) => d !== ""),
    activo: form.activo,
  };
  try {
    const { data } = editando.value
      ? await api.put<{ data: Widget }>(
          `${base.value}/captacion/widgets/${editando.value.id}`,
          datos,
        )
      : await api.post<{ data: Widget }>(
          `${base.value}/captacion/widgets`,
          datos,
        );
    editando.value = data.data;
    toast.exito(t("captacion.widgets.guardado"));
    version.value++;
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}

async function eliminar(w: Widget): Promise<void> {
  if (!(await confirmar(t("captacion.widgets.confirmarEliminar")))) {
    return;
  }
  try {
    await api.delete(`${base.value}/captacion/widgets/${w.id}`);
    if (editando.value?.id === w.id) {
      editando.value = null;
      abierto.value = false;
    }
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

// ---- Vista previa y código (del widget guardado) ----
const version = ref(0);
const previa = computed(() =>
  editando.value && sesion.slug
    ? `${rutaWidget(sesion.slug, editando.value.id)}?vista_previa=1&v=${version.value}`
    : null,
);
const codigo = computed(() =>
  editando.value && sesion.slug
    ? codigoEmbebido(
        urlWidget(sesion.slug, editando.value.id),
        editando.value.nombre,
      )
    : "",
);
const alturaPrevia = ref(420);
function alMensaje(e: MessageEvent): void {
  const d = e.data as { tipo?: string; altura?: number } | null;
  if (d?.tipo === "agendauno:altura" && typeof d.altura === "number") {
    alturaPrevia.value = Math.max(240, d.altura);
  }
}
async function copiar(): Promise<void> {
  try {
    await navigator.clipboard.writeText(codigo.value);
    toast.exito(t("captacion.widgets.copiado"));
  } catch {
    toast.error(t("captacion.widgets.copiar"));
  }
}

onMounted(() => {
  window.addEventListener("message", alMensaje);
  void cargar();
});
onBeforeUnmount(() => window.removeEventListener("message", alMensaje));
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('captacion.widgets.titulo')"
      :subtitulo="$t('captacion.widgets.descripcion')"
    />

    <div class="mt-5 flex justify-end">
      <button
        v-if="puedeGestionar"
        type="button"
        class="tu-btn tu-btn-primario tu-btn-crear"
        data-prueba="nuevo-widget"
        @click="nuevo"
      >
        {{ $t("captacion.widgets.nuevo") }}
      </button>
    </div>

    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">{{
      error
    }}</AvisoTono>
    <EstadoVacio
      v-else-if="!cargando && widgets.length === 0 && !abierto"
      class="mt-4"
      icono="captacion"
      :titulo="$t('captacion.widgets.vacio')"
    />
    <ul v-else class="mt-4 grid gap-3 sm:grid-cols-2" data-prueba="widgets">
      <li
        v-for="w in widgets"
        :key="w.id"
        class="tu-card p-4 grid gap-2"
        :class="{ 'iw-elegido': editando?.id === w.id }"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-medium">{{ w.nombre }}</h2>
          <span
            class="tu-estado text-sm"
            :style="{
              '--tono': w.activo ? 'var(--exito)' : 'var(--texto-suave)',
            }"
            >{{
              w.activo
                ? $t("captacion.widgets.activo")
                : $t("captacion.widgets.apagado")
            }}</span
          >
        </div>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t(`captacion.widgets.tipos.${w.tipo}`) }}
          <template v-if="w.dominios.length > 0">
            · {{ w.dominios.join(", ") }}</template
          >
        </p>
        <div v-if="puedeGestionar" class="flex flex-wrap gap-2">
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            data-prueba="editar-widget"
            @click="editar(w)"
          >
            {{ $t("captacion.widgets.editar") }}
          </button>
          <button
            type="button"
            class="tu-enlace"
            style="color: var(--error)"
            @click="eliminar(w)"
          >
            {{ $t("captacion.widgets.eliminar") }}
          </button>
        </div>
      </li>
    </ul>

    <!-- Editor, vista previa y código: una columna en el teléfono. -->
    <div v-if="abierto" class="mt-6 grid gap-6 lg:grid-cols-2">
      <form
        class="tu-card p-5 grid gap-4 h-fit"
        data-prueba="editor-widget"
        @submit.prevent="guardar"
      >
        <fieldset class="grid gap-2">
          <legend class="tu-label">{{ $t("captacion.widgets.titulo") }}</legend>
          <div class="tu-segmentado w-full" role="group">
            <button
              v-for="tipo in ['calendario', 'planes'] as const"
              :key="tipo"
              type="button"
              class="flex-1"
              :aria-pressed="form.tipo === tipo"
              :disabled="tipo === 'calendario' && sesion.esCitas"
              @click="form.tipo = tipo"
            >
              {{ $t(`captacion.widgets.tipos.${tipo}`) }}
            </button>
          </div>
          <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{
              sesion.esCitas
                ? $t("captacion.widgets.soloClases")
                : $t(`captacion.widgets.tiposAyuda.${form.tipo}`)
            }}
          </p>
        </fieldset>
        <label class="block">
          <span class="tu-label">{{ $t("captacion.widgets.nombre") }}</span>
          <input
            v-model="form.nombre"
            class="tu-input"
            maxlength="120"
            required
            data-prueba="nombre-widget"
          />
        </label>
        <fieldset class="grid gap-2 sm:grid-cols-2">
          <legend class="tu-label sm:col-span-2">
            {{ $t("captacion.widgets.filtros") }}
          </legend>
          <label class="block">
            <span class="text-sm">{{ $t("captacion.widgets.sucursal") }}</span>
            <select v-model="form.sucursal" class="tu-input">
              <option value="">{{ $t("captacion.widgets.todas") }}</option>
              <option v-for="s in sucursales" :key="s.id" :value="s.id">
                {{ s.nombre }}
              </option>
            </select>
          </label>
          <label v-if="form.tipo === 'calendario'" class="block">
            <span class="text-sm">{{ $t("captacion.widgets.actividad") }}</span>
            <select v-model="form.actividad" class="tu-input">
              <option value="">{{ $t("captacion.widgets.todas") }}</option>
              <option v-for="a in actividades" :key="a.id" :value="a.id">
                {{ a.nombre }}
              </option>
            </select>
          </label>
        </fieldset>
        <fieldset class="grid gap-2">
          <legend class="tu-label">{{ $t("captacion.widgets.tema") }}</legend>
          <div class="tu-segmentado w-full" role="group">
            <button
              v-for="tema in ['claro', 'oscuro', 'auto'] as const"
              :key="tema"
              type="button"
              class="flex-1"
              :aria-pressed="form.tema === tema"
              @click="form.tema = tema"
            >
              {{ $t(`captacion.widgets.temas.${tema}`) }}
            </button>
          </div>
        </fieldset>
        <div class="flex flex-wrap items-center gap-3">
          <label class="flex items-center gap-2">
            <span class="tu-label">{{ $t("captacion.widgets.acento") }}</span>
            <input
              v-model="form.acento"
              type="color"
              class="h-9 w-12 cursor-pointer rounded border"
              data-prueba="acento-widget"
            />
          </label>
          <button
            v-if="form.acento"
            type="button"
            class="tu-enlace text-sm"
            @click="form.acento = ''"
          >
            {{ $t("captacion.widgets.sinAcento") }}
          </button>
        </div>
        <fieldset class="grid gap-2">
          <legend class="tu-label">
            {{ $t("captacion.widgets.dominios") }}
          </legend>
          <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("captacion.widgets.dominiosAyuda") }}
          </p>
          <div
            v-for="(_, i) in form.dominios"
            :key="i"
            class="flex items-center gap-2"
          >
            <input
              v-model="form.dominios[i]"
              class="tu-input"
              inputmode="url"
              placeholder="www.miestudio.com"
              :aria-label="$t('captacion.widgets.dominios')"
              data-prueba="dominio-widget"
            />
            <button
              type="button"
              class="tu-enlace text-sm"
              @click="quitarDominio(i)"
            >
              {{ $t("captacion.widgets.eliminar") }}
            </button>
          </div>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma justify-self-start"
            @click="agregarDominio"
          >
            {{ $t("captacion.widgets.agregarDominio") }}
          </button>
        </fieldset>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.activo" type="checkbox" />
          {{ $t("captacion.widgets.activo") }}
        </label>
        <button
          type="submit"
          class="tu-btn tu-btn-primario justify-self-start"
          :disabled="guardando || form.nombre.trim() === ''"
          data-prueba="guardar-widget"
        >
          {{ $t("captacion.widgets.guardar") }}
        </button>
      </form>

      <div v-if="previa" class="grid gap-4 h-fit">
        <section class="tu-card p-4">
          <h2 class="tu-label">{{ $t("captacion.widgets.vistaPrevia") }}</h2>
          <iframe
            :key="previa"
            :src="previa"
            :title="$t('captacion.widgets.vistaPrevia')"
            class="mt-2 w-full rounded border-0"
            :style="{ height: `${alturaPrevia}px` }"
            data-prueba="previa-widget"
          />
        </section>
        <section class="tu-card p-4 grid gap-2">
          <h2 class="tu-label">{{ $t("captacion.widgets.codigo") }}</h2>
          <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("captacion.widgets.codigoAyuda") }}
          </p>
          <textarea
            class="tu-input font-mono text-xs"
            rows="5"
            readonly
            :value="codigo"
            data-prueba="codigo-widget"
          />
          <button
            type="button"
            class="tu-btn tu-btn-fantasma justify-self-start"
            data-prueba="copiar-codigo"
            @click="copiar"
          >
            {{ $t("captacion.widgets.copiar") }}
          </button>
        </section>
      </div>
    </div>
  </section>
</template>

<style scoped>
.iw-elegido {
  border-color: var(--acento);
}
</style>
