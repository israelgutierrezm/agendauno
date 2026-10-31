<script setup lang="ts">
import { ref } from "vue";
import { useI18n } from "vue-i18n";

import AvisoTono from "@/components/AvisoTono.vue";
import { erroresPorCampo, mensajeDeError } from "@/lib/api";
import { crearClientePlataforma } from "@/lib/clientePlataforma";
import { ladaDe, paisesOrdenados } from "@/lib/ladas";
import { PERFILES_POR_MODO } from "@/marketing/modalidades";
import { useToastStore } from "@/stores/toast";

/**
 * Dar de alta un negocio a nombre de su dueño (alta asistida, ADR 0226): también con el
 * registro cerrado. Su base se crea como en el registro y al dueño le llega su
 * activación; al entrar acepta el aviso y los términos.
 */
const props = defineProps<{ apiUrl: string; token: string }>();
const emit = defineEmits<{ creado: [slug: string] }>();

const { t } = useI18n();
const cliente = crearClientePlataforma({});
const toast = useToastStore();
const abierto = ref(false);
const ocupado = ref(false);
const errores = ref<Record<string, string>>({});
const errorGeneral = ref<string | null>(null);
const paises = paisesOrdenados();
const vacio = () => ({
  nombre: "",
  perfil_negocio: "",
  pais: "MX",
  contacto_nombre: "",
  contacto_primer_apellido: "",
  contacto_email: "",
  contacto_whatsapp_pais: "52",
  contacto_telefono: "",
});
const form = ref(vacio());

function cambiarPais(): void {
  form.value.contacto_whatsapp_pais =
    ladaDe(form.value.pais) ?? form.value.contacto_whatsapp_pais;
}

async function crear(): Promise<void> {
  if (ocupado.value) return;
  ocupado.value = true;
  errores.value = {};
  errorGeneral.value = null;
  try {
    const { data } = await cliente.post<{ data: { slug: string } }>(
      `${props.apiUrl}/api/v1/plataforma/estudios`,
      form.value,
      {
        headers: {
          Accept: "application/json",
          Authorization: `Bearer ${props.token}`,
        },
      },
    );
    toast.exito(
      t("plataformaAdmin.alta.creado", { correo: form.value.contacto_email }),
    );
    abierto.value = false;
    form.value = vacio();
    emit("creado", data.data.slug);
  } catch (e) {
    errores.value = erroresPorCampo(e);
    // Lo que no tiene campo a la vista (o no es de un campo) se dice en el formulario.
    const campos = Object.keys(errores.value);
    if (campos.length === 0 || campos.some((c) => !(c in form.value))) {
      errorGeneral.value = mensajeDeError(e);
    }
  } finally {
    ocupado.value = false;
  }
}
</script>

<template>
  <div data-prueba="alta-negocio">
    <button
      v-if="!abierto"
      type="button"
      class="tu-btn tu-btn-secundario tu-btn-chico"
      data-prueba="abrir-alta"
      @click="abierto = true"
    >
      {{ $t("plataformaAdmin.alta.abrir") }}
    </button>
    <form
      v-else
      class="tu-card mt-3 grid gap-3 p-4 sm:grid-cols-2"
      @submit.prevent="crear"
    >
      <h3 class="font-medium sm:col-span-2">
        {{ $t("plataformaAdmin.alta.titulo") }}
      </h3>
      <p class="tu-hint sm:col-span-2">
        {{ $t("plataformaAdmin.alta.ayuda") }}
      </p>
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("plataformaAdmin.alta.nombre") }}</span>
        <input
          v-model="form.nombre"
          class="tu-input text-sm"
          maxlength="255"
          required
        />
        <span v-if="errores.nombre" class="tu-error-campo" role="alert">{{
          errores.nombre
        }}</span>
      </label>
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("plataformaAdmin.alta.giro") }}</span>
        <select
          v-model="form.perfil_negocio"
          class="tu-input text-sm"
          required
          :aria-invalid="errores.perfil_negocio ? true : undefined"
        >
          <option value="" disabled>
            {{ $t("plataformaAdmin.alta.elegirGiro") }}
          </option>
          <optgroup :label="$t('plataformaAdmin.alta.clases')">
            <option v-for="p in PERFILES_POR_MODO.clases" :key="p" :value="p">
              {{ $t(`registro.perfiles.${p}`) }}
            </option>
          </optgroup>
          <optgroup :label="$t('plataformaAdmin.alta.citas')">
            <option v-for="p in PERFILES_POR_MODO.citas" :key="p" :value="p">
              {{ $t(`registro.perfiles.${p}`) }}
            </option>
          </optgroup>
        </select>
        <span
          v-if="errores.perfil_negocio"
          class="tu-error-campo"
          role="alert"
          >{{ errores.perfil_negocio }}</span
        >
      </label>
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
        <span
          v-if="errores.contacto_nombre"
          class="tu-error-campo"
          role="alert"
          >{{ errores.contacto_nombre }}</span
        >
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
        <span
          v-if="errores.contacto_primer_apellido"
          class="tu-error-campo"
          role="alert"
          >{{ errores.contacto_primer_apellido }}</span
        >
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
        />
        <span
          v-if="errores.contacto_email"
          class="tu-error-campo"
          role="alert"
          >{{ errores.contacto_email }}</span
        >
      </label>
      <label class="grid gap-1">
        <span class="tu-label">{{ $t("plataformaAdmin.alta.pais") }}</span>
        <select
          v-model="form.pais"
          class="tu-input text-sm"
          @change="cambiarPais"
        >
          <option v-for="p in paises" :key="p.codigo" :value="p.codigo">
            {{ p.nombre }}
          </option>
        </select>
        <span v-if="errores.pais" class="tu-error-campo" role="alert">{{
          errores.pais
        }}</span>
      </label>
      <label class="grid gap-1">
        <span class="tu-label">{{
          $t("plataformaAdmin.contacto.whatsapp")
        }}</span>
        <div class="flex gap-2">
          <input
            v-model="form.contacto_whatsapp_pais"
            class="tu-input w-20 text-sm"
            inputmode="numeric"
            maxlength="4"
            pattern="[0-9]{1,4}"
            :title="$t('plataformaAdmin.contacto.ladaFormato')"
            :aria-label="$t('plataformaAdmin.contacto.lada')"
            required
          />
          <input
            v-model="form.contacto_telefono"
            class="tu-input text-sm"
            inputmode="tel"
            maxlength="15"
            pattern="[0-9 \-]{7,15}"
            :title="$t('plataformaAdmin.contacto.telefonoFormato')"
            required
          />
        </div>
        <span class="tu-hint">{{
          $t("plataformaAdmin.contacto.telefonoFormato")
        }}</span>
        <span
          v-if="errores.contacto_whatsapp_pais"
          class="tu-error-campo"
          role="alert"
          >{{ errores.contacto_whatsapp_pais }}</span
        >
        <span
          v-if="errores.contacto_telefono"
          class="tu-error-campo"
          role="alert"
          >{{ errores.contacto_telefono }}</span
        >
      </label>
      <AvisoTono
        v-if="errorGeneral"
        tono="peligro"
        class="sm:col-span-2"
        role="alert"
        data-prueba="error-alta"
      >
        {{ errorGeneral }}
      </AvisoTono>
      <div class="flex flex-wrap gap-2 sm:col-span-2">
        <button
          type="submit"
          class="tu-btn tu-btn-primario tu-btn-chico"
          :disabled="ocupado"
          data-prueba="crear-negocio"
        >
          {{ $t("plataformaAdmin.alta.crear") }}
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
