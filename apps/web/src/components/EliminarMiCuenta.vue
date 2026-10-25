<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRouter } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import CampoContrasena from "@/components/CampoContrasena.vue";
import ModalDialogo from "@/components/ModalDialogo.vue";
import { mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";
import { useToastStore } from "@/stores/toast";

/**
 * Eliminar la cuenta propia (ADR 0211): para cualquier rol, confirmando con la
 * contraseña. El único dueño recibe el aviso de dar de baja el negocio antes.
 */
const { t } = useI18n();
const router = useRouter();
const sesion = useSesionTenantStore();
const toast = useToastStore();

const abierto = ref(false);
const contrasena = ref("");
const motivo = ref("");
const ocupado = ref(false);
const error = ref<string | null>(null);

function cerrar(): void {
  if (ocupado.value) return;
  abierto.value = false;
  contrasena.value = "";
  motivo.value = "";
  error.value = null;
}

async function eliminar(): Promise<void> {
  if (contrasena.value === "" || ocupado.value) return;
  ocupado.value = true;
  error.value = null;
  try {
    const mensaje = await sesion.eliminarCuenta(
      contrasena.value,
      motivo.value.trim() || null,
    );
    abierto.value = false;
    toast.exito(mensaje || t("eliminarCuenta.eliminada"));
    await router.replace({ name: "entrar" });
  } catch (e) {
    // Contraseña incorrecta, sin contraseña o el único dueño: se dice aquí.
    error.value = mensajeDeError(e);
  } finally {
    ocupado.value = false;
  }
}
</script>

<template>
  <div class="emc">
    <div>
      <h3 class="emc-titulo">{{ $t("eliminarCuenta.titulo") }}</h3>
      <p class="emc-ayuda">{{ $t("eliminarCuenta.ayuda") }}</p>
    </div>
    <button
      type="button"
      class="tu-btn tu-btn-peligro"
      data-prueba="abrir-eliminar-cuenta"
      @click="abierto = true"
    >
      {{ $t("eliminarCuenta.abrir") }}
    </button>
    <ModalDialogo
      :abierto="abierto"
      :titulo="$t('eliminarCuenta.confirmarTitulo')"
      :ocupado="ocupado"
      @cerrar="cerrar"
    >
      <form
        id="emc-form"
        class="space-y-3"
        data-prueba="eliminar-cuenta"
        @submit.prevent="eliminar"
      >
        <p class="text-sm">
          {{
            $t("eliminarCuenta.confirmarTexto", {
              negocio: sesion.estudio?.nombre ?? "",
            })
          }}
        </p>
        <div>
          <label class="tu-label" for="emc-contrasena">{{
            $t("eliminarCuenta.contrasena")
          }}</label>
          <CampoContrasena
            id="emc-contrasena"
            v-model="contrasena"
            autocomplete="current-password"
          />
        </div>
        <div>
          <label class="tu-label" for="emc-motivo">{{
            $t("eliminarCuenta.motivo")
          }}</label>
          <textarea
            id="emc-motivo"
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
          data-prueba="error-eliminar-cuenta"
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
          {{ $t("eliminarCuenta.cancelar") }}
        </button>
        <button
          type="submit"
          form="emc-form"
          class="tu-btn tu-btn-peligro"
          :disabled="ocupado || contrasena === ''"
          data-prueba="confirmar-eliminar-cuenta"
        >
          {{ $t("eliminarCuenta.confirmar") }}
        </button>
      </template>
    </ModalDialogo>
  </div>
</template>

<style scoped>
.emc {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}
.emc-titulo {
  font-weight: 600;
}
.emc-ayuda {
  max-width: 40rem;
  margin-top: 0.25rem;
  color: var(--texto-suave);
  font-size: 0.875rem;
  line-height: 1.6;
}
</style>
