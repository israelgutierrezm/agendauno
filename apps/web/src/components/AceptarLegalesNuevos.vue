<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import { mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Versión nueva del aviso de privacidad o de los términos de la plataforma (ADR 0216):
 * al entrar, su dueño la lee y la acepta. Si lo deja para después, se le vuelve a pedir
 * la próxima vez que entra.
 */
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();

const pendientes = computed(() =>
  sesion.usuario?.rol === "propietario"
    ? (sesion.estudio?.legales_pendientes ?? [])
    : [],
);
const abierto = ref(false);
const leido = ref(false);
const ocupado = ref(false);
const error = ref<string | null>(null);

watch(
  () => pendientes.value.length,
  (n) => {
    abierto.value = n > 0;
  },
  { immediate: true },
);

function ruta(tipo: string): string {
  return tipo === "aviso_privacidad" ? "/aviso-de-privacidad" : "/terminos";
}

async function aceptar(): Promise<void> {
  if (!leido.value || ocupado.value) return;
  ocupado.value = true;
  error.value = null;
  try {
    await sesion.aceptarLegales();
    abierto.value = false;
    toast.exito(t("legalesNuevos.aceptados"));
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}
</script>

<template>
  <ModalDialogo
    :abierto="abierto"
    :titulo="$t('legalesNuevos.titulo')"
    :ocupado="ocupado"
    tam="md"
    @cerrar="abierto = false"
  >
    <div class="space-y-3 text-sm" data-prueba="legales-nuevos">
      <p>{{ $t("legalesNuevos.ayuda") }}</p>
      <ul class="list-disc space-y-1 pl-5">
        <li v-for="p in pendientes" :key="p.tipo">
          <a
            :href="ruta(p.tipo)"
            target="_blank"
            rel="noopener"
            class="tu-enlace"
            >{{ $t(`legalesNuevos.documentos.${p.tipo}`) }}</a
          >
        </li>
      </ul>
      <label class="flex items-start gap-2">
        <input
          v-model="leido"
          type="checkbox"
          class="mt-1"
          data-prueba="leido-legales"
        />
        <span>{{ $t("legalesNuevos.leido") }}</span>
      </label>
      <AvisoTono v-if="error" tono="peligro" role="alert">{{
        error
      }}</AvisoTono>
    </div>
    <template #pie>
      <button
        type="button"
        class="tu-btn tu-btn-secundario"
        :disabled="ocupado"
        @click="abierto = false"
      >
        {{ $t("legalesNuevos.despues") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-primario"
        :disabled="ocupado || !leido"
        data-prueba="aceptar-legales"
        @click="aceptar"
      >
        {{ $t("legalesNuevos.aceptar") }}
      </button>
    </template>
  </ModalDialogo>
</template>
