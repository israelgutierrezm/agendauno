<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { RouterLink, useRoute } from "vue-router";

import IconoNav from "@/components/IconoNav.vue";
import { destinoDe, menuVisible, ubicacion, type Area } from "@/lib/menu";
import { plural } from "@/lib/terminologia";
import { useSesionTenantStore } from "@/stores/sesionTenant";

/**
 * Barra lateral: grupos (títulos discretos, sin desplegar) con un enlace directo por
 * área, que abre su primera vista permitida. Solo un área queda activa (la de la
 * pantalla actual, aunque sea una ficha o una pestaña). Contraída, solo íconos.
 */
defineProps<{ compacto: boolean }>();
const emit = defineEmits<{ navegar: [] }>();

const { t } = useI18n();
const route = useRoute();
const sesion = useSesionTenantStore();

const grupos = computed(() => menuVisible(sesion));
const activa = computed(() => ubicacion(route)?.area.clave ?? null);

function etiqueta(a: Area): string {
  return a.termino !== undefined
    ? plural(sesion.terminologia[a.termino])
    : t(a.etiqueta);
}
</script>

<template>
  <nav class="nl" :aria-label="t('nav.menu')">
    <div v-for="(g, i) in grupos" :key="g.clave" class="nl-grupo">
      <p v-if="!compacto" class="nl-titulo">{{ t(g.etiqueta) }}</p>
      <hr v-else-if="i > 0" class="nl-separador" />
      <RouterLink
        v-for="a in g.areas"
        :key="a.clave"
        :to="destinoDe(a.destino)"
        class="tu-side-link"
        active-class=""
        exact-active-class=""
        :class="{
          'tu-side-activo': activa === a.clave,
          'lg:justify-center': compacto,
        }"
        :aria-current="activa === a.clave ? 'page' : undefined"
        :title="compacto ? etiqueta(a) : undefined"
        :aria-label="compacto ? etiqueta(a) : undefined"
        :data-prueba="`area-${a.clave}`"
        @click="emit('navegar')"
      >
        <IconoNav :nombre="a.icono" :tam="20" class="shrink-0" />
        <span v-if="!compacto" class="truncate">{{ etiqueta(a) }}</span>
      </RouterLink>
    </div>
  </nav>
</template>

<style scoped>
.nl {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.nl-grupo {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}
.nl-titulo {
  padding: 0 0.7rem 0.3rem;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--barra-texto);
  opacity: 0.7;
}
.nl-separador {
  margin: 0 0.5rem 0.4rem;
  border: 0;
  border-top: 1px solid var(--barra-borde);
}
</style>
