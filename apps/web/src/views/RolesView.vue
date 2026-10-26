<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { filtrarOpciones } from "@/lib/buscable";
import { confirmar } from "@/lib/confirmar";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { claveSegunModalidad } from "@/lib/menu";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * «Roles y permisos» (ADR 0057): los roles del sistema (solo lectura) y los propios
 * del negocio. Al armar uno solo se pueden marcar los permisos que tiene quien lo
 * arma; los demás aparecen deshabilitados. La API aplica las mismas reglas.
 * Un rol propio es del equipo o de quien imparte (ADR 0078); eso se elige al crearlo.
 */
type FacetaPropia = "equipo" | "instructor";
interface Rol {
  id: string | null;
  clave: string;
  nombre: string | null;
  sistema: boolean;
  faceta?: string;
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
// Lo que cada permiso necesita para que sus pantallas funcionen (lo exige la API).
const requisitos = ref<Record<string, string[]>>({});
const cargando = ref(true);
const error = ref<string | null>(null);
const abiertos = ref<Set<string>>(new Set());

const propios = computed(() => roles.value.filter((r) => !r.sistema));
const deSistema = computed(() => roles.value.filter((r) => r.sistema));

// Editor.
const editorAbierto = ref(false);
const editandoId = ref<string | null>(null);
const nombre = ref("");
const faceta = ref<FacetaPropia>("equipo");
const seleccion = ref<Set<string>>(new Set());
// Buscar entre los permisos (por su nombre o el de su área); no cuenta como cambio.
const busquedaPermiso = ref("");
// El patrón común de los formularios (errores junto al campo, no cerrar mientras
// guarda, preguntar antes de descartar, confirmar lo guardado).
const {
  guardando,
  errores,
  error: errorEditor,
  empezar,
  cerrar: cerrarEditor,
  marcar,
  guardar: guardarEditor,
} = useEditorFormulario({
  abierto: editorAbierto,
  valor: () => ({
    nombre: nombre.value,
    faceta: faceta.value,
    permisos: [...seleccion.value].sort(),
  }),
  campos: ["nombre", "faceta", "permisos"],
  formulario: "#form-rol",
});

function nombreDe(rol: Rol): string {
  if (rol.nombre !== null) {
    return rol.nombre;
  }
  return te(`usuarios.rol.${rol.clave}`)
    ? t(`usuarios.rol.${rol.clave}`)
    : rol.clave;
}

// Una llave sin texto (un permiso nuevo del API) no se muestra cruda:
// «prospectos.ver» se lee «Prospectos ver» mientras le ponen su nombre.
function textoLegible(clave: string): string {
  const texto = clave.replace(/[._]+/g, " ").trim();
  return texto.charAt(0).toUpperCase() + texto.slice(1);
}

function etiquetaPermiso(clave: string): string {
  // En citas se venden bonos, no membresías (ADR 0130): su variante «…Citas».
  const llave = claveSegunModalidad(
    `operacion.rolesPropios.permiso.${clave}`,
    sesion.esCitas,
    te,
  );
  return te(llave) ? t(llave) : textoLegible(clave);
}

function etiquetaGrupo(grupo: string): string {
  const llave = claveSegunModalidad(
    `operacion.rolesPropios.grupos.${grupo}`,
    sesion.esCitas,
    te,
  );
  return te(llave) ? t(llave) : textoLegible(grupo);
}

// Lo que el rol deja hacer en cada área, en palabras: sin acceso, solo consulta,
// parcial o completo.
type Nivel = "ninguno" | "consulta" | "parcial" | "completo";
interface NivelArea {
  nivel: Nivel;
  n: number;
  total: number;
}
const TONO_NIVEL: Record<Nivel, string> = {
  ninguno: "var(--texto-suave)",
  consulta: "var(--primario)",
  parcial: "var(--primario)",
  completo: "var(--exito)",
};

function nivelDe(permisos: string[], tiene: (p: string) => boolean): NivelArea {
  const suyos = permisos.filter(tiene);
  const n = suyos.length;
  const total = permisos.length;
  const nivel: Nivel =
    n === 0
      ? "ninguno"
      : n === total
        ? "completo"
        : suyos.every((p) => p.endsWith(".ver"))
          ? "consulta"
          : "parcial";
  return { nivel, n, total };
}

function textoNivel(x: NivelArea): string {
  return t(`operacion.rolesPropios.niveles.${x.nivel}`, {
    n: x.n,
    total: x.total,
  });
}

/** «Agenda, Alumnos y Cobros». */
function unirLista(partes: string[]): string {
  return partes.length < 2
    ? (partes[0] ?? "")
    : `${partes.slice(0, -1).join(", ")} y ${partes.at(-1)}`;
}

/** Las áreas a las que da acceso, en una línea: «Acceso a Agenda (solo consulta) y Alumnos.» */
function resumenAcceso(tiene: (p: string) => boolean): string {
  const areas = Object.entries(catalogo.value).flatMap(([grupo, permisos]) => {
    const { nivel } = nivelDe(permisos, tiene);
    if (nivel === "ninguno") {
      return [];
    }
    const area = etiquetaGrupo(grupo);
    return [
      nivel === "consulta"
        ? t("operacion.rolesPropios.soloConsulta", { area })
        : area,
    ];
  });
  return areas.length === 0
    ? t("operacion.rolesPropios.sinAcceso")
    : t("operacion.rolesPropios.accesoA", { lista: unirLista(areas) });
}

function tieneRol(rol: Rol): (p: string) => boolean {
  return (p) => rol.permisos.includes("*") || rol.permisos.includes(p);
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

/** Los permisos del rol, agrupados como en el catálogo, con su nivel por área. */
function agrupados(
  rol: Rol,
): { grupo: string; permisos: string[]; nivel: NivelArea }[] {
  const tiene = tieneRol(rol);
  return Object.entries(catalogo.value)
    .map(([grupo, permisos]) => ({
      grupo,
      permisos: permisos.filter(tiene),
      nivel: nivelDe(permisos, tiene),
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
      requisitos?: Record<string, string[]>;
      mis_permisos: string[];
    }>(`${base.value}/roles`);
    roles.value = data.data;
    catalogo.value = data.catalogo;
    requisitos.value = data.requisitos ?? {};
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
  faceta.value = "equipo";
  seleccion.value = new Set();
  busquedaPermiso.value = "";
  empezar();
  editorAbierto.value = true;
}

function abrirEdicion(rol: Rol): void {
  editandoId.value = rol.id;
  nombre.value = rol.nombre ?? "";
  faceta.value = rol.faceta === "instructor" ? "instructor" : "equipo";
  seleccion.value = new Set(rol.permisos);
  busquedaPermiso.value = "";
  empezar();
  editorAbierto.value = true;
}

// Por cada permiso elegido, lo que le falta para servir.
const faltantes = computed<Record<string, string[]>>(() => {
  const r: Record<string, string[]> = {};
  for (const p of seleccion.value) {
    const faltan = (requisitos.value[p] ?? []).filter(
      (x) => !seleccion.value.has(x),
    );
    if (faltan.length > 0) {
      r[p] = faltan;
    }
  }
  return r;
});
// Quien imparte ve sus clases o citas en su portal: necesita ver la agenda.
const sinAgenda = computed(
  () => faceta.value === "instructor" && !seleccion.value.has("agenda.ver"),
);
const incompleto = computed(
  () => Object.keys(faltantes.value).length > 0 || sinAgenda.value,
);

// Las áreas del editor con los permisos que coinciden con la búsqueda. Un área sin
// coincidencias se oculta, salvo que a un permiso suyo le falte algo por agregar.
const tieneSeleccion = (p: string): boolean => seleccion.value.has(p);
const gruposEditor = computed(() =>
  Object.entries(catalogo.value)
    .map(([grupo, permisos]) => {
      const etiqueta = etiquetaGrupo(grupo);
      const visibles = filtrarOpciones(
        permisos.map((p) => ({
          valor: p,
          etiqueta: etiquetaPermiso(p),
          busqueda: etiqueta,
        })),
        busquedaPermiso.value,
      ).map((o) => o.valor);
      return {
        grupo,
        etiqueta,
        permisos,
        visibles,
        nivel: nivelDe(permisos, tieneSeleccion),
      };
    })
    .filter(
      (g) =>
        g.visibles.length > 0 ||
        g.permisos.some((p) => faltantes.value[p] !== undefined),
    ),
);
const sinCoincidencias = computed(() =>
  gruposEditor.value.every((g) => g.visibles.length === 0),
);
const resumenSeleccion = computed(() => resumenAcceso(tieneSeleccion));

// A la vista y a petición: nunca se agregan solos.
function agregarNecesarios(permiso: string): void {
  const s = new Set(seleccion.value);
  for (const x of faltantes.value[permiso] ?? []) {
    if (puedeDar(x)) {
      s.add(x);
    }
  }
  seleccion.value = s;
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
  const cuerpo = { nombre: nombre.value, permisos: [...seleccion.value] };
  const id = editandoId.value;
  const listo = await guardarEditor(
    (): Promise<unknown> =>
      id === null
        ? api.post(`${base.value}/roles`, { ...cuerpo, faceta: faceta.value })
        : api.put(`${base.value}/roles/${id}`, cuerpo),
    t("operacion.rolesPropios.guardadoNombre", { nombre: nombre.value.trim() }),
  );
  if (listo !== undefined) {
    editorAbierto.value = false;
    await cargar();
  }
}

// El rol que se está eliminando: su botón no se vuelve a tocar mientras tanto.
const eliminandoId = ref<string | null>(null);
async function eliminar(rol: Rol): Promise<void> {
  if (
    rol.id === null ||
    eliminandoId.value !== null ||
    !(await confirmar(
      t("operacion.rolesPropios.confirmarEliminar", { nombre: nombreDe(rol) }),
      { peligro: true, aceptar: t("operacion.rolesPropios.eliminar") },
    ))
  ) {
    return;
  }
  eliminandoId.value = rol.id;
  try {
    await api.delete(`${base.value}/roles/${rol.id}`);
    toast.exito(t("operacion.rolesPropios.eliminado"));
    await cargar();
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    eliminandoId.value = null;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('operacion.rolesPropios.titulo')"
      :subtitulo="$t('operacion.rolesPropios.ayuda')"
    >
      <template #acciones>
        <button
          class="tu-btn tu-btn-primario tu-btn-crear"
          type="button"
          @click="abrirNuevo"
        >
          {{ $t("operacion.rolesPropios.nuevo") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
    </AvisoTono>

    <template v-if="!cargando && error === null">
      <h2 class="rp-subtitulo">{{ $t("operacion.rolesPropios.propio") }}</h2>
      <EstadoVacio
        v-if="propios.length === 0"
        class="py-6"
        icono="usuarios"
        compacto
        :titulo="$t('operacion.rolesPropios.vacio')"
      />
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
                <template v-if="rol.faceta === 'instructor'"
                  >{{ $t("operacion.rolesPropios.deInstructor") }} ·
                </template>
                {{ cuantos(rol) }} ·
                {{ $t("operacion.rolesPropios.personas", rol.personas) }}
              </p>
              <p
                v-if="!rol.permisos.includes('*')"
                class="rp-resumen"
                data-prueba="resumen-acceso"
              >
                {{ resumenAcceso(tieneRol(rol)) }}
              </p>
            </div>
            <div class="rp-acciones">
              <button
                type="button"
                class="tu-btn tu-btn-secundario"
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
                  class="tu-btn tu-btn-secundario"
                  @click="abrirEdicion(rol)"
                >
                  {{ $t("operacion.rolesPropios.editar") }}
                </button>
                <button
                  type="button"
                  class="tu-btn tu-btn-peligro"
                  :disabled="eliminandoId !== null"
                  data-prueba="eliminar-rol"
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
              <p class="rp-grupo rp-grupo-fila">
                <span>{{ etiquetaGrupo(g.grupo) }}</span>
                <span
                  class="tu-estado rp-nivel"
                  :style="{ '--tono': TONO_NIVEL[g.nivel.nivel] }"
                  >{{ textoNivel(g.nivel) }}</span
                >
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
              <p
                v-if="!rol.permisos.includes('*')"
                class="rp-resumen"
                data-prueba="resumen-acceso"
              >
                {{ resumenAcceso(tieneRol(rol)) }}
              </p>
            </div>
            <button
              type="button"
              class="tu-btn tu-btn-secundario"
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
              <p class="rp-grupo rp-grupo-fila">
                <span>{{ etiquetaGrupo(g.grupo) }}</span>
                <span
                  class="tu-estado rp-nivel"
                  :style="{ '--tono': TONO_NIVEL[g.nivel.nivel] }"
                  >{{ textoNivel(g.nivel) }}</span
                >
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
      :ocupado="guardando"
      @cerrar="cerrarEditor"
    >
      <form id="form-rol" class="space-y-5 p-5" @submit.prevent="guardar">
        <AvisoTono v-if="errorEditor" tono="peligro" role="alert">
          {{ errorEditor }}
        </AvisoTono>
        <div>
          <label class="tu-label" for="rol-nombre">{{
            $t("operacion.rolesPropios.nombre")
          }}</label>
          <input
            id="rol-nombre"
            v-bind="marcar('nombre', 'rol-nombre-error')"
            v-model="nombre"
            class="tu-input"
            maxlength="60"
            :placeholder="$t('operacion.rolesPropios.nombrePh')"
            required
          />
          <span
            v-if="errores.nombre"
            id="rol-nombre-error"
            class="tu-error-campo"
            >{{ errores.nombre }}</span
          >
        </div>
        <!-- Del equipo o de quien imparte (ADR 0078): se elige al crearlo. -->
        <fieldset v-if="editandoId === null" data-prueba="faceta">
          <legend class="rp-grupo">
            {{ $t("operacion.rolesPropios.facetaTitulo") }}
          </legend>
          <label
            v-for="f in ['equipo', 'instructor'] as const"
            :key="f"
            class="rp-faceta"
          >
            <input
              v-model="faceta"
              type="radio"
              name="rol-faceta"
              :value="f"
              :data-prueba="`faceta-${f}`"
            />
            <span>
              {{ $t(`operacion.rolesPropios.facetas.${f}`) }}
              <span class="rp-detalle block">{{
                $t(`operacion.rolesPropios.facetasAyuda.${f}`)
              }}</span>
            </span>
          </label>
        </fieldset>
        <p v-else-if="faceta === 'instructor'" class="rp-detalle">
          {{ $t("operacion.rolesPropios.facetaFija") }}
        </p>
        <p v-if="sinAgenda" class="rp-necesita" data-prueba="necesita-agenda">
          {{ $t("operacion.rolesPropios.instructorAgenda") }}
          <button
            v-if="puedeDar('agenda.ver')"
            type="button"
            class="rp-agregar"
            @click="alternarPermiso('agenda.ver')"
          >
            {{ $t("operacion.rolesPropios.agregarNecesarios") }}
          </button>
        </p>
        <div data-prueba="acceso-elegido">
          <p class="rp-grupo">
            {{ $t("operacion.rolesPropios.conLoElegido") }}
          </p>
          <p class="rp-detalle">{{ resumenSeleccion }}</p>
        </div>
        <label class="tu-buscar block">
          <IconoNav nombre="buscar" :tam="16" />
          <input
            v-model="busquedaPermiso"
            type="search"
            class="tu-input"
            :placeholder="$t('operacion.rolesPropios.buscarPermiso')"
            :aria-label="$t('operacion.rolesPropios.buscarPermiso')"
            data-prueba="buscar-permiso"
          />
        </label>
        <p
          v-if="sinCoincidencias"
          class="rp-detalle"
          role="status"
          data-prueba="sin-permisos"
        >
          {{
            $t("operacion.rolesPropios.sinCoincidencias", {
              q: busquedaPermiso.trim(),
            })
          }}
          <button
            type="button"
            class="rp-agregar"
            @click="busquedaPermiso = ''"
          >
            {{ $t("operacion.rolesPropios.borrarBusqueda") }}
          </button>
        </p>
        <fieldset
          v-for="g in gruposEditor"
          :key="g.grupo"
          :data-prueba="`grupo-${g.grupo}`"
        >
          <legend class="rp-grupo rp-grupo-fila">
            <span>{{ g.etiqueta }}</span>
            <span
              class="tu-estado rp-nivel"
              :style="{ '--tono': TONO_NIVEL[g.nivel.nivel] }"
              >{{ textoNivel(g.nivel) }}</span
            >
          </legend>
          <label
            v-for="p in g.visibles"
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
          <template v-for="p in g.permisos" :key="`n-${p}`">
            <p
              v-if="faltantes[p]"
              class="rp-necesita"
              :data-prueba="`necesita-${p}`"
            >
              {{
                $t("operacion.rolesPropios.necesita", {
                  lista: faltantes[p].map(etiquetaPermiso).join(", "),
                })
              }}
              <button
                type="button"
                class="rp-agregar"
                @click="agregarNecesarios(p)"
              >
                {{ $t("operacion.rolesPropios.agregarNecesarios") }}
              </button>
            </p>
          </template>
        </fieldset>
        <AvisoTono v-if="incompleto" tono="aviso">
          {{ $t("operacion.rolesPropios.incompleto") }}
        </AvisoTono>
        <span
          v-if="errores.permisos"
          id="rol-permisos-error"
          class="tu-error-campo"
          >{{ errores.permisos }}</span
        >
      </form>
      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            class="tu-btn tu-btn-secundario"
            type="button"
            :disabled="guardando"
            @click="cerrarEditor"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="
              guardando ||
              nombre.trim() === '' ||
              seleccion.size === 0 ||
              incompleto
            "
            @click="guardar"
          >
            {{ $t("operacion.rolesPropios.guardar") }}
          </button>
        </div>
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
.rp-grupo-fila {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  width: 100%;
}
.rp-nivel {
  flex-shrink: 0;
  font-size: 0.78rem;
  font-weight: 400;
  color: var(--texto-suave);
}
.rp-resumen {
  margin-top: 0.3rem;
  font-size: 0.82rem;
}
.rp-necesita {
  margin: 0.25rem 0 0.5rem 1.6rem;
  font-size: 0.8rem;
  color: var(--texto-suave);
}
.rp-agregar {
  margin-left: 0.35rem;
  color: var(--acento);
  text-decoration: underline;
  cursor: pointer;
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
.rp-faceta {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.35rem 0;
  font-size: 0.9rem;
  cursor: pointer;
}
.rp-faceta input {
  margin-top: 0.25rem;
}
</style>
