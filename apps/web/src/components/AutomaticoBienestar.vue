<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";

import { useToastStore } from "@/stores/toast";

/**
 * El check-in automático de Wellhub o TotalPass en Integraciones (ADR 0148): si ya está
 * activo y, si no, qué falta. Con la dirección donde la plataforma avisa de cada
 * check-in, para copiarla (Wellhub la pide; TotalPass la registra AgendaUno).
 */
interface Automatico {
  aviso_url: string | null;
  listo: boolean;
  registrado_en: string | null;
}

const props = defineProps<{
  proveedor: string;
  automatico?: Automatico | null;
}>();

const { t } = useI18n();
const toast = useToastStore();

const listo = computed(() => props.automatico?.listo === true);
const ayuda = computed(() => {
  if (!props.automatico?.aviso_url) {
    return t("operacion.plataformas.automatico.sinDireccion");
  }
  const sufijo = listo.value ? "Listo" : "Pendiente";
  return t(`operacion.plataformas.automatico.${props.proveedor}${sufijo}`);
});

async function copiar(): Promise<void> {
  const url = props.automatico?.aviso_url;
  if (!url) {
    return;
  }
  try {
    await navigator.clipboard.writeText(url);
    toast.exito(t("operacion.plataformas.automatico.copiada"));
  } catch {
    // Sin portapapeles, la dirección sigue a la vista para copiarla a mano.
  }
}
</script>

<template>
  <div class="ab mt-4" data-prueba="checkin-automatico">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-sm font-medium">
        {{ $t("operacion.plataformas.automatico.titulo") }}
      </p>
      <span
        class="tu-estado text-sm"
        :style="{ '--tono': listo ? 'var(--exito)' : 'var(--aviso)' }"
        data-prueba="checkin-automatico-estado"
        >{{
          listo
            ? $t("operacion.plataformas.automatico.listo")
            : $t("operacion.plataformas.automatico.pendiente")
        }}</span
      >
    </div>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ ayuda }}
    </p>
    <div
      v-if="automatico?.aviso_url && (!listo || proveedor === 'wellhub')"
      class="mt-2 flex items-center gap-2"
    >
      <code
        class="ab-url"
        :title="$t('operacion.plataformas.automatico.direccion')"
        >{{ automatico.aviso_url }}</code
      >
      <button
        type="button"
        class="tu-btn tu-btn-fantasma shrink-0 text-sm"
        @click="copiar"
      >
        {{ $t("operacion.plataformas.automatico.copiar") }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.ab {
  padding: 0.85rem 1rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta);
  background: var(--superficie-2);
}
.ab-url {
  min-width: 0;
  flex: 1;
  overflow: hidden;
  font-size: 0.78rem;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--texto-suave);
}
</style>
