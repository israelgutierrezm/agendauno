<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";

import AvisoTono from "@/components/AvisoTono.vue";
import CampoContrasena from "@/components/CampoContrasena.vue";
import { trackEvent } from "@/lib/analytics";
import { api, mensajeDeError } from "@/lib/api";
import { facetaActiva } from "@/lib/roles";
import { useSesionTenantStore } from "@/stores/sesionTenant";

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const sesion = useSesionTenantStore();

const slug = ref(String(route.params.slug ?? ""));
const email = ref(String(route.query.email ?? ""));
const token = ref(String(route.query.token ?? ""));
const password = ref("");
const passwordConfirm = ref("");
const errorLocal = ref<string | null>(null);
// El enlace no sirvió (venció o es de otro envío): se puede pedir uno nuevo al mismo
// correo (ADR 0169). La respuesta no dice si la cuenta existe.
const fallo = ref(false);
const reenviando = ref(false);
const reenviado = ref(false);

// Si llegó desde el enlace del correo trae slug+email+token en la URL: no se piden
// esos datos técnicos, solo definir la contraseña. `manual` los revela si hiciera falta.
const manual = ref(false);
const desdeCorreo = computed(
  () =>
    !manual.value &&
    slug.value !== "" &&
    email.value !== "" &&
    token.value !== "",
);

async function enviar(): Promise<void> {
  errorLocal.value = null;
  reenviado.value = false;
  if (password.value !== passwordConfirm.value) {
    errorLocal.value = t("validacion.contrasenasNoCoinciden");
    return;
  }
  try {
    await sesion.activar(
      slug.value,
      email.value,
      token.value,
      password.value,
      passwordConfirm.value,
    );
    // Solo la cuenta de quien registró el negocio es un negocio activado: el equipo o
    // un cliente invitados activan su cuenta, no un negocio (no inflan las altas).
    const usuario = sesion.usuario;
    if ((usuario?.roles ?? [usuario?.rol]).includes("propietario")) {
      trackEvent("tenant_activated");
    } else {
      trackEvent("account_activated", {
        faceta: facetaActiva(usuario) ?? "equipo",
      });
    }
    void router.push({ name: "onboarding" });
  } catch {
    // El error queda en sesion.error.
    fallo.value = true;
  }
}

async function pedirOtro(): Promise<void> {
  reenviando.value = true;
  errorLocal.value = null;
  try {
    await api.post(
      `/api/v1/app/${encodeURIComponent(slug.value)}/reenviar-activacion`,
      { email: email.value },
    );
    reenviado.value = true;
    fallo.value = false;
  } catch (e) {
    errorLocal.value = mensajeDeError(e);
  } finally {
    reenviando.value = false;
  }
}
</script>

<template>
  <section class="tu-public-access">
    <p class="tu-public-eyebrow">Activa tu cuenta</p>
    <h1 class="tu-public-form-title">{{ $t("activacion.titulo") }}</h1>
    <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
      {{ $t("activacion.subtitulo", { estudio: slug }) }}
    </p>

    <form class="mt-6 tu-card p-6 space-y-4" @submit.prevent="enviar">
      <!-- Llegó desde el enlace del correo: confirma la cuenta y solo pide contraseña. -->
      <div
        v-if="desdeCorreo"
        class="rounded-lg p-3 text-sm"
        :style="{
          background: 'var(--primario-suave)',
          color: 'var(--primario-fuerte)',
        }"
      >
        {{ $t("activacion.desdeCorreo", { email }) }}
        <button type="button" class="tu-enlace ml-1" @click="manual = true">
          {{ $t("activacion.otrosDatos") }}
        </button>
      </div>

      <template v-if="!desdeCorreo">
        <div>
          <label class="tu-label" for="slug">{{ $t("entrar.slug") }}</label>
          <input id="slug" v-model="slug" class="tu-input" required />
        </div>
        <div>
          <label class="tu-label" for="email">{{
            $t("activacion.email")
          }}</label>
          <input
            id="email"
            v-model="email"
            class="tu-input"
            type="email"
            required
          />
        </div>
        <div>
          <label class="tu-label" for="token">{{
            $t("activacion.token")
          }}</label>
          <input id="token" v-model="token" class="tu-input" required />
        </div>
      </template>
      <div>
        <label class="tu-label" for="pass">{{
          $t("activacion.password")
        }}</label>
        <CampoContrasena
          id="pass"
          v-model="password"
          autocomplete="new-password"
          :required="true"
          :minlength="8"
        />
      </div>
      <div>
        <label class="tu-label" for="pass2">{{
          $t("activacion.passwordConfirm")
        }}</label>
        <CampoContrasena
          id="pass2"
          v-model="passwordConfirm"
          autocomplete="new-password"
          :required="true"
          :minlength="8"
        />
      </div>

      <AvisoTono
        v-if="reenviado"
        tono="exito"
        role="status"
        data-prueba="activacion-reenviada"
      >
        {{ $t("activacion.reenviado", { email }) }}
      </AvisoTono>
      <AvisoTono
        v-else-if="errorLocal ?? sesion.error"
        tono="peligro"
        role="alert"
      >
        {{ errorLocal ?? sesion.error }}
        <button
          v-if="fallo && slug !== '' && email !== ''"
          type="button"
          class="tu-enlace ml-1"
          :disabled="reenviando"
          data-prueba="activacion-pedir-otro"
          @click="pedirOtro"
        >
          {{
            reenviando
              ? $t("activacion.pidiendoOtro")
              : $t("activacion.pedirOtro")
          }}
        </button>
      </AvisoTono>

      <button
        class="tu-btn tu-btn-primario w-full"
        type="submit"
        :disabled="sesion.cargando"
      >
        {{
          sesion.cargando
            ? $t("activacion.activando")
            : $t("activacion.activar")
        }}
      </button>
    </form>
  </section>
</template>
