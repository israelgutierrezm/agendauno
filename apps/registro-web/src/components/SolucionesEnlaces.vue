<script setup lang="ts">
import { RouterLink } from "vue-router";
import { soluciones, rutaSolucion } from "@/marketing/soluciones";
defineProps<{ excluir?: string }>();
</script>

<template>
  <nav class="soluciones-enlaces" aria-label="Soluciones por tipo de negocio">
    <RouterLink
      v-for="solucion in soluciones.filter((s) => s.slug !== excluir)"
      :key="solucion.slug"
      :to="rutaSolucion(solucion.slug)"
    >
      <span>{{ solucion.nombre }}</span
      ><span aria-hidden="true">↗</span>
    </RouterLink>
  </nav>
</template>

<style scoped>
.soluciones-enlaces {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.75rem;
  margin-top: 1.5rem;
}
.soluciones-enlaces a {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1.2rem;
  border: 1px solid var(--borde);
  border-radius: var(--radio-tarjeta, 18px);
  background: var(--superficie);
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--texto);
  text-decoration: none;
}
.soluciones-enlaces a:hover {
  border-color: var(--acento);
  color: var(--enlace);
}
.soluciones-enlaces a:focus-visible {
  outline: 2px solid var(--enlace);
  outline-offset: 3px;
}
@media (max-width: 639px) {
  .soluciones-enlaces {
    grid-template-columns: 1fr;
  }
}
</style>
