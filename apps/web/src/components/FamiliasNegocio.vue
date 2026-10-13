<script setup lang="ts">
import { onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Familias del negocio (ADR 0123): si se usan y el texto de autorización que acepta el
 * responsable. Vacío = el texto base de la plataforma; cambiarlo sube la versión.
 */
interface ConfiguracionFamilias {
  activo: boolean;
  version: number;
  texto_autorizacion: string | null;
  texto_vigente: string;
}

const emit = defineEmits<{
  guardado: [];
  cerrar: [];
  // Guardando: quien lo abre no deja cerrar su diálogo hasta que termine.
  ocupado: [valor: boolean];
}>();
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();

const cargando = ref(true);
const guardando = ref(false);
const error = ref<string | null>(null);
// Sin la configuración actual no se guarda: se mandaría «apagado» y sin texto propio.
const cargaFallida = ref(false);
const activo = ref(false);
const texto = ref("");
const version = ref(1);
const textoBase = ref("");
watch(guardando, (valor) => emit("ocupado", valor));

async function cargar(): Promise<void> {
  cargando.value = true;
  error.value = null;
  cargaFallida.value = false;
  try {
    const { data } = await api.get<{ data: ConfiguracionFamilias }>(
      `/api/v1/app/${sesion.slug}/familias`,
    );
    activo.value = data.data.activo;
    texto.value = data.data.texto_autorizacion ?? "";
    version.value = data.data.version;
    textoBase.value =
      data.data.texto_autorizacion === null ? data.data.texto_vigente : "";
  } catch (e) {
    error.value = mensajeDeError(e);
    cargaFallida.value = true;
  } finally {
    cargando.value = false;
  }
}
onMounted(cargar);

async function guardar(): Promise<void> {
  if (cargaFallida.value) {
    return;
  }
  guardando.value = true;
  error.value = null;
  try {
    await api.put(`/api/v1/app/${sesion.slug}/familias`, {
      activo: activo.value,
      texto_autorizacion: texto.value.trim() !== "" ? texto.value : null,
    });
    toast.exito(t("familias.config.guardado"));
    emit("guardado");
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <div class="grid gap-4" data-prueba="familias-negocio">
    <p class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("familias.config.texto") }}
    </p>
    <p v-if="cargando" class="text-sm" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("comun.cargando") }}
    </p>
    <template v-else-if="!cargaFallida">
      <label class="flex items-center gap-2 text-sm">
        <input v-model="activo" type="checkbox" />
        {{ $t("familias.config.activar") }}
      </label>
      <div>
        <label class="tu-label" for="fam-texto">{{
          $t("familias.config.textoAutorizacion")
        }}</label>
        <textarea
          id="fam-texto"
          v-model="texto"
          class="tu-input"
          rows="6"
          maxlength="5000"
          :placeholder="textoBase"
        />
        <p class="text-xs mt-1" :style="{ color: 'var(--texto-suave)' }">
          {{ $t("familias.config.ayudaTexto") }}
          {{ $t("familias.config.version", { n: version }) }}
        </p>
      </div>
    </template>
    <AvisoTono v-if="error" tono="peligro" role="alert">
      {{ error }}
      <template v-if="cargaFallida" #acciones>
        <button
          type="button"
          class="tu-btn tu-btn-contorno tu-tono-peligro tu-btn-chico"
          data-prueba="reintentar-familias"
          @click="cargar"
        >
          {{ $t("comun.reintentar") }}
        </button>
      </template>
    </AvisoTono>
    <div class="flex justify-end gap-2">
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        :disabled="guardando"
        @click="emit('cerrar')"
      >
        {{ $t("comun.cancelar") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-primario"
        :disabled="cargando || guardando || cargaFallida"
        data-prueba="guardar-familias"
        @click="guardar"
      >
        {{ $t("familias.config.guardar") }}
      </button>
    </div>
  </div>
</template>
