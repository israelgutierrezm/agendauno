<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import IconoNav from "@/components/IconoNav.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Una imagen por ARCHIVO (arrastrar o elegir, nunca por URL): la portada de la página
 * pública (`marca/portada`) o la foto de una sede (`sucursales/{id}/foto`). Sube por
 * `POST {ruta}` (PNG/JPG/WebP ≤ 4 MB) en el campo `campo`, la quita con `DELETE` y
 * emite la nueva URL (`clave` de la respuesta) al padre. Se ve como toda zona de
 * carga de la app (`.tu-zona-archivo`); con imagen, la zona es la imagen misma.
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

// Contador: entrar a la imagen o al texto dispara «dragleave» en la zona.
const dentro = ref(0);
const arrastrando = computed(() => dentro.value > 0 && habilitado.value);
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
      class="tu-zona-archivo cp-zona"
      :class="{
        'tu-zona-archivo-activa': arrastrando,
        'tu-zona-archivo-ocupada': subiendo,
        'cp-con-imagen': url,
      }"
      role="button"
      :tabindex="habilitado ? 0 : -1"
      :aria-disabled="!habilitado"
      :aria-label="`${arrastra ?? $t('perfilPublico.config.portadaArrastra')} ${$t('zonaArchivo.imagen.elige')}`"
      @click="elegir"
      @keydown.enter.prevent="elegir"
      @keydown.space.prevent="elegir"
      @dragenter.prevent="dentro += 1"
      @dragover.prevent
      @dragleave.prevent="dentro = Math.max(0, dentro - 1)"
      @drop.prevent.stop="
        dentro = 0;
        habilitado && procesar($event.dataTransfer?.files?.[0]);
      "
    >
      <img v-if="url" :src="url" alt="" class="cp-imagen" />
      <template v-else>
        <span class="tu-zona-archivo-icono"
          ><IconoNav nombre="imagen" :tam="24"
        /></span>
        <span class="tu-zona-archivo-texto">
          <template v-if="subiendo">{{
            $t("perfilPublico.config.portadaSubiendo")
          }}</template>
          <template v-else>
            {{ arrastra ?? $t("perfilPublico.config.portadaArrastra") }}
            <span class="tu-zona-archivo-elige">{{
              $t("zonaArchivo.imagen.elige")
            }}</span>
          </template>
        </span>
        <span class="tu-zona-archivo-ayuda">{{
          ayuda ?? $t("perfilPublico.config.portadaAyuda")
        }}</span>
      </template>
      <!-- Con imagen: al arrastrar otra encima se dice que la reemplaza. -->
      <span v-if="url && (arrastrando || subiendo)" class="cp-aviso">{{
        subiendo
          ? $t("perfilPublico.config.portadaSubiendo")
          : $t("zonaArchivo.imagen.suelta")
      }}</span>
    </div>
    <input
      ref="entrada"
      type="file"
      accept="image/png,image/jpeg,image/webp"
      class="hidden"
      @change="procesar(($event.target as HTMLInputElement).files?.[0])"
    />
    <div
      v-if="url"
      class="mt-2 flex flex-wrap items-center justify-between gap-2"
    >
      <p class="text-xs" :style="{ color: 'var(--texto-suave)' }">
        {{ ayuda ?? $t("perfilPublico.config.portadaAyuda") }}
      </p>
      <button
        v-if="puedeGestionar"
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
  aspect-ratio: v-bind(proporcion);
  overflow: hidden;
}
/* Con imagen, la zona es la imagen: sin relleno, el borde punteado solo al arrastrar. */
.cp-con-imagen {
  padding: 0;
  border-style: solid;
}
.cp-con-imagen.tu-zona-archivo-activa {
  border-style: dashed;
}
.cp-imagen {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.cp-aviso {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: color-mix(in srgb, var(--superficie) 82%, transparent);
  color: var(--texto);
  font-size: 0.875rem;
  font-weight: 500;
}
</style>
