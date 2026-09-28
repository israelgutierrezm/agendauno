<script setup lang="ts">
import { onMounted, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";

import { api, mensajeDeError } from "@/lib/api";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Confirma el correo nuevo desde el enlace que le llegó
 * (/confirmar-correo/:slug?token). Si hay una sesión abierta en ese negocio, se
 * refresca para que ya muestre el correo nuevo.
 */
const route = useRoute();
const sesion = useSesionTenantStore();

const slug = String(route.params.slug ?? "");
const token = String(route.query.token ?? "");

const estado = ref<"confirmando" | "listo" | "error">("confirmando");
const email = ref("");
const error = ref<string | null>(null);
const conSesion = ref(false);

onMounted(async () => {
  if (slug === "" || token === "") {
    estado.value = "error";
    return;
  }
  try {
    const { data } = await api.post<{ data: { email: string } }>(
      `/api/v1/app/${slug}/confirmar-correo`,
      { token },
    );
    email.value = data.data.email;
    estado.value = "listo";
    if (sesion.slug === slug && sesion.usuario !== null) {
      conSesion.value = true;
      await sesion.cargarYo().catch(() => undefined);
    }
  } catch (e) {
    error.value = mensajeDeError(e);
    estado.value = "error";
  }
});
</script>

<template>
  <section class="tu-public-access">
    <p class="tu-public-eyebrow">Verificación de cuenta</p>
    <h1 class="tu-public-form-title">{{ $t("confirmarCorreo.titulo") }}</h1>

    <div class="mt-6 tu-card p-6 text-sm">
      <p v-if="estado === 'confirmando'" role="status">
        {{ $t("confirmarCorreo.confirmando") }}
      </p>

      <template v-else-if="estado === 'listo'">
        <p role="status">{{ $t("confirmarCorreo.listo", { email }) }}</p>
        <RouterLink
          v-if="conSesion"
          class="tu-btn tu-btn-primario mt-4 inline-flex"
          :to="{ name: 'mi-perfil' }"
        >
          {{ $t("confirmarCorreo.volver") }}
        </RouterLink>
        <RouterLink
          v-else
          class="tu-btn tu-btn-primario mt-4 inline-flex"
          :to="{ path: '/entrar', query: { estudio: slug } }"
        >
          {{ $t("confirmarCorreo.entrar") }}
        </RouterLink>
      </template>

      <p v-else role="alert" style="color: var(--error)">
        {{ error ?? $t("confirmarCorreo.sinEnlace") }}
      </p>
    </div>
  </section>
</template>
