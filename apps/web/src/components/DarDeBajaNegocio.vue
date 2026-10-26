<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import CampoContrasena from "@/components/CampoContrasena.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import { mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Dar de baja el negocio desde Mi suscripción (ADR 0213): solo su dueño, con su
 * contraseña y escribiendo la dirección del negocio. Antes dice qué pasa y en cuántos
 * días se borran los datos.
 */
const props = defineProps<{ dias: number }>();

const router = useRouter();
const sesion = useSesionTenantStore();

const abierto = ref(false);
const confirmacion = ref("");
const contrasena = ref("");
const motivo = ref("");
const ocupado = ref(false);
const error = ref<string | null>(null);

const slug = computed(() => sesion.estudio?.slug ?? "");
const listo = computed(
  () =>
    contrasena.value !== "" &&
    confirmacion.value.trim().toLowerCase() === slug.value.toLowerCase(),
);

function cerrar(): void {
  if (ocupado.value) return;
  abierto.value = false;
  confirmacion.value = "";
  contrasena.value = "";
  motivo.value = "";
  error.value = null;
}

async function darDeBaja(): Promise<void> {
  if (!listo.value || ocupado.value) return;
  ocupado.value = true;
  error.value = null;
  try {
    await sesion.darDeBajaNegocio(
      contrasena.value,
      confirmacion.value.trim(),
      motivo.value.trim() || null,
    );
    abierto.value = false;
    await router.replace({ name: "negocio-dado-de-baja" });
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}
</script>

<template>
  <div class="dbn" data-prueba="dar-de-baja-negocio">
    <div>
      <h3 class="dbn-titulo">{{ $t("bajaNegocio.titulo") }}</h3>
      <p class="dbn-ayuda">
        {{ $t("bajaNegocio.ayuda", { dias: props.dias }) }}
      </p>
    </div>
    <button
      type="button"
      class="tu-btn tu-btn-peligro"
      data-prueba="abrir-baja-negocio"
      @click="abierto = true"
    >
      {{ $t("bajaNegocio.abrir") }}
    </button>
    <ModalDialogo
      :abierto="abierto"
      :titulo="
        $t('bajaNegocio.confirmarTitulo', {
          negocio: sesion.estudio?.nombre ?? '',
        })
      "
      :ocupado="ocupado"
      @cerrar="cerrar"
    >
      <form
        id="dbn-form"
        class="space-y-3"
        data-prueba="form-baja-negocio"
        @submit.prevent="darDeBaja"
      >
        <p class="text-sm">
          {{ $t("bajaNegocio.consecuencias", { dias: props.dias }) }}
        </p>
        <div>
          <label class="tu-label" for="dbn-confirmacion">{{
            $t("bajaNegocio.escribeDireccion", { slug })
          }}</label>
          <input
            id="dbn-confirmacion"
            v-model="confirmacion"
            class="tu-input"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
          />
        </div>
        <div>
          <label class="tu-label" for="dbn-contrasena">{{
            $t("bajaNegocio.contrasena")
          }}</label>
          <CampoContrasena
            id="dbn-contrasena"
            v-model="contrasena"
            autocomplete="current-password"
          />
        </div>
        <div>
          <label class="tu-label" for="dbn-motivo">{{
            $t("bajaNegocio.motivo")
          }}</label>
          <textarea
            id="dbn-motivo"
            v-model="motivo"
            class="tu-input"
            rows="2"
            maxlength="500"
          />
        </div>
        <AvisoTono
          v-if="error"
          tono="peligro"
          role="alert"
          data-prueba="error-baja-negocio"
        >
          {{ error }}
        </AvisoTono>
      </form>
      <template #pie>
        <button
          type="button"
          class="tu-btn tu-btn-secundario"
          :disabled="ocupado"
          @click="cerrar"
        >
          {{ $t("bajaNegocio.cancelar") }}
        </button>
        <button
          type="submit"
          form="dbn-form"
          class="tu-btn tu-btn-peligro"
          :disabled="ocupado || !listo"
          data-prueba="confirmar-baja-negocio"
        >
          {{ $t("bajaNegocio.confirmar") }}
        </button>
      </template>
    </ModalDialogo>
  </div>
</template>

<style scoped>
.dbn {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}
.dbn-titulo {
  font-weight: 600;
}
.dbn-ayuda {
  max-width: 40rem;
  margin-top: 0.25rem;
  color: var(--texto-suave);
  font-size: 0.875rem;
  line-height: 1.6;
}
</style>
