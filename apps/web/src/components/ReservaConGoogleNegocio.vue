<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Reserva con Google (ADR 0198): el negocio decide aparecer con el botón de reservar en
 * la ficha de Google de sus sedes. Se ofrece solo si la plataforma ya es socia de Google;
 * dice lo que le falta (página publicada, dirección y ubicación de una sede).
 */
interface Estado {
  disponible: boolean;
  activo: boolean;
  faltantes: ("pagina" | "ubicacion")[];
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const url = computed(() => `/api/v1/app/${sesion.slug}/reserva-con-google`);
const estado = ref<Estado | null>(null);
const guardando = ref(false);
const error = ref<string | null>(null);

onMounted(async () => {
  try {
    const { data } = await api.get<{ data: Estado }>(url.value);
    estado.value = data.data;
  } catch {
    estado.value = null;
  }
});

async function cambiar(activo: boolean): Promise<void> {
  guardando.value = true;
  error.value = null;
  try {
    const { data } = await api.put<{ data: Estado }>(url.value, { activo });
    estado.value = data.data;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <div
    v-if="estado?.disponible"
    class="tu-card p-5"
    data-prueba="reserva-con-google"
  >
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="text-lg font-medium">{{ t("reservaGoogle.titulo") }}</h2>
      <span
        class="tu-estado text-sm"
        :style="{
          '--tono': estado.activo ? 'var(--exito)' : 'var(--texto-suave)',
        }"
        >{{
          estado.activo
            ? t("reservaGoogle.activo")
            : t("reservaGoogle.inactivo")
        }}</span
      >
    </div>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ t("reservaGoogle.ayuda") }}
    </p>
    <ul
      v-if="!estado.activo && estado.faltantes.length > 0"
      class="mt-3 list-disc pl-5 text-sm"
      data-prueba="reserva-google-faltantes"
    >
      <li v-for="f in estado.faltantes" :key="f">
        {{ t(`reservaGoogle.faltantes.${f}`) }}
      </li>
    </ul>
    <p v-if="error" class="tu-error-campo mt-2" role="alert">{{ error }}</p>
    <div
      v-if="sesion.puede('integraciones.configurar')"
      class="mt-3 flex justify-end"
    >
      <button
        type="button"
        class="tu-btn"
        :class="estado.activo ? 'tu-btn-fantasma' : 'tu-btn-primario'"
        :disabled="guardando || (!estado.activo && estado.faltantes.length > 0)"
        data-prueba="reserva-google-cambiar"
        @click="cambiar(!estado.activo)"
      >
        {{
          estado.activo
            ? t("reservaGoogle.apagar")
            : t("reservaGoogle.encender")
        }}
      </button>
    </div>
  </div>
</template>
