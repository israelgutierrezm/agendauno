<script setup lang="ts">
import { computed, ref } from "vue";

import { enSubdominioDeEstudio } from "@/lib/tenant";
import {
  abiertaComoApp,
  esIos,
  instalacion,
  pedirInstalacion,
} from "@/lib/pwa";

/**
 * Invitación discreta a instalar la app del negocio (PWA, ADR 0110), en su subdominio:
 * con el aviso del navegador si lo ofrece (Android, Chrome) o, en iPhone, cómo
 * agregarla a inicio. No se muestra si ya está instalada o si la persona dijo «Ahora
 * no» (se recuerda en este navegador).
 */
const props = defineProps<{ negocio: string }>();

const CLAVE = "tu.pwa.descartada";
function descartadaAntes(): boolean {
  try {
    return localStorage.getItem(CLAVE) === "1";
  } catch {
    return false;
  }
}
const descartada = ref(descartadaAntes());
const ios = esIos();

const visible = computed(
  () =>
    enSubdominioDeEstudio() &&
    !descartada.value &&
    !instalacion.instalada &&
    !abiertaComoApp() &&
    (instalacion.disponible || ios),
);

async function instalar(): Promise<void> {
  if (await pedirInstalacion()) {
    descartar();
  }
}
function descartar(): void {
  descartada.value = true;
  try {
    localStorage.setItem(CLAVE, "1");
  } catch {
    // Sin almacenamiento: se oculta mientras dure la página.
  }
}
</script>

<template>
  <section
    v-if="visible"
    class="tu-card p-4 flex flex-wrap items-center gap-3"
    data-prueba="instalar-app"
  >
    <div class="min-w-0 flex-1">
      <p class="font-medium">
        {{ $t("pwa.titulo", { negocio: props.negocio }) }}
      </p>
      <p class="mt-0.5 text-sm" :style="{ color: 'var(--texto-suave)' }">
        {{ instalacion.disponible ? $t("pwa.texto") : $t("pwa.ios") }}
      </p>
    </div>
    <div class="flex gap-2">
      <button
        v-if="instalacion.disponible"
        type="button"
        class="tu-btn tu-btn-primario text-sm"
        @click="instalar"
      >
        {{ $t("pwa.instalar") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma text-sm"
        @click="descartar"
      >
        {{ $t("pwa.ahoraNo") }}
      </button>
    </div>
  </section>
</template>
