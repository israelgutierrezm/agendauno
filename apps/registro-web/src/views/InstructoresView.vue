<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Instructor {
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
const cargando = ref(true);
const error = ref<string | null>(null);

const form = ref({ nombre: "", email: "" });
const invitando = ref(false);
const activacion = ref<Activacion | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Instructor[] }>(
      `${base.value}/instructores`,
    );
    instructores.value = data.data;
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
      },
    );
    activacion.value = data.data.activacion;
    form.value = { nombre: "", email: "" };
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
  <section class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
    <EncabezadoSeccion
      icono="instructores"
      :titulo="$t('instructores.titulo')"
      :subtitulo="$t('instructores.subtitulo')"
      :total="instructores.length"
    />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <!-- Invitar instructor -->
      <div v-if="puedeInvitar" class="mt-6 tu-card p-6">
        <h2 class="font-bold text-lg">
          {{ $t("instructores.invitar.titulo") }}
        </h2>
        <form
          class="mt-3 grid sm:grid-cols-3 gap-3 items-end"
          @submit.prevent="invitar"
        >
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
          <button
            class="tu-btn tu-btn-primario"
            type="submit"
            :disabled="invitando || form.nombre === '' || form.email === ''"
          >
            {{
              invitando
                ? $t("instructores.invitar.enviando")
                : $t("instructores.invitar.enviar")
            }}
          </button>
        </form>
        <div
          v-if="activacion"
          class="mt-3 text-sm rounded-lg p-3"
          :style="{
            background: 'var(--primario-suave)',
            color: 'var(--primario-fuerte)',
          }"
        >
          {{ $t("instructores.invitar.creada", { email: activacion.email }) }}
          <code class="block mt-1 break-all">{{ activacion.token }}</code>
        </div>
      </div>

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
  </section>
</template>
