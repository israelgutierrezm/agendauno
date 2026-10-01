<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import CargadorLogo from "@/components/CargadorLogo.vue";
import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import PerfilPublicoNegocio from "@/components/PerfilPublicoNegocio.vue";
import TerminologiaNegocio from "@/components/TerminologiaNegocio.vue";
import { api, mensajeDeError } from "@/lib/api";
import type { DatosTerminologia, TerminosNegocio } from "@/lib/terminologia";
import { urlPublicaEstudio } from "@/lib/tenant";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const puedeGestionar = computed(() => sesion.puede("estudio.gestionar"));
const aparece = ref(sesion.estudio?.publicado ?? true);
const cargando = ref(true);
const guardando = ref(false);
const guardado = ref(false);
const error = ref<string | null>(null);
const copiado = ref(false);

// Logo (branding): la subida por arrastrar/soltar la maneja CargadorLogo.
const logoUrl = ref<string | null>(null);

const enlaceDirecto = computed(
  () => `https://${urlPublicaEstudio(sesion.slug ?? "")}`,
);

async function cargar(): Promise<void> {
  cargando.value = true;
  try {
    await sesion.cargarYo();
    aparece.value = sesion.estudio?.publicado ?? true;
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

async function alternar(): Promise<void> {
  if (guardando.value) {
    return;
  }
  const nuevo = !aparece.value;
  guardando.value = true;
  guardado.value = false;
  error.value = null;
  try {
    const { data } = await api.put<{
      data: { publicado: boolean; en_directorio: boolean };
    }>(`${base.value}/publicacion`, { publicado: nuevo, privado: false });
    aparece.value = data.data.publicado;
    if (sesion.estudio) {
      sesion.estudio.publicado = data.data.publicado;
      sesion.estudio.en_directorio = data.data.en_directorio;
    }
    guardado.value = true;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
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

async function copiar(): Promise<void> {
  try {
    await navigator.clipboard.writeText(enlaceDirecto.value);
    copiado.value = true;
    setTimeout(() => (copiado.value = false), 2000);
  } catch {
    // Sin portapapeles: el usuario puede copiar manualmente.
  }
}

onMounted(cargar);
</script>

<template>
  <section class="mx-auto max-w-2xl px-4 py-10">
    <EncabezadoSeccion :titulo="$t('configuracion.titulo')" />

    <p v-if="cargando" class="mt-8" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <p v-if="error" class="mt-4 text-sm" style="color: var(--error)">
      {{ error }}
    </p>

    <template v-if="!cargando">
      <!-- Logo del estudio (Configuración › Negocio › Datos e imagen) -->
      <div id="datos" class="mt-6 tu-card p-6 scroll-mt-24">
        <h2 class="font-light text-lg">{{ $t("configuracion.logoTitulo") }}</h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
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

      <!-- Portada, descripción y redes: la página pública y la de enlaces -->
      <PerfilPublicoNegocio
        v-if="puedeGestionar"
        id="pagina-publica"
        class="mt-6 scroll-mt-24"
      />
      <!-- Cómo se llaman las cosas en el negocio -->
      <TerminologiaNegocio
        v-if="puedeGestionar"
        id="terminologia"
        class="mt-6 scroll-mt-24"
        :cargar="cargarTerminologia"
        :guardar="guardarTerminologia"
        @guardado="terminologiaGuardada"
      />

      <!-- Visibilidad en Explorar -->
      <div class="mt-6 tu-card p-6">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="font-light text-lg">
              {{ $t("configuracion.directorioTitulo") }}
            </h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
              {{ $t("configuracion.directorioDesc") }}
            </p>
          </div>

          <button
            type="button"
            role="switch"
            :aria-checked="aparece"
            :disabled="!puedeGestionar || guardando"
            class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors"
            :style="{
              background: aparece ? 'var(--primario)' : 'var(--superficie-2)',
              opacity: puedeGestionar ? 1 : 0.5,
            }"
            @click="alternar"
          >
            <span
              class="inline-block h-5 w-5 rounded-full bg-white transition-transform"
              :style="{
                transform: aparece
                  ? 'translateX(1.5rem)'
                  : 'translateX(0.25rem)',
              }"
            />
          </button>
        </div>

        <p
          class="mt-4 text-sm font-semibold"
          :style="{ color: aparece ? 'var(--exito)' : 'var(--texto-suave)' }"
        >
          {{
            aparece
              ? $t("configuracion.directorioActivo")
              : $t("configuracion.directorioInactivo")
          }}
        </p>
        <p
          v-if="guardado"
          class="mt-1 text-sm"
          :style="{ color: 'var(--exito)' }"
        >
          {{ $t("configuracion.guardado") }}
        </p>

        <div
          class="mt-6 border-t pt-4"
          :style="{ borderColor: 'var(--borde)' }"
        >
          <label class="tu-label">{{
            $t("configuracion.enlaceDirecto")
          }}</label>
          <div class="flex gap-2">
            <input
              class="tu-input font-mono text-sm"
              :value="enlaceDirecto"
              readonly
              @focus="($event.target as HTMLInputElement).select()"
            />
            <button
              class="tu-btn tu-btn-fantasma shrink-0"
              type="button"
              @click="copiar"
            >
              {{ copiado ? "✓" : $t("configuracion.copiar") }}
            </button>
          </div>
        </div>
      </div>
    </template>
  </section>
</template>
