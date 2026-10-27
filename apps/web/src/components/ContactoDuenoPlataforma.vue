<script setup lang="ts">
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";

import { erroresPorCampo, mensajeDeError } from "@/lib/api";
import { crearClientePlataforma } from "@/lib/clientePlataforma";
import { useToastStore } from "@/stores/toast";

/**
 * Corregir el contacto del dueño desde su ficha (ADR 0226): nombre, correo y WhatsApp.
 * El correo con que entra solo cambia antes de que active su cuenta; entonces le llega
 * una activación nueva (lo valida el servidor).
 */
export interface CamposContacto {
  contacto_nombre: string | null;
  contacto_segundo_nombre: string | null;
  contacto_primer_apellido: string | null;
  contacto_segundo_apellido: string | null;
  contacto_email: string | null;
  contacto_whatsapp_pais: string | null;
  contacto_telefono: string | null;
}

const props = defineProps<{
  apiUrl: string;
  token: string;
  slug: string;
  campos: CamposContacto;
}>();
const emit = defineEmits<{ guardado: [] }>();

const { t } = useI18n();
const cliente = crearClientePlataforma({});
const toast = useToastStore();
const abierto = ref(false);
const ocupado = ref(false);
const errores = ref<Record<string, string>>({});

const vacio = (c: CamposContacto) => ({
  contacto_nombre: c.contacto_nombre ?? "",
  contacto_segundo_nombre: c.contacto_segundo_nombre ?? "",
  contacto_primer_apellido: c.contacto_primer_apellido ?? "",
  contacto_segundo_apellido: c.contacto_segundo_apellido ?? "",
  contacto_email: c.contacto_email ?? "",
  contacto_whatsapp_pais: c.contacto_whatsapp_pais ?? "52",
  contacto_telefono: c.contacto_telefono ?? "",
});
const form = ref(vacio(props.campos));
watch(
  () => props.campos,
  (c) => {
    form.value = vacio(c);
  },
);

async function guardar(): Promise<void> {
  if (ocupado.value) return;
  ocupado.value = true;
  errores.value = {};
  try {
    await cliente.put(
      `${props.apiUrl}/api/v1/plataforma/estudios/${props.slug}/contacto`,
      {
        ...form.value,
        contacto_segundo_nombre: form.value.contacto_segundo_nombre || null,
        contacto_segundo_apellido: form.value.contacto_segundo_apellido || null,
      },
      {
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${props.token}`,
        },
      },
    );
    toast.exito(t("plataformaAdmin.contacto.guardado"));
    abierto.value = false;
    emit("guardado");
  } catch (e) {
    errores.value = erroresPorCampo(e);
    if (Object.keys(errores.value).length === 0) toast.error(mensajeDeError(e));
  } finally {
    ocupado.value = false;
  }
}
</script>

<template>
  <div data-prueba="contacto-dueno">
    <button
      v-if="!abierto"
      type="button"
      class="tu-btn tu-btn-secundario tu-btn-chico mt-2"
      data-prueba="abrir-contacto"
      @click="abierto = true"
    >
      {{ $t("plataformaAdmin.contacto.corregir") }}
    </button>
    <form
      v-else
      class="mt-2 grid gap-2 sm:grid-cols-2"
      @submit.prevent="guardar"
    >
      <label class="grid gap-1">
        <span class="tu-label">{{
          $t("plataformaAdmin.contacto.nombre")
        }}</span>
        <input
          v-model="form.contacto_nombre"
          class="tu-input text-sm"
          maxlength="120"
          required
        />
      </label>
      <label class="grid gap-1">
        <span class="tu-label">{{
          $t("plataformaAdmin.contacto.primerApellido")
        }}</span>
        <input
          v-model="form.contacto_primer_apellido"
          class="tu-input text-sm"
          maxlength="120"
          required
        />
      </label>
      <label class="grid gap-1 sm:col-span-2">
        <span class="tu-label">{{
          $t("plataformaAdmin.contacto.correo")
        }}</span>
        <input
          v-model="form.contacto_email"
          type="email"
          class="tu-input text-sm"
          maxlength="255"
          required
          :aria-invalid="errores.contacto_email ? true : undefined"
        />
        <span
          v-if="errores.contacto_email"
          class="tu-error-campo"
          role="alert"
          >{{ errores.contacto_email }}</span
        >
        <span class="tu-hint">{{
          $t("plataformaAdmin.contacto.correoAyuda")
        }}</span>
      </label>
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("plataformaAdmin.contacto.lada") }}</span>
        <input
          v-model="form.contacto_whatsapp_pais"
          class="tu-input text-sm"
          inputmode="numeric"
          maxlength="4"
          required
        />
      </label>
      <label class="grid gap-1">
        <span class="tu-label">{{
          $t("plataformaAdmin.contacto.whatsapp")
        }}</span>
        <input
          v-model="form.contacto_telefono"
          class="tu-input text-sm"
          inputmode="tel"
          maxlength="15"
          required
        />
      </label>
      <div class="flex flex-wrap gap-2 sm:col-span-2">
        <button
          type="submit"
          class="tu-btn tu-btn-primario tu-btn-chico"
          :disabled="ocupado"
          data-prueba="guardar-contacto"
        >
          {{ $t("plataformaAdmin.contacto.guardar") }}
        </button>
        <button
          type="button"
          class="tu-btn tu-btn-secundario tu-btn-chico"
          :disabled="ocupado"
          @click="abierto = false"
        >
          {{ $t("comun.cancelar") }}
        </button>
      </div>
    </form>
  </div>
</template>
