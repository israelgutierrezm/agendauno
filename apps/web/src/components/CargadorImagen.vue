<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Una imagen por ARCHIVO (arrastrar o elegir, nunca por URL): la portada de la página
 * pública (`marca/portada`) o la foto de una sede (`sucursales/{id}/foto`). Sube por
 * `POST {ruta}` (PNG/JPG/WebP ≤ 4 MB) en el campo `campo`, la quita con `DELETE` y
 * emite la nueva URL (`clave` de la respuesta) al padre. Muestra la imagen y, con
 * «Subir/Cambiar imagen», la zona de carga de toda la app (ZonaArchivo).
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
    // Quitar solo la deja de usar (sin DELETE): las fotos del sitio del negocio, que
    // se limpian al guardar o publicar (ADR 0114).
    quitarSinBorrar?: boolean;
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
    quitarSinBorrar: false,
  },
);
const emit = defineEmits<{ "update:url": [string | null] }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

// Coincide con la validación del backend (image | mimes:jpg,jpeg,png,webp | max:4096).
const MAX_BYTES = 4 * 1024 * 1024;
const TIPOS = "image/png,image/jpeg,image/webp";

const abierta = ref(false);
const subiendo = ref(false);
const error = ref<string | null>(null);

async function procesar(archivo: File): Promise<void> {
  error.value = null;
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
    abierta.value = false;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    subiendo.value = false;
  }
}

async function quitar(): Promise<void> {
  if (!props.puedeGestionar || subiendo.value) {
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
  if (props.quitarSinBorrar) {
    emit("update:url", null);
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
    <div v-if="url" class="cp-vista">
      <img :src="url" alt="" class="cp-imagen" />
    </div>
    <p
      v-else-if="!puedeGestionar"
      class="text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("zonaArchivo.imagen.sinImagen") }}
    </p>

    <div v-if="puedeGestionar" class="cp-acciones" :class="{ 'mt-2': url }">
      <ZonaArchivo
        v-model:abierta="abierta"
        icono="imagen"
        :accept="TIPOS"
        :max-bytes="MAX_BYTES"
        :error-tipo="$t('perfilPublico.config.portadaTipo')"
        :error-peso="$t('perfilPublico.config.portadaPeso')"
        :boton="
          url
            ? $t('zonaArchivo.imagen.cambiar')
            : $t('zonaArchivo.imagen.subir')
        "
        :texto="arrastra ?? $t('perfilPublico.config.portadaArrastra')"
        :elige="$t('zonaArchivo.imagen.elige')"
        :suelta="
          url
            ? $t('zonaArchivo.imagen.suelta')
            : $t('zonaArchivo.imagen.sueltaNueva')
        "
        :ayuda="ayuda ?? $t('perfilPublico.config.portadaAyuda')"
        :ocupado="subiendo"
        :ocupado-texto="$t('perfilPublico.config.portadaSubiendo')"
        @archivo="procesar"
      />
      <button
        v-if="url && !abierta"
        type="button"
        class="tu-btn tu-btn-fantasma text-sm"
        style="color: var(--error)"
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
.cp-vista {
  aspect-ratio: v-bind(proporcion);
  overflow: hidden;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--fondo);
}
.cp-imagen {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
/* «Cambiar imagen» y «Quitar» en una fila; desplegada, la zona ocupa el ancho. */
.cp-acciones {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.5rem;
}
.cp-acciones > :deep(:has(> .tu-zona-archivo)) {
  width: 100%;
}
</style>
