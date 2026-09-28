<script setup lang="ts">
import QRCode from "qrcode";
import { computed, onMounted, ref, watch } from "vue";

import { urlPublicaEstudio } from "@/lib/tenant";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const sesion = useSesionTenantStore();

// El enlace se DERIVA del slug (no se captura en ningún lado): así te encontrarán.
// Forma subdominio `{slug}.agendauno.mx` — el backend resuelve el estudio por el host.
const enlaceCorto = computed(() => urlPublicaEstudio(sesion.slug ?? ""));
const url = computed(() => `https://${enlaceCorto.value}`);

const qr = ref<string>("");
const copiado = ref(false);

async function generarQr(): Promise<void> {
  if (sesion.slug === null || sesion.slug === "") {
    qr.value = "";
    return;
  }
  try {
    qr.value = await QRCode.toDataURL(url.value, {
      width: 240,
      margin: 1,
      color: { dark: "#111111", light: "#ffffff" },
    });
  } catch {
    qr.value = "";
  }
}

async function copiar(): Promise<void> {
  try {
    await navigator.clipboard.writeText(url.value);
    copiado.value = true;
    window.setTimeout(() => {
      copiado.value = false;
    }, 1800);
  } catch {
    // Sin portapapeles (contexto no seguro): el usuario puede copiar el texto a mano.
  }
}

function descargar(): void {
  if (qr.value === "") {
    return;
  }
  const enlace = document.createElement("a");
  enlace.href = qr.value;
  enlace.download = `qr-${sesion.slug}.png`;
  document.body.appendChild(enlace);
  enlace.click();
  document.body.removeChild(enlace);
}

watch(() => sesion.slug, generarQr);
onMounted(generarQr);
</script>

<template>
  <div v-if="sesion.slug" class="tu-card p-5">
    <h2 class="font-semibold">{{ $t("enlace.titulo") }}</h2>

    <div class="mt-4 flex flex-col sm:flex-row sm:items-center gap-5">
      <img
        v-if="qr"
        :src="qr"
        :alt="$t('enlace.qrAlt')"
        width="120"
        height="120"
        class="rounded-lg border self-center sm:self-auto"
        :style="{ borderColor: 'var(--borde)' }"
      />
      <div class="flex-1 min-w-0 text-center sm:text-left">
        <a
          :href="url"
          target="_blank"
          rel="noopener"
          class="tu-enlace break-all"
          >{{ enlaceCorto }}</a
        >
        <div class="mt-3 flex flex-wrap gap-2 justify-center sm:justify-start">
          <button type="button" class="tu-btn tu-btn-fantasma" @click="copiar">
            {{ copiado ? $t("enlace.copiado") : $t("enlace.copiar") }}
          </button>
          <button
            type="button"
            class="tu-btn tu-btn-fantasma"
            :disabled="!qr"
            @click="descargar"
          >
            {{ $t("enlace.descargar") }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
