<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { RouterLink } from "vue-router";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelEditarMiembro, {
  type MiembroEditable,
} from "@/components/PanelEditarMiembro.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

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
      tipo: form.value.tipo,
    });
    const mismoTipo = form.value.tipo === tipo.value;
    form.value = {
      nombre: "",
      segundo_nombre: "",
      primer_apellido: "",
      segundo_apellido: "",
      email: "",
      tipo: tipo.value,
    };
    abiertoAlta.value = false;
    if (mismoTipo) {
      recargarDesde1();
    }
  } catch (e) {
    error.value = mensajeDeError(e);
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
  <section class="mx-auto max-w-5xl px-4 py-8">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion
        icono="miembros"
        :titulo="$t('miembros.titulo')"
        :subtitulo="$t('miembros.subtitulo')"
        :total="meta?.total ?? 0"
      />
      <button
        v-if="puedeGestionar"
        class="tu-btn tu-btn-primario"
        type="button"
        @click="abrirAlta"
      >
        + {{ $t("miembros.crear") }}
      </button>
    </div>

    <!-- Alumnos / instructores -->
    <div
      class="mt-6 inline-flex rounded-lg border p-1"
      :style="{ borderColor: 'var(--borde)' }"
    >
      <button
        v-for="op in ['miembro', 'instructor'] as const"
        :key="op"
        type="button"
        class="px-3 py-1.5 rounded-md text-sm font-semibold"
        :style="{
          background: tipo === op ? 'var(--primario)' : 'transparent',
          color: tipo === op ? 'var(--primario-contraste)' : 'var(--texto)',
        }"
        @click="tipo = op"
      >
        {{
          op === "miembro"
            ? $t("miembros.filtroMiembros")
            : $t("miembros.filtroInstructores")
        }}
      </button>
    </div>

    <div class="mt-6">
      <div class="min-w-0">
        <!-- Búsqueda + filtros -->
        <div class="flex flex-wrap items-center gap-2">
          <input
            v-model="q"
            type="search"
            class="tu-input flex-1 min-w-[12rem]"
            :placeholder="$t('miembros.buscar')"
          />
          <template v-if="tipo === 'miembro'">
            <select
              v-model="estado"
              class="tu-input w-auto"
              :aria-label="$t('miembros.colEstado')"
            >
              <option value="">{{ $t("miembros.filtros.estadoTodos") }}</option>
              <option value="activo">{{ $t("miembros.activo") }}</option>
              <option value="inactivo">{{ $t("miembros.suspendido") }}</option>
            </select>
            <select
              v-model="facturable"
              class="tu-input w-auto"
              :aria-label="$t('miembros.editar.facturable')"
            >
              <option value="">
                {{ $t("miembros.filtros.facturableTodos") }}
              </option>
              <option value="si">
                {{ $t("miembros.filtros.facturableSi") }}
              </option>
              <option value="no">
                {{ $t("miembros.filtros.facturableNo") }}
              </option>
            </select>
            <select
              v-model="archivado"
              class="tu-input w-auto"
              :aria-label="$t('miembros.editar.archivado')"
            >
              <option value="no">{{ $t("miembros.filtros.activos") }}</option>
              <option value="si">
                {{ $t("miembros.filtros.archivados") }}
              </option>
              <option value="todos">{{ $t("miembros.filtros.todos") }}</option>
            </select>
            <select
              v-if="hayMultiSucursal"
              v-model="sucursalFiltro"
              class="tu-input w-auto"
              :aria-label="$t('miembros.filtros.sede')"
            >
              <option value="">{{ $t("miembros.filtros.todasSedes") }}</option>
              <option v-for="s in sucursales" :key="s.id" :value="s.id">
                {{ s.nombre }}
              </option>
            </select>
          </template>
        </div>

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
                <tr class="text-left" :style="{ color: 'var(--texto-suave)' }">
                  <th class="px-4 py-2 font-medium">
                    {{ $t("miembros.colNombre") }}
                  </th>
                  <th class="px-4 py-2 font-medium hidden sm:table-cell">
                    {{ $t("miembros.colCorreo") }}
                  </th>
                  <th class="px-4 py-2 font-medium">
                    {{ $t("miembros.colEstado") }}
                  </th>
                  <th class="px-4 py-2 font-medium text-right"></th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="m in miembros"
                  :key="m.id"
                  class="border-t"
                  :style="{ borderColor: 'var(--borde)' }"
                >
                  <td class="px-4 py-2">
                    <div class="flex items-center gap-3">
                      <span
                        class="h-8 w-8 rounded-full inline-flex items-center justify-center text-xs font-bold text-white shrink-0"
                        :style="{ background: 'var(--primario)' }"
                        aria-hidden="true"
                        >{{ m.nombre.charAt(0).toUpperCase() }}</span
                      >
                      <RouterLink
                        v-if="tipo === 'miembro'"
                        :to="{ name: 'ficha-miembro', params: { id: m.id } }"
                        class="font-semibold tu-enlace"
                        >{{ nombreCompleto(m) }}</RouterLink
                      >
                      <span v-else class="font-semibold">{{
                        nombreCompleto(m)
                      }}</span>
                      <span
                        v-if="tipo === 'miembro' && m.primera_vez"
                        class="tu-badge tu-badge-aviso"
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
                  <td class="px-4 py-2">
                    <span class="flex flex-wrap gap-1">
                      <span
                        class="tu-badge"
                        :class="m.activo ? 'tu-badge-exito' : 'tu-badge-aviso'"
                        >{{
                          m.activo
                            ? $t("miembros.activo")
                            : $t("miembros.suspendido")
                        }}</span
                      >
                      <span
                        v-if="tipo === 'miembro' && !m.es_facturable"
                        class="tu-badge"
                        >{{ $t("miembros.noFacturable") }}</span
                      >
                      <span v-if="m.archivado" class="tu-badge">{{
                        $t("miembros.archivado")
                      }}</span>
                    </span>
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
                      class="tu-badge tu-badge-exito mr-3"
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
          </div>

          <!-- Paginación -->
          <div
            v-if="meta && meta.ultima_pagina > 1"
            class="mt-3 flex items-center justify-between gap-3 text-sm"
          >
            <span :style="{ color: 'var(--texto-suave)' }">{{
              $t("tabla.pagina", { n: meta.page, total: meta.ultima_pagina })
            }}</span>
            <div class="flex gap-2">
              <button
                class="tu-btn tu-btn-fantasma"
                type="button"
                :disabled="meta.page <= 1"
                @click="irPagina(meta.page - 1)"
              >
                {{ $t("tabla.anterior") }}
              </button>
              <button
                class="tu-btn tu-btn-fantasma"
                type="button"
                :disabled="meta.page >= meta.ultima_pagina"
                @click="irPagina(meta.page + 1)"
              >
                {{ $t("tabla.siguiente") }}
              </button>
            </div>
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
          <label class="tu-label" for="mt">{{ $t("miembros.tipo") }}</label>
          <select id="mt" v-model="form.tipo" class="tu-input">
            <option value="miembro">{{ $t("miembros.tipoMiembro") }}</option>
            <option value="instructor">
              {{ $t("miembros.tipoInstructor") }}
            </option>
          </select>
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
