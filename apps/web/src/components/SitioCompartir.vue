<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";

import EnlaceEstudio from "@/components/EnlaceEstudio.vue";
import { puedeEntrar } from "@/lib/acceso";
import { urlPublicaEstudio } from "@/lib/tenant";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import IntegrarWebView from "@/views/IntegrarWebView.vue";

/**
 * «Compartir» del sitio (ADR 0167): el enlace del negocio con su QR, la página de
 * enlaces para las redes, los widgets para otra web (ADR 0141) y los formularios de
 * captación. Quien atiende prospectos entra aquí aunque no edite el sitio.
 */
const sesion = useSesionTenantStore();
const enlaces = computed(
  () => `https://${urlPublicaEstudio(sesion.slug ?? "")}/enlaces`,
);
const copiado = ref(false);

async function copiar(): Promise<void> {
  try {
    await navigator.clipboard.writeText(enlaces.value);
    copiado.value = true;
    window.setTimeout(() => (copiado.value = false), 1800);
  } catch {
    // Sin portapapeles: se puede copiar el texto a mano.
  }
}
</script>

<template>
  <div class="space-y-6" data-prueba="sitio-compartir">
    <EnlaceEstudio />

    <!-- La página de enlaces (bio de Instagram, TikTok…) -->
    <div class="tu-card p-5" data-prueba="pagina-enlaces">
      <h2 class="font-semibold">{{ $t("sitioWeb.compartir.enlaces") }}</h2>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("sitioWeb.compartir.enlacesAyuda") }}
      </p>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <a
          :href="enlaces"
          target="_blank"
          rel="noopener"
          class="tu-enlace break-all"
          >{{ enlaces.replace("https://", "") }}</a
        >
        <button type="button" class="tu-btn tu-btn-secundario" @click="copiar">
          {{ copiado ? $t("enlace.copiado") : $t("enlace.copiar") }}
        </button>
      </div>
    </div>

    <!-- Formularios de captación: su enlace, QR y código están en Prospectos -->
    <div
      v-if="puedeEntrar('captacion-formularios', sesion)"
      class="tu-card p-5"
      data-prueba="compartir-formularios"
    >
      <h2 class="font-semibold">{{ $t("sitioWeb.compartir.formularios") }}</h2>
      <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("sitioWeb.compartir.formulariosAyuda") }}
      </p>
      <RouterLink
        :to="{ name: 'captacion-formularios' }"
        class="tu-enlace mt-3 inline-block text-sm"
        >{{ $t("sitioWeb.compartir.irFormularios") }}</RouterLink
      >
    </div>

    <!-- Widgets para otra web (ADR 0141) -->
    <IntegrarWebView
      v-if="sesion.puede('prospectos.ver')"
      en-pestana
      data-prueba="compartir-widgets"
    />
  </div>
</template>
