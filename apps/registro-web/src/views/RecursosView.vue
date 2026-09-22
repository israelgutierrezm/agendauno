<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
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

function abrir(): void {
  form.value = {
    sucursalId: "",
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
  if (!window.confirm(t("recursos.confirmarEliminar"))) {
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
  <section class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion
        icono="recursos"
        :titulo="$t('recursos.titulo')"
        :subtitulo="$t('recursos.subtitulo')"
        :total="recursos.length"
      />
      <button
        v-if="puedeGestionar && sucursales.length > 0"
        class="tu-btn tu-btn-primario"
        type="button"
        @click="abrir"
      >
        + {{ $t("recursos.crear") }}
      </button>
    </div>

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <!-- Lista -->
      <p
        v-if="recursos.length === 0"
        class="mt-8 text-center text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("recursos.vacio") }}
      </p>
      <ul v-else class="mt-6 space-y-2">
        <li
          v-for="r in recursos"
          :key="r.id"
          class="tu-card p-4 flex items-center justify-between gap-3"
        >
          <div class="min-w-0">
            <div class="font-semibold">{{ r.nombre }}</div>
            <div class="text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ r.sucursal ?? "—" }}<span v-if="r.tipo"> · {{ r.tipo }}</span>
            </div>
          </div>
          <div class="flex items-center gap-3 shrink-0">
            <span class="tu-badge">
              {{
                r.modo === "pool"
                  ? `${$t("recursos.modoPool")} · ${r.capacidad}`
                  : $t("recursos.modoUnidad")
              }}
            </span>
            <button
              v-if="puedeGestionar"
              class="tu-enlace text-sm"
              style="color: var(--error)"
              @click="eliminar(r)"
            >
              {{ $t("recursos.eliminar") }}
            </button>
          </div>
        </li>
      </ul>
    </template>

    <!-- Nuevo recurso (drawer lateral) -->
    <PanelLateral
      :abierto="abierto"
      :titulo="$t('recursos.nuevo')"
      @cerrar="cerrar"
    >
      <form class="space-y-4" @submit.prevent="crear">
        <div>
          <label class="tu-label" for="rn">{{ $t("recursos.nombre") }}</label>
          <input id="rn" v-model="form.nombre" class="tu-input" required />
        </div>
        <div>
          <label class="tu-label" for="rs">{{ $t("recursos.sucursal") }}</label>
          <select id="rs" v-model="form.sucursalId" class="tu-input" required>
            <option value="" disabled>{{ $t("recursos.sucursal") }}</option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </div>
        <div>
          <label class="tu-label" for="rt">{{ $t("recursos.tipo") }}</label>
          <input
            id="rt"
            v-model="form.tipo"
            class="tu-input"
            placeholder="Sala, cancha, carril…"
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
