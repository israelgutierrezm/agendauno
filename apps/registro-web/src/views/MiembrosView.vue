<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";

import { useI18n } from "vue-i18n";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import BarraListado from "@/components/BarraListado.vue";
import BotonImportar from "@/components/BotonImportar.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PaginacionListado from "@/components/PaginacionListado.vue";
import PanelEditarMiembro, {
  type MiembroEditable,
} from "@/components/PanelEditarMiembro.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

const { t } = useI18n();
const toast = useToastStore();

interface Miembro {
  id: string;
  nombre: string;
  segundo_nombre: string | null;
  primer_apellido: string | null;
  segundo_apellido: string | null;
  nombre_completo: string;
  email: string | null;
  tipo: string;
  activo: boolean;
  es_facturable: boolean;
  archivado: boolean;
  primera_vez: boolean | null;
}
interface Meta {
  total: number;
  page: number;
  per_page: number;
  ultima_pagina: number;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("miembros.gestionar"));
const puedeInvitar = computed(() => sesion.puede("usuarios.invitar"));

const tipo = ref<"miembro" | "instructor">("miembro");
const q = ref("");
const facturable = ref("");
const estado = ref("");
const archivado = ref("no");
const sucursalFiltro = ref("");
const page = ref(1);
const perPage = 20;

const sucursales = ref<{ id: string; nombre: string }[]>([]);
// El filtro por sede solo aplica con varias sucursales (R19).
const hayMultiSucursal = computed(() => sucursales.value.length > 1);

// Definición de filtros para la BarraListado (estilo Acadion). Solo para alumnos.
const filtrosDef = computed(() => {
  if (tipo.value !== "miembro") {
    return [];
  }
  const defs = [
    {
      clave: "estado",
      etiqueta: t("miembros.colEstado"),
      opciones: [
        { valor: "activo", texto: t("miembros.activo") },
        { valor: "inactivo", texto: t("miembros.suspendido") },
      ],
    },
    {
      clave: "facturable",
      etiqueta: t("miembros.editar.facturable"),
      opciones: [
        { valor: "si", texto: t("miembros.filtros.facturableSi") },
        { valor: "no", texto: t("miembros.filtros.facturableNo") },
      ],
    },
    {
      clave: "archivado",
      etiqueta: t("miembros.editar.archivado"),
      opciones: [
        { valor: "si", texto: t("miembros.filtros.archivados") },
        { valor: "todos", texto: t("miembros.filtros.todos") },
      ],
    },
  ];
  if (hayMultiSucursal.value) {
    defs.push({
      clave: "sucursal_id",
      etiqueta: t("miembros.filtros.sede"),
      opciones: sucursales.value.map((s) => ({ valor: s.id, texto: s.nombre })),
    });
  }
  return defs;
});

const valoresFiltro = computed<Record<string, string>>(() => ({
  estado: estado.value,
  facturable: facturable.value,
  archivado: archivado.value,
  sucursal_id: sucursalFiltro.value,
}));

function cambioFiltro(clave: string, valor: string): void {
  if (clave === "estado") {
    estado.value = valor;
  } else if (clave === "facturable") {
    facturable.value = valor;
  } else if (clave === "archivado") {
    archivado.value = valor === "" ? "no" : valor;
  } else if (clave === "sucursal_id") {
    sucursalFiltro.value = valor;
  }
}

function limpiarFiltros(): void {
  estado.value = "";
  facturable.value = "";
  archivado.value = "no";
  sucursalFiltro.value = "";
}

const miembros = ref<Miembro[]>([]);
const meta = ref<Meta | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);
const mensaje = ref<string | null>(null);

const editando = ref<MiembroEditable | null>(null);
const invitandoId = ref<string | null>(null);
const invitados = ref<Set<string>>(new Set());

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Miembro[]; meta: Meta }>(
      `${base.value}/miembros`,
      {
        params: {
          tipo: tipo.value,
          q: q.value.trim() || undefined,
          facturable: facturable.value || undefined,
          estado: estado.value || undefined,
          archivado: archivado.value,
          sucursal_id: sucursalFiltro.value || undefined,
          page: page.value,
          per_page: perPage,
        },
      },
    );
    miembros.value = data.data;
    meta.value = data.meta;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function recargarDesde1(): void {
  page.value = 1;
  void cargar();
}

let tempQ: ReturnType<typeof setTimeout> | undefined;
watch(q, () => {
  clearTimeout(tempQ);
  tempQ = setTimeout(recargarDesde1, 300);
});
watch([tipo, facturable, estado, archivado, sucursalFiltro], recargarDesde1);

function irPagina(n: number): void {
  if (meta.value === null || n < 1 || n > meta.value.ultima_pagina) {
    return;
  }
  page.value = n;
  void cargar();
}

function nombreCompleto(m: Miembro): string {
  return (
    m.nombre_completo ||
    [m.nombre, m.primer_apellido].filter(Boolean).join(" ").trim()
  );
}

function abrirEditar(m: Miembro): void {
  editando.value = {
    id: m.id,
    nombre: m.nombre,
    segundo_nombre: m.segundo_nombre,
    primer_apellido: m.primer_apellido,
    segundo_apellido: m.segundo_apellido,
    email: m.email,
    activo: m.activo,
    es_facturable: m.es_facturable,
    archivado: m.archivado,
  };
}
function onGuardado(): void {
  editando.value = null;
  void cargar();
}

async function invitar(m: Miembro): Promise<void> {
  if (!m.email) {
    return;
  }
  invitandoId.value = m.id;
  error.value = null;
  try {
    await api.post(`${base.value}/usuarios/invitar`, {
      nombre: nombreCompleto(m),
      email: m.email,
      rol: "miembro",
    });
    invitados.value = new Set(invitados.value).add(m.id);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    invitandoId.value = null;
  }
}

// --- Alta ---
const form = ref({
  nombre: "",
  segundo_nombre: "",
  primer_apellido: "",
  segundo_apellido: "",
  email: "",
  celular: "",
  tipo: "miembro",
});
const guardando = ref(false);
const abiertoAlta = ref(false);

function abrirAlta(): void {
  form.value = {
    nombre: "",
    segundo_nombre: "",
    primer_apellido: "",
    segundo_apellido: "",
    email: "",
    celular: "",
    tipo: tipo.value,
  };
  mensaje.value = null;
  error.value = null;
  abiertoAlta.value = true;
}
function cerrarAlta(): void {
  abiertoAlta.value = false;
}

async function crear(): Promise<void> {
  guardando.value = true;
  error.value = null;
  mensaje.value = null;
  try {
    await api.post(`${base.value}/miembros`, {
      nombre: form.value.nombre,
      segundo_nombre: form.value.segundo_nombre || null,
      primer_apellido: form.value.primer_apellido || null,
      segundo_apellido: form.value.segundo_apellido || null,
      email: form.value.email || null,
      celular: form.value.celular || null,
      tipo: form.value.tipo,
    });
    const mismoTipo = form.value.tipo === tipo.value;
    toast.exito(t("miembros.creado"));
    form.value = {
      nombre: "",
      segundo_nombre: "",
      primer_apellido: "",
      segundo_apellido: "",
      email: "",
      celular: "",
      tipo: tipo.value,
    };
    abiertoAlta.value = false;
    if (mismoTipo) {
      recargarDesde1();
    }
  } catch (e) {
    error.value = mensajeDeError(e);
    toast.error(error.value);
  } finally {
    guardando.value = false;
  }
}

watch(tipo, () => {
  form.value.tipo = tipo.value;
});
async function cargarSucursales(): Promise<void> {
  try {
    const { data } = await api.get<{ data: { id: string; nombre: string }[] }>(
      `${base.value}/sucursales`,
    );
    sucursales.value = data.data;
  } catch {
    // Sin permiso de sucursales o sin sedes: el filtro simplemente no aparece.
    sucursales.value = [];
  }
}

onMounted(() => {
  form.value.tipo = tipo.value;
  void cargarSucursales();
  void cargar();
});
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 py-8">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion
        :titulo="plural(sesion.terminologia.miembro)"
        :total="meta?.total ?? 0"
      />
      <BotonImportar
        v-if="puedeGestionar"
        ruta="importar"
        :texto="$t('nav.importar')"
      />
    </div>

    <div class="mt-6">
      <div class="min-w-0">
        <!-- Buscador + filtros + «Agregar» (estilo Acadion). -->
        <BarraListado
          v-model:busqueda="q"
          :filtros="filtrosDef"
          :valores="valoresFiltro"
          :placeholder="$t('miembros.buscar')"
          :puede-crear="puedeGestionar"
          :nuevo-texto="$t('miembros.crear')"
          @cambio-filtro="cambioFiltro"
          @limpiar="limpiarFiltros"
          @nuevo="abrirAlta"
        />

        <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
          {{ error }}
        </p>
        <p
          v-if="cargando"
          class="tu-card mt-4 p-6 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>

        <template v-else>
          <p
            v-if="miembros.length === 0"
            class="tu-card mt-4 p-6 text-sm"
            :style="{ color: 'var(--texto-suave)' }"
          >
            {{ $t("miembros.vacio") }}
          </p>
          <div v-else class="mt-4 tu-card overflow-hidden">
            <table class="w-full text-sm">
              <thead>
                <tr
                  class="text-left text-xs font-semibold uppercase tracking-wider"
                  :style="{
                    color: 'var(--texto-suave)',
                    background:
                      'color-mix(in srgb, var(--texto-suave) 6%, var(--superficie))',
                  }"
                >
                  <th class="px-4 py-3 whitespace-nowrap">
                    {{ $t("miembros.colNombre") }}
                  </th>
                  <th class="px-4 py-3 whitespace-nowrap hidden sm:table-cell">
                    {{ $t("miembros.colCorreo") }}
                  </th>
                  <th class="px-4 py-3 whitespace-nowrap">
                    {{ $t("miembros.colEstado") }}
                  </th>
                  <th class="px-4 py-3 text-right"></th>
                </tr>
              </thead>
              <tbody class="tu-tabla-cuerpo">
                <tr
                  v-for="m in miembros"
                  :key="m.id"
                  class="border-t"
                  :style="{ borderColor: 'var(--borde)' }"
                >
                  <td class="px-4 py-2">
                    <div class="flex items-center gap-3">
                      <AvatarIniciales :nombre="m.nombre" tam="md" />
                      <RouterLink
                        v-if="tipo === 'miembro'"
                        :to="{ name: 'ficha-miembro', params: { id: m.id } }"
                        class="font-medium hover:underline"
                        >{{ nombreCompleto(m) }}</RouterLink
                      >
                      <span v-else class="font-medium">{{
                        nombreCompleto(m)
                      }}</span>
                      <span
                        v-if="tipo === 'miembro' && m.primera_vez"
                        class="text-xs font-medium"
                        :style="{ color: 'var(--aviso)' }"
                        :title="$t('miembros.nuevoAyuda')"
                        >{{ $t("miembros.nuevo") }}</span
                      >
                    </div>
                  </td>
                  <td
                    class="px-4 py-2 hidden sm:table-cell"
                    :style="{ color: 'var(--texto-suave)' }"
                  >
                    {{ m.email ?? "—" }}
                  </td>
                  <!-- Estado en texto: lo normal (activo) en gris; lo que requiere atención, en color. -->
                  <td class="px-4 py-2">
                    <span
                      :style="{
                        color: m.activo ? 'var(--texto-suave)' : 'var(--aviso)',
                      }"
                      >{{
                        m.activo
                          ? $t("miembros.activo")
                          : $t("miembros.suspendido")
                      }}</span
                    >
                    <span
                      v-if="tipo === 'miembro' && !m.es_facturable"
                      :style="{ color: 'var(--texto-suave)' }"
                    >
                      · {{ $t("miembros.noFacturable") }}</span
                    >
                    <span
                      v-if="m.archivado"
                      :style="{ color: 'var(--texto-suave)' }"
                    >
                      · {{ $t("miembros.archivado") }}</span
                    >
                  </td>
                  <td class="px-4 py-2 text-right whitespace-nowrap">
                    <button
                      v-if="
                        puedeInvitar &&
                        tipo === 'miembro' &&
                        m.email &&
                        !invitados.has(m.id)
                      "
                      class="tu-enlace text-sm mr-3"
                      type="button"
                      :disabled="invitandoId === m.id"
                      @click="invitar(m)"
                    >
                      {{
                        invitandoId === m.id
                          ? $t("miembros.invitando")
                          : $t("miembros.invitar")
                      }}
                    </button>
                    <span
                      v-else-if="invitados.has(m.id)"
                      class="text-sm mr-3"
                      :style="{ color: 'var(--texto-suave)' }"
                      >{{ $t("miembros.invitado") }}</span
                    >
                    <button
                      v-if="puedeGestionar"
                      class="tu-enlace text-sm"
                      type="button"
                      @click="abrirEditar(m)"
                    >
                      {{ $t("miembros.editar.abrir") }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
            <PaginacionListado
              v-if="meta"
              :page="meta.page"
              :ultima-pagina="meta.ultima_pagina"
              :total="meta.total"
              :per-page="meta.per_page"
              @ir="irPagina"
            />
          </div>
        </template>
      </div>
    </div>

    <!-- Alta de miembro (drawer lateral) -->
    <PanelLateral
      :abierto="abiertoAlta"
      :titulo="$t('miembros.nuevoTitulo')"
      @cerrar="cerrarAlta"
    >
      <form class="space-y-4" @submit.prevent="crear">
        <div>
          <label class="tu-label" for="mn">{{ $t("miembros.nombre") }}</label>
          <input id="mn" v-model="form.nombre" class="tu-input" required />
        </div>
        <div>
          <label class="tu-label" for="mpa">{{
            $t("miembros.primerApellido")
          }}</label>
          <input id="mpa" v-model="form.primer_apellido" class="tu-input" />
        </div>
        <div>
          <label class="tu-label" for="me">{{ $t("miembros.email") }}</label>
          <input id="me" v-model="form.email" class="tu-input" type="email" />
        </div>
        <div>
          <label class="tu-label" for="mc">{{ $t("miembros.celular") }}</label>
          <input id="mc" v-model="form.celular" class="tu-input" type="tel" />
        </div>
        <p v-if="mensaje" class="text-sm" :style="{ color: 'var(--exito)' }">
          {{ $t("miembros.creado") }}
        </p>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            class="tu-btn tu-btn-fantasma"
            type="button"
            @click="cerrarAlta"
          >
            {{ $t("comun.cerrar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="guardando || form.nombre === ''"
            @click="crear"
          >
            {{ guardando ? $t("miembros.creando") : $t("miembros.crear") }}
          </button>
        </div>
      </template>
    </PanelLateral>

    <PanelEditarMiembro
      v-if="editando"
      :miembro="editando"
      @cerrar="editando = null"
      @guardado="onGuardado"
    />
  </section>
</template>

<style scoped>
.tu-tabla-cuerpo tr {
  transition: background-color 0.12s ease;
}
.tu-tabla-cuerpo tr:hover {
  background: color-mix(in srgb, var(--primario) 5%, transparent);
}
</style>
