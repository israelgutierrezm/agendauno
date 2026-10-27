<script setup lang="ts">
import QRCode from "qrcode";
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import IconoNav from "@/components/IconoNav.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useDescartarCambios } from "@/lib/editorFormulario";
import {
  codigoEmbebido,
  TIPOS_CAMPO,
  urlFormulario,
  type CampoCaptacion,
  type FormularioCaptacion,
} from "@/lib/captacion";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Formularios de captación (ADR 0117): el de «Pedir acceso» de la página del negocio y
 * los que comparte por enlace o QR o pone en su propio sitio. Lo que envían llega a
 * Prospectos. Aparte de las fichas de datos que llenan sus alumnos.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("prospectos.gestionar"));
const puedeEncender = computed(() => sesion.puede("estudio.gestionar"));

const formularios = ref<FormularioCaptacion[]>([]);
// Con varios formularios, un buscador por nombre o título.
const busqueda = ref("");
const visibles = computed(() => {
  const q = busqueda.value.trim().toLocaleLowerCase("es");
  return q === ""
    ? formularios.value
    : formularios.value.filter((f) =>
        `${f.nombre} ${f.titulo}`.toLocaleLowerCase("es").includes(q),
      );
});
const activa = ref(true);
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: FormularioCaptacion[];
      meta: { captacion_activa: boolean };
    }>(`${base.value}/captacion/formularios`);
    formularios.value = data.data;
    activa.value = data.meta.captacion_activa;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);

async function alternarCaptacion(): Promise<void> {
  const nuevo = !activa.value;
  try {
    await api.put(`${base.value}/parametros`, {
      valores: { "captacion.activa": nuevo ? 1 : 0 },
    });
    activa.value = nuevo;
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

// ---------------------------------------------------------------- editor
interface Borrador {
  id: string | null;
  nombre: string;
  titulo: string;
  descripcion: string;
  mensaje_confirmacion: string;
  pide_telefono: boolean;
  telefono_obligatorio: boolean;
  predeterminado: boolean;
  activo: boolean;
  campos: (CampoCaptacion & { opcionesTexto: string })[];
}
const editor = ref<Borrador | null>(null);
const guardando = ref(false);
// Cerrar con cambios sin guardar pregunta antes, como en los demás editores.
const editorAbierto = computed({
  get: () => editor.value !== null,
  set: (abierto: boolean) => {
    if (!abierto) {
      editor.value = null;
    }
  },
});
const descartar = useDescartarCambios({
  abierto: editorAbierto,
  valor: () => editor.value,
  guardando,
});

function editar(f: FormularioCaptacion | null): void {
  editor.value = {
    id: f?.id ?? null,
    nombre: f?.nombre ?? "",
    titulo: f?.titulo ?? "",
    descripcion: f?.descripcion ?? "",
    mensaje_confirmacion: f?.mensaje_confirmacion ?? "",
    pide_telefono: f?.pide_telefono ?? true,
    telefono_obligatorio: f?.telefono_obligatorio ?? false,
    predeterminado: f?.predeterminado ?? false,
    activo: f?.activo ?? true,
    campos: (f?.campos ?? []).map((c) => ({
      ...c,
      opcionesTexto: (c.opciones ?? []).join("\n"),
    })),
  };
  descartar.fijar();
}
function agregarPregunta(): void {
  editor.value?.campos.push({
    id: null,
    etiqueta: "",
    tipo: "texto",
    obligatorio: false,
    opciones: null,
    opcionesTexto: "",
  });
}
function mover(i: number, delta: number): void {
  const campos = editor.value?.campos;
  if (!campos) {
    return;
  }
  const j = i + delta;
  if (j < 0 || j >= campos.length) {
    return;
  }
  const [c] = campos.splice(i, 1);
  if (c) {
    campos.splice(j, 0, c);
  }
}
async function guardar(): Promise<void> {
  const b = editor.value;
  if (!b) {
    return;
  }
  guardando.value = true;
  const cuerpo = {
    nombre: b.nombre,
    titulo: b.titulo,
    descripcion: b.descripcion || null,
    mensaje_confirmacion: b.mensaje_confirmacion || null,
    pide_telefono: b.pide_telefono,
    telefono_obligatorio: b.pide_telefono && b.telefono_obligatorio,
    predeterminado: b.predeterminado,
    activo: b.activo,
    campos: b.campos.map((c) => ({
      id: c.id ?? null,
      etiqueta: c.etiqueta,
      tipo: c.tipo,
      obligatorio: c.obligatorio,
      opciones:
        c.tipo === "seleccion"
          ? c.opcionesTexto
              .split("\n")
              .map((o) => o.trim())
              .filter((o) => o !== "")
          : null,
    })),
  };
  try {
    if (b.id) {
      await api.put(`${base.value}/captacion/formularios/${b.id}`, cuerpo);
    } else {
      await api.post(`${base.value}/captacion/formularios`, cuerpo);
    }
    editor.value = null;
    toast.exito(t("captacion.formularios.editor.guardado"));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}

// ---------------------------------------------------------------- compartir
const compartiendo = ref<FormularioCaptacion | null>(null);
const qr = ref("");
const copiado = ref<"enlace" | "codigo" | null>(null);
const enlace = computed(() =>
  compartiendo.value && sesion.slug
    ? urlFormulario(sesion.slug, compartiendo.value.id)
    : "",
);
const codigo = computed(() =>
  compartiendo.value
    ? codigoEmbebido(enlace.value, compartiendo.value.titulo)
    : "",
);

async function compartir(f: FormularioCaptacion): Promise<void> {
  compartiendo.value = f;
  copiado.value = null;
  try {
    qr.value = await QRCode.toDataURL(enlace.value, {
      width: 240,
      margin: 1,
      color: { dark: "#111111", light: "#ffffff" },
    });
  } catch {
    qr.value = "";
  }
}
async function copiar(texto: string, que: "enlace" | "codigo"): Promise<void> {
  try {
    await navigator.clipboard.writeText(texto);
    copiado.value = que;
  } catch {
    // Sin portapapeles: el texto queda a la vista para copiarlo a mano.
  }
}
function descargarQr(): void {
  if (!qr.value || !compartiendo.value) {
    return;
  }
  const a = document.createElement("a");
  a.href = qr.value;
  a.download = `qr-${compartiendo.value.nombre.toLowerCase().replace(/[^a-z0-9]+/g, "-")}.png`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('captacion.formularios.titulo')"
      :subtitulo="$t('captacion.formularios.descripcion')"
    >
      <template v-if="puedeGestionar" #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-primario tu-btn-crear"
          data-prueba="nuevo-formulario-captacion"
          @click="editar(null)"
        >
          {{ $t("captacion.formularios.nuevo") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <div
      class="tu-card mt-5 p-4 flex flex-wrap items-center justify-between gap-3"
    >
      <div class="min-w-0">
        <p class="font-medium">{{ $t("captacion.formularios.activa") }}</p>
        <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("captacion.formularios.activaAyuda") }}
        </p>
        <!-- Deshabilitado, dice por qué (ADR 0229). -->
        <p
          v-if="!puedeEncender"
          id="captacion-activa-motivo"
          class="mt-1 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
          data-prueba="captacion-activa-motivo"
        >
          {{ $t("captacion.formularios.activaSinPermiso") }}
        </p>
      </div>
      <button
        type="button"
        role="switch"
        :aria-checked="activa"
        :aria-label="$t('captacion.formularios.activa')"
        :aria-describedby="
          puedeEncender ? undefined : 'captacion-activa-motivo'
        "
        :title="
          puedeEncender
            ? undefined
            : $t('captacion.formularios.activaSinPermiso')
        "
        :disabled="!puedeEncender"
        class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors"
        :style="{
          background: activa ? 'var(--primario)' : 'var(--superficie-2)',
          opacity: puedeEncender ? 1 : 0.5,
        }"
        data-prueba="captacion-activa"
        @click="alternarCaptacion"
      >
        <span
          class="inline-block h-5 w-5 rounded-full bg-white transition-transform"
          :class="activa ? 'translate-x-6' : 'translate-x-1'"
        />
      </button>
    </div>

    <AvisoTono
      v-if="!cargando && !activa"
      tono="aviso"
      class="mt-4"
      role="status"
      data-prueba="captacion-apagada"
    >
      {{ $t("captacion.formularios.compartirPanel.captacionApagada") }}
    </AvisoTono>

    <div
      v-if="formularios.length > 3"
      class="mt-4 flex flex-wrap items-center justify-end gap-3"
    >
      <label class="tu-buscar min-w-0" data-prueba="buscar-formulario">
        <IconoNav nombre="buscar" :tam="16" />
        <input
          v-model="busqueda"
          type="search"
          class="tu-input"
          :placeholder="$t('captacion.formularios.buscar')"
          :aria-label="$t('captacion.formularios.buscar')"
        />
      </label>
    </div>

    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
    </AvisoTono>
    <p
      v-else-if="cargando"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("comun.cargando") }}
    </p>
    <p
      v-else-if="visibles.length === 0 && busqueda.trim() !== ''"
      class="mt-4 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("captacion.formularios.sinCoincidencias") }}
    </p>
    <ul v-else class="mt-4 grid gap-3 sm:grid-cols-2">
      <li
        v-for="f in visibles"
        :key="f.id"
        class="tu-card p-4 grid gap-2"
        data-prueba="formulario-captacion-tarjeta"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="font-medium truncate">{{ f.nombre }}</p>
            <p
              class="text-sm truncate"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ f.titulo }}
            </p>
          </div>
          <span
            class="tu-estado text-sm shrink-0"
            :style="{
              '--tono': f.activo ? 'var(--exito)' : 'var(--texto-suave)',
            }"
          >
            {{
              f.activo
                ? $t(
                    "captacion.formularios.prospectos",
                    { n: f.prospectos ?? 0 },
                    f.prospectos ?? 0,
                  )
                : $t("captacion.formularios.inactivo")
            }}
          </span>
        </div>
        <p v-if="f.predeterminado" class="text-sm">
          {{ $t("captacion.formularios.predeterminado") }}
        </p>
        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            data-prueba="compartir-tarjeta"
            @click="compartir(f)"
          >
            <IconoNav nombre="enlace" :tam="16" />
            {{ $t("captacion.formularios.compartir") }}
          </button>
          <button
            v-if="puedeGestionar"
            type="button"
            class="tu-btn tu-btn-secundario tu-btn-chico"
            data-prueba="editar-tarjeta"
            @click="editar(f)"
          >
            <IconoNav nombre="editar" :tam="16" />
            {{ $t("captacion.formularios.editar") }}
          </button>
        </div>
      </li>
    </ul>

    <!-- Editor -->
    <PanelLateral
      :abierto="editor !== null"
      :titulo="
        editor?.id
          ? $t('captacion.formularios.editor.tituloEditar')
          : $t('captacion.formularios.editor.tituloNuevo')
      "
      :ocupado="guardando"
      @cerrar="descartar.cerrar()"
    >
      <form
        v-if="editor"
        id="form-captacion"
        class="grid gap-4 text-sm"
        @submit.prevent="guardar"
      >
        <label class="block">
          <span class="tu-label">{{
            $t("captacion.formularios.editor.nombre")
          }}</span>
          <input
            v-model="editor.nombre"
            class="tu-input"
            required
            maxlength="120"
          />
          <span class="tu-hint">{{
            $t("captacion.formularios.editor.nombreAyuda")
          }}</span>
        </label>
        <label class="block">
          <span class="tu-label">{{
            $t("captacion.formularios.editor.titulo")
          }}</span>
          <input
            v-model="editor.titulo"
            class="tu-input"
            required
            maxlength="160"
          />
        </label>
        <label class="block">
          <span class="tu-label">{{
            $t("captacion.formularios.editor.descripcion")
          }}</span>
          <textarea
            v-model="editor.descripcion"
            class="tu-input"
            rows="2"
            maxlength="1000"
          />
        </label>
        <label class="block">
          <span class="tu-label">{{
            $t("captacion.formularios.editor.confirmacion")
          }}</span>
          <input
            v-model="editor.mensaje_confirmacion"
            class="tu-input"
            maxlength="500"
          />
        </label>
        <p :style="{ color: 'var(--texto-suave)' }">
          {{ $t("captacion.formularios.editor.siempre") }}
        </p>
        <label class="flex items-center gap-2">
          <input v-model="editor.pide_telefono" type="checkbox" />
          <span>{{ $t("captacion.formularios.editor.telefono") }}</span>
        </label>
        <label v-if="editor.pide_telefono" class="flex items-center gap-2 pl-6">
          <input v-model="editor.telefono_obligatorio" type="checkbox" />
          <span>{{
            $t("captacion.formularios.editor.telefonoObligatorio")
          }}</span>
        </label>
        <label class="flex items-center gap-2">
          <input v-model="editor.predeterminado" type="checkbox" />
          <span>{{ $t("captacion.formularios.editor.predeterminado") }}</span>
        </label>
        <label class="flex items-center gap-2">
          <input v-model="editor.activo" type="checkbox" />
          <span>{{ $t("captacion.formularios.editor.activo") }}</span>
        </label>

        <fieldset class="grid gap-3">
          <legend class="tu-label">
            {{ $t("captacion.formularios.editor.preguntas") }}
          </legend>
          <p :style="{ color: 'var(--texto-suave)' }">
            {{ $t("captacion.formularios.editor.noSensibles") }}
          </p>
          <div
            v-for="(c, i) in editor.campos"
            :key="c.id ?? `nuevo-${i}`"
            class="tu-card p-3 grid gap-2"
          >
            <label class="block">
              <span class="tu-label">{{
                $t("captacion.formularios.editor.etiqueta")
              }}</span>
              <input
                v-model="c.etiqueta"
                class="tu-input"
                required
                maxlength="160"
              />
            </label>
            <div class="flex flex-wrap items-center gap-3">
              <label class="block">
                <span class="tu-label">{{
                  $t("captacion.formularios.editor.tipo")
                }}</span>
                <select v-model="c.tipo" class="tu-input">
                  <option v-for="tipo in TIPOS_CAMPO" :key="tipo" :value="tipo">
                    {{ $t(`captacion.formularios.tipos.${tipo}`) }}
                  </option>
                </select>
              </label>
              <label class="flex items-center gap-2 mt-5">
                <input v-model="c.obligatorio" type="checkbox" />
                <span>{{
                  $t("captacion.formularios.editor.obligatoria")
                }}</span>
              </label>
            </div>
            <label v-if="c.tipo === 'seleccion'" class="block">
              <span class="tu-label">{{
                $t("captacion.formularios.editor.opciones")
              }}</span>
              <textarea v-model="c.opcionesTexto" class="tu-input" rows="3" />
            </label>
            <!-- Mover como en las fichas de datos; quitar se guarda con el formulario. -->
            <div class="flex flex-wrap items-center gap-1">
              <button
                type="button"
                class="tu-icono-btn"
                :aria-label="$t('captacion.formularios.editor.subir')"
                :disabled="i === 0"
                @click="mover(i, -1)"
              >
                <IconoNav nombre="arriba" :tam="14" />
              </button>
              <button
                type="button"
                class="tu-icono-btn"
                :aria-label="$t('captacion.formularios.editor.bajar')"
                :disabled="i === editor.campos.length - 1"
                @click="mover(i, 1)"
              >
                <IconoNav nombre="abajo" :tam="14" />
              </button>
              <button
                type="button"
                class="tu-btn tu-btn-discreto tu-btn-chico ml-auto"
                @click="editor.campos.splice(i, 1)"
              >
                {{ $t("captacion.formularios.editor.quitar") }}
              </button>
            </div>
          </div>
          <button
            type="button"
            class="tu-btn tu-btn-secundario justify-self-start"
            @click="agregarPregunta"
          >
            {{ $t("captacion.formularios.editor.agregarPregunta") }}
          </button>
        </fieldset>
      </form>
      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            type="button"
            class="tu-btn tu-btn-secundario"
            :disabled="guardando"
            @click="descartar.cerrar()"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            type="submit"
            form="form-captacion"
            class="tu-btn tu-btn-primario"
            :disabled="
              guardando ||
              !editor ||
              editor.nombre.trim() === '' ||
              editor.titulo.trim() === ''
            "
            :aria-busy="guardando || undefined"
          >
            {{ $t("captacion.formularios.editor.guardar") }}
          </button>
        </div>
      </template>
    </PanelLateral>

    <!-- Compartir: enlace con su QR, y el código para insertarlo en otro sitio. -->
    <ModalDialogo
      :abierto="compartiendo !== null"
      :titulo="
        compartiendo
          ? $t('captacion.formularios.compartirPanel.titulo', {
              nombre: compartiendo.nombre,
            })
          : ''
      "
      :descripcion="$t('captacion.formularios.compartirPanel.descripcion')"
      tam="lg"
      @cerrar="compartiendo = null"
    >
      <div
        v-if="compartiendo"
        class="grid gap-4 text-sm"
        data-prueba="compartir-formulario"
      >
        <AvisoTono v-if="!activa" tono="aviso" role="status">
          {{ $t("captacion.formularios.compartirPanel.captacionApagada") }}
        </AvisoTono>
        <AvisoTono v-else-if="!compartiendo.activo" tono="aviso" role="status">
          {{ $t("captacion.formularios.compartirPanel.apagado") }}
        </AvisoTono>

        <section class="fc-bloque">
          <span class="fc-icono" aria-hidden="true">
            <IconoNav nombre="enlace" :tam="18" />
          </span>
          <div class="min-w-0 grid gap-3">
            <div>
              <h3 class="font-medium">
                {{ $t("captacion.formularios.compartirPanel.enlaceTitulo") }}
              </h3>
              <p :style="{ color: 'var(--texto-suave)' }">
                {{ $t("captacion.formularios.compartirPanel.enlaceAyuda") }}
              </p>
            </div>
            <div>
              <p class="tu-label">
                {{ $t("captacion.formularios.compartirPanel.enlace") }}
              </p>
              <div class="fc-enlace">
                <span
                  class="min-w-0 truncate"
                  data-prueba="enlace-formulario"
                  >{{ enlace }}</span
                >
                <button
                  type="button"
                  class="tu-btn tu-btn-secundario inline-flex items-center gap-1.5 shrink-0"
                  data-prueba="copiar-enlace"
                  @click="copiar(enlace, 'enlace')"
                >
                  <IconoNav nombre="copiar" :tam="16" />
                  {{
                    copiado === "enlace"
                      ? $t("captacion.formularios.compartirPanel.copiado")
                      : $t("captacion.formularios.compartirPanel.copiarEnlace")
                  }}
                </button>
              </div>
            </div>
            <div
              v-if="qr"
              class="fc-qr pt-3 border-t"
              :style="{ borderColor: 'var(--borde-fila)' }"
            >
              <img
                :src="qr"
                :alt="$t('captacion.formularios.compartirPanel.qr')"
                width="128"
                height="128"
                class="rounded-lg border p-1.5"
                :style="{ borderColor: 'var(--borde)' }"
              />
              <div class="min-w-0 grid gap-2 content-start">
                <h4 class="font-medium">
                  {{ $t("captacion.formularios.compartirPanel.qr") }}
                </h4>
                <p :style="{ color: 'var(--texto-suave)' }">
                  {{ $t("captacion.formularios.compartirPanel.qrAyuda") }}
                </p>
                <div class="flex flex-wrap gap-2">
                  <button
                    type="button"
                    class="tu-btn tu-btn-secundario inline-flex items-center gap-1.5"
                    @click="descargarQr"
                  >
                    <IconoNav nombre="descargar" :tam="16" />
                    {{ $t("captacion.formularios.compartirPanel.descargarQr") }}
                  </button>
                  <a
                    :href="enlace"
                    target="_blank"
                    rel="noopener"
                    class="tu-btn tu-btn-secundario inline-flex items-center gap-1.5"
                  >
                    <IconoNav nombre="externo" :tam="16" />
                    {{ $t("captacion.formularios.compartirPanel.abrir") }}
                  </a>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section class="fc-bloque">
          <span class="fc-icono" aria-hidden="true">
            <IconoNav nombre="codigo" :tam="18" />
          </span>
          <div class="min-w-0 grid gap-3">
            <div>
              <h3 class="font-medium">
                {{ $t("captacion.formularios.compartirPanel.embeber") }}
              </h3>
              <p :style="{ color: 'var(--texto-suave)' }">
                {{ $t("captacion.formularios.compartirPanel.embeberAyuda") }}
              </p>
            </div>
            <div class="fc-codigo">
              <pre data-prueba="codigo-formulario">{{ codigo }}</pre>
              <button
                type="button"
                class="fc-copiar-codigo"
                :aria-label="
                  $t('captacion.formularios.compartirPanel.copiarCodigo')
                "
                @click="copiar(codigo, 'codigo')"
              >
                <IconoNav nombre="copiar" :tam="16" />
              </button>
            </div>
            <button
              type="button"
              class="tu-btn tu-btn-secundario inline-flex items-center gap-1.5 justify-self-start"
              data-prueba="copiar-codigo"
              @click="copiar(codigo, 'codigo')"
            >
              <IconoNav nombre="copiar" :tam="16" />
              {{
                copiado === "codigo"
                  ? $t("captacion.formularios.compartirPanel.copiado")
                  : $t("captacion.formularios.compartirPanel.copiarCodigo")
              }}
            </button>
          </div>
        </section>
      </div>
    </ModalDialogo>
  </section>
</template>

<style scoped>
/* Compartir (propuesta del dueño, 2026-10-20): dos bloques, cada uno con su ícono. */
.fc-bloque {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 1rem;
  padding: 1rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie);
}
.fc-icono {
  display: grid;
  place-items: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 9999px;
  background: var(--superficie-2);
  color: var(--texto-suave);
}
.fc-enlace {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.35rem 0.35rem 0.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton);
  background: var(--fondo);
}
.fc-qr {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
}
.fc-codigo {
  position: relative;
}
.fc-codigo pre {
  margin: 0;
  padding: 0.75rem 2.75rem 0.75rem 0.75rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-boton);
  background: var(--fondo);
  font-size: 0.75rem;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-all;
}
.fc-copiar-codigo {
  position: absolute;
  top: 0.5rem;
  right: 0.5rem;
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: var(--radio-boton);
  color: var(--texto-suave);
}
.fc-copiar-codigo:hover {
  background: var(--superficie-2);
  color: var(--texto);
}
@media (max-width: 30rem) {
  .fc-bloque {
    grid-template-columns: minmax(0, 1fr);
  }
  .fc-icono {
    display: none;
  }
  .fc-qr {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
