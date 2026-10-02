<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Una imagen por ARCHIVO (arrastrar o elegir, nunca por URL): la portada de la página
 * pública (`marca/portada`) o la foto de una sede (`sucursales/{id}/foto`). Sube por
 * `POST {ruta}` (PNG/JPG/WebP ≤ 4 MB) en el campo `campo`, la quita con `DELETE` y
 * emite la nueva URL (`clave` de la respuesta) al padre.
 */
const props = withDefaults(
  defineProps<{
    url: string | null;
    ruta?: string;
    campo?: string;
    clave?: string;
    // Textos propios (la portada usa los suyos si no se dan).
    arrastra?: string;
    ayuda?: string;
    quitarTexto?: string;
    proporcion?: string;
    puedeGestionar?: boolean;
  }>(),
  {
    ruta: "marca/portada",
    campo: "portada",
    clave: "portada_url",
    arrastra: undefined,
    ayuda: undefined,
    quitarTexto: undefined,
    proporcion: "8 / 3",
    puedeGestionar: true,
  },
);
const emit = defineEmits<{ "update:url": [string | null] }>();

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
    cuerpo.append(props.campo, archivo);
    const { data } = await api.post<{ data: Record<string, unknown> }>(
      `${base.value}/${props.ruta}`,
      cuerpo,
    );
    const url = data.data[props.clave];
    emit("update:url", typeof url === "string" ? url : null);
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
  if (
    !(await confirmar(t("confirmaciones.quitarImagen"), {
      aceptar: t("confirmaciones.quitar"),
      peligro: true,
    }))
  ) {
    return;
  }
  subiendo.value = true;
  error.value = null;
  try {
    await api.delete(`${base.value}/${props.ruta}`);
    emit("update:url", null);
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
      :aria-label="arrastra ?? $t('perfilPublico.config.portadaArrastra')"
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
      <img v-if="url" :src="url" alt="" class="cp-imagen" />
      <p v-else class="text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{
          subiendo
            ? $t("perfilPublico.config.portadaSubiendo")
            : (arrastra ?? $t("perfilPublico.config.portadaArrastra"))
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
        {{ ayuda ?? $t("perfilPublico.config.portadaAyuda") }}
      </p>
      <button
        v-if="url && puedeGestionar"
        type="button"
        class="tu-enlace text-sm"
        :disabled="subiendo"
        @click="quitar"
      >
        {{ quitarTexto ?? $t("perfilPublico.config.portadaQuitar") }}
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
  aspect-ratio: v-bind(proporcion);
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
