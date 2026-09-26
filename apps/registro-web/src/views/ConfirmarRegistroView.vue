<script setup lang="ts">
import { onMounted, ref } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Confirma un registro desde el enlace del correo
 * (/confirmar-registro/:slug?email&token): liga la cuenta a su historial en el
 * negocio y entra a su cuenta.
 */
const route = useRoute();
const router = useRouter();
const sesion = useSesionTenantStore();

const slug = String(route.params.slug ?? "");
const email = String(route.query.email ?? "");
const token = String(route.query.token ?? "");

const error = ref<string | null>(null);
const confirmando = ref(true);

onMounted(async () => {
  if (slug === "" || email === "" || token === "") {
    confirmando.value = false;
    return;
  }
  try {
    await sesion.confirmarRegistro(slug, email, token);
    void router.replace({ name: sesion.rutaInicio });
  } catch (e) {
    error.value = mensajeDeError(e);
    confirmando.value = false;
  }
});
</script>

<template>
  <section class="tu-public-access">
    <h1 class="tu-public-form-title">{{ $t("confirmarRegistro.titulo") }}</h1>

    <div class="mt-6 tu-card p-6 text-sm">
      <p v-if="confirmando" role="status">
        {{ $t("confirmarRegistro.confirmando") }}
      </p>
      <template v-else>
        <p role="alert" style="color: var(--error)">
          {{ error ?? $t("confirmarRegistro.sinEnlace") }}
        </p>
        <RouterLink
          v-if="slug !== ''"
          class="tu-btn tu-btn-fantasma mt-4 inline-flex"
          :to="{ path: `/estudio/${slug}` }"
        >
          {{ $t("confirmarRegistro.volverARegistrarte") }}
        </RouterLink>
      </template>
    </div>
  </section>
</template>
