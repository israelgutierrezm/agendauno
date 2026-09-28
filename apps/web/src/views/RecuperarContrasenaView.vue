<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute, useRouter } from "vue-router";

import CampoContrasena from "@/components/CampoContrasena.vue";
import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Recuperar la contraseña, en dos pasos: pedir el enlace por correo
 * (/recuperar/:slug) y, desde ese enlace, elegir la nueva (/restablecer/:slug con
 * email y token). Al guardarla la sesión queda iniciada.
 */
const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const sesion = useSesionTenantStore();

const esRestablecer = computed(() => route.name === "restablecer-contrasena");
const slug = ref(String(route.params.slug ?? ""));
const email = ref(String(route.query.email ?? ""));
const token = String(route.query.token ?? "");

// ---- Pedir el enlace ----
const enviando = ref(false);
const enviado = ref(false);
const error = ref<string | null>(null);

async function pedirEnlace(): Promise<void> {
  enviando.value = true;
  error.value = null;
  try {
    await api.post(`/api/v1/app/${slug.value.trim()}/recuperar-contrasena`, {
      email: email.value.trim(),
    });
    enviado.value = true;
  } catch (e) {
    error.value = mensajeDeError(e);
  } finally {
    enviando.value = false;
  }
}

// ---- Elegir la contraseña nueva ----
const password = ref("");
const passwordConfirm = ref("");
const enlaceCompleto = computed(
  () => slug.value !== "" && email.value !== "" && token !== "",
);

async function guardar(): Promise<void> {
  error.value = null;
  if (password.value !== passwordConfirm.value) {
    error.value = t("validacion.contrasenasNoCoinciden");
    return;
  }
  try {
    await sesion.restablecerContrasena(
      slug.value,
      email.value,
      token,
      password.value,
      passwordConfirm.value,
    );
    void router.push({ name: sesion.destinoAlEntrar });
  } catch {
    error.value = sesion.error;
  }
}
</script>

<template>
  <section class="tu-public-access">
    <p class="tu-public-eyebrow">Recupera tu acceso</p>
    <!-- Paso 2: desde el enlace del correo -->
    <template v-if="esRestablecer">
      <h1 class="tu-public-form-title">
        {{ $t("recuperarContrasena.nuevaTitulo") }}
      </h1>
      <p
        v-if="enlaceCompleto"
        class="mt-1"
        :style="{ color: 'var(--texto-suave)' }"
      >
        {{ $t("recuperarContrasena.nuevaSubtitulo", { email }) }}
      </p>

      <div v-if="!enlaceCompleto" class="mt-6 tu-card p-6 text-sm">
        <p>{{ $t("recuperarContrasena.sinEnlace") }}</p>
        <RouterLink
          class="tu-enlace mt-3 inline-block"
          :to="{ name: 'recuperar-contrasena', params: { slug } }"
        >
          {{ $t("recuperarContrasena.pedirOtro") }}
        </RouterLink>
      </div>

      <form v-else class="mt-6 tu-card p-6 space-y-4" @submit.prevent="guardar">
        <div>
          <label class="tu-label" for="rc-pass">{{
            $t("recuperarContrasena.nueva")
          }}</label>
          <CampoContrasena
            id="rc-pass"
            v-model="password"
            autocomplete="new-password"
            :required="true"
            :minlength="8"
          />
        </div>
        <div>
          <label class="tu-label" for="rc-pass2">{{
            $t("recuperarContrasena.confirmar")
          }}</label>
          <CampoContrasena
            id="rc-pass2"
            v-model="passwordConfirm"
            autocomplete="new-password"
            :required="true"
            :minlength="8"
          />
        </div>
        <p
          v-if="error"
          class="text-sm"
          role="alert"
          style="color: var(--error)"
        >
          {{ error }}
          <RouterLink
            class="tu-enlace ml-1"
            :to="{
              name: 'recuperar-contrasena',
              params: { slug },
              query: { email },
            }"
          >
            {{ $t("recuperarContrasena.pedirOtro") }}
          </RouterLink>
        </p>
        <button
          class="tu-btn tu-btn-primario w-full"
          type="submit"
          :disabled="sesion.cargando"
        >
          {{
            sesion.cargando
              ? $t("recuperarContrasena.guardando")
              : $t("recuperarContrasena.guardar")
          }}
        </button>
      </form>
    </template>

    <!-- Paso 1: pedir el enlace -->
    <template v-else>
      <h1 class="tu-public-form-title">
        {{ $t("recuperarContrasena.titulo") }}
      </h1>
      <p class="mt-1" :style="{ color: 'var(--texto-suave)' }">
        {{ $t("recuperarContrasena.subtitulo") }}
      </p>

      <div
        v-if="enviado"
        class="mt-6 tu-card p-6 text-sm"
        role="status"
        :style="{ color: 'var(--texto)' }"
      >
        {{ $t("recuperarContrasena.enviado") }}
      </div>

      <form
        v-else
        class="mt-6 tu-card p-6 space-y-4"
        @submit.prevent="pedirEnlace"
      >
        <div v-if="route.params.slug === undefined || route.params.slug === ''">
          <label class="tu-label" for="rc-slug">{{
            $t("recuperarContrasena.negocio")
          }}</label>
          <input
            id="rc-slug"
            v-model="slug"
            class="tu-input"
            :placeholder="$t('recuperarContrasena.negocioPh')"
            autocapitalize="off"
            required
          />
        </div>
        <div>
          <label class="tu-label" for="rc-email">{{
            $t("recuperarContrasena.email")
          }}</label>
          <input
            id="rc-email"
            v-model="email"
            class="tu-input"
            type="email"
            autocomplete="email"
            required
          />
        </div>
        <p
          v-if="error"
          class="text-sm"
          role="alert"
          style="color: var(--error)"
        >
          {{ error }}
        </p>
        <button
          class="tu-btn tu-btn-primario w-full"
          type="submit"
          :disabled="enviando"
        >
          {{
            enviando
              ? $t("recuperarContrasena.enviando")
              : $t("recuperarContrasena.enviar")
          }}
        </button>
      </form>

      <RouterLink
        class="tu-enlace mt-4 inline-block text-sm"
        :to="{
          name: 'entrar',
          query: slug ? { estudio: slug } : {},
        }"
      >
        {{ $t("recuperarContrasena.volver") }}
      </RouterLink>
    </template>
  </section>
</template>
