<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import TablaDatos from "@/components/TablaDatos.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface UsuarioRow {
  id: string;
  nombre: string;
  email: string | null;
  rol: string;
  roles: string[];
  activo: boolean;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const usuarios = ref<UsuarioRow[]>([]);
const rolesDisponibles = ref<string[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

// El usuario actual solo puede conceder/quitar el rol de dueño si él mismo lo tiene.
const soyDueno = computed(() =>
  (sesion.usuario?.roles ?? []).includes("propietario"),
);

const editando = ref<UsuarioRow | null>(null);
const seleccion = ref<Set<string>>(new Set());
const guardando = ref(false);
const errorEdicion = ref<string | null>(null);

const columnas = computed(() => [
  { clave: "nombre", etiqueta: t("usuarios.colUsuario") },
  { clave: "roles", etiqueta: t("usuarios.colRoles") },
  { clave: "activo", etiqueta: t("usuarios.colEstado") },
  { clave: "acciones", etiqueta: "", alinear: "derecha" as const },
]);

function nombreRol(rol: string): string {
  return t(`usuarios.rol.${rol}`);
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: UsuarioRow[]; roles: string[] }>(
      `${base.value}/usuarios`,
    );
    usuarios.value = data.data;
    rolesDisponibles.value = data.roles;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function abrirEdicion(u: UsuarioRow): void {
  editando.value = u;
  seleccion.value = new Set(u.roles);
  errorEdicion.value = null;
}

function cerrarEdicion(): void {
  editando.value = null;
  errorEdicion.value = null;
}

function alternarRol(rol: string): void {
  const s = new Set(seleccion.value);
  if (s.has(rol)) {
    s.delete(rol);
  } else {
    s.add(rol);
  }
  seleccion.value = s;
}

// El rol de dueño solo lo toca otro dueño (el backend también lo protege).
function rolBloqueado(rol: string): boolean {
  return rol === "propietario" && !soyDueno.value;
}

async function guardar(): Promise<void> {
  if (editando.value === null || seleccion.value.size === 0) {
    errorEdicion.value = t("usuarios.minimoUnRol");
    return;
  }
  guardando.value = true;
  errorEdicion.value = null;
  try {
    const objetivo = editando.value;
    await api.put(`${base.value}/usuarios/${objetivo.id}/roles`, {
      roles: [...seleccion.value],
    });
    cerrarEdicion();
    await cargar();
    // Si me edité a mí mismo, refresco la sesión para actualizar permisos y menú.
    if (objetivo.id === sesion.usuario?.ulid) {
      await sesion.cargarYo();
    }
  } catch (e) {
    errorEdicion.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

const reenviandoId = ref<string | null>(null);
const reenviadoId = ref<string | null>(null);

async function reenviar(u: UsuarioRow): Promise<void> {
  reenviandoId.value = u.id;
  reenviadoId.value = null;
  error.value = null;
  try {
    await api.post(`${base.value}/usuarios/${u.id}/reenviar`);
    reenviadoId.value = u.id;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    reenviandoId.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      icono="usuarios"
      :titulo="$t('usuarios.titulo')"
      :subtitulo="$t('usuarios.subtitulo')"
      :total="usuarios.length"
    />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <TablaDatos
      v-if="!cargando && error === null"
      class="mt-6"
      :columnas="columnas"
      :filas="usuarios"
      :buscar-en="['nombre', 'email']"
      :vacio="$t('usuarios.vacio')"
    >
      <template #col-nombre="{ fila }">
        <div class="flex items-center gap-3">
          <span
            class="h-8 w-8 rounded-full inline-flex items-center justify-center text-xs font-bold text-white shrink-0"
            :style="{ background: 'var(--primario)' }"
            aria-hidden="true"
            >{{ (fila as UsuarioRow).nombre.charAt(0).toUpperCase() }}</span
          >
          <div class="min-w-0">
            <div class="font-semibold truncate">
              {{ (fila as UsuarioRow).nombre }}
            </div>
            <div
              class="text-xs truncate"
              :style="{ color: 'var(--texto-suave)' }"
            >
              {{ (fila as UsuarioRow).email ?? $t("usuarios.sinCorreo") }}
            </div>
          </div>
        </div>
      </template>

      <template #col-roles="{ fila }">
        <div class="flex flex-wrap gap-1">
          <span
            v-for="r in (fila as UsuarioRow).roles"
            :key="r"
            class="tu-badge"
            :class="r === 'propietario' ? 'tu-badge-exito' : ''"
          >
            {{ nombreRol(r) }}
          </span>
        </div>
      </template>

      <template #col-activo="{ valor }">
        <span class="tu-badge" :class="valor ? 'tu-badge-exito' : ''">
          {{ valor ? $t("usuarios.activo") : $t("usuarios.inactivo") }}
        </span>
      </template>

      <template #col-acciones="{ fila }">
        <div class="flex items-center justify-end gap-2">
          <button
            v-if="!(fila as UsuarioRow).activo"
            class="tu-btn tu-btn-fantasma text-sm"
            type="button"
            :disabled="reenviandoId === (fila as UsuarioRow).id"
            @click="reenviar(fila as UsuarioRow)"
          >
            {{
              reenviadoId === (fila as UsuarioRow).id
                ? $t("usuarios.reenviado")
                : reenviandoId === (fila as UsuarioRow).id
                  ? $t("usuarios.reenviando")
                  : $t("usuarios.reenviar")
            }}
          </button>
          <button
            class="tu-btn tu-btn-fantasma text-sm"
            type="button"
            @click="abrirEdicion(fila as UsuarioRow)"
          >
            {{ $t("usuarios.editarRoles") }}
          </button>
        </div>
      </template>
    </TablaDatos>

    <!-- Modal: editar roles -->
    <div
      v-if="editando"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
    >
      <div class="absolute inset-0 bg-black/50" @click="cerrarEdicion" />
      <div class="relative tu-card w-full max-w-md p-6">
        <div class="flex items-center justify-between">
          <h2 class="font-bold text-lg">{{ $t("usuarios.editarRoles") }}</h2>
          <button
            class="tu-icono-btn"
            :aria-label="$t('usuarios.cancelar')"
            @click="cerrarEdicion"
          >
            ✕
          </button>
        </div>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ editando.nombre }} ·
          {{ editando.email ?? $t("usuarios.sinCorreo") }}
        </p>

        <div class="mt-4 space-y-2">
          <label
            v-for="r in rolesDisponibles"
            :key="r"
            class="flex items-center gap-3 rounded-lg border p-3 cursor-pointer"
            :style="{
              borderColor: seleccion.has(r)
                ? 'var(--primario)'
                : 'var(--borde)',
              opacity: rolBloqueado(r) ? 0.5 : 1,
            }"
          >
            <input
              type="checkbox"
              :checked="seleccion.has(r)"
              :disabled="rolBloqueado(r)"
              @change="alternarRol(r)"
            />
            <span class="font-medium">{{ nombreRol(r) }}</span>
          </label>
        </div>

        <p
          v-if="soyDueno === false"
          class="mt-3 text-xs"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("usuarios.duenoProtegido") }}
        </p>
        <p v-if="errorEdicion" class="mt-3 text-sm" style="color: var(--error)">
          {{ errorEdicion }}
        </p>

        <div class="mt-5 flex justify-end gap-2">
          <button
            class="tu-btn tu-btn-fantasma"
            type="button"
            @click="cerrarEdicion"
          >
            {{ $t("usuarios.cancelar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="guardando || seleccion.size === 0"
            @click="guardar"
          >
            {{ guardando ? $t("usuarios.guardando") : $t("usuarios.guardar") }}
          </button>
        </div>
      </div>
    </div>
  </section>
</template>
