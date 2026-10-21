<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";

/**
 * El archivo de un mensaje de la bandeja (ADR 0200). Se pide con la sesión (no tiene
 * una dirección pública): una imagen se muestra; lo demás se descarga. Si aún no se
 * bajó de Meta, el servidor lo intenta al pedirlo.
 */
export interface ArchivoMensaje {
  nombre: string;
  mime: string | null;
  bytes: number | null;
  listo: boolean;
  en_linea: boolean;
}

const props = defineProps<{
  base: string;
  mensajeId: string;
  archivo: ArchivoMensaje;
}>();

const { t } = useI18n();
const url = ref<string | null>(null);
const cargando = ref(false);
const error = ref<string | null>(null);

async function blob(): Promise<Blob> {
  const { data } = await api.get<Blob>(
    `${props.base}/bandeja/mensajes/${props.mensajeId}/archivo`,
    { responseType: "blob" },
  );
  return data;
}

async function mostrar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    url.value = URL.createObjectURL(await blob());
  } catch (err) {
    error.value = mensajeDeError(
      err,
      t("bandejaMensajes.archivo.noDisponible"),
    );
  } finally {
    cargando.value = false;
  }
}

async function descargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  try {
    const enlace = URL.createObjectURL(await blob());
    const a = document.createElement("a");
    a.href = enlace;
    a.download = props.archivo.nombre;
    a.click();
    URL.revokeObjectURL(enlace);
  } catch (err) {
    error.value = mensajeDeError(
      err,
      t("bandejaMensajes.archivo.noDisponible"),
    );
  } finally {
    cargando.value = false;
  }
}

function tamano(bytes: number | null): string {
  if (bytes === null) {
    return "";
  }
  return bytes >= 1024 * 1024
    ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

onMounted(() => {
  if (props.archivo.en_linea && props.archivo.listo) {
    void mostrar();
  }
});
onBeforeUnmount(() => {
  if (url.value !== null) {
    URL.revokeObjectURL(url.value);
  }
});
</script>

<template>
  <div class="am-archivo" data-prueba="archivo-mensaje">
    <img
      v-if="url"
      :src="url"
      :alt="archivo.nombre"
      class="am-imagen"
      loading="lazy"
    />
    <button
      v-else-if="archivo.en_linea && cargando"
      type="button"
      class="tu-enlace text-sm"
      disabled
    >
      {{ $t("bandejaMensajes.archivo.cargando") }}
    </button>
    <button
      v-else
      type="button"
      class="tu-enlace text-sm"
      :disabled="cargando"
      data-prueba="descargar-archivo"
      @click="archivo.en_linea ? mostrar() : descargar()"
    >
      {{
        archivo.en_linea
          ? $t("bandejaMensajes.archivo.ver")
          : $t("bandejaMensajes.archivo.descargar", { nombre: archivo.nombre })
      }}<template v-if="archivo.bytes"> · {{ tamano(archivo.bytes) }}</template>
    </button>
    <p v-if="error" class="tu-hint">{{ error }}</p>
  </div>
</template>

<style scoped>
.am-archivo {
  display: grid;
  gap: 0.25rem;
}
.am-imagen {
  max-width: min(100%, 16rem);
  max-height: 16rem;
  border-radius: 0.5rem;
  object-fit: contain;
}
</style>
