<script setup lang="ts">
import axios from "axios";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import { useToastStore } from "@/stores/toast";

/**
 * En la ficha de un negocio, para el superadmin: sus avisos por WhatsApp a sus
 * clientes (ADR 0083). Los paga la plataforma, así que solo el superadmin los activa;
 * el negocio no puede. Activados, el negocio elige qué avisos manda. Además tiene que
 * estar encendido «De los negocios a sus clientes» en Configuración → WhatsApp.
 */
export interface EstadoWhatsAppNegocio {
  habilitado: boolean;
  plataforma: boolean;
}

const props = defineProps<{
  apiUrl: string;
  token: string;
  slug: string;
  nombre: string;
  inicial: EstadoWhatsAppNegocio;
}>();

const { t } = useI18n();
const toast = useToastStore();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});

const estado = ref<EstadoWhatsAppNegocio>({ ...props.inicial });
const guardando = ref(false);

const resumen = computed<{ texto: string; activo: boolean }>(() => {
  if (!estado.value.habilitado) {
    return {
      texto: t("plataformaAdmin.ficha.whatsappInactivo"),
      activo: false,
    };
  }
  return estado.value.plataforma
    ? { texto: t("plataformaAdmin.ficha.whatsappActivo"), activo: true }
    : {
        texto: t("plataformaAdmin.ficha.whatsappSinPlataforma"),
        activo: false,
      };
});

async function cambiar(): Promise<void> {
  const habilitado = !estado.value.habilitado;
  if (
    habilitado &&
    !(await confirmar(
      t("plataformaAdmin.ficha.confirmarWhatsApp", { estudio: props.nombre }),
    ))
  ) {
    return;
  }
  guardando.value = true;
  try {
    const { data } = await cliente.put<{ data: EstadoWhatsAppNegocio }>(
      `/api/v1/plataforma/estudios/${props.slug}/whatsapp`,
      { habilitado },
      { headers: { Authorization: `Bearer ${props.token}` } },
    );
    estado.value = data.data;
    toast.exito(
      t(
        habilitado
          ? "plataformaAdmin.ficha.whatsappActivado"
          : "plataformaAdmin.ficha.whatsappDesactivado",
      ),
    );
  } catch (e) {
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <section data-prueba="whatsapp-negocio">
    <h3
      class="text-xs font-medium uppercase tracking-wide"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("plataformaAdmin.ficha.whatsappClientes") }}
    </h3>
    <div class="mt-2 flex items-center justify-between gap-3">
      <span class="wn-estado text-sm" data-prueba="estado">
        <span
          class="wn-punto"
          :class="resumen.activo ? 'wn-exito' : 'wn-gris'"
          aria-hidden="true"
        ></span>
        {{ resumen.texto }}
      </span>
      <button
        class="tu-btn tu-btn-fantasma text-sm"
        type="button"
        data-prueba="cambiar"
        :disabled="guardando"
        @click="cambiar"
      >
        {{
          estado.habilitado
            ? $t("plataformaAdmin.ficha.whatsappDesactivar")
            : $t("plataformaAdmin.ficha.whatsappActivar")
        }}
      </button>
    </div>
    <p class="mt-1 text-xs" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("plataformaAdmin.ficha.whatsappAyuda") }}
      <template v-if="!estado.plataforma">
        {{ $t("plataformaAdmin.ficha.whatsappAyudaPlataforma") }}
      </template>
    </p>
  </section>
</template>

<style scoped>
.wn-estado {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}
.wn-punto {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  flex-shrink: 0;
}
.wn-exito {
  background: var(--exito);
}
.wn-gris {
  background: var(--texto-suave);
}
</style>
