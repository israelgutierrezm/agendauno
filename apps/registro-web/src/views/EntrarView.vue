<script setup lang="ts">
import { onMounted, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import CampoContrasena from "@/components/CampoContrasena.vue";
import { api } from "@/lib/api";
import { clientIdGoogle, renderizarBotonGoogle } from "@/lib/google";
import { enSubdominioDeEstudio, slugDeContexto } from "@/lib/tenant";
import { useSesionTenantStore } from "@/stores/sesionTenant";

interface Marca {
  nombre: string;
  logo_url: string | null;
}

const route = useRoute();
const router = useRouter();
const sesion = useSesionTenantStore();

// El estudio en contexto viene del subdominio (`{slug}.agendauno.mx`) o, en dev,
// de `?estudio=`. En un subdominio real el estudio es fijo (no editable).
const slug = ref(slugDeContexto() ?? String(route.query.estudio ?? ""));
const estudioFijo = enSubdominioDeEstudio();
const email = ref("");
const password = ref("");
const avisoGoogle = ref(false);
const marca = ref<Marca | null>(null);

const hayGoogle = clientIdGoogle() !== undefined;
const contenedorGoogle = ref<HTMLElement | null>(null);

// Cada rol entra a su inicio: alumno→su cuenta, dueño/admin→panel, recepción→
// operación de hoy, instructor→su agenda (ver store: rutaInicio).
function destino(): { name: string } {
  return { name: sesion.rutaInicio };
}

// Marca (branding) publica del estudio: para mostrar su logo antes de entrar.
async function cargarMarca(valor: string): Promise<void> {
  const s = valor.trim();
  if (s === "") {
    marca.value = null;
    return;
  }
  try {
    const { data } = await api.get<{ data: Marca }>(`/api/v1/app/${s}/marca`);
    marca.value = data.data;
  } catch {
    // Estudio inexistente o no operativo: se muestra el logo de AgendaUno.
    marca.value = null;
  }
}

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch(slug, (valor) => {
  clearTimeout(temporizador);
  temporizador = setTimeout(() => void cargarMarca(valor), 400);
});

async function enviar(): Promise<void> {
  try {
    await sesion.iniciarSesion(slug.value.trim(), email.value, password.value);
    void router.push(destino());
  } catch {
    // El error queda en sesion.error.
  }
}

async function entrarConGoogle(credential: string): Promise<void> {
  if (slug.value.trim() === "") {
    sesion.error = "Escribe primero la direccion de tu estudio.";
    return;
  }
  try {
    await sesion.iniciarSesionConGoogle(slug.value.trim(), credential);
    void router.push(destino());
  } catch {
    // El error queda en sesion.error.
  }
}

onMounted(() => {
  if (slug.value.trim() !== "") {
    void cargarMarca(slug.value);
  }
  if (hayGoogle && contenedorGoogle.value !== null) {
    void renderizarBotonGoogle(
      contenedorGoogle.value,
      (c) => void entrarConGoogle(c),
    );
  }
});
</script>

<template>
  <section class="mx-auto flex max-w-md flex-col items-center px-4 py-14">
    <!-- Branding: logo del estudio si lo tiene; si no, el de AgendaUno -->
    <img
      v-if="marca?.logo_url"
      :src="marca.logo_url"
      :alt="marca.nombre"
      class="h-16 w-16 rounded-2xl object-cover"
      :style="{ boxShadow: 'var(--sombra)' }"
    />
    <img
      v-else
      src="/assets/brand/turnouno-logo.webp"
      alt="AgendaUno"
      class="h-20 w-auto"
    />

    <h1 class="mt-4 text-2xl font-extrabold text-center">
      {{
        marca?.nombre
          ? $t("entrar.tituloEstudio", { nombre: marca.nombre })
          : $t("entrar.titulo")
      }}
    </h1>
    <p class="mt-1 text-center" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("entrar.subtitulo") }}
    </p>

    <form class="mt-6 w-full tu-card p-6 space-y-4" @submit.prevent="enviar">
      <div>
        <label class="tu-label" for="slug">{{ $t("entrar.slug") }}</label>
        <input
          id="slug"
          v-model="slug"
          class="tu-input"
          :placeholder="$t('entrar.slugPh')"
          :readonly="estudioFijo"
          :style="estudioFijo ? { background: 'var(--superficie-2)' } : {}"
          required
        />
        <span v-if="estudioFijo" class="tu-hint">{{
          $t("entrar.estudioFijo")
        }}</span>
      </div>
      <div>
        <label class="tu-label" for="email">{{ $t("entrar.email") }}</label>
        <input
          id="email"
          v-model="email"
          class="tu-input"
          type="email"
          required
        />
      </div>
      <div>
        <label class="tu-label" for="password">{{
          $t("entrar.password")
        }}</label>
        <CampoContrasena
          id="password"
          v-model="password"
          autocomplete="current-password"
          :required="true"
        />
      </div>

      <p v-if="sesion.error" class="text-sm" style="color: var(--error)">
        {{ sesion.error }}
      </p>

      <button
        class="tu-btn tu-btn-primario w-full"
        type="submit"
        :disabled="sesion.cargando"
      >
        {{ sesion.cargando ? $t("entrar.entrando") : $t("entrar.entrar") }}
      </button>

      <div
        class="flex items-center gap-3 text-xs"
        :style="{ color: 'var(--texto-suave)' }"
      >
        <span
          class="flex-1 border-t"
          :style="{ borderColor: 'var(--borde)' }"
        />
        <span>o</span>
        <span
          class="flex-1 border-t"
          :style="{ borderColor: 'var(--borde)' }"
        />
      </div>

      <div
        v-if="hayGoogle"
        ref="contenedorGoogle"
        class="flex justify-center"
      ></div>
      <template v-else>
        <button
          class="tu-btn tu-btn-fantasma w-full"
          type="button"
          @click="avisoGoogle = true"
        >
          <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
            <path
              fill="#EA4335"
              d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"
            />
            <path
              fill="#4285F4"
              d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"
            />
            <path
              fill="#FBBC05"
              d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"
            />
            <path
              fill="#34A853"
              d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"
            />
          </svg>
          {{ $t("entrar.google") }}
        </button>
        <p
          v-if="avisoGoogle"
          class="text-xs text-center"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("entrar.googlePronto") }}
        </p>
      </template>
    </form>

    <p
      class="mt-4 text-sm text-center"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("entrar.sinCuenta") }}
      <RouterLink class="tu-enlace" :to="{ name: 'registro' }">{{
        $t("entrar.registrar")
      }}</RouterLink>
    </p>
  </section>
</template>
