<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Portada de la página pública por ARCHIVO (arrastrar o elegir, nunca por URL). Sube
 * a `POST /marca/portada` (PNG/JPG/WebP ≤ 4 MB) y la quita con `DELETE`. Emite la
 * nueva `portada_url` al padre.
 */
const props = withDefaults(
  defineProps<{ portadaUrl: string | null; puedeGestionar?: boolean }>(),
  { puedeGestionar: true },
);
const emit = defineEmits<{ "update:portadaUrl": [string | null] }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

// Coincide con la validación del backend (image | mimes:jpg,jpeg,png,webp | max:4096).
const MAX_BYTES = 4 * 1024 * 1024;
const TIPOS = ["image/png", "image/jpeg", "image/webp"];

const arrastrando = ref(false);
const subiendo = ref(false);
const error = ref<string | null>(null);
const entrada = ref<HTMLInputElement | null>(null);
const habilitado = computed(() => props.puedeGestionar && !subiendo.value);

function elegir(): void {
  if (habilitado.value) {
    entrada.value?.click();
  }
}

async function procesar(archivo: File | undefined | null): Promise<void> {
  error.value = null;
  if (archivo === undefined || archivo === null) {
    return;
  }
  if (!TIPOS.includes(archivo.type)) {
    error.value = t("perfilPublico.config.portadaTipo");
    return;
  }
  if (archivo.size > MAX_BYTES) {
    error.value = t("perfilPublico.config.portadaPeso");
    return;
  }
  subiendo.value = true;
  try {
    const cuerpo = new FormData();
    cuerpo.append("portada", archivo);
    const { data } = await api.post<{ data: { portada_url: string } }>(
      `${base.value}/marca/portada`,
      cuerpo,
    );
    emit("update:portadaUrl", data.data.portada_url);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    subiendo.value = false;
    if (entrada.value) {
      entrada.value.value = "";
    }
  }
}

async function quitar(): Promise<void> {
  if (!habilitado.value) {
    return;
  }
  subiendo.value = true;
  error.value = null;
  try {
    await api.delete(`${base.value}/marca/portada`);
    emit("update:portadaUrl", null);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    subiendo.value = false;
  }
}
</script>

<template>
  <div>
    <div
      class="cp-zona"
      :class="{ 'cursor-pointer': habilitado, 'cp-arrastrando': arrastrando }"
      role="button"
      :tabindex="habilitado ? 0 : -1"
      :aria-label="$t('perfilPublico.config.portadaArrastra')"
      @click="elegir"
      @keydown.enter.prevent="elegir"
      @keydown.space.prevent="elegir"
      @dragover.prevent="arrastrando = habilitado"
      @dragenter.prevent="arrastrando = habilitado"
      @dragleave.prevent="arrastrando = false"
      @drop.prevent="
        arrastrando = false;
        habilitado && procesar($event.dataTransfer?.files?.[0]);
      "
    >
      <img v-if="portadaUrl" :src="portadaUrl" alt="" class="cp-imagen" />
      <p v-else class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{
          subiendo
            ? $t("perfilPublico.config.portadaSubiendo")
            : $t("perfilPublico.config.portadaArrastra")
        }}
      </p>
    </div>
    <input
      ref="entrada"
      type="file"
      accept="image/png,image/jpeg,image/webp"
      class="hidden"
      @change="procesar(($event.target as HTMLInputElement).files?.[0])"
    />
    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
      <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("perfilPublico.config.portadaAyuda") }}
      </p>
      <button
        v-if="portadaUrl && puedeGestionar"
        type="button"
        class="tu-enlace text-sm"
        :disabled="subiendo"
        @click="quitar"
      >
        {{ $t("perfilPublico.config.portadaQuitar") }}
      </button>
    </div>
    <p v-if="error" class="mt-1 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
  </div>
</template>

<style scoped>
.cp-zona {
  display: flex;
  align-items: center;
  justify-content: center;
  aspect-ratio: 8 / 3;
  overflow: hidden;
  border-radius: 1rem;
  border: 1px dashed var(--borde);
  background: var(--fondo);
  text-align: center;
  padding: 0;
}
.cp-arrastrando {
  border-color: var(--primario);
  background: var(--primario-suave);
}
.cp-imagen {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
</style>
