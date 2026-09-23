<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRoute, useRouter } from "vue-router";

import CampoContrasena from "@/components/CampoContrasena.vue";
import { trackEvent } from "@/lib/analytics";
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
    trackEvent("tenant_activated");
    void router.push({ name: "onboarding" });
  } catch {
    // El error queda en sesion.error.
  }
}
</script>

<template>
  <section class="mx-auto max-w-md px-4 py-10">
    <h1 class="text-xl font-semibold">{{ $t("activacion.titulo") }}</h1>
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

      <p
        v-if="errorLocal ?? sesion.error"
        class="text-sm"
        style="color: var(--error)"
      >
        {{ errorLocal ?? sesion.error }}
      </p>

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
