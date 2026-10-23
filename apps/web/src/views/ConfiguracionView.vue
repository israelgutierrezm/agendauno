<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";

import CargadorLogo from "@/components/CargadorLogo.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import GiroNegocio from "@/components/GiroNegocio.vue";
import TerminologiaNegocio from "@/components/TerminologiaNegocio.vue";
import { api, mensajeDeError } from "@/lib/api";
import type { DatosTerminologia, TerminosNegocio } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

// La página pública (portada, descripción, redes, si aparece en Explorar y su
// enlace) pasó a Captación › Sitio web (ADR 0167).
const route = useRoute();
const secciones: Record<string, string> = {
  datos: "configNegocio.opciones.datos",
  "tipo-negocio": "modalidadNegocio.giro.titulo",
  terminologia: "configNegocio.opciones.terminologia",
};
const seccion = computed(() => {
  const pedida = route.hash.slice(1);
  return secciones[pedida] ? pedida : "";
});
const visible = (clave: string): boolean =>
  !seccion.value || seccion.value === clave;
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const puedeGestionar = computed(() => sesion.puede("estudio.gestionar"));
const cargando = ref(true);
const error = ref<string | null>(null);

// Logo (branding): la subida por arrastrar/soltar la maneja CargadorLogo.
const logoUrl = ref<string | null>(null);

async function cargar(): Promise<void> {
  cargando.value = true;
  try {
    await sesion.cargarYo();
    const { data } = await api.get<{ data: { logo_url: string | null } }>(
      `${base.value}/marca`,
    );
    logoUrl.value = data.data.logo_url;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    cargando.value = false;
  }
}

// Cómo se llaman las cosas (ADR 0049): al guardar, las pantallas cambian de inmediato.
async function cargarTerminologia(): Promise<DatosTerminologia> {
  const { data } = await api.get<{ data: DatosTerminologia }>(
    `${base.value}/terminologia`,
  );
  return data.data;
}
async function guardarTerminologia(
  valores: Record<string, string | null>,
): Promise<DatosTerminologia> {
  const { data } = await api.put<{ data: DatosTerminologia }>(
    `${base.value}/terminologia`,
    { valores },
  );
  return data.data;
}
function terminologiaGuardada(vigente: TerminosNegocio): void {
  if (sesion.estudio?.perfil_config) {
    sesion.estudio.perfil_config.terminologia = vigente;
  }
}

onMounted(cargar);
</script>

<template>
  <section class="tu-pagina">
    <EncabezadoSeccion
      :titulo="$t(secciones[seccion] ?? 'configuracion.titulo')"
    />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <!-- Logo del estudio (Configuración › Negocio › Logo del negocio). Solo se
           cambia el logo: el nombre y el contacto no se editan desde el panel. -->
      <div
        v-show="visible('datos')"
        id="datos"
        class="mt-6 tu-card p-6 scroll-mt-24"
      >
        <!-- Sola, el encabezado de la página ya lo nombra. -->
        <h2 v-if="seccion !== 'datos'" class="font-medium text-lg">
          {{ $t("configuracion.logoTitulo") }}
        </h2>
        <p
          class="text-sm"
          :class="{ 'mt-1': seccion !== 'datos' }"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("configuracion.logoDesc") }}
        </p>

        <div class="mt-4">
          <CargadorLogo
            :logo-url="logoUrl"
            :puede-gestionar="puedeGestionar"
            @update:logo-url="logoUrl = $event"
          />
        </div>
      </div>

      <!-- Tipo de negocio: solo los de su modalidad (ADR 0104) -->
      <div
        v-if="puedeGestionar"
        v-show="visible('tipo-negocio')"
        id="tipo-negocio"
        class="mt-6 scroll-mt-24"
      >
        <GiroNegocio />
      </div>
      <!-- Cómo se llaman las cosas en el negocio (otro giro trae otros términos) -->
      <div
        v-if="puedeGestionar"
        v-show="visible('terminologia')"
        id="terminologia"
        class="mt-6 scroll-mt-24"
      >
        <TerminologiaNegocio
          :key="sesion.estudio?.perfil"
          :cargar="cargarTerminologia"
          :guardar="guardarTerminologia"
          @guardado="terminologiaGuardada"
        />
      </div>
    </template>
  </section>
</template>
