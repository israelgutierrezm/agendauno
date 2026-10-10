<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink } from "vue-router";

import { trackEvent } from "@/lib/analytics";
import { api, camposConError, mensajeDeError } from "@/lib/api";
import { PRODUCTOS, type Producto, productoActual } from "@/lib/producto";
import { tokenRecaptcha } from "@/lib/recaptcha";
import { PERFILES_POR_MODO } from "@/marketing/modalidades";

/**
 * Lista de interesados de un producto que aún no recibe registros (ADR 0108: TurnoUno
 * antes de su lanzamiento). Deja nombre, correo y, si quiere, su negocio, y el
 * superadmin le avisa al abrir. Con captcha y aceptación del aviso de privacidad.
 */
const props = withDefaults(defineProps<{ producto?: Producto }>(), {
  producto: undefined,
});

const { t } = useI18n();
const producto = computed(() => props.producto ?? productoActual());
const marca = computed(() => PRODUCTOS[producto.value].nombre);
const giros = computed(
  () => PERFILES_POR_MODO[PRODUCTOS[producto.value].modalidad],
);

const datos = reactive({
  nombre: "",
  correo: "",
  telefono: "",
  negocio: "",
  giro: "",
  ciudad: "",
  acepta_aviso: false,
});
const enviando = ref(false);
const listo = ref(false);
const error = ref<string | null>(null);
// Campos que el servidor rechazó (el motivo va en `error`).
const errores = ref<string[]>([]);

async function enviar(): Promise<void> {
  enviando.value = true;
  error.value = null;
  errores.value = [];
  try {
    await api.post("/api/v1/interesados", {
      producto: producto.value,
      nombre: datos.nombre,
      correo: datos.correo,
      telefono: datos.telefono || null,
      negocio: datos.negocio || null,
      giro: datos.giro || null,
      ciudad: datos.ciudad || null,
      acepta_aviso: datos.acepta_aviso,
      recaptcha_token: await tokenRecaptcha("interesados"),
    });
    listo.value = true;
    trackEvent("waitlist_joined", { product: producto.value });
  } catch (e) {
    errores.value = camposConError(e);
    error.value = mensajeDeError(e);
  } finally {
    enviando.value = false;
  }
}
</script>

<template>
  <section class="tu-card p-6 sm:p-8" data-prueba="lista-interesados">
    <template v-if="listo">
      <h2 class="text-xl font-medium">
        {{ t("interesados.listoTitulo") }}
      </h2>
      <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
        {{ t("interesados.listoTexto", { marca }) }}
      </p>
    </template>
    <form v-else class="space-y-4" novalidate @submit.prevent="enviar">
      <div>
        <h2 class="text-xl font-medium">
          {{ t("interesados.titulo", { marca }) }}
        </h2>
        <p class="mt-2" :style="{ color: 'var(--texto-suave)' }">
          {{ t("interesados.texto", { marca }) }}
        </p>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block">
          <span class="tu-label">{{ t("interesados.nombre") }}</span>
          <input
            v-model="datos.nombre"
            class="tu-input"
            :aria-invalid="errores.includes('nombre')"
            autocomplete="name"
            maxlength="120"
            required
          />
        </label>
        <label class="block">
          <span class="tu-label">{{ t("interesados.correo") }}</span>
          <input
            v-model="datos.correo"
            class="tu-input"
            :aria-invalid="errores.includes('correo')"
            type="email"
            autocomplete="email"
            maxlength="190"
            required
          />
        </label>
        <label class="block">
          <span class="tu-label">{{ t("interesados.negocio") }}</span>
          <input
            v-model="datos.negocio"
            class="tu-input"
            autocomplete="organization"
            maxlength="120"
          />
        </label>
        <label class="block">
          <span class="tu-label">{{ t("interesados.giro") }}</span>
          <select v-model="datos.giro" class="tu-input">
            <option value="">{{ t("interesados.giroSin") }}</option>
            <option v-for="g in giros" :key="g" :value="g">
              {{ t(`registro.perfiles.${g}`) }}
            </option>
          </select>
        </label>
        <label class="block">
          <span class="tu-label">{{ t("interesados.ciudad") }}</span>
          <input
            v-model="datos.ciudad"
            class="tu-input"
            autocomplete="address-level2"
            maxlength="120"
          />
        </label>
        <label class="block">
          <span class="tu-label">{{ t("interesados.telefono") }}</span>
          <input
            v-model="datos.telefono"
            class="tu-input"
            type="tel"
            autocomplete="tel"
            maxlength="30"
          />
        </label>
      </div>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="datos.acepta_aviso" type="checkbox" class="mt-1" />
        <span>
          {{ t("interesados.aviso") }}
          <RouterLink class="tu-enlace" to="/aviso-de-privacidad">{{
            t("interesados.avisoEnlace")
          }}</RouterLink>
        </span>
      </label>
      <p v-if="error" class="text-sm" style="color: var(--error)" role="alert">
        {{ error }}
      </p>
      <button
        class="tu-btn tu-btn-primario"
        type="submit"
        :disabled="enviando || !datos.acepta_aviso"
      >
        {{ enviando ? t("interesados.enviando") : t("interesados.enviar") }}
      </button>
    </form>
  </section>
</template>
