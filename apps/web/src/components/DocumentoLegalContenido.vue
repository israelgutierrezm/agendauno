<script setup lang="ts">
import { computed } from "vue";

import { bloquesLegales } from "@/lib/documentoLegal";
import borradorAviso from "@/marketing/legales/aviso-privacidad.txt?raw";
import borradorTerminos from "@/marketing/legales/terminos.txt?raw";

/**
 * El aviso de privacidad o los términos publicados, con sus apartados numerados, sus
 * párrafos y sus listas. Sin documento publicado, en desarrollo se ve el texto base
 * (marcado como borrador); en producción, nunca.
 */
const props = withDefaults(
  defineProps<{ contenido: string | null; tipo?: "aviso" | "terminos" }>(),
  { tipo: "aviso" },
);
const vistaPrevia = import.meta.env.DEV
  ? props.tipo === "aviso"
    ? borradorAviso
    : borradorTerminos
  : null;
const publicado = computed(() => Boolean(props.contenido?.trim()));
const bloques = computed(() =>
  bloquesLegales(publicado.value ? props.contenido! : (vistaPrevia ?? "")),
);
</script>

<template>
  <div class="legal-contenido">
    <template v-if="publicado || vistaPrevia">
      <p v-if="!publicado" class="legal-pendiente" role="status">
        Borrador para revisión · Solo visible en desarrollo. Al publicarlo desde
        el superadmin se llenan el nombre, el domicilio y el contacto del
        responsable. No es un documento publicado.
      </p>
      <div class="legal-texto">
        <template v-for="(bloque, i) in bloques" :key="i">
          <h2 v-if="bloque.tipo === 'parte'" class="legal-parte">
            {{ bloque.texto }}
          </h2>
          <h2 v-else-if="bloque.tipo === 'titulo'">{{ bloque.texto }}</h2>
          <ul v-else-if="bloque.tipo === 'lista'">
            <li v-for="(elemento, j) in bloque.elementos" :key="j">
              {{ elemento }}
            </li>
          </ul>
          <p v-else>{{ bloque.texto }}</p>
        </template>
      </div>
    </template>
    <p v-else class="legal-pendiente" role="status">
      {{
        tipo === "aviso"
          ? "El aviso de privacidad aún no está disponible. No proporciones datos personales hasta poder consultarlo."
          : "Los términos y condiciones aún no están publicados."
      }}
      Si tienes dudas, escríbenos a
      <a class="tu-enlace" href="mailto:hola@agendauno.mx">hola@agendauno.mx</a
      >.
    </p>
  </div>
</template>

<style scoped>
.legal-texto {
  overflow-wrap: anywhere;
  line-height: 1.8;
  font-size: 0.95rem;
}
.legal-pendiente {
  padding: 1rem 1.25rem;
  border-left: 3px solid var(--aviso);
  background: var(--superficie-2);
  line-height: 1.7;
  margin-bottom: 1.5rem;
}
.legal-texto > p {
  white-space: pre-wrap;
  margin-block: 0 1rem;
}
.legal-texto > h2 {
  font-size: 1.15rem;
  font-weight: 600;
  line-height: 1.4;
  margin-block: 2.25rem 0.75rem;
}
.legal-texto > h2:first-child {
  margin-top: 0;
}
/* El encabezado de cada parte del documento, sobre sus apartados. */
.legal-texto > h2.legal-parte {
  margin-block: 3rem 0.5rem;
  padding-top: 1.5rem;
  border-top: 1px solid var(--borde);
  font-size: 0.85rem;
  letter-spacing: 0.06em;
  color: var(--texto-suave);
}
.legal-texto > ul {
  margin-block: 0 1.25rem;
  padding-left: 1.25rem;
  list-style: disc;
}
.legal-texto > ul > li {
  margin-block: 0.35rem;
  padding-left: 0.2rem;
}
</style>
