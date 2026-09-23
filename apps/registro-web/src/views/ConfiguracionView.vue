<script setup lang="ts">
import { computed, onMounted, ref } from "vue";

import EncabezadoSeccion from "@/components/EncabezadoSeccion.vue";
import LogoTurnoUno from "@/components/LogoTurnoUno.vue";
import { api, mensajeDeError } from "@/lib/api";
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

// Logo (branding).
const logoUrl = ref<string | null>(null);
const subiendoLogo = ref(false);
const archivo = ref<HTMLInputElement | null>(null);

const enlaceDirecto = computed(
  () => `${window.location.origin}/entrar?estudio=${sesion.slug ?? ""}`,
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

async function subirLogo(evento: Event): Promise<void> {
  const archivos = (evento.target as HTMLInputElement).files;
  if (archivos === null || archivos.length === 0) {
    return;
  }
  subiendoLogo.value = true;
  error.value = null;
  try {
    const cuerpo = new FormData();
    cuerpo.append("logo", archivos[0]);
    const { data } = await api.post<{ data: { logo_url: string } }>(
      `${base.value}/marca/logo`,
      cuerpo,
    );
    logoUrl.value = data.data.logo_url;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    subiendoLogo.value = false;
    if (archivo.value) {
      archivo.value.value = "";
    }
  }
}

async function quitarLogo(): Promise<void> {
  subiendoLogo.value = true;
  error.value = null;
  try {
    await api.delete(`${base.value}/marca/logo`);
    logoUrl.value = null;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    subiendoLogo.value = false;
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
      <!-- Logo del estudio -->
      <div class="mt-6 tu-card p-6">
        <h2 class="font-bold text-lg">{{ $t("configuracion.logoTitulo") }}</h2>
        <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("configuracion.logoDesc") }}
        </p>

        <div class="mt-4 flex items-center gap-4">
          <img
            v-if="logoUrl"
            :src="logoUrl"
            :alt="sesion.estudio?.nombre"
            class="h-16 w-16 rounded-2xl object-cover"
            :style="{ boxShadow: 'var(--sombra)' }"
          />
          <LogoTurnoUno v-else :tam="64" />

          <div class="flex flex-wrap gap-2">
            <label
              class="tu-btn tu-btn-primario cursor-pointer"
              :class="{ 'opacity-60': subiendoLogo || !puedeGestionar }"
            >
              {{
                subiendoLogo
                  ? $t("configuracion.logoSubiendo")
                  : $t("configuracion.logoSubir")
              }}
              <input
                ref="archivo"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                class="hidden"
                :disabled="subiendoLogo || !puedeGestionar"
                @change="subirLogo"
              />
            </label>
            <button
              v-if="logoUrl"
              type="button"
              class="tu-btn tu-btn-fantasma"
              :disabled="subiendoLogo || !puedeGestionar"
              @click="quitarLogo"
            >
              {{ $t("configuracion.logoQuitar") }}
            </button>
          </div>
        </div>
        <p class="mt-3 text-xs" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("configuracion.logoAyuda") }}
        </p>
      </div>

      <!-- Visibilidad en la Comunidad -->
      <div class="mt-6 tu-card p-6">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="font-bold text-lg">
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
