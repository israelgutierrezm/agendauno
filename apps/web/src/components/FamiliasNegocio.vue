<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";

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

const emit = defineEmits<{ guardado: []; cerrar: [] }>();
const { t } = useI18n();
const sesion = useSesionTenantStore();
const toast = useToastStore();

const cargando = ref(true);
const guardando = ref(false);
const error = ref<string | null>(null);
const activo = ref(false);
const texto = ref("");
const version = ref(1);
const textoBase = ref("");

onMounted(async () => {
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
  } finally {
    cargando.value = false;
  }
});

async function guardar(): Promise<void> {
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
    <template v-else>
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
    <p v-if="error" class="text-sm" :style="{ color: 'var(--error)' }">
      {{ error }}
    </p>
    <div class="flex justify-end gap-2">
      <button
        type="button"
        class="tu-btn tu-btn-fantasma"
        @click="emit('cerrar')"
      >
        {{ $t("comun.cancelar") }}
      </button>
      <button
        type="button"
        class="tu-btn tu-btn-primario"
        :disabled="cargando || guardando"
        @click="guardar"
      >
        {{ $t("familias.config.guardar") }}
      </button>
    </div>
  </div>
</template>
