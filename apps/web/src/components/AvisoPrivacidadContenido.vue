<script setup lang="ts">
import { computed } from "vue";
import borrador from "@/marketing/aviso-privacidad.borrador.txt?raw";

const props = defineProps<{ contenido: string | null }>();
// El borrador nunca sustituye el documento publicado en producción.
const vistaPrevia = import.meta.env.DEV ? borrador : null;
const publicado = computed(() => Boolean(props.contenido?.trim()));
const bloques = computed(() =>
  (publicado.value ? props.contenido! : (vistaPrevia ?? ""))
    .split(/\r?\n\s*\r?\n/)
    .filter((texto) => texto.trim())
    .map((texto) => ({ texto, titulo: /^\d+\. [^\r\n]+$/.test(texto.trim()) })),
);
</script>

<template>
  <div class="aviso-contenido">
    <template v-if="publicado || vistaPrevia">
      <p v-if="!publicado" class="aviso-pendiente" role="status">
        Borrador para revisión · Solo visible en desarrollo. Faltan la
        identidad, el domicilio y el contacto del responsable, además de validar
        proveedores y prácticas de tratamiento. No es un aviso publicado ni
        acredita cumplimiento legal.
      </p>
      <div class="aviso-texto">
        <template v-for="(bloque, i) in bloques" :key="i">
          <h2 v-if="bloque.titulo">{{ bloque.texto }}</h2>
          <p v-else>{{ bloque.texto }}</p>
        </template>
      </div>
    </template>
    <p v-else class="aviso-pendiente" role="status">
      El aviso de privacidad aún no está disponible. No proporciones datos
      personales hasta poder consultar el documento y los datos de su
      responsable.
    </p>
  </div>
</template>

<style scoped>
.aviso-texto {
  overflow-wrap: anywhere;
  line-height: 1.85;
  font-size: 0.95rem;
}
.aviso-pendiente {
  padding: 1rem 1.25rem;
  border-left: 3px solid var(--aviso);
  background: var(--superficie-2);
  line-height: 1.7;
  margin-bottom: 1.5rem;
}
.aviso-texto > p {
  white-space: pre-wrap;
  margin-block: 0 1.25rem;
}
.aviso-texto > h2 {
  font-size: 1.15rem;
  font-weight: 500;
  line-height: 1.5;
  margin-block: 2.25rem 0.75rem;
}
</style>
