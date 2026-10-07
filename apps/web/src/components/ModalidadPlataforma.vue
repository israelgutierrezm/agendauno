<script setup lang="ts">
import axios from "axios";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";

import { mensajeDeError } from "@/lib/api";
import { confirmar } from "@/lib/confirmar";
import type { ModalidadServicio } from "@/lib/modalidad";
import { useToastStore } from "@/stores/toast";

/**
 * En la ficha de un negocio, para el superadmin: su modalidad, solo clases o solo
 * citas (ADR 0104). El negocio no la cambia; AgendaUno sí, y solo mientras no tenga
 * sesiones ni reservas (como la moneda antes de cobrar). Si ya opera, se explica.
 */
const props = defineProps<{
  apiUrl: string;
  token: string;
  slug: string;
  nombre: string;
  modalidad: ModalidadServicio;
  // Null: el servidor no lo dijo; solo se muestra la modalidad.
  cambiable: boolean | null;
}>();

const emit = defineEmits<{ cambiada: [modalidad: ModalidadServicio] }>();

const { t } = useI18n();
const toast = useToastStore();
const cliente = axios.create({
  baseURL: props.apiUrl,
  headers: { Accept: "application/json" },
});

const guardando = ref(false);
const otra = computed<ModalidadServicio>(() =>
  props.modalidad === "citas" ? "clases" : "citas",
);

async function cambiar(): Promise<void> {
  const nueva = otra.value;
  if (
    !(await confirmar(
      t("modalidadNegocio.plataforma.confirmar", {
        estudio: props.nombre,
        modalidad: t(`modalidadNegocio.nombres.${nueva}`),
      }),
      { aceptar: t("modalidadNegocio.plataforma.aceptar"), peligro: true },
    ))
  ) {
    return;
  }
  guardando.value = true;
  try {
    await cliente.put(
      `/api/v1/plataforma/estudios/${props.slug}/modalidad`,
      { modalidad: nueva },
      { headers: { Authorization: `Bearer ${props.token}` } },
    );
    toast.exito(t("modalidadNegocio.plataforma.cambiada"));
    emit("cambiada", nueva);
  } catch (e) {
    // MODALITY_IN_USE: ya tiene sesiones o reservas (el mensaje del servidor).
    toast.error(mensajeDeError(e));
  } finally {
    guardando.value = false;
  }
}
</script>

<template>
  <section data-prueba="modalidad-negocio">
    <h3
      class="text-xs font-medium uppercase tracking-wide"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ $t("modalidadNegocio.plataforma.etiqueta") }}
    </h3>
    <div class="mt-2 flex items-center justify-between gap-3">
      <span class="text-sm font-medium" data-prueba="modalidad">{{
        $t(`modalidadNegocio.nombres.${modalidad}`)
      }}</span>
      <button
        v-if="cambiable === true"
        class="tu-btn tu-btn-fantasma text-sm"
        type="button"
        data-prueba="cambiar-modalidad"
        :disabled="guardando"
        @click="cambiar"
      >
        {{
          $t("modalidadNegocio.plataforma.cambiar", {
            modalidad: $t(`modalidadNegocio.nombres.${otra}`),
          })
        }}
      </button>
    </div>
    <p
      v-if="cambiable !== null"
      class="mt-1 text-xs"
      :style="{ color: 'var(--texto-suave)' }"
      data-prueba="modalidad-ayuda"
    >
      {{
        cambiable
          ? $t("modalidadNegocio.plataforma.ayuda")
          : $t("modalidadNegocio.plataforma.yaOpera")
      }}
    </p>
  </section>
</template>
