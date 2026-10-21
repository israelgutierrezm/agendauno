<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

import { api } from "@/lib/api";
import { useToastStore } from "@/stores/toast";

/**
 * Referidos entre negocios (ADR 0188): el código del negocio para invitar a otro, a
 * cuántos invitó y sus meses gratis. Cuando el invitado paga su primer mes, los dos
 * ganan un mes gratis.
 */
interface Referidos {
  codigo: string;
  invitados: number;
  beneficios: { motivo: string; aplicado: boolean; fecha: string | null }[];
}

const props = defineProps<{ base: string }>();
const { t } = useI18n();
const toast = useToastStore();
const datos = ref<Referidos | null>(null);
// El registro de la marca del negocio, con el código.
const enlace = computed(() =>
  datos.value
    ? `${window.location.origin}/registro?ref=${encodeURIComponent(datos.value.codigo)}`
    : "",
);

async function copiar(): Promise<void> {
  await navigator.clipboard?.writeText(enlace.value).catch(() => undefined);
  toast.exito(t("suscripcion.referidos.copiado"));
}

onMounted(async () => {
  try {
    const { data } = await api.get<{ data: Referidos }>(
      `${props.base}/renta/referidos`,
    );
    datos.value = data.data;
  } catch {
    datos.value = null;
  }
});
</script>

<template>
  <section v-if="datos" class="tu-card mt-4 p-5" data-prueba="referidos">
    <h2 class="font-medium">{{ $t("suscripcion.referidos.titulo") }}</h2>
    <p class="mt-1 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("suscripcion.referidos.ayuda") }}
    </p>
    <div class="mt-3 flex flex-wrap items-center gap-3">
      <span class="text-sm">
        {{ $t("suscripcion.referidos.codigo") }}:
        <strong class="font-mono" data-prueba="codigo-referido">{{
          datos.codigo
        }}</strong>
      </span>
      <button
        type="button"
        class="tu-btn tu-btn-fantasma text-sm"
        @click="copiar"
      >
        {{ $t("suscripcion.referidos.copiar") }}
      </button>
    </div>
    <p class="mt-2 text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{
        $t(
          "suscripcion.referidos.invitados",
          { n: datos.invitados },
          datos.invitados,
        )
      }}
    </p>
    <ul v-if="datos.beneficios.length > 0" class="mt-2 space-y-1 text-sm">
      <li v-for="(b, i) in datos.beneficios" :key="i">
        {{ b.motivo }} ·
        <span :style="{ color: 'var(--texto-suave)' }">{{
          b.aplicado
            ? $t("suscripcion.referidos.aplicado")
            : $t("suscripcion.referidos.pendiente")
        }}</span>
      </li>
    </ul>
  </section>
</template>
