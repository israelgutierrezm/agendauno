<script setup lang="ts">
import IconoNav from "@/components/IconoNav.vue";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import AvatarIniciales from "@/components/AvatarIniciales.vue";
import ExpedientePersona from "@/components/ExpedientePersona.vue";
import { puedeEntrar } from "@/lib/acceso";
import { api, mensajeDeError } from "@/lib/api";
import { useRegreso } from "@/lib/regreso";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Perfil de un profesional (instructor, barbero…) para quien administra al equipo:
 * quién es y su expediente (documentos, formularios, consentimientos). Sin correo
 * ni teléfono a la vista.
 */
interface Profesional {
  id: string;
  nombre: string;
  nombre_corto: string;
  foto_url: string | null;
  activo: boolean;
  desde: string | null;
  persona_id: string;
}

const route = useRoute();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);
const id = computed(() => String(route.params.id));
// «Volver»: a la pantalla de donde se llegó o a la lista del equipo.
const { t } = useI18n();
const regreso = useRegreso({
  name: "instructores",
  etiqueta: () =>
    t("profesional.volver", {
      grupo: plural(sesion.terminologia.instructor).toLowerCase(),
    }),
});

const profesional = ref<Profesional | null>(null);
const cargando = ref(true);
const error = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const { data } = await api.get<{ data: Profesional }>(
      `${base.value}/instructores/${id.value}`,
    );
    profesional.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}
watch(id, cargar, { immediate: true });

function fecha(iso: string | null): string {
  if (iso === null) {
    return "—";
  }
  return new Intl.DateTimeFormat("es-MX", {
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(new Date(`${iso.slice(0, 10)}T12:00:00`));
}
</script>

<template>
  <section class="tu-pagina">
    <RouterLink :to="regreso.destino.value" class="tu-enlace text-sm"
      ><IconoNav nombre="atras" :tam="14" class="inline align-[-0.15em]" />
      {{ regreso.etiqueta.value }}</RouterLink
    >

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

    <div v-else-if="profesional" class="mt-4 tu-card overflow-hidden">
      <header
        class="flex items-center gap-4 p-5 border-b"
        :style="{ borderColor: 'var(--borde)' }"
      >
        <AvatarIniciales
          :nombre="profesional.nombre"
          :foto="profesional.foto_url"
          tam="xl"
        />
        <div class="min-w-0">
          <p class="fi-etiqueta">{{ sesion.terminologia.instructor }}</p>
          <h1 class="mt-1 text-xl font-semibold tracking-tight truncate">
            {{ profesional.nombre }}
          </h1>
          <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
            {{ $t("profesional.desde", { fecha: fecha(profesional.desde) }) }}
          </p>
          <p
            v-if="!profesional.activo"
            class="mt-1 text-sm"
            :style="{ color: 'var(--aviso)' }"
          >
            {{ $t("profesional.inactivo") }}
          </p>
        </div>
        <!-- Su acceso al panel se administra en Configuración › Accesos. -->
        <RouterLink
          v-if="puedeEntrar('usuarios', sesion)"
          :to="{ name: 'usuarios' }"
          class="tu-btn tu-btn-fantasma ml-auto shrink-0"
          >{{ $t("instructores.administrarAcceso") }}</RouterLink
        >
      </header>

      <div class="px-5 pt-5">
        <h2 class="font-semibold">{{ $t("expediente.titulo") }}</h2>
      </div>
      <ExpedientePersona
        :persona-id="profesional.persona_id"
        tipo-persona="instructor"
      />
    </div>
  </section>
</template>

<style scoped>
.fi-etiqueta {
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--texto-suave);
}
</style>
