<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PanelLateral from "@/components/PanelLateral.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Instructor {
  id: string;
  nombre: string;
}
interface Sucursal {
  id: string;
  nombre: string;
}
interface Activacion {
  email: string;
  token: string;
}

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const puedeInvitar = computed(() => sesion.puede("usuarios.invitar"));

const instructores = ref<Instructor[]>([]);
const sucursales = ref<Sucursal[]>([]);
const cargando = ref(true);
const error = ref<string | null>(null);

// Con varias sedes, se puede acotar al instructor a una desde el alta (R19).
const hayMultiSucursal = computed(() => sucursales.value.length > 1);

const form = ref({ nombre: "", email: "", sucursalId: "" });
const invitando = ref(false);
const activacion = ref<Activacion | null>(null);
const abierto = ref(false);

function abrir(): void {
  form.value = { nombre: "", email: "", sucursalId: "" };
  activacion.value = null;
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
    const [i, s] = await Promise.all([
      api.get<{ data: Instructor[] }>(`${base.value}/instructores`),
      api.get<{ data: Sucursal[] }>(`${base.value}/sucursales`),
    ]);
    instructores.value = i.data.data;
    sucursales.value = s.data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

async function invitar(): Promise<void> {
  invitando.value = true;
  error.value = null;
  activacion.value = null;
  try {
    const { data } = await api.post<{ data: { activacion: Activacion } }>(
      `${base.value}/usuarios/invitar`,
      {
        nombre: form.value.nombre,
        email: form.value.email,
        rol: "instructor",
        // Sede opcional: si se elige, el instructor queda acotado a ella.
        sucursal_id:
          form.value.sucursalId !== "" ? form.value.sucursalId : null,
      },
    );
    activacion.value = data.data.activacion;
    form.value = { nombre: "", email: "", sucursalId: "" };
    await cargar();
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    invitando.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <div class="flex items-start justify-between gap-3 flex-wrap">
      <EncabezadoSeccion
        icono="instructores"
        :titulo="$t('instructores.titulo')"
        :subtitulo="$t('instructores.subtitulo')"
        :total="instructores.length"
      />
      <button
        v-if="puedeInvitar"
        class="tu-btn tu-btn-primario"
        type="button"
        @click="abrir"
      >
        + {{ $t("instructores.invitar.enviar") }}
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
        v-if="instructores.length === 0"
        class="mt-8 text-center text-sm"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("instructores.vacio") }}
      </p>
      <ul v-else class="mt-6 space-y-2">
        <li
          v-for="i in instructores"
          :key="i.id"
          class="tu-card p-4 flex items-center gap-3"
        >
          <span
            class="h-9 w-9 rounded-xl inline-flex items-center justify-center text-white text-sm font-bold shrink-0"
            :style="{ background: 'var(--primario)' }"
            aria-hidden="true"
            >{{ i.nombre.charAt(0).toUpperCase() }}</span
          >
          <span class="font-medium">{{ i.nombre }}</span>
        </li>
      </ul>
    </template>

    <!-- Invitar instructor (drawer lateral) -->
    <PanelLateral
      :abierto="abierto"
      :titulo="$t('instructores.invitar.titulo')"
      @cerrar="cerrar"
    >
      <form class="space-y-4" @submit.prevent="invitar">
        <div>
          <label class="tu-label" for="in">{{
            $t("instructores.invitar.nombre")
          }}</label>
          <input id="in" v-model="form.nombre" class="tu-input" required />
        </div>
        <div>
          <label class="tu-label" for="ie">{{
            $t("instructores.invitar.email")
          }}</label>
          <input
            id="ie"
            v-model="form.email"
            class="tu-input"
            type="email"
            required
          />
        </div>
        <div v-if="hayMultiSucursal">
          <label class="tu-label" for="is">{{
            $t("instructores.invitar.sede")
          }}</label>
          <select id="is" v-model="form.sucursalId" class="tu-input">
            <option value="">
              {{ $t("instructores.invitar.todasSedes") }}
            </option>
            <option v-for="s in sucursales" :key="s.id" :value="s.id">
              {{ s.nombre }}
            </option>
          </select>
        </div>
      </form>
      <div
        v-if="activacion"
        class="mt-4 text-sm rounded-lg p-3"
        :style="{
          background: 'var(--primario-suave)',
          color: 'var(--primario-fuerte)',
        }"
      >
        {{ $t("instructores.invitar.creada", { email: activacion.email }) }}
        <code class="block mt-1 break-all">{{ activacion.token }}</code>
      </div>

      <template #pie>
        <div class="flex justify-end gap-2">
          <button class="tu-btn tu-btn-fantasma" type="button" @click="cerrar">
            {{ $t("comun.cerrar") }}
          </button>
          <button
            class="tu-btn tu-btn-primario"
            type="button"
            :disabled="invitando || form.nombre === '' || form.email === ''"
            @click="invitar"
          >
            {{
              invitando
                ? $t("instructores.invitar.enviando")
                : $t("instructores.invitar.enviar")
            }}
          </button>
        </div>
      </template>
    </PanelLateral>
  </section>
</template>
