<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * «Roles y permisos» (ADR 0057): los roles del sistema (solo lectura) y los propios
 * del negocio. Al armar uno solo se pueden marcar los permisos que tiene quien lo
 * arma; los demás aparecen deshabilitados. La API aplica las mismas reglas.
 */
interface Rol {
  id: string | null;
  clave: string;
  nombre: string | null;
  sistema: boolean;
  permisos: string[];
  personas: number;
  puede_cambiar: boolean;
}

const { t, te } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const roles = ref<Rol[]>([]);
const catalogo = ref<Record<string, string[]>>({});
const misPermisos = ref<string[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
const abiertos = ref<Set<string>>(new Set());

const propios = computed(() => roles.value.filter((r) => !r.sistema));
const deSistema = computed(() => roles.value.filter((r) => r.sistema));

// Editor.
const editorAbierto = ref(false);
const editandoId = ref<string | null>(null);
const nombre = ref("");
const seleccion = ref<Set<string>>(new Set());
const guardando = ref(false);
const errorEditor = ref<string | null>(null);

function nombreDe(rol: Rol): string {
  if (rol.nombre !== null) {
    return rol.nombre;
  }
  return te(`usuarios.rol.${rol.clave}`)
    ? t(`usuarios.rol.${rol.clave}`)
    : rol.clave;
}

function etiquetaPermiso(clave: string): string {
  const llave = `operacion.rolesPropios.permiso.${clave}`;
  return te(llave) ? t(llave) : clave;
}

/** ¿Lo puede dar quien arma el rol? (nadie da lo que no tiene) */
function puedeDar(permiso: string): boolean {
  return misPermisos.value.includes("*") || misPermisos.value.includes(permiso);
}

function cuantos(rol: Rol): string {
  return rol.permisos.includes("*")
    ? t("operacion.rolesPropios.todos")
    : t("operacion.rolesPropios.cuantosPermisos", rol.permisos.length);
}

/** Los permisos del rol, agrupados como en el catálogo. */
function agrupados(rol: Rol): { grupo: string; permisos: string[] }[] {
  const todos = rol.permisos.includes("*");
  return Object.entries(catalogo.value)
    .map(([grupo, permisos]) => ({
      grupo,
      permisos: permisos.filter((p) => todos || rol.permisos.includes(p)),
    }))
    .filter((g) => g.permisos.length > 0);
}

function alternarVista(clave: string): void {
  const s = new Set(abiertos.value);
  if (s.has(clave)) {
    s.delete(clave);
  } else {
    s.add(clave);
  }
  abiertos.value = s;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{
      data: Rol[];
      catalogo: Record<string, string[]>;
      mis_permisos: string[];
    }>(`${base.value}/roles`);
    roles.value = data.data;
    catalogo.value = data.catalogo;
    misPermisos.value = data.mis_permisos;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

function abrirNuevo(): void {
  editandoId.value = null;
  nombre.value = "";
  seleccion.value = new Set();
  errorEditor.value = null;
  editorAbierto.value = true;
}

function abrirEdicion(rol: Rol): void {
  editandoId.value = rol.id;
  nombre.value = rol.nombre ?? "";
  seleccion.value = new Set(rol.permisos);
  errorEditor.value = null;
  editorAbierto.value = true;
}

function alternarPermiso(permiso: string): void {
  const s = new Set(seleccion.value);
  if (s.has(permiso)) {
    s.delete(permiso);
  } else {
    s.add(permiso);
  }
  seleccion.value = s;
}

async function guardar(): Promise<void> {
  guardando.value = true;
  errorEditor.value = null;
  const cuerpo = { nombre: nombre.value, permisos: [...seleccion.value] };
  try {
    if (editandoId.value === null) {
      await api.post(`${base.value}/roles`, cuerpo);
    } else {
      await api.put(`${base.value}/roles/${editandoId.value}`, cuerpo);
    }
    editorAbierto.value = false;
    toast.exito(t("operacion.rolesPropios.guardado"));
    await cargar();
  } catch (e) {
    errorEditor.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}

async function eliminar(rol: Rol): Promise<void> {
  if (
    rol.id === null ||
    !(await confirmar(
      t("operacion.rolesPropios.confirmarEliminar", { nombre: nombreDe(rol) }),
    ))
  ) {
    return;
  }
  try {
    await api.delete(`${base.value}/roles/${rol.id}`);
    toast.exito(t("operacion.rolesPropios.eliminado"));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion :titulo="$t('operacion.rolesPropios.titulo')" />
      <button class="tu-btn tu-btn-primario" type="button" @click="abrirNuevo">
        {{ $t("operacion.rolesPropios.nuevo") }}
      </button>
    </div>
    <p class="mt-2 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("operacion.rolesPropios.ayuda") }}
    </p>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando && error === null">
      <h2 class="rp-subtitulo">{{ $t("operacion.rolesPropios.propio") }}</h2>
      <p
        v-if="propios.length === 0"
        class="text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("operacion.rolesPropios.vacio") }}
      </p>
      <ul class="rp-lista">
        <li
          v-for="rol in propios"
          :key="rol.clave"
          class="tu-card rp-rol"
          data-prueba="rol-propio"
        >
          <div class="rp-cabeza">
            <div class="min-w-0">
              <p class="rp-nombre">{{ nombreDe(rol) }}</p>
              <p class="rp-detalle">
                {{ cuantos(rol) }} ·
                {{ $t("operacion.rolesPropios.personas", rol.personas) }}
              </p>
            </div>
            <div class="rp-acciones">
              <button
                type="button"
                class="tu-btn tu-btn-fantasma"
                @click="alternarVista(rol.clave)"
              >
                {{
                  abiertos.has(rol.clave)
                    ? $t("operacion.rolesPropios.ocultar")
                    : $t("operacion.rolesPropios.ver")
                }}
              </button>
              <template v-if="rol.puede_cambiar">
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma"
                  @click="abrirEdicion(rol)"
                >
                  {{ $t("operacion.rolesPropios.editar") }}
                </button>
                <button
                  type="button"
                  class="tu-btn tu-btn-fantasma"
                  @click="eliminar(rol)"
                >
                  {{ $t("operacion.rolesPropios.eliminar") }}
                </button>
              </template>
            </div>
          </div>
          <p v-if="!rol.puede_cambiar" class="rp-nota">
            {{ $t("operacion.rolesPropios.noCambiar") }}
          </p>
          <div v-if="abiertos.has(rol.clave)" class="rp-permisos">
            <div v-for="g in agrupados(rol)" :key="g.grupo">
              <p class="rp-grupo">
                {{ $t(`operacion.rolesPropios.grupos.${g.grupo}`) }}
              </p>
              <p class="rp-detalle">
                {{ g.permisos.map(etiquetaPermiso).join(" · ") }}
              </p>
            </div>
          </div>
        </li>
      </ul>

      <h2 class="rp-subtitulo">{{ $t("operacion.rolesPropios.sistema") }}</h2>
      <ul class="rp-lista">
        <li
          v-for="rol in deSistema"
          :key="rol.clave"
          class="tu-card rp-rol"
          data-prueba="rol-sistema"
        >
          <div class="rp-cabeza">
            <div class="min-w-0">
              <p class="rp-nombre">{{ nombreDe(rol) }}</p>
              <p class="rp-detalle">
                {{ cuantos(rol) }} ·
                {{ $t("operacion.rolesPropios.personas", rol.personas) }}
              </p>
            </div>
            <button
              type="button"
              class="tu-btn tu-btn-fantasma"
              @click="alternarVista(rol.clave)"
            >
              {{
                abiertos.has(rol.clave)
                  ? $t("operacion.rolesPropios.ocultar")
                  : $t("operacion.rolesPropios.ver")
              }}
            </button>
          </div>
          <div v-if="abiertos.has(rol.clave)" class="rp-permisos">
            <div v-for="g in agrupados(rol)" :key="g.grupo">
              <p class="rp-grupo">
                {{ $t(`operacion.rolesPropios.grupos.${g.grupo}`) }}
              </p>
              <p class="rp-detalle">
                {{ g.permisos.map(etiquetaPermiso).join(" · ") }}
              </p>
            </div>
          </div>
        </li>
      </ul>
    </template>

    <PanelLateral
      :abierto="editorAbierto"
      :titulo="
        editandoId === null
          ? $t('operacion.rolesPropios.editorNuevo')
          : $t('operacion.rolesPropios.editorEditar')
      "
      @cerrar="editorAbierto = false"
    >
      <form class="space-y-5 p-5" @submit.prevent="guardar">
        <div>
          <label class="tu-label" for="rol-nombre">{{
            $t("operacion.rolesPropios.nombre")
          }}</label>
          <input
            id="rol-nombre"
            v-model="nombre"
            class="tu-input"
            maxlength="60"
            :placeholder="$t('operacion.rolesPropios.nombrePh')"
            required
          />
        </div>
        <fieldset v-for="(permisos, grupo) in catalogo" :key="grupo">
          <legend class="rp-grupo">
            {{ $t(`operacion.rolesPropios.grupos.${grupo}`) }}
          </legend>
          <label
            v-for="p in permisos"
            :key="p"
            class="rp-opcion"
            :class="{ 'rp-opcion--bloqueada': !puedeDar(p) }"
            :title="puedeDar(p) ? '' : $t('operacion.rolesPropios.sinPermiso')"
          >
            <input
              type="checkbox"
              :value="p"
              :checked="seleccion.has(p)"
              :disabled="!puedeDar(p)"
              @change="alternarPermiso(p)"
            />
            <span>{{ etiquetaPermiso(p) }}</span>
          </label>
        </fieldset>
        <p v-if="errorEditor" class="text-sm" style="color: var(--error)">
          {{ errorEditor }}
        </p>
      </form>
      <template #pie>
        <button
          class="tu-btn tu-btn-primario w-full"
          type="button"
          :disabled="guardando || nombre.trim() === '' || seleccion.size === 0"
          @click="guardar"
        >
          {{ $t("operacion.rolesPropios.guardar") }}
        </button>
      </template>
    </PanelLateral>
  </section>
</template>

<style scoped>
.rp-subtitulo {
  margin-top: 2rem;
  margin-bottom: 0.75rem;
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--texto-suave);
}
.rp-lista {
  display: grid;
  gap: 0.75rem;
}
.rp-rol {
  padding: 1rem 1.1rem;
}
.rp-cabeza {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}
.rp-nombre {
  font-weight: 600;
}
.rp-detalle {
  font-size: 0.82rem;
  color: var(--texto-suave);
}
.rp-acciones {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
.rp-nota {
  margin-top: 0.5rem;
  font-size: 0.8rem;
  color: var(--texto-suave);
}
.rp-permisos {
  display: grid;
  gap: 0.6rem;
  margin-top: 0.9rem;
  padding-top: 0.9rem;
  border-top: 1px solid var(--borde);
}
.rp-grupo {
  font-size: 0.8rem;
  font-weight: 600;
  margin-bottom: 0.3rem;
}
.rp-opcion {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.35rem 0;
  font-size: 0.9rem;
  cursor: pointer;
}
.rp-opcion--bloqueada {
  cursor: not-allowed;
  opacity: 0.5;
}
</style>
