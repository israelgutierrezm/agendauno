<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import ZonaArchivo from "@/components/ZonaArchivo.vue";
import { api, mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Cargador del logo del negocio por ARCHIVO: arrastrar y soltar o elegirlo (nunca por
 * URL). Sube a `POST /marca/logo` (imagen PNG/JPG/WebP ≤ 2 MB) y permite quitarlo
 * (`DELETE /marca/logo`). Emite el nuevo `logo_url` al padre. Muestra el logo y, con
 * «Subir/Cambiar logo», la zona de carga de toda la app (ZonaArchivo).
 */
const props = withDefaults(
  defineProps<{ logoUrl: string | null; puedeGestionar?: boolean }>(),
  { puedeGestionar: true },
);
const emit = defineEmits<{ "update:logoUrl": [string | null] }>();

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

// Coincide con la validación del backend (image | mimes:jpg,jpeg,png,webp | max:2048).
const MAX_BYTES = 2 * 1024 * 1024;
const TIPOS = "image/png,image/jpeg,image/webp";

const abierta = ref(false);
const subiendo = ref(false);
const error = ref<string | null>(null);

async function procesar(archivo: File): Promise<void> {
  error.value = null;
  subiendo.value = true;
  try {
    const cuerpo = new FormData();
    cuerpo.append("logo", archivo);
    const { data } = await api.post<{ data: { logo_url: string } }>(
      `${base.value}/marca/logo`,
      cuerpo,
    );
    emit("update:logoUrl", data.data.logo_url);
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
    !(await confirmar(t("confirmaciones.quitarLogo"), {
      aceptar: t("confirmaciones.quitar"),
      peligro: true,
    }))
  ) {
    return;
  }
  subiendo.value = true;
  error.value = null;
  try {
    await api.delete(`${base.value}/marca/logo`);
    emit("update:logoUrl", null);
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    subiendo.value = false;
  }
}
</script>

<template>
  <div>
    <img
      v-if="logoUrl"
      :src="logoUrl"
      alt=""
      class="cl-logo"
      data-prueba="logo-actual"
    />
    <div v-if="puedeGestionar" class="cl-acciones" :class="{ 'mt-3': logoUrl }">
      <ZonaArchivo
        v-model:abierta="abierta"
        icono="imagen"
        :accept="TIPOS"
        :max-bytes="MAX_BYTES"
        :error-tipo="$t('configuracion.logoTipo')"
        :error-peso="$t('configuracion.logoPeso')"
        :boton="
          logoUrl
            ? $t('zonaArchivo.logo.cambiar')
            : $t('configuracion.logoSubir')
        "
        :texto="$t('asistente.logo.arrastra')"
        :elige="$t('asistente.logo.selecciona')"
        :suelta="
          logoUrl
            ? $t('zonaArchivo.imagen.suelta')
            : $t('zonaArchivo.imagen.sueltaNueva')
        "
        :ayuda="$t('asistente.logo.ayuda')"
        :ocupado="subiendo"
        :ocupado-texto="$t('configuracion.logoSubiendo')"
        @archivo="procesar"
      />
      <button
        v-if="logoUrl && !abierta"
        type="button"
        class="tu-btn tu-btn-fantasma text-sm"
        style="color: var(--error)"
        :disabled="subiendo"
        @click="quitar"
      >
        {{ $t("configuracion.logoQuitar") }}
      </button>
    </div>

    <p v-if="error" class="mt-2 text-sm" style="color: var(--error)">
      {{ error }}
    </p>
  </div>
</template>

<style scoped>
.cl-logo {
  width: 5rem;
  height: 5rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  object-fit: cover;
}
/* «Cambiar logo» y «Quitar» en una fila; desplegada, la zona ocupa el ancho. */
.cl-acciones {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.5rem;
}
.cl-acciones > :deep(:has(> .tu-zona-archivo)) {
  width: 100%;
}
</style>
