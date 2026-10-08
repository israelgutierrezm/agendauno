<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import LeyendaSucursal from "@/components/LeyendaSucursal.vue";
import EstadoVacio from "@/components/EstadoVacio.vue";
import IconoNav from "@/components/IconoNav.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import TarjetasIndicadores, {
  type Indicador,
} from "@/components/TarjetasIndicadores.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSucursalOperativa } from "@/lib/sucursalOperativa";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

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

const form = ref({
  sucursalId: "",
  nombre: "",
  tipo: "",
  modo: "unidad",
  capacidad: "1",
});
const creando = ref(false);
const abierto = ref(false);
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
  form.value = {
    sucursalId: sucursalFija.value ?? "",
    nombre: "",
    tipo: "",
    modo: "unidad",
    capacidad: "1",
  };
  error.value = null;
  abierto.value = true;
}
function cerrar(): void {
  abierto.value = false;
}

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const [r, s] = await Promise.all([
      api.get<{ data: Recurso[] }>(`${base.value}/recursos`),
      api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`),
    ]);
    recursos.value = r.data.data;
    sucursales.value = s.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function crear(): Promise<void> {
  creando.value = true;
  error.value = null;
  try {
    await api.post(`${base.value}/recursos`, {
      sucursal_id: form.value.sucursalId,
      nombre: form.value.nombre,
      tipo: form.value.tipo || null,
      modo: form.value.modo,
      capacidad: form.value.modo === "pool" ? Number(form.value.capacidad) : 1,
    });
    abierto.value = false;
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    creando.value = false;
  }
}

async function eliminar(r: Recurso): Promise<void> {
  if (!(await confirmar(t("recursos.confirmarEliminar"), { peligro: true }))) {
    return;
  }
  try {
    await api.delete(`${base.value}/recursos/${r.id}`);
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
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
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
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
                <td class="text-right">
                  <button
                    v-if="puedeEliminar"
                    class="tu-enlace text-sm"
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
      :titulo="$t('recursos.nuevo')"
      @cerrar="cerrar"
    >
      <form class="space-y-4" @submit.prevent="crear">
        <p
          v-if="error"
          class="text-sm"
          role="alert"
          :style="{ color: 'var(--error)' }"
        >
          {{ error }}
        </p>
        <div>
          <label class="tu-label" for="rn">{{ $t("recursos.nombre") }}</label>
          <input id="rn" v-model="form.nombre" class="tu-input" required />
        </div>
        <div v-if="elegirSucursal">
          <label class="tu-label" for="rs">{{ $t("recursos.sucursal") }}</label>
          <select id="rs" v-model="form.sucursalId" class="tu-input" required>
            <option value="" disabled>{{ $t("recursos.sucursal") }}</option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </div>
        <LeyendaSucursal v-else />
        <div>
          <label class="tu-label" for="rt">{{ $t("recursos.tipo") }}</label>
          <input
            id="rt"
            v-model="form.tipo"
            class="tu-input"
            :placeholder="$t('validacion.recursoTipoPh')"
          />
        </div>
        <div>
          <label class="tu-label" for="rm">{{ $t("recursos.modo") }}</label>
          <select id="rm" v-model="form.modo" class="tu-input">
            <option value="unidad">{{ $t("recursos.modoUnidad") }}</option>
            <option value="pool">{{ $t("recursos.modoPool") }}</option>
          </select>
        </div>
        <div v-if="form.modo === 'pool'">
          <label class="tu-label" for="rc">{{
            $t("recursos.capacidad")
          }}</label>
          <input
            id="rc"
            v-model="form.capacidad"
            class="tu-input"
            type="number"
            min="1"
          />
        </div>
      </form>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button class="tu-btn tu-btn-fantasma" type="button" @click="cerrar">
            {{ $t("comun.cancelar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="creando || form.nombre === '' || form.sucursalId === ''"
            @click="crear"
          >
            {{ creando ? $t("recursos.creando") : $t("recursos.crear") }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>
