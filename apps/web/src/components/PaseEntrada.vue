<script setup lang="ts">
import QRCode from "qrcode";
import { computed, onBeforeUnmount, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Pase de entrada del alumno: un QR firmado que vence en minutos. Mientras se
 * muestra se renueva solo cada minuto, así una captura de pantalla no sirve después.
 */
const RENOVAR_MS = 60_000;

const { t } = useI18n();
const sesion = useSesionTenantStore();
const base = computed(() => `/api/v1/app/${sesion.slug}`);

const visible = ref(false);
const qr = ref<string | null>(null);
const error = ref<string | null>(null);
let temporizador: ReturnType<typeof setInterval> | undefined;

async function cargar(): Promise<void> {
  try {
    const { data } = await api.get<{ data: { codigo: string } }>(
      `${base.value}/mi/pase`,
    );
    qr.value = await QRCode.toDataURL(data.data.codigo, {
      width: 240,
      margin: 1,
      errorCorrectionLevel: "M",
    });
    error.value = null;
  } catch (e) {
    error.value = mensajeDeError(e, t("paseEntrada.error"));
  }
}

async function mostrar(): Promise<void> {
  visible.value = true;
  await cargar();
  clearInterval(temporizador);
  temporizador = setInterval(() => void cargar(), RENOVAR_MS);
}

function ocultar(): void {
  visible.value = false;
  qr.value = null;
  clearInterval(temporizador);
}

onBeforeUnmount(() => clearInterval(temporizador));
</script>

<template>
  <div class="tu-card p-6">
    <h2 class="font-light text-lg">{{ $t("paseEntrada.titulo") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("paseEntrada.ayuda") }}
    </p>

    <template v-if="visible">
      <div class="mt-4 flex justify-center">
        <img
          v-if="qr"
          :src="qr"
          :alt="$t('paseEntrada.alt')"
          width="240"
          height="240"
          class="rounded-lg bg-white p-2"
        />
        <p
          v-else-if="!error"
          class="py-16 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          {{ $t("comun.cargando") }}
        </p>
      </div>
      <p
        v-if="error"
        class="mt-3 text-sm"
        role="alert"
        style="color: var(--error)"
      >
        {{ error }}
      </p>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma mt-4 w-full"
        @click="ocultar"
      >
        {{ $t("paseEntrada.ocultar") }}
      </button>
    </template>
    <button
      v-else
      type="button"
      class="tu-btn tu-btn-primario mt-4 w-full"
      @click="mostrar"
    >
      {{ $t("paseEntrada.mostrar") }}
    </button>
  </div>
</template>
