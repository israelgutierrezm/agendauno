<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import LeyendaSucursal from "@/components/LeyendaSucursal.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import InterruptorSiNo from "@/components/InterruptorSiNo.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import { confirmar } from "@/lib/confirmar";
import { useEditorFormulario } from "@/lib/editorFormulario";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

interface Sucursal {
  id: string;
  nombre: string;
}
interface Recurso {
  id: string;
  sucursal: string | null;
  nombre: string;
  tipo: string | null;
  modo: string;
  capacidad: number;
  activo: boolean;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeGestionar = computed(() => sesion.puede("agenda.gestionar"));
const puedeEliminar = computed(() => sesion.puede("agenda.eliminar"));

const recursos = ref<Recurso[]>([]);
const sucursales = ref<Sucursal[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);
// El de la carga, aparte del de guardar: sin lista no es «aún no hay espacios».
const errorCarga = ref<string | null>(null);

const form = ref({
  sucursalId: "",
  nombre: "",
  tipo: "",
  modo: "unidad",
  capacidad: "1",
});
const abierto = ref(false);
// El que se corrige (null = alta de uno nuevo).
const editando = ref<Recurso | null>(null);
// El patrón común de los formularios.
const {
  guardando: creando,
  errores,
  error: errorAlta,
  empezar,
  cerrar,
  marcar,
  guardar: guardarAlta,
} = useEditorFormulario({
  abierto,
  valor: () => form.value,
  campos: ["nombre", "sucursal_id", "tipo", "modo", "capacidad"],
  formulario: "#form-recurso",
});
// Con una sucursal fija (la de la barra o la única), la sala nueva es de esa.
const {
  mostrarSelect: elegirSucursal,
  fija: sucursalFija,
  actual: sucursalActual,
} = useSucursalOperativa();

// ---- Indicadores y búsqueda (patrón de los listados) ----
const busqueda = ref("");
// Con una sucursal elegida en la barra, solo los de esa sucursal.
const deLaSucursal = computed(() =>
  sucursalActual.value
    ? recursos.value.filter((r) => r.sucursal === sucursalActual.value!.nombre)
    : recursos.value,
);
const visibles = computed(() => {
  const q = busqueda.value.trim().toLowerCase();
  return deLaSucursal.value.filter(
    (r) =>
      q === "" ||
      r.nombre.toLowerCase().includes(q) ||
      (r.tipo ?? "").toLowerCase().includes(q),
  );
});
const indicadores = computed<Indicador[]>(() => {
  const lista = deLaSucursal.value;
  const pools = lista.filter((r) => r.modo === "pool");
  return [
    {
      clave: "recursos",
      etiqueta: t("recursos.titulo"),
      valor: String(lista.length),
      icono: "recursos",
    },
    {
      clave: "unidades",
      etiqueta: t("recursosVisual.kpi.unidades"),
      valor: String(lista.length - pools.length),
      icono: "ubicacion",
    },
    {
      clave: "pools",
      etiqueta: t("recursosVisual.kpi.pools"),
      valor: String(pools.reduce((s, r) => s + r.capacidad, 0)),
      icono: "cuadricula",
    },
    {
      clave: "sedes",
      etiqueta: t("recursosVisual.kpi.sedes"),
      valor: String(new Set(lista.map((r) => r.sucursal)).size),
      icono: "ubicacion",
    },
  ];
});

function abrir(): void {
  editando.value = null;
  form.value = {
    sucursalId: sucursalFija.value ?? "",
    nombre: "",
    tipo: "",
    modo: "unidad",
    capacidad: "1",
  };
  empezar();
  abierto.value = true;
}
// Corregir nombre, tipo, modo y capacidad (su sede no cambia).
function editar(r: Recurso): void {
  editando.value = r;
  form.value = {
    sucursalId: "",
    nombre: r.nombre,
    tipo: r.tipo ?? "",
    modo: r.modo,
    capacidad: String(r.capacidad),
  };
  empezar();
  abierto.value = true;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  errorCarga.value = null;
  try {
    const [r, s] = await Promise.all([
      api.get<{ data: Recurso[] }>(`${base.value}/recursos`),
      api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`),
    ]);
    recursos.value = r.data.data;
    sucursales.value = s.data.data;
  } catch (e) {
    errorCarga.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function crear(): Promise<void> {
  if (editando.value !== null) {
    await guardarCambios(editando.value);
    return;
  }
  const nombre = form.value.nombre.trim();
  const listo = await guardarAlta(
    () =>
      api.post(`${base.value}/recursos`, {
        sucursal_id: form.value.sucursalId,
        nombre: form.value.nombre,
        tipo: form.value.tipo || null,
        modo: form.value.modo,
        capacidad:
          form.value.modo === "pool" ? Number(form.value.capacidad) : 1,
      }),
    t("recursos.creado", { nombre }),
  );
  if (listo !== undefined) {
    abierto.value = false;
    await cargar();
  }
}

// Al corregir, el API no deja bajar la capacidad (ni volverlo exclusivo) por debajo
// de las sesiones próximas que ya lo usan a la vez: el error queda en su campo.
async function guardarCambios(r: Recurso): Promise<void> {
  const nombre = form.value.nombre.trim();
  const listo = await guardarAlta(
    () =>
      api.put(`${base.value}/recursos/${r.id}`, {
        nombre,
        tipo: form.value.tipo.trim() || null,
        modo: form.value.modo,
        ...(form.value.modo === "pool"
          ? { capacidad: Number(form.value.capacidad) }
          : {}),
      }),
    t("recursos.guardado", { nombre }),
  );
  if (listo !== undefined) {
    abierto.value = false;
    await cargar();
  }
}

// Activar o desactivar, y eliminar, con las mismas reglas (el API): no deja sin espacio
// a un servicio ni a una serie, y sus citas próximas pasan a otro espacio libre.
const toast = useToastStore();
function avisarMovidas(n: number, hecho: string): void {
  toast.exito(n > 0 ? `${hecho} ${t("recursos.movidas", { n }, n)}` : hecho);
}
const cambiando = ref<string | null>(null);
async function cambiarActivo(r: Recurso, activo: boolean): Promise<void> {
  cambiando.value = r.id;
  error.value = null;
  try {
    const { data } = await api.put<{
      data: Recurso;
      meta?: { movidas: number };
    }>(`${base.value}/recursos/${r.id}`, { activo });
    r.activo = data.data.activo;
    avisarMovidas(
      data.meta?.movidas ?? 0,
      activo ? t("recursos.activado") : t("recursos.desactivado"),
    );
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cambiando.value = null;
  }
}

async function eliminar(r: Recurso): Promise<void> {
  if (!(await confirmar(t("recursos.confirmarEliminar"), { peligro: true }))) {
    return;
  }
  try {
    const { data } = await api.delete<{ data?: { movidas: number } }>(
      `${base.value}/recursos/${r.id}`,
    );
    avisarMovidas(data?.data?.movidas ?? 0, t("recursos.eliminado"));
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t('recursos.titulo')"
      :subtitulo="
        sesion.esCitas
          ? $t('recursosVisual.subtitulo')
          : $t('recursosVisual.subtituloClases')
      "
    >
      <template v-if="puedeGestionar && sucursales.length > 0" #acciones>
        <button
          class="tu-btn tu-btn-primario tu-btn-crear"
          type="button"
          data-prueba="nuevo-recurso"
          @click="abrir"
        >
          {{ $t("recursos.crear") }}
        </button>
      </template>
    </EncabezadoSeccion>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <AvisoTono v-if="error" tono="peligro" class="mt-4" role="alert">
      {{ error }}
    </AvisoTono>

    <div
      v-if="!cargando && errorCarga"
      class="mt-6 tu-card flex flex-wrap items-center justify-between gap-3 p-6 text-sm"
      role="alert"
      data-prueba="error-carga-recursos"
    >
      <span style="color: var(--error)">{{ errorCarga }}</span>
      <button type="button" class="tu-btn tu-btn-fantasma" @click="cargar">
        {{ $t("comun.reintentar") }}
      </button>
    </div>

    <template v-else-if="!cargando">
      <!-- Lista -->
      <EstadoVacio
        v-if="recursos.length === 0"
        class="mt-8"
        icono="recursos"
        :titulo="$t('recursos.vacio')"
      />
      <template v-else>
        <TarjetasIndicadores class="mt-6" :tarjetas="indicadores" />
        <div class="tu-card mt-5 overflow-x-auto">
          <div class="tu-filtros">
            <label class="tu-buscar">
              <IconoNav nombre="buscar" :tam="16" />
              <input
                v-model="busqueda"
                type="search"
                class="tu-input"
                :placeholder="$t('recursosVisual.buscar')"
                :aria-label="$t('recursosVisual.buscar')"
                data-prueba="buscar-recurso"
              />
            </label>
          </div>
          <table class="tu-tabla">
            <thead>
              <tr>
                <th>{{ $t("recursos.nombre") }}</th>
                <th v-if="!sucursalActual" class="hidden sm:table-cell">
                  {{ $t("recursos.sucursal") }}
                </th>
                <th class="hidden md:table-cell">{{ $t("recursos.tipo") }}</th>
                <th>{{ $t("recursos.modo") }}</th>
                <th>{{ $t("operacion.recursos.estado") }}</th>
                <th>
                  <span class="sr-only">{{
                    $t("recursosVisual.acciones")
                  }}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in visibles" :key="r.id" data-prueba="recurso">
                <td class="font-medium">{{ r.nombre }}</td>
                <td
                  v-if="!sucursalActual"
                  class="hidden sm:table-cell"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ r.sucursal ?? "—" }}
                </td>
                <td
                  class="hidden md:table-cell"
                  :style="{ color: 'var(--texto-suave)' }"
                >
                  {{ r.tipo ?? "—" }}
                </td>
                <td>
                  {{
                    r.modo === "pool"
                      ? $t("recursosVisual.pool", { n: r.capacidad })
                      : $t("recursosVisual.unidad")
                  }}
                </td>
                <td>
                  <InterruptorSiNo
                    v-if="puedeGestionar"
                    :model-value="r.activo"
                    :etiqueta="
                      r.activo
                        ? $t('operacion.recursos.activo')
                        : $t('operacion.recursos.inactivo')
                    "
                    :deshabilitado="cambiando === r.id"
                    :prueba="`activo-${r.id}`"
                    @update:model-value="cambiarActivo(r, $event)"
                  />
                  <span
                    v-else
                    class="tu-pastilla"
                    :class="r.activo ? 'tu-tono-exito' : 'tu-tono-neutral'"
                    >{{
                      r.activo
                        ? $t("operacion.recursos.activo")
                        : $t("operacion.recursos.inactivo")
                    }}</span
                  >
                </td>
                <td class="text-right whitespace-nowrap">
                  <button
                    v-if="puedeGestionar"
                    class="tu-enlace text-sm"
                    type="button"
                    data-prueba="editar-recurso"
                    @click="editar(r)"
                  >
                    {{ $t("recursos.editar") }}
                  </button>
                  <button
                    v-if="puedeEliminar"
                    class="tu-enlace ml-3 text-sm"
                    style="color: var(--error)"
                    type="button"
                    @click="eliminar(r)"
                  >
                    {{ $t("recursos.eliminar") }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="visibles.length === 0" class="tu-sin-resultados">
            {{ $t("recursosVisual.sinResultados") }}
          </p>
        </div>
      </template>
    </template>

    <!-- Nuevo recurso (drawer lateral) -->
    <PanelLateral
      :abierto="abierto"
      :titulo="editando ? $t('recursos.editarTitulo') : $t('recursos.nuevo')"
      :ocupado="creando"
      @cerrar="cerrar"
    >
      <form id="form-recurso" class="space-y-4" @submit.prevent="crear">
        <AvisoTono v-if="errorAlta" tono="peligro" role="alert">
          {{ errorAlta }}
        </AvisoTono>
        <div>
          <label class="tu-label" for="rn">{{ $t("recursos.nombre") }}</label>
          <input
            id="rn"
            v-bind="marcar('nombre', 'rn-error')"
            v-model="form.nombre"
            class="tu-input"
            required
          />
          <span v-if="errores.nombre" id="rn-error" class="tu-error-campo">{{
            errores.nombre
          }}</span>
        </div>
        <p
          v-if="editando"
          class="text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("recursos.sucursal") }}: {{ editando.sucursal ?? "—" }}
        </p>
        <div v-else-if="elegirSucursal">
          <label class="tu-label" for="rs">{{ $t("recursos.sucursal") }}</label>
          <select
            id="rs"
            v-bind="marcar('sucursal_id', 'rs-error')"
            v-model="form.sucursalId"
            class="tu-input"
            required
          >
            <option value="" disabled>{{ $t("recursos.sucursal") }}</option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
          <span
            v-if="errores.sucursal_id"
            id="rs-error"
            class="tu-error-campo"
            >{{ errores.sucursal_id }}</span
          >
        </div>
        <LeyendaSucursal v-else />
        <div>
          <label class="tu-label" for="rt">{{ $t("recursos.tipo") }}</label>
          <input
            id="rt"
            v-bind="marcar('tipo', 'rt-error')"
            v-model="form.tipo"
            class="tu-input"
            :placeholder="$t('validacion.recursoTipoPh')"
          />
          <span v-if="errores.tipo" id="rt-error" class="tu-error-campo">{{
            errores.tipo
          }}</span>
        </div>
        <div>
          <label class="tu-label" for="rm">{{ $t("recursos.modo") }}</label>
          <select
            id="rm"
            v-bind="marcar('modo', 'rm-error')"
            v-model="form.modo"
            class="tu-input"
          >
            <option value="unidad">{{ $t("recursos.modoUnidad") }}</option>
            <option value="pool">{{ $t("recursos.modoPool") }}</option>
          </select>
          <span v-if="errores.modo" id="rm-error" class="tu-error-campo">{{
            errores.modo
          }}</span>
        </div>
        <div v-if="form.modo === 'pool'">
          <label class="tu-label" for="rc">{{
            $t("recursos.capacidad")
          }}</label>
          <input
            id="rc"
            v-bind="marcar('capacidad', 'rc-error')"
            v-model="form.capacidad"
            class="tu-input"
            type="number"
            min="1"
            max="1000"
          />
          <span v-if="editando && !errores.capacidad" class="tu-hint">{{
            $t("recursos.capacidadAyuda")
          }}</span>
          <span v-if="errores.capacidad" id="rc-error" class="tu-error-campo">{{
            errores.capacidad
          }}</span>
        </div>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button
            class="tu-btn tu-btn-fantasma"
            type="button"
            :disabled="creando"
            @click="cerrar"
          >
            {{ $t("comun.cancelar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="
              creando ||
              form.nombre.trim() === '' ||
              (!editando && form.sucursalId === '')
            "
            @click="crear"
          >
            {{
              creando
                ? editando
                  ? $t("recursos.guardando")
                  : $t("recursos.creando")
                : editando
                  ? $t("recursos.guardar")
                  : $t("recursos.crear")
            }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>
