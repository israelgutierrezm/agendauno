<script setup lang="ts">
import { RouterLink } from "vue-router";

import ModalDialogo from "@/components/ModalDialogo.vue";

/**
 * La ficha de alguien del equipo en el sitio del negocio (ADR 0167): su foto, su
 * semblanza completa y qué hacer con él: agendar en un negocio de citas o ver sus
 * próximas clases en uno de clases.
 */
defineProps<{
  persona: {
    id?: string;
    nombre: string;
    foto_url: string | null;
    semblanza?: string | null;
  } | null;
  // A dónde lleva «Agendar con…» (citas); null si no aplica.
  agendar: Record<string, unknown> | null;
  // Sus próximas clases, ya en texto (clases).
  clases: string[];
}>();
const emit = defineEmits<{ cerrar: [] }>();

function iniciales(nombre: string): string {
  return nombre
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join("");
}
</script>

<template>
  <ModalDialogo
    :abierto="persona !== null"
    :titulo="persona?.nombre ?? ''"
    tam="md"
    @cerrar="emit('cerrar')"
  >
    <div v-if="persona" class="space-y-4" data-prueba="ficha-integrante">
      <img
        v-if="persona.foto_url"
        :src="persona.foto_url"
        :alt="persona.nombre"
        class="fi-foto"
      />
      <span v-else class="fi-foto fi-iniciales" aria-hidden="true">{{
        iniciales(persona.nombre)
      }}</span>
      <p v-if="persona.semblanza" class="whitespace-pre-line">
        {{ persona.semblanza }}
      </p>
      <div v-if="clases.length > 0">
        <h3 class="font-medium">{{ $t("sitioWeb.publico.susClases") }}</h3>
        <ul
          class="mt-2 space-y-1 text-sm"
          :style="{ color: 'var(--texto-suave)' }"
        >
          <li v-for="(c, i) in clases" :key="i">{{ c }}</li>
        </ul>
      </div>
      <RouterLink
        v-if="agendar"
        :to="agendar"
        class="tu-btn tu-btn-primario inline-flex"
        data-prueba="agendar-con"
        >{{
          $t("sitioWeb.publico.agendarCon", { nombre: persona.nombre })
        }}</RouterLink
      >
    </div>
  </ModalDialogo>
</template>

<style scoped>
.fi-foto {
  display: block;
  width: 8rem;
  height: 8rem;
  border-radius: 9999px;
  object-fit: cover;
}
.fi-iniciales {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  font-weight: 700;
  background: var(--primario);
  color: var(--primario-contraste);
}
</style>
