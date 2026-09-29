<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Privacidad del alumno (derechos ARCO frente al negocio): recibir o no promociones
 * (oposición), aceptar los avisos por WhatsApp (solo si el negocio los usa, ADR
 * 0069), descargar sus datos (acceso) y pedir la baja de sus datos (cancelación). La
 * rectificación está en "Mi perfil".
 */
interface Privacidad {
  recibe_promociones: boolean;
  whatsapp_disponible?: boolean;
  acepta_whatsapp?: boolean;
  baja: {
    estado: "pendiente" | "atendida" | "rechazada";
    solicitada_en: string | null;
    respuesta: string | null;
  } | null;
}

const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const datos = ref<Privacidad | null>(null);
const pidiendoBaja = ref(false);
const motivo = ref("");
const ocupado = ref(false);

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: Privacidad }>(
      `${base.value}/mi/privacidad`,
    );
    datos.value = data.data;
  } catch {
    datos.value = null;
  }
}

async function cambiar(
  cambio: { recibe_promociones: boolean } | { acepta_whatsapp: boolean },
): Promise<void> {
  ocupado.value = true;
  try {
    const { data } = await api.put<{ data: Privacidad }>(
      `${base.value}/mi/privacidad`,
      cambio,
    );
    datos.value = data.data;
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

async function descargar(): Promise<void> {
  ocupado.value = true;
  try {
    const { data } = await api.get<Blob>(`${base.value}/mi/datos`, {
      responseType: "blob",
    });
    const url = URL.createObjectURL(data);
    const enlace = document.createElement("a");
    enlace.href = url;
    enlace.download = `mis-datos-${sesion.slug}.json`;
    enlace.click();
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

async function solicitarBaja(): Promise<void> {
  ocupado.value = true;
  try {
    const { data } = await api.post<{ data: Privacidad }>(
      `${base.value}/mi/privacidad/baja`,
      { motivo: motivo.value.trim() || null },
    );
    datos.value = data.data;
    pidiendoBaja.value = false;
    toast.exito(t("miPrivacidad.bajaEnviada"));
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}

onMounted(cargar);
</script>

<template>
  <div v-if="datos" class="tu-card p-6">
    <h2 class="font-light text-lg">{{ $t("miPrivacidad.titulo") }}</h2>

    <label class="mt-3 flex items-center justify-between gap-3 text-sm">
      <span>
        {{ $t("miPrivacidad.promociones") }}
        <span class="block text-xs" :style="{ color: 'var(--texto-suave)' }">{{
          $t("miPrivacidad.promocionesAyuda")
        }}</span>
      </span>
      <input
        type="checkbox"
        class="h-5 w-5"
        :checked="datos.recibe_promociones"
        :disabled="ocupado"
        @change="
          cambiar({
            recibe_promociones: ($event.target as HTMLInputElement).checked,
          })
        "
      />
    </label>

    <label
      v-if="datos.whatsapp_disponible"
      class="mt-3 flex items-center justify-between gap-3 text-sm"
    >
      <span>
        {{ $t("miPrivacidad.whatsapp") }}
        <span class="block text-xs" :style="{ color: 'var(--texto-suave)' }">{{
          $t("miPrivacidad.whatsappAyuda")
        }}</span>
      </span>
      <input
        type="checkbox"
        class="h-5 w-5"
        data-prueba="acepta-whatsapp"
        :checked="datos.acepta_whatsapp"
        :disabled="ocupado"
        @change="
          cambiar({
            acepta_whatsapp: ($event.target as HTMLInputElement).checked,
          })
        "
      />
    </label>

    <div class="mt-4 flex flex-wrap gap-2">
      <button
        type="button"
        class="tu-btn tu-btn-fantasma text-sm"
        :disabled="ocupado"
        @click="descargar"
      >
        {{ $t("miPrivacidad.descargar") }}
      </button>
      <button
        v-if="!datos.baja || datos.baja.estado === 'rechazada'"
        type="button"
        class="tu-btn tu-btn-fantasma text-sm"
        style="color: var(--error)"
        @click="pidiendoBaja = !pidiendoBaja"
      >
        {{ $t("miPrivacidad.baja") }}
      </button>
    </div>

    <p
      v-if="datos.baja?.estado === 'pendiente'"
      class="mt-3 text-sm"
      role="status"
      style="color: var(--aviso)"
    >
      {{ $t("miPrivacidad.bajaPendiente") }}
    </p>
    <p
      v-else-if="datos.baja?.estado === 'rechazada'"
      class="mt-3 text-sm"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{
        $t("miPrivacidad.bajaRechazada", { respuesta: datos.baja.respuesta })
      }}
    </p>

    <form
      v-if="pidiendoBaja"
      class="mt-3 space-y-3 rounded-xl border p-4"
      :style="{ borderColor: 'var(--borde)' }"
      @submit.prevent="solicitarBaja"
    >
      <p class="text-sm">{{ $t("miPrivacidad.bajaExplica") }}</p>
      <div>
        <label class="tu-label" for="mp-baja-motivo">{{
          $t("miPrivacidad.motivo")
        }}</label>
        <input
          id="mp-baja-motivo"
          v-model="motivo"
          class="tu-input"
          maxlength="500"
        />
      </div>
      <button
        type="submit"
        class="tu-btn tu-btn-primario text-sm"
        :disabled="ocupado"
      >
        {{ $t("miPrivacidad.confirmarBaja") }}
      </button>
    </form>
  </div>
</template>
