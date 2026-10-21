<script setup lang="ts">
import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import { api } from "@/lib/api";

/**
 * Cancelar la demo agendada desde el enlace del correo (ADR 0193). El enlace lleva la
 * demo y su token; sin ellos (o si no coinciden) no se cancela nada.
 */
const { t } = useI18n();
const ruta = useRoute();
const estado = ref<"cancelando" | "cancelada" | "error">("cancelando");

onMounted(async () => {
  const demo = typeof ruta.query.demo === "string" ? ruta.query.demo : "";
  const token = typeof ruta.query.token === "string" ? ruta.query.token : "";
  if (demo === "" || token === "") {
    estado.value = "error";
    return;
  }
  try {
    await api.post(`/api/v1/demos/${encodeURIComponent(demo)}/cancelar`, {
      token,
    });
    estado.value = "cancelada";
  } catch {
    estado.value = "error";
  }
});
</script>

<template>
  <main class="mx-auto max-w-xl px-4 py-24 text-center">
    <h1 class="text-2xl font-semibold" data-prueba="demo-cancelar">
      {{ t(`demoAgendable.cancelar.${estado}`) }}
    </h1>
    <p
      v-if="estado !== 'cancelando'"
      class="mt-3"
      :style="{ color: 'var(--texto-suave)' }"
    >
      {{ t(`demoAgendable.cancelar.${estado}Texto`) }}
    </p>
    <RouterLink to="/" class="tu-btn tu-btn-primario mt-8 inline-block">
      {{ t("demoAgendable.cancelar.volver") }}
    </RouterLink>
  </main>
</template>
