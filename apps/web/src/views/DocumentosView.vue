<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";

import BuscarPersona from "@/components/BuscarPersona.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Documentos del estudio en tres pestañas: los documentos cargados (con su
 * validación), los requisitos que se piden a cada tipo de persona y los
 * consentimientos que firman los alumnos (versionados: publicar una versión nueva
 * pide volver a firmar).
 */
const { t } = useI18n();

interface TipoDoc {
  id: string;
  nombre: string;
  descripcion: string | null;
  obligatorio: boolean;
  aplica_a: string;
  activo: boolean;
}
interface Doc {
  id: string;
  nombre: string;
  estado: string;
  motivo: string | null;
  persona: string | null;
  tipo: string | null;
  subido_en: string | null;
}
interface Consentimiento {
  id: string;
  clave: string;
  titulo: string;
  version: number;
  contenido: string;
  publicado_en: string | null;
  firmas: number;
}
type Pestana = "documentos" | "requisitos" | "consentimientos";

const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("documentos.gestionar"));
const puedeSubir = computed(() => sesion.puede("documentos.subir"));

// La vista va en la dirección (`?vista=`): Clientes › Expedientes es la de
// documentos; Configuración › Documentos y privacidad abre requisitos o
// consentimientos. Recargar o volver conserva la vista.
const route = useRoute();
const router = useRouter();
const pestana = computed<Pestana>({
  get: () => {
    const v = route.query.vista;
    if (v === "requisitos") {
      return "requisitos";
    }
    // Sin permiso de gestión no hay consentimientos: se queda en documentos.
    return v === "consentimientos" && puedeGestionar.value
      ? "consentimientos"
      : "documentos";
  },
  set: (p) => {
    void router.push({
      query: { ...route.query, vista: p === "documentos" ? undefined : p },
    });
  },
});
const TITULOS: Record<Pestana, string> = {
  documentos: "nav.vistas.expedientes",
  requisitos: "configNegocio.opciones.requisitos",
  consentimientos: "configNegocio.opciones.consentimientos",
};
const tipos = ref<TipoDoc[]>([]);
const docs = ref<Doc[]>([]);

const consentimientosVigentes = ref<Consentimiento[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const filtroEstado = ref("");
// El listado va por páginas: ningún documento se queda fuera (antes, solo los
// últimos 200).
interface MetaPaginas {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
}
const OPCIONES_POR_PAGINA = [25, 50, 100];
const pagina = ref(1);
const porPagina = ref(25);
const meta = ref<MetaPaginas | null>(null);
function irPagina(n: number): void {
  pagina.value = n;
  void cargar();
}
function cambiarPorPagina(n: number): void {
  porPagina.value = n;
  pagina.value = 1;
  void cargar();
}

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(iso));
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [t, d, c] = await Promise.all([
      api.get<{ data: TipoDoc[] }>(`${base.value}/tipos-documento`),
      api.get<{ data: Doc[]; meta?: MetaPaginas }>(`${base.value}/documentos`, {
        params: {
          page: pagina.value,
          per_page: porPagina.value,
          ...(filtroEstado.value !== "" ? { estado: filtroEstado.value } : {}),
        },
      }),
      puedeGestionar.value
        ? api.get<{ data: Consentimiento[] }>(`${base.value}/waivers`)
        : Promise.resolve({ data: { data: [] as Consentimiento[] } }),
    ]);
    tipos.value = t.data.data;
    docs.value = d.data.data;
    meta.value = d.data.meta ?? null;
    consentimientosVigentes.value = c.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// ---- Documentos ----
const subida = ref<{ persona: string; tipo: string; archivo: File | null }>({
  persona: "",
  tipo: "",
  archivo: null,
});
const subiendo = ref(false);
const accionando = ref(false);

async function subir(): Promise<void> {
  if (subida.value.archivo === null || subida.value.persona === "") {
    return;
  }
  subiendo.value = true;
  error.value = null;
  try {
    const fd = new FormData();
    fd.append("persona_id", subida.value.persona);
    if (subida.value.tipo !== "") {
      fd.append("tipo_documento_id", subida.value.tipo);
    }
    fd.append("archivo", subida.value.archivo);
    await api.post(`${base.value}/documentos`, fd);
    subida.value = { persona: "", tipo: "", archivo: null };
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    subiendo.value = false;
  }
}

async function validar(
  doc: Doc,
  estado: "aprobado" | "rechazado",
): Promise<void> {
  accionando.value = true;
  error.value = null;
  try {
    let motivo: string | null = null;
    if (estado === "rechazado") {
      motivo = window.prompt(t("documentos.docs.motivo")) ?? "";
    }
    await api.post(`${base.value}/documentos/${doc.id}/validar`, {
      estado,
      motivo,
    });
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    accionando.value = false;
  }
}

async function ver(doc: Doc): Promise<void> {
  try {
    const r = await api.get(`${base.value}/documentos/${doc.id}`, {
      responseType: "blob",
    });
    const url = URL.createObjectURL(r.data as Blob);
    window.open(url, "_blank");
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

// ---- Requisitos: alta y edición ----
function tipoVacio(): Omit<TipoDoc, "id"> {
  return {
    nombre: "",
    descripcion: "",
    obligatorio: true,
    aplica_a: "miembro",
    activo: true,
  };
}
const nuevoTipo = ref(tipoVacio());
const creandoTipo = ref(false);
const editandoTipo = ref<string | null>(null);
const tipoEditado = ref(tipoVacio());

async function crearTipo(): Promise<void> {
  creandoTipo.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/tipos-documento`, {
      nombre: nuevoTipo.value.nombre,
      descripcion: nuevoTipo.value.descripcion || null,
      obligatorio: nuevoTipo.value.obligatorio,
      aplica_a: nuevoTipo.value.aplica_a,
    });
    nuevoTipo.value = tipoVacio();
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    creandoTipo.value = false;
  }
}

function editarTipo(tipo: TipoDoc): void {
  editandoTipo.value = tipo.id;
  tipoEditado.value = { ...tipo, descripcion: tipo.descripcion ?? "" };
}

async function guardarTipo(): Promise<void> {
  if (editandoTipo.value === null) {
    return;
  }
  accionando.value = true;
  try {
    await api.put(`${base.value}/tipos-documento/${editandoTipo.value}`, {
      nombre: tipoEditado.value.nombre,
      descripcion: tipoEditado.value.descripcion || null,
      obligatorio: tipoEditado.value.obligatorio,
      aplica_a: tipoEditado.value.aplica_a,
      activo: tipoEditado.value.activo,
    });
    editandoTipo.value = null;
    toast.exito(t("documentosTabs.guardado"));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = false;
  }
}

// ---- Consentimientos ----
const editor = ref<{ abierto: boolean; clave: string | null }>({
  abierto: false,
  clave: null,
});
const borrador = ref({ titulo: "", contenido: "" });
const publicando = ref(false);
const textoAbierto = ref<string | null>(null);

function nuevoConsentimiento(): void {
  borrador.value = { titulo: "", contenido: "" };
  editor.value = { abierto: true, clave: null };
}

function nuevaVersion(c: Consentimiento): void {
  borrador.value = { titulo: c.titulo, contenido: c.contenido };
  editor.value = { abierto: true, clave: c.clave };
}

/** Clave estable a partir del título, sin chocar con una vigente. */
function claveNueva(titulo: string): string {
  const raiz =
    titulo
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-|-$/g, "")
      .slice(0, 80) || "consentimiento";
  const usadas = new Set(consentimientosVigentes.value.map((c) => c.clave));
  let clave = raiz;
  for (let n = 2; usadas.has(clave); n++) {
    clave = `${raiz}-${n}`;
  }
  return clave;
}

async function publicar(): Promise<void> {
  if (
    !(await confirmar(
      t("confirmaciones.publicarDocumento", { titulo: borrador.value.titulo }),
      { aceptar: t("confirmaciones.publicar") },
    ))
  ) {
    return;
  }
  publicando.value = true;
  try {
    await api.post(`${base.value}/waivers`, {
      clave: editor.value.clave ?? claveNueva(borrador.value.titulo),
      titulo: borrador.value.titulo,
      contenido: borrador.value.contenido,
    });
    editor.value.abierto = false;
    toast.exito(t("consentimientos.publicadoOk"));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    publicando.value = false;
  }
}

async function retirar(c: Consentimiento): Promise<void> {
  if (
    !(await confirmar(t("consentimientos.confirmarRetirar"), { peligro: true }))
  ) {
    return;
  }
  accionando.value = true;
  try {
    await api.post(`${base.value}/waivers/${c.id}/retirar`);
    toast.exito(t("consentimientos.retirado"));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    accionando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion :titulo="$t(TITULOS[pestana])">
      <template
        v-if="pestana === 'consentimientos' && puedeGestionar"
        #acciones
      >
        <button
          type="button"
          class="tu-btn tu-btn-primario tu-btn-crear text-sm"
          @click="nuevoConsentimiento"
        >
          {{ $t("consentimientos.nuevo") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <!-- Documentos cargados -->
    <div
      v-if="!cargando && pestana === 'documentos'"
      class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]"
    >
      <div class="relative tu-card min-w-0 overflow-x-auto">
        <div class="tu-filtros">
          <h2 class="font-medium mr-auto">
            {{ $t("documentos.docs.titulo") }}
          </h2>
          <div class="tu-segmentado" role="group">
            <button
              v-for="f in ['', 'pendiente', 'aprobado', 'rechazado']"
              :key="f"
              type="button"
              :aria-pressed="filtroEstado === f"
              :data-prueba="`filtro-doc-${f || 'todos'}`"
              @click="
                filtroEstado = f;
                pagina = 1;
                cargar();
              "
            >
              {{ $t(`documentos.docs.${f || "todos"}`) }}
            </button>
          </div>
        </div>

        <EstadoVacio
          v-if="docs.length === 0"
          class="py-8"
          icono="documentos"
          compacto
          :titulo="$t('documentos.docs.vacio')"
        />
        <table v-else class="tu-tabla">
          <thead>
            <tr>
              <th>{{ $t("documentosVisual.col.persona") }}</th>
              <th class="hidden sm:table-cell">
                {{ $t("documentosVisual.col.documento") }}
              </th>
              <th class="hidden md:table-cell">
                {{ $t("documentosVisual.col.subido") }}
              </th>
              <th>{{ $t("documentosVisual.col.estado") }}</th>
              <th>
                <span class="sr-only">{{
                  $t("documentosVisual.col.acciones")
                }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in docs" :key="d.id" data-prueba="documento">
              <td>
                <span class="font-medium">{{ d.persona ?? "—" }}</span>
                <span class="tu-sub sm:hidden">{{ d.tipo ?? d.nombre }}</span>
              </td>
              <td class="hidden sm:table-cell">{{ d.tipo ?? d.nombre }}</td>
              <td
                class="hidden md:table-cell whitespace-nowrap"
                :style="{ color: 'var(--texto-suave)' }"
              >
                {{ fecha(d.subido_en) }}
              </td>
              <td>
                <span
                  class="tu-pildora"
                  :style="{
                    '--tono':
                      d.estado === 'aprobado'
                        ? 'var(--exito)'
                        : d.estado === 'pendiente'
                          ? 'var(--aviso)'
                          : 'var(--error)',
                  }"
                  >{{ $t(`documentos.docs.${d.estado}`) }}</span
                >
              </td>
              <td class="text-right whitespace-nowrap">
                <template v-if="puedeGestionar && d.estado === 'pendiente'">
                  <button
                    class="tu-enlace text-sm"
                    :disabled="accionando"
                    @click="validar(d, 'aprobado')"
                  >
                    {{ $t("documentos.docs.aprobar") }}
                  </button>
                  <button
                    class="tu-enlace text-sm ml-3"
                    style="color: var(--error)"
                    :disabled="accionando"
                    @click="validar(d, 'rechazado')"
                  >
                    {{ $t("documentos.docs.rechazar") }}
                  </button>
                </template>
                <button class="tu-enlace text-sm ml-3" @click="ver(d)">
                  {{ $t("documentos.docs.ver") }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <PaginacionListado
          v-if="meta && meta.total > 0"
          :page="meta.page"
          :ultima-pagina="meta.ultima_pagina"
          :total="meta.total"
          :per-page="meta.per_page"
          :opciones-por-pagina="OPCIONES_POR_PAGINA"
          @ir="irPagina"
          @por-pagina="cambiarPorPagina"
        />
      </div>

      <div v-if="puedeSubir" class="tu-card p-5 h-max">
        <h2 class="font-semibold">{{ $t("documentos.docs.subir") }}</h2>
        <form class="mt-3 space-y-3" @submit.prevent="subir">
          <div>
            <label class="tu-label" for="dp">{{
              $t("documentos.docs.persona")
            }}</label>
            <BuscarPersona
              v-model="subida.persona"
              campo-id="dp"
              :buscar-en="`${base}/miembros`"
              :parametros="{ tipo: 'miembro' }"
            />
          </div>
          <div>
            <label class="tu-label" for="dt">{{
              $t("documentos.docs.tipo")
            }}</label>
            <select id="dt" v-model="subida.tipo" class="tu-input">
              <option value="">{{ $t("documentos.docs.sinTipo") }}</option>
              <option v-for="t2 in tipos" :key="t2.id" :value="t2.id">
                {{ t2.nombre }}
              </option>
            </select>
          </div>
          <div>
            <label class="tu-label" for="df">{{
              $t("documentos.docs.archivo")
            }}</label>
            <ZonaArchivo
              id="df"
              compacta
              icono="archivo"
              accept=".jpg,.jpeg,.png,.pdf"
              :max-bytes="8 * 1024 * 1024"
              :texto="$t('zonaArchivo.documento.arrastra')"
              :ayuda="$t('zonaArchivo.formatos.documento')"
              :cargado="subida.archivo?.name ?? null"
              :ocupado="subiendo"
              @archivo="subida.archivo = $event"
            />
          </div>
          <button
            class="tu-btn tu-btn-primario w-full"
            type="submit"
            :disabled="
              subiendo || subida.archivo === null || subida.persona === ''
            "
          >
            {{
              subiendo
                ? $t("documentos.docs.subiendo")
                : $t("documentos.docs.subirBtn")
            }}
          </button>
        </form>
      </div>
    </div>

    <!-- Requisitos -->
    <div
      v-if="!cargando && pestana === 'requisitos'"
      class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]"
    >
      <div class="tu-card p-5 min-w-0">
        <h2 class="font-semibold">{{ $t("documentos.tipos.titulo") }}</h2>
        <EstadoVacio
          v-if="tipos.length === 0"
          class="mt-4 py-6"
          icono="documentos"
          compacto
          :titulo="$t('documentos.tipos.vacio')"
        />
        <ul v-else class="mt-2">
          <li v-for="t2 in tipos" :key="t2.id" class="doc-fila text-sm">
            <form
              v-if="editandoTipo === t2.id"
              class="w-full space-y-3 py-1"
              @submit.prevent="guardarTipo"
            >
              <input
                v-model="tipoEditado.nombre"
                class="tu-input"
                :aria-label="$t('documentos.tipos.nombre')"
                required
              />
              <input
                v-model="tipoEditado.descripcion"
                class="tu-input"
                :placeholder="$t('documentos.tipos.descripcion')"
              />
              <div class="flex items-center gap-4 flex-wrap">
                <select
                  v-model="tipoEditado.aplica_a"
                  class="tu-input max-w-[12rem]"
                  :aria-label="$t('documentos.tipos.aplicaA')"
                >
                  <option value="miembro">
                    {{ $t("documentos.tipos.miembro") }}
                  </option>
                  <option value="instructor">
                    {{ $t("documentos.tipos.instructor") }}
                  </option>
                  <option value="todos">
                    {{ $t("documentos.tipos.todos") }}
                  </option>
                </select>
                <label class="flex items-center gap-1.5">
                  <input v-model="tipoEditado.obligatorio" type="checkbox" />
                  {{ $t("documentos.tipos.obligatorio") }}
                </label>
                <label class="flex items-center gap-1.5">
                  <input v-model="tipoEditado.activo" type="checkbox" />
                  {{ $t("documentosTabs.activo") }}
                </label>
              </div>
              <div class="flex gap-2">
                <button
                  type="submit"
                  class="tu-btn tu-btn-primario text-sm"
                  :disabled="accionando"
                >
                  {{ $t("documentosTabs.guardar") }}
                </button>
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma text-sm"
                  @click="editandoTipo = null"
                >
                  {{ $t("comun.cancelar") }}
                </button>
              </div>
            </form>
            <template v-else>
              <div class="min-w-0">
                <p
                  class="font-medium truncate"
                  :style="t2.activo ? {} : { color: 'var(--texto-suave)' }"
                >
                  {{ t2.nombre }}
                </p>
                <p
                  class="mt-0.5 text-xs flex items-center gap-1.5 flex-wrap"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  <span>{{ $t(`documentos.tipos.${t2.aplica_a}`) }}</span>
                  <span v-if="t2.obligatorio"
                    >· {{ $t("documentos.tipos.obligatorio") }}</span
                  >
                  <span v-if="!t2.activo"
                    >· {{ $t("documentosTabs.inactivo") }}</span
                  >
                </p>
              </div>
              <button
                v-if="puedeGestionar"
                type="button"
                class="tu-enlace shrink-0"
                @click="editarTipo(t2)"
              >
                {{ $t("documentosTabs.editar") }}
              </button>
            </template>
          </li>
        </ul>
      </div>

      <form
        v-if="puedeGestionar"
        class="tu-card p-5 h-max space-y-3"
        @submit.prevent="crearTipo"
      >
        <h2 class="font-semibold">{{ $t("documentos.tipos.nuevo") }}</h2>
        <input
          v-model="nuevoTipo.nombre"
          class="tu-input"
          :placeholder="$t('documentos.tipos.nombrePh')"
          :aria-label="$t('documentos.tipos.nombre')"
          required
        />
        <input
          v-model="nuevoTipo.descripcion"
          class="tu-input"
          :placeholder="$t('documentos.tipos.descripcion')"
        />
        <select
          v-model="nuevoTipo.aplica_a"
          class="tu-input"
          :aria-label="$t('documentos.tipos.aplicaA')"
        >
          <option value="miembro">{{ $t("documentos.tipos.miembro") }}</option>
          <option value="instructor">
            {{ $t("documentos.tipos.instructor") }}
          </option>
          <option value="todos">{{ $t("documentos.tipos.todos") }}</option>
        </select>
        <label class="flex items-center gap-1.5 text-sm">
          <input v-model="nuevoTipo.obligatorio" type="checkbox" />
          {{ $t("documentos.tipos.obligatorio") }}
        </label>
        <button
          class="tu-btn tu-btn-fantasma w-full"
          type="submit"
          :disabled="creandoTipo || nuevoTipo.nombre.trim() === ''"
        >
          {{ $t("documentos.tipos.crear") }}
        </button>
      </form>
    </div>

    <!-- Consentimientos -->
    <div
      v-if="!cargando && pestana === 'consentimientos'"
      class="mt-5 tu-card p-5"
    >
      <EstadoVacio
        v-if="consentimientosVigentes.length === 0"
        class="py-6"
        icono="documentos"
        compacto
        :titulo="$t('consentimientos.vacio')"
      />
      <ul v-else>
        <li
          v-for="c in consentimientosVigentes"
          :key="c.id"
          class="doc-fila text-sm"
        >
          <div class="min-w-0">
            <p class="font-medium truncate">{{ c.titulo }}</p>
            <p class="mt-0.5 text-xs" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("consentimientos.version", { n: c.version }) }} ·
              {{
                $t("consentimientos.publicado", {
                  fecha: fecha(c.publicado_en),
                })
              }}
              · {{ $t("consentimientos.firmas", c.firmas) }}
            </p>
          </div>
          <span class="flex items-center gap-3 shrink-0">
            <button
              type="button"
              class="tu-enlace"
              :aria-expanded="textoAbierto === c.id"
              @click="textoAbierto = textoAbierto === c.id ? null : c.id"
            >
              {{ $t("consentimientos.verTexto") }}
            </button>
            <button type="button" class="tu-enlace" @click="nuevaVersion(c)">
              {{ $t("consentimientos.nuevaVersion") }}
            </button>
            <button
              type="button"
              class="tu-enlace"
              style="color: var(--error)"
              :disabled="accionando"
              @click="retirar(c)"
            >
              {{ $t("consentimientos.retirar") }}
            </button>
          </span>
          <p
            v-if="textoAbierto === c.id"
            class="w-full whitespace-pre-line rounded-xl border p-4"
            :style="{ borderColor: 'var(--borde)', background: 'var(--fondo)' }"
          >
            {{ c.contenido }}
          </p>
        </li>
      </ul>
    </div>

    <PanelLateral
      :abierto="editor.abierto"
      :titulo="
        editor.clave === null
          ? $t('consentimientos.nuevo')
          : $t('consentimientos.nuevaVersion')
      "
      @cerrar="editor.abierto = false"
    >
      <form
        id="form-consentimiento"
        class="space-y-4 p-5"
        @submit.prevent="publicar"
      >
        <div>
          <label class="tu-label" for="c-titulo">{{
            $t("consentimientos.titulo")
          }}</label>
          <input
            id="c-titulo"
            v-model="borrador.titulo"
            class="tu-input"
            :placeholder="$t('consentimientos.tituloPh')"
            maxlength="255"
            required
          />
        </div>
        <div>
          <label class="tu-label" for="c-contenido">{{
            $t("consentimientos.contenido")
          }}</label>
          <textarea
            id="c-contenido"
            v-model="borrador.contenido"
            class="tu-input"
            rows="12"
            maxlength="20000"
            required
          />
        </div>
        <p
          v-if="editor.clave !== null"
          class="text-xs"
          :style="{ color: 'var(--aviso)' }"
        >
          {{ $t("consentimientos.avisoVersion") }}
        </p>
      </form>
      <template #pie>
        <div class="flex justify-end">
          <button
            type="submit"
            form="form-consentimiento"
            class="tu-btn tu-btn-primario"
            :disabled="publicando"
          >
            {{ $t("consentimientos.publicar") }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>

<style scoped>
.doc-fila {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--borde);
}
.doc-fila:first-child {
  border-top: 0;
}
</style>
